<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/educore/modules/groups/');
csrf_verify();

$id  = (int)($_POST['id'] ?? 0);
$cid = centerId();

if ($id) {
    $pdo = getDB();
    // Remove enrollments first
    $pdo->prepare("DELETE FROM group_students WHERE group_id = ?")->execute([$id]);
    // Remove group
    $pdo->prepare("DELETE FROM `groups` WHERE id = ? AND center_id = ?")->execute([$id, $cid]);
    auditLog('group.deleted', 'group', $id);
    flash('success', 'Group deleted.');
}

redirect('/educore/modules/groups/');
