<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('super_admin');

$pdo = getDB();

$pageTitle   = 'Super Admin';
$view        = $_GET['view'] ?? 'centers';

// Map view → nav key so the correct sidebar item highlights
$activePageMap = [
    'centers'           => 'admin_centers',
    'center_detail'     => 'admin_centers',
    'registrations'     => 'admin_centers',
    'plans'             => 'admin_plans',
    'password_requests' => 'admin_passwords',
    'notifications'     => 'admin_notifications',
];
$activePage  = $activePageMap[$view] ?? 'admin_centers';

// Breadcrumb labels per view
$viewLabels = [
    'centers'           => 'Centers',
    'center_detail'     => 'Center Detail',
    'registrations'     => 'Registrations',
    'plans'             => 'Plans',
    'password_requests' => 'Password Requests',
    'notifications'     => 'Notifications',
];
$breadcrumbs = [['label' => 'Super Admin', 'href' => '/educore/modules/admin/centers.php'], ['label' => $viewLabels[$view] ?? 'Centers']];

// ── Ensure password_reset_requests table exists ──────────────
try {
    $pdo->query("SELECT 1 FROM password_reset_requests LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        center_id INT NOT NULL,
        user_id INT NOT NULL,
        email VARCHAR(255) NOT NULL,
        requested_at DATETIME DEFAULT NOW(),
        status ENUM('pending','resolved','dismissed') DEFAULT 'pending',
        resolved_by INT NULL,
        resolved_at DATETIME NULL,
        message TEXT NULL
    )");
}

// ── POST handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_center') {
        $cid = (int)$_POST['center_id'];
        $pdo->prepare("UPDATE centers SET status='trial' WHERE id=?")->execute([$cid]);
        $pdo->prepare("UPDATE users SET is_active=1 WHERE center_id=? AND role='owner'")->execute([$cid]);
        $pdo->prepare("UPDATE subscriptions SET status='trial' WHERE center_id=?")->execute([$cid]);
        $ow = $pdo->prepare("SELECT id FROM users WHERE center_id=? AND role='owner' LIMIT 1");
        $ow->execute([$cid]);
        if ($owId = $ow->fetchColumn()) {
            pushNotification($owId,'approval','Your account has been approved!',
                'Welcome to EduCore! Your 14-day free trial has started.','/educore/dashboard.php',$cid);
        }
        auditLog('admin.center_approved','center',$cid);
        flash('success','Center approved — trial started.');
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'reject_center') {
        $cid    = (int)$_POST['center_id'];
        $reason = trim($_POST['reason'] ?? 'Your registration did not meet our requirements.');
        $ow = $pdo->prepare("SELECT id FROM users WHERE center_id=? AND role='owner' LIMIT 1");
        $ow->execute([$cid]);
        if ($owId = $ow->fetchColumn()) {
            pushNotification($owId,'rejection','Registration not approved',$reason,'',$cid);
        }
        $pdo->prepare("UPDATE centers SET status='suspended' WHERE id=?")->execute([$cid]);
        auditLog('admin.center_rejected','center',$cid,['reason'=>$reason]);
        flash('success','Center rejected.');
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'set_status') {
        $cid   = (int)$_POST['center_id'];
        $newSt = $_POST['new_status'] ?? '';
        if (in_array($newSt, ['active','trial','suspended'])) {
            $pdo->prepare("UPDATE centers SET status=? WHERE id=?")->execute([$newSt,$cid]);
            $active = ($newSt === 'suspended') ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_active=? WHERE center_id=? AND role='owner'")->execute([$active,$cid]);
            auditLog('admin.center_status','center',$cid,['status'=>$newSt]);
            flash('success','Status updated to '.ucfirst($newSt).'.');
        }
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'assign_plan') {
        $cid    = (int)$_POST['center_id'];
        $planId = (int)$_POST['plan_id'];
        $days   = max(1,(int)($_POST['days'] ?? 30));
        $existing = $pdo->prepare("SELECT id FROM subscriptions WHERE center_id=? ORDER BY id DESC LIMIT 1");
        $existing->execute([$cid]);
        $sub = $existing->fetch();
        if ($sub) {
            $pdo->prepare("UPDATE subscriptions SET plan_id=?,status='active',expires_at=DATE_ADD(NOW(),INTERVAL ? DAY) WHERE id=?")
                ->execute([$planId,$days,$sub['id']]);
        } else {
            $pdo->prepare("INSERT INTO subscriptions (center_id,plan_id,status,starts_at,expires_at) VALUES (?,?,'active',NOW(),DATE_ADD(NOW(),INTERVAL ? DAY))")
                ->execute([$cid,$planId,$days]);
        }
        $pdo->prepare("UPDATE centers SET status='active' WHERE id=?")->execute([$cid]);
        $pdo->prepare("UPDATE users SET is_active=1 WHERE center_id=? AND role='owner'")->execute([$cid]);
        $pdo->prepare("INSERT INTO subscription_history (center_id,to_plan,changed_by,reason) VALUES (?,?,?,'Admin assignment')")
            ->execute([$cid,$planId,authId()]);
        // Notify owner
        $ow2 = $pdo->prepare("SELECT id FROM users WHERE center_id=? AND role='owner' LIMIT 1");
        $ow2->execute([$cid]);
        if ($owId2 = $ow2->fetchColumn()) {
            $planName = '';
            foreach ($pdo->query("SELECT name FROM plans WHERE id=".(int)$planId)->fetchAll() as $pl) { $planName = $pl['name']; }
            pushNotification($owId2,'billing','Subscription Updated',
                'Your subscription has been updated to '.$planName.' for '.$days.' days.',
                '/educore/modules/settings/subscription.php',$cid);
        }
        auditLog('admin.plan_assigned','center',$cid,['plan_id'=>$planId,'days'=>$days]);
        flash('success','Plan assigned and center activated.');
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'revoke_subscription') {
        $cid = (int)$_POST['center_id'];
        $pdo->prepare("UPDATE subscriptions SET status='expired' WHERE center_id=?")->execute([$cid]);
        $pdo->prepare("UPDATE centers SET status='suspended' WHERE id=?")->execute([$cid]);
        $pdo->prepare("UPDATE users SET is_active=0 WHERE center_id=? AND role='owner'")->execute([$cid]);
        $ow3 = $pdo->prepare("SELECT id FROM users WHERE center_id=? AND role='owner' LIMIT 1");
        $ow3->execute([$cid]);
        if ($owId3 = $ow3->fetchColumn()) {
            pushNotification($owId3,'billing','Subscription Revoked',
                'Your subscription has been revoked. Contact support to restore access.','',$cid);
        }
        auditLog('admin.subscription_revoked','center',$cid);
        flash('success','Subscription revoked and center suspended.');
        redirect('/educore/modules/admin/centers.php');
    }

    if ($action === 'extend_subscription') {
        $cid  = (int)$_POST['center_id'];
        $days = max(1,(int)($_POST['days'] ?? 30));
        $pdo->prepare("UPDATE subscriptions SET expires_at=DATE_ADD(GREATEST(expires_at,NOW()),INTERVAL ? DAY),status='active' WHERE center_id=?")
            ->execute([$days,$cid]);
        $pdo->prepare("UPDATE centers SET status='active' WHERE id=?")->execute([$cid]);
        auditLog('admin.subscription_extended','center',$cid,['days'=>$days]);
        flash('success','Subscription extended by '.$days.' days.');
        redirect('/educore/modules/admin/centers.php');
    }

    // ── Plan CRUD ────────────────────────────────────────────
    if (in_array($action, ['create_plan','update_plan'])) {
        $name     = trim($_POST['name'] ?? '');
        $slug     = trim(strtolower(preg_replace('/[^a-z0-9]+/','-',$_POST['slug'] ?? $name)));
        $planType = in_array($_POST['plan_type'] ?? '', ['individual','academy']) ? $_POST['plan_type'] : 'academy';
        $price    = (float)($_POST['price'] ?? 0);
        $cycle    = $_POST['billing_cycle'] ?? 'monthly';
        // Individual plan: sensible limits; academy: unlimited
        if ($planType === 'individual') {
            $maxStu = (int)($_POST['max_students'] ?? 100);
            $maxTch = 1;  // always 1 for individual
            $maxGrp = (int)($_POST['max_groups'] ?? 20);
        } else {
            $maxStu = (int)($_POST['max_students'] ?? 999999);
            $maxTch = (int)($_POST['max_teachers'] ?? 999999);
            $maxGrp = (int)($_POST['max_groups'] ?? 999999);
        }
        $features = [
            'attendance' => isset($_POST['f_attendance']),
            'exams'      => isset($_POST['f_exams']),
            'payments'   => isset($_POST['f_payments']),
            'reports'    => isset($_POST['f_reports']),
            'export'     => isset($_POST['f_export']),
            'videos'     => isset($_POST['f_videos']),
        ];
        if ($name) {
            if ($action === 'create_plan') {
                $pdo->prepare("INSERT INTO plans (name,slug,plan_type,price,billing_cycle,max_students,max_teachers,max_groups,features) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$name,$slug,$planType,$price,$cycle,$maxStu,$maxTch,$maxGrp,json_encode($features)]);
                flash('success','Plan created.');
            } else {
                $pid = (int)$_POST['plan_id'];
                $pdo->prepare("UPDATE plans SET name=?,slug=?,plan_type=?,price=?,billing_cycle=?,max_students=?,max_teachers=?,max_groups=?,features=? WHERE id=?")
                    ->execute([$name,$slug,$planType,$price,$cycle,$maxStu,$maxTch,$maxGrp,json_encode($features),$pid]);
                flash('success','Plan updated.');
            }
        }
        redirect('/educore/modules/admin/centers.php?view=plans');
    }

    if ($action === 'toggle_plan') {
        $pid = (int)$_POST['plan_id'];
        $pdo->prepare("UPDATE plans SET is_active=NOT is_active WHERE id=?")->execute([$pid]);
        redirect('/educore/modules/admin/centers.php?view=plans');
    }

    // ── Delete a center and ALL its data ────────────────────
    if ($action === 'delete_center') {
        $cid = (int)$_POST['center_id'];
        $chk = $pdo->prepare("SELECT name FROM centers WHERE id=? LIMIT 1");
        $chk->execute([$cid]);
        $cName = $chk->fetchColumn();
        if ($cName) {
            $pdo->prepare("DELETE FROM centers WHERE id=?")->execute([$cid]);
            auditLog('admin.center_deleted', 'center', $cid, ['name' => $cName]);
            flash('success', 'Center "' . $cName . '" and all its data permanently deleted.');
        } else {
            flash('error', 'Center not found.');
        }
        redirect('/educore/modules/admin/centers.php');
    }

    // ── Delete a plan (only if not actively in use) ──────────
    if ($action === 'delete_plan') {
        $pid  = (int)$_POST['plan_id'];
        $chk  = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE plan_id=? AND status IN('active','trial')");
        $chk->execute([$pid]);
        $inUse = (int)$chk->fetchColumn();
        if ($inUse > 0) {
            flash('error', 'Cannot delete — ' . $inUse . ' active/trial subscription' . ($inUse!==1?'s are':' is') . ' using this plan. Disable it instead.');
        } else {
            $nc = $pdo->prepare("SELECT name FROM plans WHERE id=? LIMIT 1");
            $nc->execute([$pid]);
            $pName = $nc->fetchColumn();
            $pdo->prepare("UPDATE subscription_history SET to_plan=NULL WHERE to_plan=?")->execute([$pid]);
            $pdo->prepare("UPDATE subscription_history SET from_plan=NULL WHERE from_plan=?")->execute([$pid]);
            $pdo->prepare("DELETE FROM plans WHERE id=?")->execute([$pid]);
            auditLog('admin.plan_deleted', 'plan', $pid, ['name' => $pName]);
            flash('success', 'Plan "' . $pName . '" deleted.');
        }
        redirect('/educore/modules/admin/centers.php?view=plans');
    }

    // ── Delete a center and ALL its data ────────────────────
    if ($action === 'delete_center') {
        $cid = (int)$_POST['center_id'];
        // Confirm center exists
        $chk = $pdo->prepare("SELECT name FROM centers WHERE id=? LIMIT 1");
        $chk->execute([$cid]);
        $cName = $chk->fetchColumn();
        if ($cName) {
            // ON DELETE CASCADE handles all related data automatically
            $pdo->prepare("DELETE FROM centers WHERE id=?")->execute([$cid]);
            auditLog('admin.center_deleted', 'center', $cid, ['name' => $cName]);
            flash('success', 'Center "' . $cName . '" and all its data have been permanently deleted.');
        } else {
            flash('error', 'Center not found.');
        }
        redirect('/educore/modules/admin/centers.php');
    }

    // ── Delete a plan (only if no active subscriptions use it) ──
    if ($action === 'delete_plan') {
        $pid = (int)$_POST['plan_id'];
        $chk = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE plan_id=? AND status IN('active','trial')");
        $chk->execute([$pid]);
        $inUse = (int)$chk->fetchColumn();
        if ($inUse > 0) {
            flash('error', 'Cannot delete plan — ' . $inUse . ' active/trial subscription' . ($inUse!==1?'s are':' is') . ' using it. Disable it instead.');
        } else {
            $namChk = $pdo->prepare("SELECT name FROM plans WHERE id=? LIMIT 1");
            $namChk->execute([$pid]);
            $pName = $namChk->fetchColumn();
            // Set NULL on history rows before deleting (FK is SET NULL)
            $pdo->prepare("UPDATE subscription_history SET to_plan=NULL WHERE to_plan=?")->execute([$pid]);
            $pdo->prepare("UPDATE subscription_history SET from_plan=NULL WHERE from_plan=?")->execute([$pid]);
            $pdo->prepare("DELETE FROM plans WHERE id=?")->execute([$pid]);
            auditLog('admin.plan_deleted', 'plan', $pid, ['name' => $pName]);
            flash('success', 'Plan "' . $pName . '" deleted.');
        }
        redirect('/educore/modules/admin/centers.php?view=plans');
    }

    // ── Password reset handling ──────────────────────────────
    if ($action === 'resolve_password_reset') {
        $reqId    = (int)$_POST['request_id'];
        $centerId = (int)$_POST['center_id'];
        $userId   = (int)$_POST['user_id'];
        $newPass  = $_POST['new_password'] ?? '';
        if (strlen($newPass) >= 8) {
            $hashed = password_hash($newPass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hashed, $userId]);
            $pdo->prepare("UPDATE password_reset_requests SET status='resolved', resolved_by=?, resolved_at=NOW() WHERE id=?")
                ->execute([authId(), $reqId]);
            pushNotification($userId, 'password_reset', 'Password Has Been Reset',
                'Your password reset request has been processed. Please log in with your new password.',
                '/educore/login.php', $centerId);
            auditLog('admin.password_reset','user',$userId);
            flash('success','Password reset successfully. User has been notified.');
        } else {
            flash('error','Password must be at least 8 characters.');
        }
        redirect('/educore/modules/admin/centers.php?view=password_requests');
    }

    if ($action === 'dismiss_password_reset') {
        $reqId    = (int)$_POST['request_id'];
        $userId   = (int)$_POST['user_id'];
        $centerId = (int)$_POST['center_id'];
        $pdo->prepare("UPDATE password_reset_requests SET status='dismissed', resolved_by=?, resolved_at=NOW() WHERE id=?")
            ->execute([authId(), $reqId]);
        pushNotification($userId, 'info', 'Password Reset Request Dismissed',
            'Your password reset request was reviewed. Please contact support if you still need help.',
            '', $centerId);
        flash('success','Request dismissed.');
        redirect('/educore/modules/admin/centers.php?view=password_requests');
    }

    // ── Send notification ────────────────────────────────────
    if ($action === 'send_notification') {
        $targetType = $_POST['target_type'] ?? 'center';
        $targetCid  = (int)($_POST['target_center_id'] ?? 0);
        $notifType  = trim($_POST['notif_type'] ?? 'announcement');
        $notifTitle = trim($_POST['notif_title'] ?? '');
        $notifBody  = trim($_POST['notif_body'] ?? '');
        $notifLink  = trim($_POST['notif_link'] ?? '');

        if ($notifTitle && $notifBody) {
            if ($targetType === 'all') {
                $owners = $pdo->query("SELECT id, center_id FROM users WHERE role='owner' AND is_active=1")->fetchAll();
                foreach ($owners as $o) {
                    pushNotification($o['id'], $notifType, $notifTitle, $notifBody, $notifLink, $o['center_id']);
                }
                auditLog('admin.broadcast_notification', 'platform', 0, ['title'=>$notifTitle,'count'=>count($owners)]);
                flash('success', 'Broadcast sent to '.count($owners).' center owner'.( count($owners)!=1?'s':'').'.');
            } else {
                $owner = $pdo->prepare("SELECT id FROM users WHERE center_id=? AND role='owner' LIMIT 1");
                $owner->execute([$targetCid]);
                if ($owId = $owner->fetchColumn()) {
                    pushNotification($owId, $notifType, $notifTitle, $notifBody, $notifLink, $targetCid);
                    auditLog('admin.send_notification', 'center', $targetCid, ['title'=>$notifTitle]);
                    flash('success', 'Notification sent.');
                } else {
                    flash('error','No active owner found for that center.');
                }
            }
        } else {
            flash('error', 'Title and message are required.');
        }
        redirect('/educore/modules/admin/centers.php?view=notifications');
    }
}

