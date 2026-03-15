<?php
/**
 * EduCore — Attendance Auto-Scheduler Cron
 *
 * Add this to your server's crontab to run every minute:
 *   * * * * * php /path/to/educore/modules/attendance/cron.php
 *
 * Or via HTTP (replace the key with your own secret):
 *   * * * * * curl -s "https://yourdomain.com/educore/modules/attendance/cron.php?key=YOUR_KEY" >/dev/null 2>&1
 */

// ── Auth ─────────────────────────────────────────────────────
$isCli = (php_sapi_name() === 'cli');
$key   = getenv('EDUCORE_CRON_KEY') ?: 'educore_cron_secret';

if (!$isCli && ($_GET['key'] ?? '') !== $key) {
    http_response_code(403);
    exit('Unauthorized');
}

// ── Bootstrap ────────────────────────────────────────────────
define('EDUCORE_CRON', true);
require_once __DIR__ . '/../../includes/helpers.php';

// Force run by removing the lock file
$lock = sys_get_temp_dir() . '/educore_scheduler.lock';
if (file_exists($lock)) unlink($lock);

// ── Run ──────────────────────────────────────────────────────
runScheduler();

if ($isCli) {
    echo '[' . date('Y-m-d H:i:s') . "] Scheduler OK\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]);
}
