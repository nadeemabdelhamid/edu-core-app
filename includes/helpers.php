<?php
require_once __DIR__ . '/../config/database.php';

// Start session securely
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'secure'   => false, // set true in production with HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ─── CSRF ───────────────────────────────────────────────────
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify() {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'CSRF validation failed']));
    }
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

// ─── AUTH HELPERS ────────────────────────────────────────────
function auth() {
    return $_SESSION['user'] ?? null;
}

function authId() {
    return $_SESSION['user']['id'] ?? null;
}

function centerId() {
    return $_SESSION['user']['center_id'] ?? null;
}

function isRole(...$roles) {
    $user = auth();
    if (!$user) return false;
    return in_array($user['role'], $roles);
}

function requireAuth() {
    if (!auth()) {
        header('Location: /educore/login.php');
        exit;
    }
}

function requireRole(...$roles) {
    requireAuth();
    if (!isRole(...$roles)) {
        http_response_code(403);
        include __DIR__ . '/../templates/403.php';
        exit;
    }
}

function login($user) {
    // Use false so the old session file is NOT deleted — allows multiple
    // users to be logged in concurrently on different browsers/devices.
    session_regenerate_id(false);
    $_SESSION['user'] = $user;
    // Update last login
    $pdo = getDB();
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
}

function logout() {
    session_unset();
    session_destroy();
    header('Location: /educore/login.php');
    exit;
}

// ─── TENANT SCOPING ──────────────────────────────────────────
function tenantScope($query, $alias = '') {
    $col = $alias ? "$alias.center_id" : "center_id";
    return $query . " AND $col = " . (int)centerId();
}

// ─── FLASH MESSAGES ──────────────────────────────────────────
function flash($type, $message) {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    $msgs = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $msgs;
}

// ─── SANITIZE ────────────────────────────────────────────────
function sanitize($val) {
    return htmlspecialchars(trim((string)$val), ENT_QUOTES, 'UTF-8');
}

function sanitizeAll(array $data) {
    return array_map('sanitize', $data);
}

