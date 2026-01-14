<?php
require_once __DIR__ . '/security.php';
ensure_session_started();
session_destroy();
header('Location: /auth.php');
exit;
