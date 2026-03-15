<?php
/**
 * scheduler.php — EduCore Auto Attendance Scheduler
 *
 * Scenario: Group has timetable slot "Saturday 05:30", duration 120 min.
 *   → At 05:30 Saturday  : session created automatically, status = open
 *   → At 07:30 Saturday  : session closed automatically, absent students marked
 *
 * Trigger: Called from layout.php on every non-student page load.
 *          Throttled to run at most once per minute via a lock file.
 *
 * Cron (recommended for exact timing):
 *   * * * * * php /path/to/educore/modules/attendance/cron.php
 */

function runScheduler(): void {
    // Use a stable lock file path (not __FILE__ which can vary)
    $lockFile = sys_get_temp_dir() . '/educore_scheduler.lock';
    $now = time();

    // Throttle: skip if ran within the last 60 seconds
    if (file_exists($lockFile) && ($now - (int)file_get_contents($lockFile)) < 60) {
        return;
    }
    // Write timestamp immediately — concurrent requests will see it and skip
    file_put_contents($lockFile, $now, LOCK_EX);

    try {
        _doAutoCreate($now);
        _doAutoClose($now);
    } catch (Throwable $e) {
        error_log('[EduCore Scheduler] ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
//  AUTO-CREATE
//  Finds groups whose timetable says "today at HH:MM" and that
//  time has already arrived, then creates a session if one
//  doesn't exist yet for that group today.
// ─────────────────────────────────────────────────────────────
function _doAutoCreate(int $now): void {
    $pdo     = getDB();
    $today   = date('Y-m-d', $now);
    $dayName = date('l', $now);   // "Saturday", "Monday" …

    $rows = $pdo->query("
        SELECT g.id, g.center_id, g.teacher_id, g.name, g.schedule,
               COALESCE(g.session_duration_minutes, 120) AS duration_minutes
        FROM `groups` g
        WHERE g.is_active = 1
          AND g.schedule IS NOT NULL
          AND g.schedule NOT IN ('null', '[]', '{}', '')
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $group) {
        foreach (_parseSchedule($group['schedule']) as $slot) {
            // Only process today's day
            if (trim($slot['day'] ?? '') !== $dayName) continue;

            $slotTime = trim($slot['time'] ?? '');
            if ($slotTime === '') continue;

            // Convert "05:30" → unix timestamp for today
            $slotTs = strtotime($today . ' ' . $slotTime);
            if ($slotTs === false) continue;

            // Session time must have arrived
            if ($slotTs > $now) continue;

            // Skip if a session already exists for this group today
            $exists = $pdo->prepare("
                SELECT id FROM attendance_sessions
                WHERE group_id = ? AND center_id = ? AND session_date = ?
                LIMIT 1
            ");
            $exists->execute([$group['id'], $group['center_id'], $today]);
            if ($exists->fetchColumn()) continue;

            // Resolve teacher (fall back to center owner)
            $teacherId = (int)($group['teacher_id'] ?? 0);
            if (!$teacherId) {
                $s = $pdo->prepare("SELECT id FROM users WHERE center_id=? AND role='owner' LIMIT 1");
                $s->execute([$group['center_id']]);
                $teacherId = (int)$s->fetchColumn();
            }
            if (!$teacherId) continue;

            // Code expires at end of session (start + duration)
            $durationMin = (int)($group['duration_minutes'] ?? 120);
            $codeExpires = date('Y-m-d H:i:s', $slotTs + $durationMin * 60);
            $code = strtoupper(substr(md5(uniqid('', true)), 0, 6));

            // INSERT the session
            $pdo->prepare("
                INSERT INTO attendance_sessions
                    (center_id, group_id, teacher_id, session_code,
                     code_expires_at, session_date, topic, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?)
            ")->execute([
                $group['center_id'],
                $group['id'],
                $teacherId,
                $code,
                $codeExpires,
                $today,
                'Auto-scheduled — ' . $slotTime,
                date('Y-m-d H:i:s', $slotTs),   // created_at = exact slot time
            ]);

            $newId = (int)$pdo->lastInsertId();

            // Notification to teacher
            pushNotification(
                $teacherId,
                'attendance',
                'Session opened: ' . $group['name'],
                'Auto-scheduled session started at ' . $slotTime . '. Code: ' . $code,
                '/educore/modules/attendance/create.php?session=' . $newId,
                $group['center_id']
            );

            // Audit log (silent if table missing)
            try {
                $pdo->prepare("
                    INSERT INTO audit_logs
                        (center_id, user_id, action, entity_type, entity_id, details, ip_address)
                    VALUES (?, ?, 'attendance.auto_opened', 'attendance_session', ?, ?, 'scheduler')
                ")->execute([
                    $group['center_id'], $teacherId, $newId,
                    json_encode(['group' => $group['name'], 'time' => $slotTime, 'code' => $code]),
                ]);
            } catch (Throwable $ignored) {}
        }
    }
}

// ─────────────────────────────────────────────────────────────
//  AUTO-CLOSE
//  Closes any open session where:
//    (a) session_date is a past day, OR
//    (b) created_at + duration_minutes <= now
//  Then marks all absent students who didn't submit attendance.
// ─────────────────────────────────────────────────────────────
function _doAutoClose(int $now): void {
    $pdo   = getDB();
    $today = date('Y-m-d', $now);

    $rows = $pdo->query("
        SELECT s.id, s.group_id, s.center_id, s.session_date, s.created_at,
               COALESCE(g.session_duration_minutes, 120) AS duration_minutes
        FROM attendance_sessions s
        JOIN `groups` g ON g.id = s.group_id
        WHERE s.status = 'open'
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $sess) {
        $shouldClose = false;

        // Case A: left open from a previous day
        if ($sess['session_date'] < $today) {
            $shouldClose = true;
        } else {
            // Case B: created_at + duration has elapsed
            $openedAt    = strtotime($sess['created_at']);
            $durationMin = (int)($sess['duration_minutes'] ?? 120);
            $closeAt     = $openedAt + ($durationMin * 60);

            if ($now >= $closeAt) {
                $shouldClose = true;
            }
        }

        if (!$shouldClose) continue;

        // Close the session
        $pdo->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=?")
            ->execute([$sess['id']]);

        // Mark absent: enrolled students who have no attendance record
        $absentStmt = $pdo->prepare("
            SELECT gs.student_id
            FROM group_students gs
            WHERE gs.group_id = ? AND gs.status = 'active'
              AND gs.student_id NOT IN (
                  SELECT ar.student_id FROM attendance_records ar
                  WHERE ar.session_id = ?
              )
        ");
        $absentStmt->execute([$sess['group_id'], $sess['id']]);
        $absentIds = $absentStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($absentIds)) {
            $ins = $pdo->prepare("
                INSERT IGNORE INTO attendance_records
                    (session_id, student_id, center_id, status, submitted_at)
                VALUES (?, ?, ?, 'absent', NOW())
            ");
            foreach ($absentIds as $sid) {
                $ins->execute([$sess['id'], $sid, $sess['center_id']]);
            }
        }

        // Audit log (silent if table missing)
        try {
            $pdo->prepare("
                INSERT INTO audit_logs
                    (center_id, user_id, action, entity_type, entity_id, details, ip_address)
                VALUES (?, 0, 'attendance.auto_closed', 'attendance_session', ?, ?, 'scheduler')
            ")->execute([
                $sess['center_id'], $sess['id'],
                json_encode(['absent_marked' => count($absentIds)]),
            ]);
        } catch (Throwable $ignored) {}
    }
}

// ─────────────────────────────────────────────────────────────
//  HELPER: Parse schedule JSON → [{day, time}, ...]
// ─────────────────────────────────────────────────────────────
function _parseSchedule(?string $json): array {
    if (!$json || $json === 'null') return [];
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data)) return [];

    // New format: [{day:"Saturday", time:"05:30"}, ...]
    if (isset($data[0]) && is_array($data[0])) {
        return $data;
    }

    // Old format: {days:["Saturday","Monday"], time:"05:30"}
    if (isset($data['days']) && is_array($data['days'])) {
        $t = $data['time'] ?? '';
        return array_map(function ($d) use ($t) {
            return ['day' => $d, 'time' => $t];
        }, $data['days']);
    }

    return [];
}