// ── Platform stats ───────────────────────────────────────────
$stats = [
    'total'     => $pdo->query("SELECT COUNT(*) FROM centers")->fetchColumn(),
    'active'    => $pdo->query("SELECT COUNT(*) FROM centers WHERE status='active'")->fetchColumn(),
    'trial'     => $pdo->query("SELECT COUNT(*) FROM centers WHERE status='trial'")->fetchColumn(),
    'pending'   => $pdo->query("SELECT COUNT(*) FROM centers WHERE status='pending'")->fetchColumn(),
    'suspended' => $pdo->query("SELECT COUNT(*) FROM centers WHERE status='suspended'")->fetchColumn(),
    'mrr'       => $pdo->query("SELECT COALESCE(SUM(p.price),0) FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.status='active'")->fetchColumn(),
    'expiring'  => $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
];

// ── Load pending registrations with full submitted data ───────
$pendingRegistrations = [];
if ($view === 'registrations' || $stats['pending'] > 0) {
    $regStmt = $pdo->prepare("
        SELECT c.id, c.name, c.email, c.phone, c.address, c.status, c.created_at,
               u.id AS owner_id, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
               p.name AS plan_name, p.slug AS plan_slug,
               s.status AS sub_status, s.trial_ends_at
        FROM centers c
        LEFT JOIN users u ON u.center_id = c.id AND u.role = 'owner'
        LEFT JOIN subscriptions s ON s.center_id = c.id
            AND s.id = (SELECT MAX(id) FROM subscriptions WHERE center_id = c.id)
        LEFT JOIN plans p ON p.id = s.plan_id
        WHERE c.status = 'pending'
        ORDER BY c.created_at DESC");
    $regStmt->execute();
    $pendingRegistrations = $regStmt->fetchAll();

    // Attach branches to each pending registration
    foreach ($pendingRegistrations as &$reg) {
        try {
            $brStmt = $pdo->prepare("SELECT name, address, phone FROM center_branches WHERE center_id = ? ORDER BY sort_order, id");
            $brStmt->execute([$reg['id']]);
            $reg['branches'] = $brStmt->fetchAll();
        } catch (Exception $e) {
            // Fallback: try JSON column on centers table
            $reg['branches'] = [];
            if (!empty($reg['branches_json'])) {
                $reg['branches'] = json_decode($reg['branches_json'], true) ?? [];
            }
        }
    }
    unset($reg);
}

// ── Load pending registrations with full data ─────────────────
$pendingRegistrations = [];
if ($view === 'registrations' || $stats['pending'] > 0) {
    $regStmt = $pdo->prepare("
        SELECT c.id, c.name, c.email, c.phone, c.address, c.status, c.created_at,
               u.id AS owner_id, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
               p.name AS plan_name, p.slug AS plan_slug,
               s.status AS sub_status, s.trial_ends_at
        FROM centers c
        LEFT JOIN users u ON u.center_id = c.id AND u.role = 'owner'
        LEFT JOIN subscriptions s ON s.center_id = c.id AND s.id = (SELECT MAX(id) FROM subscriptions WHERE center_id = c.id)
        LEFT JOIN plans p ON p.id = s.plan_id
        WHERE c.status = 'pending'
        ORDER BY c.created_at DESC");
    $regStmt->execute();
    $pendingRegistrations = $regStmt->fetchAll();

    // Attach branches to each pending registration
    foreach ($pendingRegistrations as &$reg) {
        try {
            $brStmt = $pdo->prepare("SELECT name, address, phone FROM center_branches WHERE center_id = ? ORDER BY sort_order, id");
            $brStmt->execute([$reg['id']]);
            $reg['branches'] = $brStmt->fetchAll();
        } catch (Exception $e) {
            $reg['branches'] = [];
        }
    }
    unset($reg);
}

$pendingResets = 0;
try {
    $pendingResets = (int)$pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status='pending'")->fetchColumn();
} catch (Exception $e) {}

// ── Centers list ─────────────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$filterSt = $_GET['status'] ?? '';
$page     = max(1,(int)($_GET['page'] ?? 1));
$where    = 'WHERE 1=1';
$params   = [];
if ($search)   { $where .= ' AND (c.name LIKE ? OR c.email LIKE ?)'; $like="%$search%"; $params=[$like,$like]; }
if ($filterSt) { $where .= ' AND c.status=?'; $params[]=$filterSt; }

$centersQuery = "SELECT c.id, c.name, c.email, c.phone, c.status, c.created_at,
    p.name  AS plan_name, p.id AS plan_id, p.price AS plan_price,
    s.id    AS sub_id, s.status AS sub_status, s.expires_at, s.starts_at,
    u.id    AS owner_id, u.name AS owner_name, u.email AS owner_email,
    (SELECT COUNT(*) FROM users WHERE center_id=c.id AND role='student') AS student_count,
    (SELECT COUNT(*) FROM users WHERE center_id=c.id AND role='teacher') AS teacher_count,
    (SELECT COUNT(*) FROM groups WHERE center_id=c.id) AS group_count
  FROM centers c
  LEFT JOIN subscriptions s ON s.center_id=c.id AND s.id=(SELECT MAX(id) FROM subscriptions WHERE center_id=c.id)
  LEFT JOIN plans p ON p.id=s.plan_id
  LEFT JOIN users u ON u.center_id=c.id AND u.role='owner'
  $where
  ORDER BY FIELD(c.status,'pending','trial','active','suspended'), c.created_at DESC";

$result       = paginate($centersQuery, $params, $page, 20);
$centers_list = $result['data'];

// ── Center detail ─────────────────────────────────────────────
$centerDetail = null;
if ($view === 'center_detail' && isset($_GET['id'])) {
    $cid_d = (int)$_GET['id'];
    $cdStmt = $pdo->prepare("SELECT c.*, u.name AS owner_name, u.email AS owner_email, u.id AS owner_id,
        p.name AS plan_name, p.price AS plan_price, s.status AS sub_status, s.expires_at, s.starts_at
        FROM centers c
        LEFT JOIN subscriptions s ON s.center_id=c.id AND s.id=(SELECT MAX(id) FROM subscriptions WHERE center_id=c.id)
        LEFT JOIN plans p ON p.id=s.plan_id
        LEFT JOIN users u ON u.center_id=c.id AND u.role='owner'
        WHERE c.id=?");
    $cdStmt->execute([$cid_d]);
    $centerDetail = $cdStmt->fetch();
    if ($centerDetail) {
        $centerDetail['students']    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE center_id=$cid_d AND role='student'")->fetchColumn();
        $centerDetail['teachers']    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE center_id=$cid_d AND role='teacher'")->fetchColumn();
        $centerDetail['groups']      = (int)$pdo->query("SELECT COUNT(*) FROM groups WHERE center_id=$cid_d")->fetchColumn();
        $centerDetail['staff']       = $pdo->query("SELECT id,name,email,role,is_active,last_login FROM users WHERE center_id=$cid_d AND role != 'student' ORDER BY role")->fetchAll();
        try {
            $centerDetail['sub_history'] = $pdo->query("SELECT sh.*, p.name AS plan_name, u.name AS changed_by_name FROM subscription_history sh LEFT JOIN plans p ON p.id=sh.to_plan LEFT JOIN users u ON u.id=sh.changed_by WHERE sh.center_id=$cid_d ORDER BY sh.created_at DESC LIMIT 10")->fetchAll();
        } catch(Exception $e){ $centerDetail['sub_history']=[]; }
    }
}

// ── Plans ────────────────────────────────────────────────────
$allPlans    = $pdo->query("SELECT p.*,
    (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id=p.id AND s.status IN('active','trial')) AS sub_count
    FROM plans p ORDER BY price")->fetchAll();
$activePlans = $pdo->query("SELECT id,name,price FROM plans WHERE is_active=1 ORDER BY price")->fetchAll();
$featureList = ['attendance'=>'Attendance','exams'=>'Exams','payments'=>'Payments','reports'=>'Reports','export'=>'Export','videos'=>'Videos'];

$editPlan = null;
if ($view === 'plans' && isset($_GET['edit'])) {
    $ep = $pdo->prepare("SELECT * FROM plans WHERE id=?");
    $ep->execute([(int)$_GET['edit']]);
    $editPlan = $ep->fetch();
}

// ── Password reset requests ────────────────────────────────
$resetRequests     = [];
$resolvedRequests  = [];
if ($view === 'password_requests') {
    try {
        $resetRequests    = $pdo->query("SELECT r.*, c.name AS center_name, u.name AS user_name FROM password_reset_requests r LEFT JOIN centers c ON c.id=r.center_id LEFT JOIN users u ON u.id=r.user_id WHERE r.status='pending' ORDER BY r.requested_at DESC")->fetchAll();
        $resolvedRequests = $pdo->query("SELECT r.*, c.name AS center_name, u.name AS user_name, a.name AS resolved_by_name FROM password_reset_requests r LEFT JOIN centers c ON c.id=r.center_id LEFT JOIN users u ON u.id=r.user_id LEFT JOIN users a ON a.id=r.resolved_by WHERE r.status!='pending' ORDER BY r.resolved_at DESC LIMIT 20")->fetchAll();
    } catch (Exception $e) {}
}

// ── Centers for notification selector ───────────────────────
$allCenters = $pdo->query("SELECT id, name FROM centers WHERE status != 'suspended' ORDER BY name")->fetchAll();

$statusBadge = ['active'=>'badge-success','trial'=>'badge-warning','suspended'=>'badge-danger','pending'=>'badge-info'];

include __DIR__ . '/../../templates/layout.php';
?>

<style>
/* ── Super Admin Layout ── */
.sa-tabs { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:24px; overflow-x:auto; }
.sa-tab  { padding:10px 20px; font-size:0.82rem; font-weight:500; color:var(--text-muted); border-bottom:3px solid transparent; text-decoration:none; white-space:nowrap; transition:all .15s; display:flex; align-items:center; gap:7px; margin-bottom:-2px; border-radius:var(--radius-sm) var(--radius-sm) 0 0; }
.sa-tab:hover  { color:var(--text-primary); background:var(--bg-hover); }
.sa-tab.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; background:transparent; }
.sa-tab .bdot  { min-width:17px; height:17px; border-radius:9px; background:var(--danger); color:#fff; font-size:0.62rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; padding:0 4px; }

/* Centers table */
.c-row { display:grid; grid-template-columns:1fr 210px 130px 180px; align-items:center; gap:16px; padding:13px 20px; border-bottom:1px solid var(--border); transition:background .1s; }
.c-row:last-child { border-bottom:none; }
.c-row:hover { background:var(--bg-hover); }
.c-name { font-size:0.88rem; font-weight:600; color:var(--text-primary); }
.c-meta { font-size:0.7rem; color:var(--text-muted); margin-top:3px; display:flex; flex-wrap:wrap; gap:8px; }
.sub-expiry.warn    { color:#f59e0b; }
.sub-expiry.expired { color:var(--danger); }
.act-btns { display:flex; gap:5px; justify-content:flex-end; flex-wrap:wrap; }

/* Detail */
.stat-box { background:var(--bg-elevated); border-radius:var(--radius-md); padding:20px; text-align:center; border:1px solid var(--border); }
.stat-box .val { font-size:1.8rem; font-weight:800; color:var(--accent); line-height:1; }
.stat-box .lbl { font-size:0.68rem; color:var(--text-muted); margin-top:6px; text-transform:uppercase; letter-spacing:.07em; }

/* Plan cards */
.plan-card { border-radius:var(--radius-lg); overflow:hidden; background:var(--bg-card); border:1px solid var(--border); transition:box-shadow .2s,transform .15s; }
.plan-card:hover { box-shadow:var(--shadow-md); transform:translateY(-2px); }

/* Reset request card */
.req-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-md); padding:18px 20px; margin-bottom:12px; border-left:3px solid var(--accent); }

/* Notif target toggle */
.ntab { padding:7px 16px; font-size:0.8rem; font-weight:500; border-radius:var(--radius-sm); cursor:pointer; border:1px solid var(--border); background:transparent; color:var(--text-muted); transition:all .15s; }
.ntab.active { background:var(--accent); color:#fff; border-color:var(--accent); }

/* Alert bars */
.abar { border-radius:var(--radius-md); padding:11px 16px; margin-bottom:13px; display:flex; align-items:center; gap:10px; font-size:0.82rem; }
.abar.warn   { background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.25); }
.abar.danger { background:rgba(239,68,68,0.06);  border:1px solid rgba(239,68,68,0.20); }

.th { font-size:0.69rem; font-weight:600; text-transform:uppercase; letter-spacing:.07em; color:var(--text-muted); }

@media(max-width:768px) {
  .c-row { grid-template-columns:1fr auto; }
  .c-row > *:nth-child(2),
  .c-row > *:nth-child(3) { display:none; }
}
</style>

<!-- Page header -->
<div class="page-header" style="margin-bottom:16px;">
  <div>
    <h1 class="page-title">Super Admin</h1>
    <p class="page-subtitle">Platform-wide center, subscription &amp; notification control</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <?php if ($view === 'plans'): ?>
    <button class="btn btn-primary" onclick="openModal('planCreateModal')">+ New Plan</button>
    <?php endif; ?>
    <?php
    // Check if current admin has 2FA enabled
    $_sa2fa = $pdo->prepare("SELECT totp_enabled FROM users WHERE id=? LIMIT 1");
    $_sa2fa->execute([authId()]);
    $_sa2faRow = $_sa2fa->fetch();
    ?>
    <a href="/educore/totp_setup.php" class="btn btn-ghost btn-sm" title="Two-Factor Authentication">
      <?php if (!empty($_sa2faRow['totp_enabled'])): ?>
        <span style="color:var(--success);">🔐 2FA On</span>
      <?php else: ?>
        <span style="color:var(--warning);">⚠️ Enable 2FA</span>
      <?php endif; ?>
    </a>
  </div>
</div>

<!-- Stats strip -->
<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--border);border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden;margin-bottom:24px;">
  <?php
  $strip = [
    ['v'=>$stats['total'],    'l'=>'Total',        'c'=>'var(--text-primary)', 'href'=>'?'],
    ['v'=>$stats['active'],   'l'=>'Active',       'c'=>'var(--success)',      'href'=>'?status=active'],
    ['v'=>$stats['trial'],    'l'=>'Trial',        'c'=>'var(--accent)',       'href'=>'?status=trial'],
    ['v'=>$stats['pending'],  'l'=>'Pending',      'c'=>$stats['pending']>0?'#f59e0b':'var(--text-muted)', 'href'=>'?status=pending'],
    ['v'=>$stats['suspended'],'l'=>'Suspended',    'c'=>'var(--danger)',       'href'=>'?status=suspended'],
    ['v'=>$stats['expiring'], 'l'=>'Expiring 7d',  'c'=>$stats['expiring']>0?'#ef4444':'var(--text-muted)', 'href'=>'?status=active'],
    ['v'=>'$'.number_format($stats['mrr'],0),'l'=>'Active MRR','c'=>'var(--success)','href'=>'?view=plans'],
  ];
  foreach ($strip as $s): ?>
  <a href="<?= $s['href'] ?>" style="background:var(--bg-card);display:block;text-decoration:none;text-align:center;padding:14px 8px;">
    <div style="font-size:1.35rem;font-weight:700;color:<?= $s['c'] ?>;"><?= $s['v'] ?></div>
    <div style="font-size:0.68rem;color:var(--text-muted);margin-top:3px;"><?= $s['l'] ?></div>
  </a>
  <?php endforeach; ?>
</div>

<!-- Tabs -->
<div class="sa-tabs">
  <a href="?" class="sa-tab <?= $view==='centers'||$view==='center_detail'?'active':'' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
    Centers
  </a>
  <a href="?view=registrations" class="sa-tab <?= $view==='registrations'?'active':'' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
    Registrations
    <?php if($stats['pending']>0):?><span class="bdot"><?=$stats['pending']?></span><?php endif;?>
  </a>
  <a href="?view=plans" class="sa-tab <?= $view==='plans'?'active':'' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    Plans
    <span style="font-size:0.7rem;color:var(--text-muted);">(<?=count($allPlans)?>)</span>
  </a>
  <a href="?view=password_requests" class="sa-tab <?= $view==='password_requests'?'active':'' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    Password Requests
    <?php if($pendingResets>0):?><span class="bdot"><?=$pendingResets?></span><?php endif;?>
  </a>
  <a href="?view=notifications" class="sa-tab <?= $view==='notifications'?'active':'' ?>">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    Send Notification
  </a>
</div>


<?php /* ═══════ REGISTRATIONS ═══════ */ ?>
<?php if ($view === 'registrations'): ?>

<style>
.reg-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-left: 4px solid var(--accent);
  border-radius: var(--radius-md);
  padding: 22px 24px;
  margin-bottom: 16px;
  transition: box-shadow 0.15s;
}
.reg-card:hover { box-shadow: var(--shadow-md); }
.reg-field { font-size: 0.78rem; color: var(--text-muted); margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.06em; }
.reg-value { font-size: 0.88rem; color: var(--text-primary); font-weight: 500; }
.reg-grid  { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin: 16px 0; padding: 14px 16px; background: var(--bg-elevated); border-radius: var(--radius-sm); }
</style>

<?php if (empty($pendingRegistrations)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:60px 20px;">
    <div style="font-size:3rem;margin-bottom:14px;">✅</div>
    <div style="font-size:1.1rem;font-weight:700;margin-bottom:6px;">No pending registrations</div>
    <div style="font-size:0.84rem;color:var(--text-muted);">All registration requests have been reviewed.</div>
  </div>
</div>

<?php else: ?>
<div style="margin-bottom:16px;padding:13px 18px;background:rgba(245,158,11,0.07);border:1px solid rgba(245,158,11,0.25);border-radius:var(--radius-md);font-size:0.83rem;display:flex;align-items:center;gap:10px;">
  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <span><strong><?= count($pendingRegistrations) ?> registration<?= count($pendingRegistrations) !== 1 ? 's' : '' ?></strong> waiting for your review. Each one shows all submitted data so you can make an informed decision.</span>
</div>

<?php foreach ($pendingRegistrations as $reg): ?>
<div class="reg-card">

  <!-- Header row -->
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
    <div>
      <div style="font-size:1.05rem;font-weight:700;color:var(--text-primary);margin-bottom:4px;">
        <?= sanitize($reg['name']) ?>
      </div>
      <div style="font-size:0.76rem;color:var(--text-muted);">
        Submitted <?= date('l, F j, Y \a\t g:i a', strtotime($reg['created_at'])) ?>
        &nbsp;·&nbsp; <?= round((time() - strtotime($reg['created_at'])) / 3600, 1) ?> hours ago
      </div>
    </div>
    <span class="badge badge-info" style="padding:5px 12px;font-size:0.72rem;">⏳ Pending Review</span>
  </div>

  <!-- All submitted data -->
  <div class="reg-grid">
    <div>
      <div class="reg-field">Center Name</div>
      <div class="reg-value"><?= sanitize($reg['name']) ?></div>
    </div>
    <div>
      <div class="reg-field">Center Email</div>
      <div class="reg-value"><?= sanitize($reg['email']) ?></div>
    </div>
    <div>
      <div class="reg-field">Center Phone</div>
      <div class="reg-value"><?= sanitize($reg['phone'] ?: '—') ?></div>
    </div>
    <div>
      <div class="reg-field">Address</div>
      <div class="reg-value"><?= sanitize($reg['address'] ?: '—') ?></div>
    </div>
    <div>
      <div class="reg-field">Owner Name</div>
      <div class="reg-value"><?= sanitize($reg['owner_name'] ?? '—') ?></div>
    </div>
    <div>
      <div class="reg-field">Owner Email</div>
      <div class="reg-value"><?= sanitize($reg['owner_email'] ?? $reg['email']) ?></div>
    </div>
    <div>
      <div class="reg-field">Owner Phone</div>
      <div class="reg-value"><?= sanitize($reg['owner_phone'] ?? '—') ?></div>
    </div>
    <div>
      <div class="reg-field">Requested Plan</div>
      <div class="reg-value"><?= sanitize($reg['plan_name'] ?? 'Individual') ?></div>
    </div>
  </div>

  <!-- Branches -->
  <?php if (!empty($reg['branches'])): ?>
  <div style="margin-top:4px;">
    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:8px;">
      📍 Branches (<?= count($reg['branches']) ?>)
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;">
      <?php foreach ($reg['branches'] as $bi => $br): ?>
      <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;">
        <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);margin-bottom:3px;">
          <?= sanitize($br['name']) ?>
        </div>
        <?php if (!empty($br['address'])): ?>
        <div style="font-size:0.74rem;color:var(--text-muted);">📍 <?= sanitize($br['address']) ?></div>
        <?php endif; ?>
        <?php if (!empty($br['phone'])): ?>
        <div style="font-size:0.74rem;color:var(--text-muted);">📞 <?= sanitize($br['phone']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Action buttons -->
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:4px;">
    <!-- Approve -->
    <form method="POST" style="display:inline;" onsubmit="return confirm('Approve <?= addslashes(sanitize($reg['name'])) ?>? Their 14-day trial will start immediately.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="approve_center">
      <input type="hidden" name="center_id" value="<?= $reg['id'] ?>">
      <button type="submit" class="btn btn-success" style="gap:8px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Approve &amp; Start Trial
      </button>
    </form>

    <!-- Reject -->
    <button class="btn btn-danger" onclick="openRejectModal(<?= $reg['id'] ?>,'<?= addslashes(sanitize($reg['name'])) ?>')" style="gap:8px;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      Reject
    </button>

    <!-- Assign custom plan before approving -->
    <button class="btn btn-ghost" onclick="openPlanModal(<?= $reg['id'] ?>,'<?= addslashes(sanitize($reg['name'])) ?>')" style="font-size:0.8rem;">
      Assign Plan &amp; Activate
    </button>

    <span style="font-size:0.74rem;color:var(--text-muted);margin-left:4px;">
      ID #<?= $reg['id'] ?>
    </span>
  </div>

</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; /* end registrations view */ ?>


<?php /* ═══════ CENTERS ═══════ */ ?>
<?php if ($view === 'centers'): ?>

<?php if ($stats['pending'] > 0): ?>
<div class="abar warn">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <strong><?=$stats['pending']?> center<?=$stats['pending']!=1?'s':''?> waiting for approval.</strong>
  <a href="?status=pending" class="btn btn-sm btn-warning" style="margin-left:auto;">Review →</a>
</div>
<?php endif; ?>
<?php if ($stats['expiring'] > 0): ?>
<div class="abar danger">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
  <strong><?=$stats['expiring']?> subscription<?=$stats['expiring']!=1?'s':''?> expiring within 7 days.</strong>
  <a href="?status=active" class="btn btn-sm btn-danger" style="margin-left:auto;">View →</a>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px;">
  <div class="card-body" style="padding:11px 16px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="q" class="form-control" placeholder="Search name or email…" value="<?=sanitize($search)?>" style="width:240px;" autocomplete="off">
      <select name="status" class="form-control" style="width:150px;">
        <option value="">All statuses</option>
        <?php foreach(['pending','trial','active','suspended'] as $st): ?>
        <option value="<?=$st?>" <?=$filterSt===$st?'selected':''?>><?=ucfirst($st)?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if($search||$filterSt):?><a href="?" class="btn btn-ghost btn-sm">Clear</a><?php endif;?>
    </form>
  </div>
</div>

<div class="card">
  <div style="display:grid;grid-template-columns:1fr 210px 130px 180px;gap:16px;padding:9px 20px;border-bottom:1px solid var(--border);background:var(--bg-elevated);">
    <div class="th">Center</div>
    <div class="th">Subscription</div>
    <div class="th">Status</div>
    <div class="th" style="text-align:right;">Actions</div>
  </div>
  <div class="card-body no-padding">
    <?php if(!empty($centers_list)): ?>
    <?php foreach($centers_list as $c):
      $exClass=''; $exText='';
      if($c['expires_at']) {
        $diff=(strtotime($c['expires_at'])-time())/86400;
        $exText='Expires '.date('M j, Y',strtotime($c['expires_at']));
        if($diff<0){$exClass='expired';$exText='Expired '.date('M j, Y',strtotime($c['expires_at']));}
        elseif($diff<=7){$exClass='warn';$exText='Expires in '.ceil($diff).'d';}
      } elseif($c['sub_status']==='trial') { $exText='Trial — no expiry'; }
    ?>
    <div class="c-row">
      <div>
        <div class="c-name"><?=sanitize($c['name'])?></div>
        <div class="c-meta">
          <span><?=sanitize($c['owner_name']??'—')?> · <?=sanitize($c['owner_email']??$c['email'])?></span>
        </div>
        <div class="c-meta" style="margin-top:2px;">
          <span>👥 <?=(int)($c['student_count']??0)?></span>
          <span>🧑‍🏫 <?=(int)($c['teacher_count']??0)?></span>
          <span>📁 <?=(int)($c['group_count']??0)?> groups</span>
          <span>Joined <?=date('M j, Y',strtotime($c['created_at']))?></span>
        </div>
      </div>
      <div style="font-size:0.79rem;">
        <?php if($c['plan_name']): ?>
          <div style="font-weight:600;color:var(--text-primary);"><?=sanitize($c['plan_name'])?><?=$c['plan_price']>0?' <span style="font-weight:400;color:var(--text-muted);">($'.number_format($c['plan_price'],0).'/mo)</span>':''?></div>
          <?php if($exText): ?><div class="sub-expiry <?=$exClass?>" style="font-size:0.7rem;margin-top:2px;"><?=$exText?></div><?php endif;?>
        <?php else: ?>
          <div style="color:var(--text-muted);">No plan</div>
        <?php endif; ?>
      </div>
      <div><span class="badge <?=$statusBadge[$c['status']]??'badge-muted'?>"><?=ucfirst($c['status'])?></span>
        <?php if($c['sub_status']&&$c['sub_status']!==$c['status']):?><div style="font-size:0.68rem;color:var(--text-muted);margin-top:4px;">Sub: <?=ucfirst($c['sub_status'])?></div><?php endif;?>
      </div>
      <div class="act-btns">
        <a href="?view=center_detail&id=<?=$c['id']?>" class="btn btn-ghost btn-sm">Detail</a>
        <?php if($c['status']==='pending'): ?>
          <form method="POST" style="display:inline;"><?=csrf_field()?><input type="hidden" name="action" value="approve_center"><input type="hidden" name="center_id" value="<?=$c['id']?>"><button class="btn btn-sm btn-success" type="submit">✓ Approve</button></form>
          <button class="btn btn-sm btn-danger" onclick="openRejectModal(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>')">✕ Reject</button>
          <button class="btn btn-sm btn-danger" style="opacity:.7;" onclick="openDeleteCenterModal(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>')">🗑</button>
        <?php else: ?>
          <a href="?view=center_detail&id=<?=$c['id']?>" class="btn btn-ghost btn-sm">Detail</a>
          <button class="btn btn-ghost btn-sm" onclick="openPlanModal(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>')">Sub</button>
          <button class="btn btn-ghost btn-sm" onclick="openStatusModal(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>','<?=$c['status']?>')">Status</button>
          <button class="btn btn-ghost btn-sm" title="Send notification" onclick="openQuickNotif(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>')">📢</button>
          <button class="btn btn-sm btn-danger" style="opacity:.7;" onclick="openDeleteCenterModal(<?=$c['id']?>,'<?=addslashes(sanitize($c['name']))?>')">🗑</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(($result['last_page']??1)>1): ?>
    <div class="pagination">
      <span>Page <?=$result['current_page']?> of <?=$result['last_page']?> · <?=$result['total']?> centers</span>
      <div class="page-btns">
        <?php for($i=1;$i<=$result['last_page'];$i++): ?>
        <a href="?page=<?=$i?>&q=<?=urlencode($search)?>&status=<?=$filterSt?>" class="page-btn <?=$i===$result['current_page']?'active':''?>"><?=$i?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">🏫</div><div class="empty-title">No centers found</div></div>
    <?php endif; ?>
  </div>
</div>


<?php /* ═══════ CENTER DETAIL ═══════ */ ?>
<?php elseif($view==='center_detail'&&$centerDetail): ?>

<div style="margin-bottom:16px;"><a href="?" class="btn btn-ghost btn-sm">← Back to Centers</a></div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
  <div>
    <h2 style="font-size:1.4rem;font-weight:700;margin-bottom:4px;"><?=sanitize($centerDetail['name'])?></h2>
    <div style="font-size:0.82rem;color:var(--text-muted);display:flex;gap:16px;flex-wrap:wrap;">
      <span>📧 <?=sanitize($centerDetail['email'])?></span>
      <?php if(!empty($centerDetail['phone'])):?><span>📞 <?=sanitize($centerDetail['phone'])?></span><?php endif;?>
      <span>Registered <?=date('M j, Y',strtotime($centerDetail['created_at']))?></span>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <span class="badge <?=$statusBadge[$centerDetail['status']]??'badge-muted'?>" style="padding:5px 10px;"><?=ucfirst($centerDetail['status'])?></span>
    <button class="btn btn-ghost btn-sm" onclick="openPlanModal(<?=$centerDetail['id']?>,'<?=addslashes(sanitize($centerDetail['name']))?>')">Manage Sub</button>
    <button class="btn btn-ghost btn-sm" onclick="openStatusModal(<?=$centerDetail['id']?>,'<?=addslashes(sanitize($centerDetail['name']))?>','<?=$centerDetail['status']?>')">Change Status</button>
    <button class="btn btn-primary btn-sm" onclick="openQuickNotif(<?=$centerDetail['id']?>,'<?=addslashes(sanitize($centerDetail['name']))?>')">📢 Notify</button>
    <button class="btn btn-danger btn-sm" onclick="openDeleteCenterModal(<?=$centerDetail['id']?>,'<?=addslashes(sanitize($centerDetail['name']))?>')">🗑 Delete Center</button>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
  <?php foreach([
    ['val'=>$centerDetail['students'],'lbl'=>'Students','icon'=>'👥'],
    ['val'=>$centerDetail['teachers'],'lbl'=>'Teachers','icon'=>'🧑‍🏫'],
    ['val'=>$centerDetail['groups'],'lbl'=>'Groups','icon'=>'📁'],
    ['val'=>$centerDetail['plan_name']??'No plan','lbl'=>'Current Plan','icon'=>'⭐'],
  ] as $ds):?>
  <div class="stat-box">
    <div style="font-size:1.4rem;margin-bottom:6px;"><?=$ds['icon']?></div>
    <div class="val" style="font-size:1.4rem;"><?=$ds['val']?></div>
    <div class="lbl"><?=$ds['lbl']?></div>
  </div>
  <?php endforeach;?>
</div>

<?php if($centerDetail['plan_name']):?>
<div class="card" style="margin-bottom:18px;">
  <div class="card-header"><strong>Subscription</strong></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;font-size:0.84rem;">
      <div><div style="color:var(--text-muted);font-size:0.7rem;margin-bottom:3px;text-transform:uppercase;">Plan</div><strong><?=sanitize($centerDetail['plan_name'])?></strong><?=$centerDetail['plan_price']>0?' — $'.number_format($centerDetail['plan_price'],0).'/mo':''?></div>
      <div><div style="color:var(--text-muted);font-size:0.7rem;margin-bottom:3px;text-transform:uppercase;">Status</div><span class="badge <?=$statusBadge[$centerDetail['sub_status']]??'badge-muted'?>"><?=ucfirst($centerDetail['sub_status']??'—')?></span></div>
      <div><div style="color:var(--text-muted);font-size:0.7rem;margin-bottom:3px;text-transform:uppercase;">Expires</div><?=$centerDetail['expires_at']?date('M j, Y',strtotime($centerDetail['expires_at'])):'No expiry set'?></div>
    </div>
  </div>
</div>
<?php endif;?>

<?php if(!empty($centerDetail['staff'])):?>
<div class="card" style="margin-bottom:18px;">
  <div class="card-header"><strong>Staff (<?=count($centerDetail['staff'])?>)</strong></div>
  <div class="card-body no-padding">
    <div style="display:grid;grid-template-columns:1fr 180px 110px 120px;gap:12px;padding:8px 16px;border-bottom:1px solid var(--border);background:var(--bg-elevated);">
      <div class="th">Name</div><div class="th">Email</div><div class="th">Role</div><div class="th">Last Login</div>
    </div>
    <?php foreach($centerDetail['staff'] as $st):?>
    <div style="display:grid;grid-template-columns:1fr 180px 110px 120px;gap:12px;align-items:center;padding:9px 16px;border-bottom:1px solid var(--border);font-size:0.82rem;">
      <div style="font-weight:500;"><?=sanitize($st['name'])?><?=$st['is_active']?'':' <span style="color:var(--danger);font-size:0.68rem;">(inactive)</span>'?></div>
      <div style="color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;"><?=sanitize($st['email'])?></div>
      <div><span class="badge badge-muted"><?=ucfirst($st['role'])?></span></div>
      <div style="color:var(--text-muted);"><?=$st['last_login']?date('M j, Y',strtotime($st['last_login'])):'Never'?></div>
    </div>
    <?php endforeach;?>
  </div>
</div>
<?php endif;?>

<?php if(!empty($centerDetail['sub_history'])):?>
<div class="card">
  <div class="card-header"><strong>Subscription History</strong></div>
  <div class="card-body no-padding">
    <?php foreach($centerDetail['sub_history'] as $sh):?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 16px;border-bottom:1px solid var(--border);font-size:0.82rem;">
      <div><strong><?=sanitize($sh['plan_name']??'Plan #'.$sh['to_plan'])?></strong><?=$sh['reason']?' <span style="color:var(--text-muted);">— '.sanitize($sh['reason']).'</span>':''?></div>
      <div style="color:var(--text-muted);font-size:0.73rem;"><?=date('M j, Y',strtotime($sh['created_at']))?> by <?=sanitize($sh['changed_by_name']??'System')?></div>
    </div>
    <?php endforeach;?>
  </div>
</div>
<?php endif;?>


<?php /* ═══════ PLANS ═══════ */ ?>
<?php elseif($view==='plans'): ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px;">
<?php foreach($allPlans as $p):
  $pf = json_decode($p['features']??'{}',true)??[];
  $pt = $p['plan_type'] ?? 'academy';
  $pc = ($pt === 'individual') ? '#8b5cf6' : '#3b82f6';
  $ptLabel = $pt === 'individual' ? '👤 Individual' : '🏫 Academy';
?>
<div class="plan-card">
  <div style="padding:18px 20px 14px;border-bottom:1px solid var(--border);border-top:3px solid <?=$pc?>;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
      <div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
          <div style="font-size:1.05rem;font-weight:700;color:<?=$pc?>;"><?=sanitize($p['name'])?></div>
          <span style="font-size:0.65rem;padding:2px 7px;border-radius:8px;background:<?=$pt==='individual'?'rgba(139,92,246,0.1)':'rgba(59,130,246,0.1)'?>;color:<?=$pc?>;font-weight:600;"><?=$ptLabel?></span>
        </div>
        <div style="font-size:0.71rem;color:var(--text-muted);"><?=ucfirst($p['billing_cycle'])?> · <?=$p['sub_count']?> center<?=$p['sub_count']!=1?'s':''?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:1.5rem;font-weight:800;"><?=$p['price']>0?'$'.number_format($p['price'],0):'Free'?></div>
        <span class="badge <?=$p['is_active']?'badge-success':'badge-muted'?>"><?=$p['is_active']?'Active':'Inactive'?></span>
      </div>
    </div>
  </div>
  <div style="padding:14px 20px 18px;">
    <div style="font-size:0.78rem;color:var(--text-secondary);line-height:2;margin-bottom:10px;">
      <div>Max students: <strong><?=$p['max_students']>=999999?'Unlimited':number_format($p['max_students'])?></strong></div>
      <div>Max teachers: <strong><?=$p['max_teachers']>=999999?'Unlimited':number_format($p['max_teachers'])?></strong></div>
      <div>Max groups: <strong><?=$p['max_groups']>=999999?'Unlimited':number_format($p['max_groups'])?></strong></div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:14px;">
      <?php foreach($featureList as $fk=>$fl):?>
      <span style="font-size:0.65rem;padding:2px 7px;border-radius:7px;background:<?=($pf[$fk]??false)?'var(--success-dim)':'var(--bg-elevated)'?>;color:<?=($pf[$fk]??false)?'var(--success)':'var(--text-muted)'?>;">
        <?=($pf[$fk]??false)?'✓':'✗'?> <?=$fl?>
      </span>
      <?php endforeach;?>
    </div>
    <div style="display:flex;gap:6px;">
      <a href="?view=plans&edit=<?=$p['id']?>" class="btn btn-ghost btn-sm" style="flex:1;text-align:center;">Edit</a>
      <form method="POST" style="flex:1;"><?=csrf_field()?><input type="hidden" name="action" value="toggle_plan"><input type="hidden" name="plan_id" value="<?=$p['id']?>"><button type="submit" class="btn btn-sm <?=$p['is_active']?'btn-danger':'btn-success'?>" style="width:100%;"><?=$p['is_active']?'Disable':'Enable'?></button></form>
      <button class="btn btn-danger btn-sm" style="padding:6px 10px;opacity:.75;" onclick="openDeletePlanModal(<?=$p['id']?>,<?=(int)$p['sub_count']?>,'<?=addslashes(sanitize($p['name']))?>')" title="Delete plan">🗑</button>
    </div>
  </div>
</div>
<?php endforeach;?>
<?php if(empty($allPlans)):?>
<div class="card"><div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted);">No plans yet. Create your first plan.</div></div>
<?php endif;?>
</div>


<?php /* ═══════ PASSWORD REQUESTS ═══════ */ ?>
<?php elseif($view==='password_requests'): ?>

<div style="margin-bottom:18px;padding:14px 18px;background:rgba(82,71,230,0.06);border:1px solid rgba(82,71,230,0.18);border-radius:var(--radius-md);font-size:0.83rem;color:var(--text-secondary);">
  <strong style="color:var(--text-primary);">How it works:</strong> When a center owner clicks "Forgot password?" on the login page, their request appears here. You set a new temporary password and they receive an in-app notification to log in with it.
</div>

<?php if(empty($resetRequests)): ?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="text-align:center;padding:50px 20px;">
    <div style="font-size:2.5rem;margin-bottom:12px;">🔐</div>
    <div style="font-size:1rem;font-weight:600;margin-bottom:6px;">No pending password requests</div>
    <div style="font-size:0.82rem;color:var(--text-muted);">All caught up!</div>
  </div>
</div>
<?php else: ?>
<div style="margin-bottom:8px;font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">Pending (<?=count($resetRequests)?>)</div>
<?php foreach($resetRequests as $req):?>
<div class="req-card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div>
      <div style="font-size:0.9rem;font-weight:700;"><?=sanitize($req['user_name']??$req['email'])?></div>
      <div style="font-size:0.78rem;color:var(--text-muted);margin-top:3px;display:flex;gap:12px;flex-wrap:wrap;">
        <span>📧 <?=sanitize($req['email'])?></span>
        <span>🏫 <?=sanitize($req['center_name']??'Unknown')?></span>
        <span>🕐 <?=date('M j, Y g:ia',strtotime($req['requested_at']))?></span>
      </div>
      <?php if(!empty($req['message'])):?>
      <div style="margin-top:9px;font-size:0.8rem;background:var(--bg-elevated);padding:8px 12px;border-radius:var(--radius-sm);color:var(--text-secondary);">"<?=sanitize($req['message'])?>"</div>
      <?php endif;?>
    </div>
    <div style="display:flex;gap:8px;flex-shrink:0;">
      <button class="btn btn-primary btn-sm" onclick="openResetModal(<?=$req['id']?>,<?=$req['center_id']?>,<?=$req['user_id']?>,'<?=addslashes(sanitize($req['user_name']??$req['email']))?>')">🔑 Reset Password</button>
      <form method="POST" style="display:inline;"><?=csrf_field()?><input type="hidden" name="action" value="dismiss_password_reset"><input type="hidden" name="request_id" value="<?=$req['id']?>"><input type="hidden" name="user_id" value="<?=$req['user_id']?>"><input type="hidden" name="center_id" value="<?=$req['center_id']?>"><button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm('Dismiss this request?')">Dismiss</button></form>
    </div>
  </div>
</div>
<?php endforeach;?>
<?php endif;?>

<?php if(!empty($resolvedRequests)):?>
<div style="margin-top:24px;margin-bottom:8px;font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">Recently Resolved</div>
<div class="card">
  <div class="card-body no-padding">
    <?php foreach($resolvedRequests as $rr):?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border);font-size:0.82rem;">
      <div>
        <strong><?=sanitize($rr['user_name']??$rr['email'])?></strong>
        <span style="color:var(--text-muted);margin-left:8px;"><?=sanitize($rr['center_name']??'')?></span>
      </div>
      <div style="display:flex;gap:10px;align-items:center;color:var(--text-muted);font-size:0.74rem;">
        <span class="badge <?=$rr['status']==='resolved'?'badge-success':'badge-muted'?>"><?=ucfirst($rr['status'])?></span>
        <?=$rr['resolved_at']?date('M j, Y',strtotime($rr['resolved_at'])):'—'?>
        by <?=sanitize($rr['resolved_by_name']??'Admin')?>
      </div>
    </div>
    <?php endforeach;?>
  </div>
