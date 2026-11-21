<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Landlords keep the deposit ledger for their own leases only.
crud_run(deposit_resource((int)requireLandlordProfile()['id']));
