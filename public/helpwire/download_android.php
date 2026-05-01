<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/soporte/downloads/sistemax-assist-android.apk', true, 302);
exit;
