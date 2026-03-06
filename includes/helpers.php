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
    session_regenerate_id(true);
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
