<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';

// Landlords read the inspection evidence for their own properties; management records it.
$conditionLandlordId = (int)requireLandlordProfile()['id'];
$conditionCanEdit = false;
$conditionPageUrl = BASE_URL . '/landlord/condition-reports.php';
include __DIR__ . '/../includes/condition_reports_page.php';
