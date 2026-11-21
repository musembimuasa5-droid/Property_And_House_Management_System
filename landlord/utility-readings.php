<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Landlords record meter readings for their own units only.
crud_run(utility_reading_resource(['landlord_id', (int)requireLandlordProfile()['id']]));
