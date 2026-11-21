<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Staff see every charge; the definition is shared with the landlord portal.
crud_run(rent_charge_resource());
