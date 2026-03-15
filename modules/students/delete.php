<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireRole('owner', 'teacher');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/educore/modules/students/');
csrf_verify();

$id  = (int)($_POST['id'] ?? 0);
$cid = centerId();

if ($id) {
    $pdo = getDB();
    // Academy plan: teachers cannot delete students (owner-only action)
    if (isRole('teacher') && isAcademyPlan()) {
        flash('error', 'Only the academy owner can delete students.');
        redirect('/educore/modules/students/');
    }
    $pdo->prepare("DELETE FROM students WHERE id=? AND center_id=?")->execute([$id, $cid]);
    // Also clean up teacher assignments
    $pdo->prepare("DELETE FROM student_teachers WHERE student_id=? AND center_id=?")->execute([$id, $cid]);
    auditLog('student.deleted', 'student', $id);
    flash('success', 'Student deleted.');
}

redirect('/educore/modules/students/');