// ─── SUBSCRIPTION CHECK ───────────────────────────────────────
function hasFeature($feature) {
    if (isRole('super_admin')) return true;
    $cid = centerId();
    if (!$cid) return false;
    $pdo = getDB();
    $sub = $pdo->prepare("
        SELECT p.features FROM subscriptions s
        JOIN plans p ON p.id = s.plan_id
        WHERE s.center_id = ? AND s.status IN ('active','trial')
        AND (s.expires_at IS NULL OR s.expires_at > NOW())
        ORDER BY s.id DESC LIMIT 1
    ");
    $sub->execute([$cid]);
    $row = $sub->fetch();
    if (!$row) return false;
    $features = json_decode($row['features'], true);
    return !empty($features[$feature]);
}

// ─── AUDIT LOG ───────────────────────────────────────────────
function auditLog($action, $entityType = null, $entityId = null, $details = []) {
    try {
        $pdo = getDB();
        $pdo->prepare("INSERT INTO audit_logs (center_id, user_id, action, entity_type, entity_id, details, ip_address)
                        VALUES (?,?,?,?,?,?,?)")
            ->execute([centerId(), authId(), $action, $entityType, $entityId,
                       json_encode($details), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) { /* silent */ }
}

// ─── PAGINATION ───────────────────────────────────────────────
function paginate($query, $params, $page = 1, $perPage = 20) {
    $pdo = getDB();
    $countQuery = "SELECT COUNT(*) FROM ($query) AS sub";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt2 = $pdo->prepare("$query LIMIT $perPage OFFSET $offset");
    $stmt2->execute($params);
    $data = $stmt2->fetchAll();

    return [
        'data'        => $data,
        'total'       => $total,
        'per_page'    => $perPage,
        'current_page'=> $page,
        'last_page'   => (int)ceil($total / $perPage),
    ];
}

// ─── RESPONSE HELPERS ────────────────────────────────────────
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect($url) {
    header("Location: $url");
    exit;
}

// ─── PLAN LIMITS ─────────────────────────────────────────────
function getPlanLimits() {
    if (isRole('super_admin')) return ['max_students'=>PHP_INT_MAX,'max_teachers'=>PHP_INT_MAX,'max_groups'=>PHP_INT_MAX,'features'=>[]];
    $cid = centerId();
    if (!$cid) return null;
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT p.max_students, p.max_teachers, p.max_groups, p.features,
               s.status, s.expires_at, s.trial_ends_at, p.name AS plan_name, p.slug AS plan_slug
        FROM subscriptions s JOIN plans p ON p.id=s.plan_id
        WHERE s.center_id=? AND s.status IN('active','trial')
        AND (s.expires_at IS NULL OR s.expires_at > NOW())
        ORDER BY s.id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $row = $stmt->fetch();
    if (!$row) return ['max_students'=>0,'max_teachers'=>0,'max_groups'=>0,'features'=>[],'plan_name'=>'None','plan_slug'=>'none','status'=>'expired'];
    $row['features'] = json_decode($row['features'] ?? '{}', true) ?? [];
    return $row;
}

function checkLimit($resource) {
    $limits = getPlanLimits();
    if (!$limits) return ['ok'=>false,'used'=>0,'max'=>0,'plan'=>'None','slug'=>'none'];
    $cid = centerId();
    $pdo = getDB();
    $queryMap = [
        'students' => ["SELECT COUNT(*) FROM students WHERE center_id=? AND is_active=1",     (int)$limits['max_students']],
        'groups'   => ["SELECT COUNT(*) FROM `groups` WHERE center_id=? AND is_active=1",      (int)$limits['max_groups']],
        'teachers' => ["SELECT COUNT(*) FROM users WHERE center_id=? AND role IN('teacher','assistant') AND is_active=1", (int)$limits['max_teachers']],
    ];
    if (!isset($queryMap[$resource])) {
        return ['ok'=>true,'used'=>0,'max'=>PHP_INT_MAX,'plan'=>$limits['plan_name']??'','slug'=>$limits['plan_slug']??''];
    }
    [$sql, $max] = $queryMap[$resource];
    $s = $pdo->prepare($sql);
    $s->execute([$cid]);
    $used = (int)$s->fetchColumn();
    return [
        'ok'   => ($max >= 999999 || $used < $max),
        'used' => $used,
        'max'  => $max,
        'plan' => $limits['plan_name'] ?? '',
        'slug' => $limits['plan_slug'] ?? '',
    ];
}

function getSubscription() {
    $cid = centerId();
    if (!$cid) return null;
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.price, p.billing_cycle,
               p.max_students, p.max_teachers, p.max_groups, p.features
        FROM subscriptions s JOIN plans p ON p.id=s.plan_id
        WHERE s.center_id=? ORDER BY s.id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $row = $stmt->fetch();
    if ($row) $row['features'] = json_decode($row['features'] ?? '{}', true) ?? [];
    return $row;
}

function trialDaysLeft() {
    $sub = getSubscription();
    if (!$sub || $sub['status'] !== 'trial' || !$sub['trial_ends_at']) return null;
    $diff = (strtotime($sub['trial_ends_at']) - time()) / 86400;
    return max(0, (int)ceil($diff));
}

// ─── RATE LIMITING ───────────────────────────────────────────
function rateLimit($identifier, $action, $maxAttempts = 5, $windowSeconds = 300) {
    try {
        $pdo  = getDB();
        $now  = date('Y-m-d H:i:s');
        $win  = date('Y-m-d H:i:s', time() - $windowSeconds);
        // Clean old entries
        $pdo->prepare("DELETE FROM rate_limits WHERE last_attempt < ? AND action=?")->execute([$win, $action]);
        // Check current
        $stmt = $pdo->prepare("SELECT * FROM rate_limits WHERE identifier=? AND action=? AND window_start >= ?");
        $stmt->execute([$identifier, $action, $win]);
        $row  = $stmt->fetch();
        if ($row) {
            if ($row['attempts'] >= $maxAttempts) return false;
            $pdo->prepare("UPDATE rate_limits SET attempts=attempts+1, last_attempt=? WHERE id=?")->execute([$now, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO rate_limits (identifier, action, attempts, window_start, last_attempt) VALUES (?,?,1,?,?)")->execute([$identifier, $action, $now, $now]);
        }
        return true;
    } catch (Exception $e) { return true; } // fail open if table missing
}

// ─── NOTIFICATIONS ───────────────────────────────────────────
function pushNotification($userId, $type, $title, $body = '', $link = '', $centerId = null) {
    try {
        $pdo = getDB();
        $pdo->prepare("INSERT INTO notifications (center_id, user_id, type, title, body, link) VALUES (?,?,?,?,?,?)")
            ->execute([$centerId ?? centerId(), $userId, $type, $title, $body, $link]);
    } catch (Exception $e) { /* silent */ }
}

function getUnreadNotifications($userId, $limit = 10) {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) { return []; }
}

function markNotificationsRead($userId) {
    try {
        getDB()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$userId]);
    } catch (Exception $e) {}
}

function unreadCount($userId) {
    try {
        $s = getDB()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    } catch (Exception $e) { return 0; }
}

// ─── VIDEO HELPERS ────────────────────────────────────────────

/**
 * Build an embeddable iframe src URL for a given provider + video ID.
 *
 * @param  string $provider  'youtube' or 'vimeo'
 * @param  string $videoId   Provider video ID (e.g. dQw4w9WgXcQ)
 * @return string            Embed URL safe to use as iframe src
 */
function videoEmbedUrl(string $provider, string $videoId): string {
    return match($provider) {
        'youtube' => "https://www.youtube.com/embed/{$videoId}?rel=0&modestbranding=1",
        'vimeo'   => "https://player.vimeo.com/video/{$videoId}?dnt=1",
        default   => '#',
    };
}

/**
 * Parse a YouTube or Vimeo URL and return [$provider, $videoId].
 * Returns null if the URL is not recognised.
 *
 * Supports:
 *   YouTube – https://www.youtube.com/watch?v=ID
 *             https://youtu.be/ID
 *             https://youtube.com/shorts/ID
 *   Vimeo   – https://vimeo.com/ID
 *             https://vimeo.com/channels/channel/ID
 *
 * @param  string     $url  Raw URL pasted by teacher
 * @return array|null       ['youtube'|'vimeo', videoId] or null on failure
 */
function videoIdFromUrl(string $url): ?array {
    // YouTube: standard watch, youtu.be short link, or Shorts
    if (preg_match('%youtu(?:be\.com/(?:watch\?v=|shorts/)|\.be/)([\w-]{11})%', $url, $m)) {
        return ['youtube', $m[1]];
    }

    // Vimeo: vimeo.com/ID  or  vimeo.com/channels/*/ID  or  vimeo.com/groups/*/videos/ID
    if (preg_match('%vimeo\.com/(?:(?:channels|groups)/[^/]+/)?(?:videos?/)?(\d+)%', $url, $m)) {
        return ['vimeo', $m[1]];
    }

    return null; // unrecognised URL
}

/**
 * Build the YouTube thumbnail URL for a given video ID.
 * Returns an empty string for Vimeo (thumbnail must be fetched via API or left blank).
 *
 * @param  string $provider
 * @param  string $videoId
 * @return string
 */
function videoThumbnailUrl(string $provider, string $videoId): string {
    if ($provider === 'youtube') {
        return "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
    }
    return ''; // Vimeo thumbnails require an API call — handled in create.php
}

// ─── FEATURE MODULES (loaded after all helpers defined) ────
require_once __DIR__ . '/access_guard.php';
require_once __DIR__ . '/scheduler.php';

// ─── MCQ OPTION LABELS ────────────────────────────────────
/**
 * Returns the display label for an MCQ option key (a/b/c/d).
 * If the question text is Arabic, returns Arabic abjad letters (ا ب ج د).
 * Otherwise returns uppercase Latin (A B C D).
 *
 * @param string $key       'a'|'b'|'c'|'d'
 * @param string $questionText  The question text — used to detect Arabic
 * @return string
 */
function optionLabel(string $key, string $questionText = ''): string {
    $arabicMap = ['a' => 'ا', 'b' => 'ب', 'c' => 'ج', 'd' => 'د'];
    $latinMap  = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];

    // Detect Arabic: check if question contains Arabic Unicode characters
    $isArabic = (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $questionText);

    $map = $isArabic ? $arabicMap : $latinMap;
    return $map[strtolower($key)] ?? strtoupper($key);
}

/**
 * Returns ordered option arrays for a question, using Arabic or Latin labels.
 * Usage: [$labels, $opts] = optionSet($question);
 *
 * @param array $q  Question row from DB
 * @return array    [labels_map, options_map] e.g. ['a'=>'ا','b'=>'ب',...], ['a'=>'text',...]
 */
function optionSet(array $q): array {
    $opts   = ['a' => $q['option_a'] ?? '', 'b' => $q['option_b'] ?? '',
               'c' => $q['option_c'] ?? '', 'd' => $q['option_d'] ?? ''];
    $labels = [];
    foreach (array_keys($opts) as $k) {
        $labels[$k] = optionLabel($k, $q['question'] ?? '');
    }
    return [$labels, $opts];
}


