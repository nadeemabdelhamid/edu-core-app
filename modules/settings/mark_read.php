<?php
require_once __DIR__ . '/../../includes/helpers.php';
requireAuth();
csrf_verify();
markNotificationsRead(authId());
echo json_encode(['ok'=>true]);
