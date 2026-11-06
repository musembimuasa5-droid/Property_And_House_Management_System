<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/modules.php';

require_module('reports');
$reportLandlordId = null;
$reportPageUrl = module_url('reports');
include __DIR__ . '/../includes/reports_page.php';
