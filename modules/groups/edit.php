<?php
// edit.php — loads create.php in edit mode
require_once __DIR__ . '/../../includes/helpers.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('/educore/modules/groups/');
include __DIR__ . '/create.php';
