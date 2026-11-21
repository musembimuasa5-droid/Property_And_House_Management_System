<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';

// Landlords record and review the inspections of their own properties; the owner filter
// limits every lookup, the lease list and saving to those properties.
$conditionOwner = ['landlord_id', (int)requireLandlordProfile()['id']];
$conditionCanEdit = true;
$conditionCanDelete = true;
$conditionDepositsModule = 'my_deposits';
$conditionPageUrl = module_url('my_condition_reports');
include __DIR__ . '/../includes/condition_reports_page.php';
