<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/educore/modules/students/');
csrf_verify();

$id  = (int)($_POST['id'] ?? 0);
$cid = centerId();

if ($id) {
    $pdo = getDB();
    $pdo->prepare("DELETE FROM students WHERE id=? AND center_id=?")->execute([$id, $cid]);
    auditLog('student.deleted', 'student', $id);
    flash('success', 'Student deleted.');
}

redirect('/educore/modules/students/');
