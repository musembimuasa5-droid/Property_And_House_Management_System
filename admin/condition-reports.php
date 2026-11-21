<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';

require_module('condition_reports');
$conditionOwner = null;
$conditionCanEdit = true;
$conditionCanDelete = true;
$conditionDepositsModule = 'deposits';
$conditionPageUrl = module_url('condition_reports');
include __DIR__ . '/../includes/condition_reports_page.php';
