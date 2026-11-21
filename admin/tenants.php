<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Staff see every tenant; the definition is shared with the landlord portal.
crud_run(tenant_resource());
