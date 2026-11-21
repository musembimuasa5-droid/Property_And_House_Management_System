<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Landlords record payments only against leases in their own properties.
crud_run(payment_resource((int)requireLandlordProfile()['id']));
