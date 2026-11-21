<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';

// Caretakers inspect units in the properties assigned to them. They cannot delete a report
// or see deposit amounts; management reviews their reports and decides any deduction.
$conditionOwner = ['caretaker_id', caretaker_user_id()];
$conditionCanEdit = true;
$conditionCanDelete = false;
$conditionDepositsModule = null;
$conditionPageUrl = module_url('care_condition_reports');
include __DIR__ . '/../includes/condition_reports_page.php';
