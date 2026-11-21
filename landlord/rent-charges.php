<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Landlords bill only leases in their own properties; the scope enforces it on every query.
crud_run(rent_charge_resource((int)requireLandlordProfile()['id']));