</div>
<?php endif;?>


<?php /* ═══════ NOTIFICATIONS ═══════ */ ?>
<?php elseif($view==='notifications'): ?>

<div class="card">
  <div class="card-header">
    <strong>Send Notification to Centers</strong>
    <p style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;margin-bottom:0;">Deliver an in-app notification to a specific center owner or broadcast to all active centers.</p>
  </div>
  <div class="card-body">
    <form method="POST">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="send_notification">

      <div class="form-group">
        <label class="form-label">Target</label>
        <div style="display:flex;gap:6px;margin-bottom:0;">
          <button type="button" class="ntab active" id="ntabSingle" onclick="setNTarget('center')">Specific Center</button>
          <button type="button" class="ntab" id="ntabAll" onclick="setNTarget('all')">📢 All Active Centers</button>
        </div>
        <input type="hidden" name="target_type" id="notifTargetType" value="center">
      </div>

      <div class="form-group" id="centerPickGroup">
        <label class="form-label required">Center</label>
        <select name="target_center_id" class="form-control" style="max-width:400px;">
          <option value="">— Select a center —</option>
          <?php foreach($allCenters as $ac):?>
          <option value="<?=$ac['id']?>"><?=sanitize($ac['name'])?></option>
          <?php endforeach;?>
        </select>
      </div>

      <div style="display:grid;grid-template-columns:200px 1fr;gap:12px;">
        <div class="form-group">
          <label class="form-label required">Type</label>
          <select name="notif_type" class="form-control">
            <option value="announcement">📢 Announcement</option>
            <option value="warning">⚠️ Warning</option>
            <option value="info">ℹ️ Info</option>
            <option value="success">✅ Good news</option>
            <option value="billing">💳 Billing</option>
            <option value="maintenance">🔧 Maintenance</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Link (optional)</label>
          <input type="text" name="notif_link" class="form-control" placeholder="/educore/…">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label required">Title</label>
        <input type="text" name="notif_title" class="form-control" placeholder="e.g. Scheduled Maintenance Tonight" required maxlength="120">
      </div>

      <div class="form-group" style="margin-bottom:20px;">
        <label class="form-label required">Message</label>
        <textarea name="notif_body" class="form-control" rows="4" placeholder="Write your message here…" required maxlength="1000"></textarea>
        <span class="form-hint">Delivered to the center owner's notification inbox immediately.</span>
      </div>

      <div id="broadcastWarning" style="display:none;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);border-radius:var(--radius-sm);padding:11px 14px;font-size:0.82rem;margin-bottom:16px;">
        ⚠️ <strong>Broadcast mode:</strong> This will send a notification to <strong>all <?=count($allCenters)?> active centers</strong>. Make sure your message is correct before sending.
      </div>

      <div style="display:flex;justify-content:flex-end;">
        <button type="submit" class="btn btn-primary" id="sendNBtn">Send Notification</button>
      </div>
    </form>
  </div>
