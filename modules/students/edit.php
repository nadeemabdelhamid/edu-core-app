<?php
// edit.php — redirects to create.php with ?id= for edit mode
require_once __DIR__ . '/../../includes/helpers.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('/educore/modules/students/');
include __DIR__ . '/create.php';
