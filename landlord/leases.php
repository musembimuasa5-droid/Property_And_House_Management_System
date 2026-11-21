<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Landlords write leases only for their own units and tenants; the scope enforces it on every query.
crud_run(lease_resource((int)requireLandlordProfile()['id']));
