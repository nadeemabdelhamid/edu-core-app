<?php
require_once __DIR__ . '/config/database.php';

// ─── CHOOSE WHAT TO TEST ──────────────────────────────────
$mode = $_GET['mode'] ?? 'check';
// Modes:
// check   → just show current subscription status
// expire  → force trial to expire right now (for testing)
// restore → restore trial to 14 days from now
// ─────────────────────────────────────────────────────────

$pdo = getDB();

function showStatus($pdo)
{
    $subs = $pdo->query("
        SELECT 
            s.id,
            s.status,
            s.starts_at,
            s.expires_at,
            s.trial_ends_at,
            p.name as plan_name,
            c.name as center_name,
            TIMESTAMPDIFF(SECOND, NOW(), s.expires_at) as seconds_left
        FROM subscriptions s
        JOIN plans p ON p.id = s.plan_id
        JOIN centers c ON c.id = s.center_id
        ORDER BY s.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>All Subscriptions</h2>";
    echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse:collapse; font-family:monospace;'>";
    echo "<tr style='background:#f0f0f0;'>
            <th>ID</th>
            <th>Center</th>
            <th>Plan</th>
            <th>Status</th>
            <th>Starts At</th>
            <th>Expires At</th>
            <th>Trial Ends At</th>
            <th>Time Left</th>
            <th>Is Active?</th>
          </tr>";

    foreach ($subs as $s) {
        $secondsLeft = (int)$s['seconds_left'];
        $timeLeft = $secondsLeft > 0
            ? gmdate('H:i:s', $secondsLeft) . ' remaining'
            : '<span style="color:red">EXPIRED</span>';

        $isActive = ($s['status'] === 'active' || $s['status'] === 'trial')
            && $secondsLeft > 0;

        $activeLabel = $isActive
            ? '<span style="color:green">✅ Active</span>'
            : '<span style="color:red">❌ Expired / Inactive</span>';

        $rowColor = $isActive ? '#f0fff0' : '#fff0f0';

        echo "<tr style='background:$rowColor;'>
                <td>{$s['id']}</td>
                <td>{$s['center_name']}</td>
                <td>{$s['plan_name']}</td>
                <td>{$s['status']}</td>
                <td>{$s['starts_at']}</td>
                <td>{$s['expires_at']}</td>
                <td>{$s['trial_ends_at']}</td>
                <td>$timeLeft</td>
                <td>$activeLabel</td>
              </tr>";
    }
    echo "</table>";
}

echo "<!DOCTYPE html>
<html>
<head>
  <title>Trial Test — EduCore</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 30px; background: #f9f9f9; }
    h1   { color: #333; }
    h2   { color: #555; margin-top: 30px; }
    .btn { display: inline-block; padding: 10px 20px; margin: 6px 4px;
           border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px; }
    .btn-check   { background: #3b82f6; color: white; }
    .btn-expire  { background: #ef4444; color: white; }
    .btn-restore { background: #22c55e; color: white; }
    .btn-short   { background: #f59e0b; color: white; }
    .result { background: white; border: 1px solid #ddd; border-radius: 8px;
              padding: 20px; margin-top: 20px; }
    .msg-success { color: green; font-weight: bold; font-size: 16px; }
    .msg-info    { color: #3b82f6; font-weight: bold; font-size: 16px; }
  </style>
</head>
<body>";

echo "<h1>🧪 Trial System Tester</h1>";

// ─── ACTION BUTTONS ───────────────────────────────────────
echo "
<a href='?mode=check'   class='btn btn-check'>🔍 Check Status</a>
<a href='?mode=expire'  class='btn btn-expire'>💀 Force Expire All Trials</a>
<a href='?mode=restore' class='btn btn-restore'>♻️ Restore All Trials (14 days)</a>
<a href='?mode=short'   class='btn btn-short'>⏱️ Set Trial to 10 Seconds</a>
";

echo "<div class='result'>";

// ─── MODES ───────────────────────────────────────────────

if ($mode === 'check') {
    echo "<p class='msg-info'>📊 Current subscription status:</p>";
    showStatus($pdo);
}

elseif ($mode === 'expire') {
    $pdo->exec("UPDATE subscriptions SET 
                    expires_at    = DATE_SUB(NOW(), INTERVAL 1 DAY),
                    trial_ends_at = DATE_SUB(NOW(), INTERVAL 1 DAY),
                    status        = 'expired'
                WHERE status IN ('trial', 'active')");
    echo "<p class='msg-success'>✅ All trials force-expired.</p>";
    echo "<p>Now try to log in and access a feature — it should be blocked.</p>";
    showStatus($pdo);
}

elseif ($mode === 'restore') {
    $pdo->exec("UPDATE subscriptions SET 
                    expires_at    = DATE_ADD(NOW(), INTERVAL 14 DAY),
                    trial_ends_at = DATE_ADD(NOW(), INTERVAL 14 DAY),
                    status        = 'trial'
                WHERE status IN ('expired', 'trial', 'active')");
    echo "<p class='msg-success'>✅ All trials restored to 14 days.</p>";
    showStatus($pdo);
}

elseif ($mode === 'short') {
    $pdo->exec("UPDATE subscriptions SET 
                    expires_at    = DATE_ADD(NOW(), INTERVAL 10 SECOND),
                    trial_ends_at = DATE_ADD(NOW(), INTERVAL 10 SECOND),
                    status        = 'trial'
                WHERE status IN ('expired', 'trial', 'active')");
    echo "<p class='msg-success'>✅ Trial set to expire in 10 seconds.</p>";
    echo "<p>Wait 10 seconds then click <strong>Check Status</strong> to see it expire.</p>";
    echo "<script>
        let c = 10;
        const p = document.createElement('p');
        p.style = 'font-size:24px; font-weight:bold; color:#f59e0b;';
        document.querySelector('.result').appendChild(p);
        const t = setInterval(() => {
            p.textContent = '⏳ Expiring in ' + c + ' seconds...';
            if (--c < 0) {
                clearInterval(t);
                window.location = '?mode=check';
            }
        }, 1000);
    </script>";
    showStatus($pdo);
}

echo "</div>";

// ─── WHAT TO TEST NEXT ────────────────────────────────────
echo "
<h2>📋 What to Test</h2>
<ol style='line-height:2;'>
  <li>Click <strong>Check Status</strong> — confirm your center has an active trial</li>
  <li>Click <strong>Set Trial to 10 Seconds</strong> — wait for countdown</li>
  <li>After expiry, go to <a href='/educore/dashboard.php' target='_blank'>Dashboard</a> and try to access a locked feature</li>
  <li>Click <strong>Force Expire</strong> to instantly kill all trials</li>
  <li>Click <strong>Restore</strong> to bring everything back to 14 days</li>
</ol>

<p style='color:red; font-weight:bold; margin-top:20px;'>
  ⚠️ Delete this file before going to production.
</p>
</body>
</html>";
?>
```

---

## How to Use

1. Save it as `educore/test_trial.php`
2. Visit `http://localhost/educore/test_trial.php`
3. You'll see 4 buttons:

| Button | What it does |
|---|---|
| 🔍 Check Status | Shows all subscriptions and whether they are active or expired |
| 💀 Force Expire | Instantly kills all trials — test what happens after expiry |
| ♻️ Restore | Brings all trials back to 14 days |
| ⏱️ 10 Seconds | Sets trial to expire in 10 seconds with a live countdown |

---

## Full Test Flow
```
1. Check Status       → confirm trial is active
2. Set to 10 Seconds  → watch it expire live
3. Open Dashboard     → see if expired trial blocks features
4. Force Expire       → test blocked state fully
5. Restore            → bring everything back to normal