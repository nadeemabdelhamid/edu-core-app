<?php
/**
 * access_guard.php
 * Enforces read-only mode for expired accounts.
 *
 * Usage: included automatically by helpers.php after auth check.
 *
 * Rules:
 *  - super_admin: always full access
 *  - owner:       sees expired banner, write actions blocked, gets upgrade prompt
 *  - teacher/assistant/accountant: sees data only — all write actions blocked silently
 *  - student:     normal (student portal has its own limits)
 *
 * "Write action" = any POST/DELETE that mutates data.
 * We block at the PHP level AND visually disable buttons/forms in the layout.
 */

function isAccountExpired(): bool {
    if (isRole('super_admin', 'student')) return false;
    $cid = centerId();
    if (!$cid) return false;

    // Check subscription status
    $pdo  = getDB();
    $stmt = $pdo->prepare("
        SELECT status, expires_at, trial_ends_at
        FROM subscriptions
        WHERE center_id = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$cid]);
    $sub = $stmt->fetch();

    if (!$sub) return true; // no subscription = expired

    if ($sub['status'] === 'expired' || $sub['status'] === 'cancelled') return true;

    if ($sub['status'] === 'trial' && $sub['trial_ends_at']) {
        return strtotime($sub['trial_ends_at']) < time();
    }

    if ($sub['expires_at']) {
        return strtotime($sub['expires_at']) < time();
    }

    return false;
}

function blockIfExpired(): void {
    if (!isAccountExpired()) return;

    // Teachers/staff see a read-only notice instead of error
    if (isRole('teacher', 'assistant', 'accountant')) {
        // Block all mutating requests silently — redirect to referring page
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            http_response_code(403);
            // Try to redirect back gracefully
            $referer = $_SERVER['HTTP_REFERER'] ?? '/educore/dashboard.php';
            flash('warning', 'Your center subscription has expired. You have read-only access. Contact your center owner.');
            header("Location: $referer");
            exit;
        }
        return; // GET requests are fine — they can still view data
    }

    // Owners: block POST mutations too, but let them reach subscription page
    if (isRole('owner')) {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        $allowedUrls = [
            '/educore/modules/settings/subscription.php',
            '/educore/modules/settings/security.php',
            '/educore/logout.php',
        ];
        foreach ($allowedUrls as $url) {
            if (str_contains($currentUrl, $url)) return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $referer = $_SERVER['HTTP_REFERER'] ?? '/educore/dashboard.php';
            flash('error', 'Your subscription has expired. Please renew to make changes.');
            header("Location: $referer");
            exit;
        }
        return; // Owners can view everything
    }
}

// ── Read-only UI helpers ────────────────────────────────────
// Called from layout.php to inject the expired banner + CSS disable
function expiredBannerHtml(): string {
    if (!isAccountExpired()) return '';

    if (isRole('teacher', 'assistant', 'accountant')) {
        return '
        <div style="
            background: rgba(245,158,11,0.1);
            border-bottom: 1px solid rgba(245,158,11,0.3);
            padding: 10px 28px;
            display: flex; align-items: center; gap: 12px;
            font-size: 0.82rem;
        ">
            <span style="font-size:1rem;">⚠️</span>
            <span style="color:var(--text-primary);">
                <strong>Read-only mode</strong> — Your center\'s subscription has expired.
                You can view data but cannot make changes. Contact your center owner.
            </span>
        </div>
        <!-- Disable all write actions visually -->
        <style>
            .readonly-blocked { opacity: 0.4 !important; pointer-events: none !important; cursor: not-allowed !important; }
            form[method="post"] button[type="submit"],
            form[method="POST"] button[type="submit"] { opacity: 0.4; pointer-events: none; cursor: not-allowed; }
            .btn-primary:not(.readonly-allow),
            .btn-danger:not(.readonly-allow),
            .btn-success:not(.readonly-allow) { opacity: 0.35; pointer-events: none; cursor: not-allowed; }
            a[href*="create"], a[href*="edit"], a[href*="delete"] { opacity: 0.35; pointer-events: none; }
        </style>';
    }

    if (isRole('owner')) {
        return '
        <div style="
            background: rgba(239,68,68,0.10);
            border-bottom: 1px solid rgba(239,68,68,0.3);
            padding: 10px 28px;
            display: flex; align-items: center; gap: 12px;
            font-size: 0.82rem;
        ">
            <span style="font-size:1rem;">🚨</span>
            <span style="color:var(--text-primary);">
                <strong>Subscription expired.</strong>
                All write actions are locked. Renew your plan to restore full access.
            </span>
            <a href="/educore/modules/settings/subscription.php"
               style="margin-left:auto; background:var(--danger); color:#fff;
                      padding:5px 16px; border-radius:20px; font-size:0.75rem;
                      font-weight:700; text-decoration:none;">
                Renew Now →
            </a>
        </div>
        <style>
            form[method="post"] button[type="submit"],
            form[method="POST"] button[type="submit"] { opacity: 0.4; pointer-events: none; cursor: not-allowed; }
            .btn-primary:not(.readonly-allow),
            .btn-danger:not(.readonly-allow) { opacity: 0.35; pointer-events: none; cursor: not-allowed; }
        </style>';
    }

    return '';
}
