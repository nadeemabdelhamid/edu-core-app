<?php
require_once __DIR__ . '/config/database.php';

// ─── SET YOUR NEW CREDENTIALS HERE ───────────────────────
$newEmail = 'admin@educore.io';
$newPassword = 'Admin@1234';
$newName = 'Super Admin';
// ─────────────────────────────────────────────────────────

$pdo = getDB();

// Check if super admin exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1");
$stmt->execute();
$admin = $stmt->fetch();

$hashed = password_hash($newPassword, PASSWORD_BCRYPT);

if ($admin) {
    // Update existing
    $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, updated_at = NOW() WHERE role = 'super_admin'")
        ->execute([$newName, $newEmail, $hashed]);
    echo "✅ Super admin credentials updated.<br>";
}
else {
    // Create new
    $pdo->prepare("INSERT INTO users (center_id, name, email, password, role) VALUES (NULL, ?, ?, ?, 'super_admin')")
        ->execute([$newName, $newEmail, $hashed]);
    echo "✅ Super admin created.<br>";
}

echo "📧 Email: <strong>$newEmail</strong><br>";
echo "🔑 Password: <strong>$newPassword</strong><br>";
echo "<br><strong style='color:red;'>⚠️ Delete this file immediately after use.</strong>";
?>
```

---

## How to Use

1. Change the credentials at the top of the file to whatever you want
2. Place it in your `educore/` folder
3. Visit `http://localhost/educore/reset_admin.php`
4. You'll see a success message
5. **Delete the file immediately** — never leave it on the server

---

## Then Login With
```
http://localhost/educore/login.php

Email:    admin@educore.io
Password: Admin@1234