</div>

<?php endif; /* end views */ ?>


<!-- ────────────────── MODALS ────────────────── -->

<!-- Subscription modal -->
<div class="modal-backdrop" id="planModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Manage Subscription — <span id="pmCenterName"></span></span>
      <button class="modal-close" onclick="closeModal('planModal')">✕</button>
    </div>
    <div class="modal-body">
      <div style="display:flex;gap:6px;margin-bottom:18px;border-bottom:1px solid var(--border);padding-bottom:12px;">
        <button type="button" class="btn btn-ghost btn-sm" id="pmTabAssign" onclick="pmShowTab('assign')">Assign Plan</button>
        <button type="button" class="btn btn-ghost btn-sm" id="pmTabExtend" onclick="pmShowTab('extend')">Extend</button>
        <button type="button" class="btn btn-ghost btn-sm" id="pmTabRevoke" onclick="pmShowTab('revoke')">Revoke</button>
      </div>

      <form method="POST" id="pmFormAssign">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="assign_plan">
        <input type="hidden" name="center_id" id="pmCenterIdAssign">
        <div class="form-group">
          <label class="form-label required">Plan</label>
          <select name="plan_id" class="form-control">
            <?php foreach($activePlans as $p):?>
            <option value="<?=$p['id']?>"><?=sanitize($p['name'])?><?=$p['price']>0?' — $'.number_format($p['price'],0).'/mo':' — Free'?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label required">Duration (days)</label>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
            <?php foreach([30=>'1 mo',90=>'3 mo',180=>'6 mo',365=>'1 yr'] as $d=>$l):?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('pmDA').value=<?=$d?>"><?=$l?></button>
            <?php endforeach;?>
          </div>
          <input type="number" name="days" id="pmDA" class="form-control" value="30" min="1" max="3650">
          <span class="form-hint">Center activated &amp; owner notified automatically.</span>
        </div>
        <div class="modal-footer" style="padding:18px 0 0;margin:0;">
          <button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Assign &amp; Activate</button>
        </div>
      </form>

      <form method="POST" id="pmFormExtend" style="display:none;">
        <?=csrf_field()?><input type="hidden" name="action" value="extend_subscription"><input type="hidden" name="center_id" id="pmCenterIdExtend">
        <p style="font-size:0.83rem;color:var(--text-secondary);margin-bottom:14px;">Adds days on top of current expiry (or from today if expired).</p>
        <div class="form-group">
          <label class="form-label required">Extend by (days)</label>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
            <?php foreach([30=>'1 mo',90=>'3 mo',180=>'6 mo',365=>'1 yr'] as $d=>$l):?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('pmDE').value=<?=$d?>"><?=$l?></button>
            <?php endforeach;?>
          </div>
          <input type="number" name="days" id="pmDE" class="form-control" value="30" min="1" max="3650">
        </div>
        <div class="modal-footer" style="padding:18px 0 0;margin:0;">
          <button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Extend</button>
        </div>
      </form>

      <form method="POST" id="pmFormRevoke" style="display:none;">
        <?=csrf_field()?><input type="hidden" name="action" value="revoke_subscription"><input type="hidden" name="center_id" id="pmCenterIdRevoke">
        <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.2);border-radius:var(--radius-sm);padding:14px;font-size:0.84rem;color:var(--danger);margin-bottom:16px;">
          <strong>This will immediately:</strong><br>• Mark subscription as Expired<br>• Suspend center &amp; lock owner out<br>• Owner will be notified
        </div>
        <div class="modal-footer" style="padding:0;margin:0;">
          <button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
          <button type="submit" class="btn btn-danger">Revoke &amp; Suspend</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Status modal -->
