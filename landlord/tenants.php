<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Landlords see and register only their own tenants; the scope enforces it on every query.
crud_run(tenant_resource((int)requireLandlordProfile()['id']));
