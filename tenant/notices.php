<?php
// Notices moved to the shared notifications page; keep old links working.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
redirect('account/notifications.php');