<div class="modal-backdrop" id="statusModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Change Status — <span id="smCenterName"></span></span>
      <button class="modal-close" onclick="closeModal('statusModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?><input type="hidden" name="action" value="set_status"><input type="hidden" name="center_id" id="smCenterId">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">New status</label>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach(['active'=>['badge-success','Full access — requires active subscription'],
                           'trial' =>['badge-warning','Trial — 14-day free access'],
                           'suspended'=>['badge-danger','Suspended — owner locked out']] as $st=>[$cls,$desc]):?>
            <label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;">
              <input type="radio" name="new_status" value="<?=$st?>" style="accent-color:var(--accent);">
              <div><span class="badge <?=$cls?>"><?=ucfirst($st)?></span><div style="font-size:0.73rem;color:var(--text-muted);margin-top:3px;"><?=$desc?></div></div>
            </label>
            <?php endforeach;?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('statusModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Status</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject modal -->
<div class="modal-backdrop" id="rejectModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Reject Registration</span>
      <button class="modal-close" onclick="closeModal('rejectModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?><input type="hidden" name="action" value="reject_center"><input type="hidden" name="center_id" id="rejCenterId">
        <p style="font-size:0.84rem;color:var(--text-muted);margin-bottom:14px;">Rejecting: <strong id="rejCenterName" style="color:var(--text-primary);"></strong><br>The owner will receive an in-app notification with your reason.</p>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Reason (sent to applicant)</label>
          <textarea name="reason" class="form-control" rows="3" placeholder="Your registration did not meet our requirements."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="submit" class="btn btn-danger">Reject</button>
      </div>
    </form>
  </div>
</div>

<!-- Quick notify modal -->
<div class="modal-backdrop" id="quickNotifModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Notify — <span id="qnCenterName"></span></span>
      <button class="modal-close" onclick="closeModal('quickNotifModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?><input type="hidden" name="action" value="send_notification"><input type="hidden" name="target_type" value="center"><input type="hidden" name="target_center_id" id="qnCenterId">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label class="form-label">Type</label>
            <select name="notif_type" class="form-control">
              <option value="announcement">📢 Announcement</option>
              <option value="warning">⚠️ Warning</option>
              <option value="info">ℹ️ Info</option>
              <option value="billing">💳 Billing</option>
              <option value="maintenance">🔧 Maintenance</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Link</label>
            <input type="text" name="notif_link" class="form-control" placeholder="/educore/…">
          </div>
        </div>
        <div class="form-group"><label class="form-label required">Title</label><input type="text" name="notif_title" class="form-control" required maxlength="120"></div>
        <div class="form-group" style="margin-bottom:0;"><label class="form-label required">Message</label><textarea name="notif_body" class="form-control" rows="3" required maxlength="1000"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('quickNotifModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Send</button>
      </div>
    </form>
  </div>
</div>

<!-- Password reset modal -->
<div class="modal-backdrop" id="resetPassModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Reset Password — <span id="rpUserName"></span></span>
      <button class="modal-close" onclick="closeModal('resetPassModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?><input type="hidden" name="action" value="resolve_password_reset"><input type="hidden" name="request_id" id="rpReqId"><input type="hidden" name="center_id" id="rpCid"><input type="hidden" name="user_id" id="rpUid">
        <div style="background:rgba(82,71,230,0.06);border:1px solid rgba(82,71,230,0.18);border-radius:var(--radius-sm);padding:12px 14px;font-size:0.82rem;margin-bottom:16px;color:var(--text-secondary);">
          The user will receive an in-app notification once you save their new password.
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label required">New Password <span style="font-weight:400;color:var(--text-muted);">(min 8 characters)</span></label>
          <input type="text" name="new_password" id="rpPass" class="form-control" minlength="8" required autocomplete="new-password" placeholder="Enter a temporary password">
          <span class="form-hint">Share this securely with the user after resetting.</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('resetPassModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Reset &amp; Notify User</button>
      </div>
    </form>
  </div>
</div>

<!-- Plan create/edit modal -->
<div class="modal-backdrop" id="planCreateModal" <?=$editPlan?'style="opacity:1;visibility:visible;"':''?>>
  <div class="modal" style="max-width:560px;" <?=$editPlan?'style="transform:none;"':''?>>
    <div class="modal-header">
      <span class="modal-title"><?=$editPlan?'Edit Plan — '.sanitize($editPlan['name']):'New Plan'?></span>
      <button class="modal-close" onclick="closeModal('planCreateModal');<?=$editPlan?"window.location='?view=plans'":''?>">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="<?=$editPlan?'update_plan':'create_plan'?>">
        <?php if($editPlan):?><input type="hidden" name="plan_id" value="<?=$editPlan['id']?>"><?php endif;?>
        <?php $ef = $editPlan ? (json_decode($editPlan['features']??'{}',true)??[]) : [];
               $ept = $editPlan['plan_type'] ?? 'academy'; ?>

        <!-- Plan type selector -->
        <div class="form-group" style="margin-bottom:16px;">
          <label class="form-label required">Plan Type</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;" id="planTypeCards">
            <label style="border:2px solid var(--border);border-radius:var(--radius-md);padding:14px;cursor:pointer;transition:all .15s;" id="ptCardIndividual">
              <input type="radio" name="plan_type" value="individual" <?=$ept==='individual'?'checked':''?> onchange="onPlanTypeChange('individual')" style="accent-color:var(--accent);margin-right:6px;">
              <strong>Individual Teacher</strong>
              <div style="font-size:0.74rem;color:var(--text-muted);margin-top:4px;margin-left:20px;">1 teacher · the owner IS the teacher · limited students &amp; groups</div>
            </label>
            <label style="border:2px solid var(--border);border-radius:var(--radius-md);padding:14px;cursor:pointer;transition:all .15s;" id="ptCardAcademy">
              <input type="radio" name="plan_type" value="academy" <?=$ept==='academy'?'checked':''?> onchange="onPlanTypeChange('academy')" style="accent-color:var(--accent);margin-right:6px;">
              <strong>Academy</strong>
              <div style="font-size:0.74rem;color:var(--text-muted);margin-top:4px;margin-left:20px;">Multiple teachers · owner has full oversight · unlimited scale</div>
            </label>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group"><label class="form-label required">Name</label><input type="text" name="name" class="form-control" value="<?=sanitize($editPlan['name']??'')?>" required></div>
          <div class="form-group"><label class="form-label">Slug</label><input type="text" name="slug" class="form-control" id="planSlug" value="<?=sanitize($editPlan['slug']??'')?>" placeholder="individual"></div>
          <div class="form-group"><label class="form-label">Price (USD/mo)</label><input type="number" name="price" class="form-control" step="0.01" min="0" value="<?=$editPlan['price']??0?>"></div>
          <div class="form-group"><label class="form-label">Billing cycle</label><select name="billing_cycle" class="form-control"><?php foreach(['monthly','yearly','lifetime'] as $cy):?><option value="<?=$cy?>" <?=($editPlan['billing_cycle']??'monthly')===$cy?'selected':''?>><?=ucfirst($cy)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label class="form-label">Max students</label><input type="number" name="max_students" id="pmMaxStu" class="form-control" value="<?=$editPlan['max_students']??100?>"></div>
          <div class="form-group" id="pmMaxTchGroup"><label class="form-label">Max teachers <span id="pmMaxTchNote" style="font-weight:400;color:var(--text-muted);font-size:0.72rem;">(academy only)</span></label><input type="number" name="max_teachers" id="pmMaxTch" class="form-control" value="<?=$editPlan['max_teachers']??999999?>"></div>
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Max groups</label><input type="number" name="max_groups" id="pmMaxGrp" class="form-control" value="<?=$editPlan['max_groups']??20?>"></div>
        </div>
        <div>
          <div class="form-label" style="margin-bottom:8px;">Features included</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
            <?php foreach($featureList as $fk=>$fl):?>
            <label style="display:flex;align-items:center;gap:8px;font-size:0.82rem;cursor:pointer;padding:8px 10px;border-radius:var(--radius-sm);background:var(--bg-elevated);">
              <input type="checkbox" name="f_<?=$fk?>" <?=($ef[$fk]??false)?'checked':''?> style="accent-color:var(--accent);"><?=$fl?>
            </label>
            <?php endforeach;?>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('planCreateModal');<?=$editPlan?"window.location='?view=plans'":''?>">Cancel</button>
        <button type="submit" class="btn btn-primary"><?=$editPlan?'Save Changes':'Create Plan'?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openPlanModal(id, name) {
    ['pmCenterIdAssign','pmCenterIdExtend','pmCenterIdRevoke'].forEach(el => document.getElementById(el).value = id);
    document.getElementById('pmCenterName').textContent = name;
    pmShowTab('assign');
    openModal('planModal');
}
function openStatusModal(id, name, current) {
    document.getElementById('smCenterId').value = id;
    document.getElementById('smCenterName').textContent = name;
    document.querySelectorAll('#statusModal input[type=radio]').forEach(r => r.checked = r.value === current);
    openModal('statusModal');
}
function openRejectModal(id, name) {
    document.getElementById('rejCenterId').value = id;
    document.getElementById('rejCenterName').textContent = name;
    openModal('rejectModal');
}
function openQuickNotif(id, name) {
    document.getElementById('qnCenterId').value = id;
    document.getElementById('qnCenterName').textContent = name;
    openModal('quickNotifModal');
}
function openResetModal(reqId, centerId, userId, userName) {
    document.getElementById('rpReqId').value = reqId;
    document.getElementById('rpCid').value   = centerId;
    document.getElementById('rpUid').value   = userId;
    document.getElementById('rpUserName').textContent = userName;
    document.getElementById('rpPass').value  = '';
    openModal('resetPassModal');
}
function pmShowTab(tab) {
    ['assign','extend','revoke'].forEach(t => {
        const f = document.getElementById('pmForm'+t.charAt(0).toUpperCase()+t.slice(1));
        const b = document.getElementById('pmTab'+t.charAt(0).toUpperCase()+t.slice(1));
        if(f) f.style.display = t===tab ? '' : 'none';
        if(b) b.style.fontWeight = t===tab ? '700' : '400';
    });
}
function setNTarget(type) {
    document.getElementById('notifTargetType').value = type;
    document.getElementById('ntabSingle').classList.toggle('active', type==='center');
    document.getElementById('ntabAll').classList.toggle('active',   type==='all');
    const cpg = document.getElementById('centerPickGroup');
    const bw  = document.getElementById('broadcastWarning');
    const btn = document.getElementById('sendNBtn');
    if(cpg) cpg.style.display = type==='center' ? '' : 'none';
    if(bw)  bw.style.display  = type==='all'    ? '' : 'none';
    if(btn) btn.textContent = type==='all' ? '📢 Broadcast to All Centers' : 'Send Notification';
}
<?php if(!$editPlan && $view==='plans'):?>
document.querySelector('input[name="name"]')?.addEventListener('input', function() {
    const s = document.getElementById('planSlug');
    if (!s.dataset.manual) s.value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
});
document.getElementById('planSlug')?.addEventListener('input', function() { this.dataset.manual=true; });
<?php endif;?>

function onPlanTypeChange(type) {
    // Highlight selected card
    document.getElementById('ptCardIndividual').style.borderColor = type==='individual' ? 'var(--accent)' : 'var(--border)';
    document.getElementById('ptCardAcademy').style.borderColor    = type==='academy'    ? 'var(--accent)' : 'var(--border)';

    const maxTchGrp  = document.getElementById('pmMaxTchGroup');
    const maxTchNote = document.getElementById('pmMaxTchNote');
    const maxStu     = document.getElementById('pmMaxStu');
    const maxTch     = document.getElementById('pmMaxTch');
    const maxGrp     = document.getElementById('pmMaxGrp');

    if (type === 'individual') {
        if (maxTchGrp) { maxTchGrp.style.opacity='0.4'; maxTchGrp.style.pointerEvents='none'; }
        if (maxTch) { maxTch.value = 1; maxTch.readOnly = true; }
        if (maxStu && !maxStu.dataset.edited) maxStu.value = 100;
        if (maxGrp && !maxGrp.dataset.edited) maxGrp.value = 20;
        if (maxTchNote) maxTchNote.textContent = '(fixed at 1)';
    } else {
        if (maxTchGrp) { maxTchGrp.style.opacity='1'; maxTchGrp.style.pointerEvents='auto'; }
        if (maxTch) { maxTch.readOnly = false; if (maxTch.value == 1) maxTch.value = 999999; }
        if (maxStu && !maxStu.dataset.edited) maxStu.value = 999999;
        if (maxGrp && !maxGrp.dataset.edited) maxGrp.value = 999999;
        if (maxTchNote) maxTchNote.textContent = '(academy only)';
    }
}

// Init on load
(function() {
    const checked = document.querySelector('input[name="plan_type"]:checked');
    if (checked) onPlanTypeChange(checked.value);
    ['pmMaxStu','pmMaxGrp'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => el.dataset.edited = true);
    });
})();
</script>


<!-- ── Delete Center Modal ──────────────────────────────── -->
<div class="modal-backdrop" id="deleteCenterModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header" style="border-top:3px solid var(--danger);">
      <span class="modal-title" style="color:var(--danger);">🗑 Delete Center</span>
      <button class="modal-close" onclick="closeModal('deleteCenterModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="delete_center">
        <input type="hidden" name="center_id" id="dcCenterId">
        <div style="background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-md);padding:16px 18px;margin-bottom:16px;">
          <div style="font-weight:700;color:var(--danger);margin-bottom:8px;font-size:0.9rem;">⚠️ This action is permanent and cannot be undone.</div>
          <div style="font-size:0.82rem;color:var(--text-secondary);line-height:1.7;">
            Deleting <strong id="dcCenterName" style="color:var(--text-primary);"></strong> will permanently remove:
          </div>
          <ul style="font-size:0.8rem;color:var(--text-secondary);margin:8px 0 0 16px;line-height:2;">
            <li>All students and their data</li>
            <li>All groups, attendance sessions and records</li>
            <li>All exams, submissions and results</li>
            <li>All payments and receipts</li>
            <li>All staff accounts (teachers, assistants, accountants)</li>
            <li>All videos, playlists and comments</li>
            <li>Subscription history</li>
          </ul>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label required">Type the center name to confirm</label>
          <input type="text" id="dcConfirmName" class="form-control"
                 placeholder="Type exact center name here" autocomplete="off">
          <span class="form-hint">Must match exactly: <strong id="dcNameHint" style="color:var(--text-primary);"></strong></span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('deleteCenterModal')">Cancel</button>
        <button type="submit" class="btn btn-danger" id="dcSubmitBtn" disabled>Delete Permanently</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Delete Plan Modal ─────────────────────────────────── -->
<div class="modal-backdrop" id="deletePlanModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header" style="border-top:3px solid var(--danger);">
      <span class="modal-title" style="color:var(--danger);">🗑 Delete Plan</span>
      <button class="modal-close" onclick="closeModal('deletePlanModal')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="delete_plan">
        <input type="hidden" name="plan_id" id="dpPlanId">
        <p style="font-size:0.86rem;color:var(--text-secondary);margin-bottom:14px;line-height:1.7;">
          You are about to delete plan <strong id="dpPlanName" style="color:var(--text-primary);"></strong>.
        </p>
        <div id="dpActiveWarn" style="display:none;background:var(--danger-dim);border:1px solid rgba(239,68,68,0.25);border-radius:var(--radius-sm);padding:12px 14px;font-size:0.82rem;color:var(--danger);margin-bottom:14px;">
          This plan has active/trial subscriptions. You must wait for them to expire or reassign those centers before deleting.
        </div>
        <div id="dpSafeMsg" style="display:none;background:var(--success-dim);border:1px solid rgba(16,185,129,0.2);border-radius:var(--radius-sm);padding:12px 14px;font-size:0.82rem;color:var(--success);">
          ✓ No active subscriptions use this plan. Safe to delete.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('deletePlanModal')">Cancel</button>
        <button type="submit" class="btn btn-danger" id="dpSubmitBtn">Delete Plan</button>
      </div>
    </form>
  </div>
</div>

<script>
function openDeleteCenterModal(id, name) {
  document.getElementById('dcCenterId').value   = id;
  document.getElementById('dcCenterName').textContent = name;
  document.getElementById('dcNameHint').textContent   = name;
  document.getElementById('dcConfirmName').value      = '';
  document.getElementById('dcSubmitBtn').disabled     = true;
  openModal('deleteCenterModal');
  // Enable submit only when typed name matches exactly
  document.getElementById('dcConfirmName').oninput = function() {
    document.getElementById('dcSubmitBtn').disabled = (this.value.trim() !== name.trim());
  };
}

function openDeletePlanModal(id, subCount, name) {
  document.getElementById('dpPlanId').value = id;
  document.getElementById('dpPlanName').textContent = name;
  const hasActive = subCount > 0;
  document.getElementById('dpActiveWarn').style.display = hasActive ? '' : 'none';
  document.getElementById('dpSafeMsg').style.display    = hasActive ? 'none' : '';
  document.getElementById('dpSubmitBtn').disabled       = hasActive;
  openModal('deletePlanModal');
}
</script>
<?php include __DIR__ . '/../../templates/layout_end.php'; ?>
