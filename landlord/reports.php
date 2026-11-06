<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';

$reportLandlordId = (int)requireLandlordProfile()['id'];
$reportPageUrl = BASE_URL . '/landlord/reports.php';
include __DIR__ . '/../includes/reports_page.php';
