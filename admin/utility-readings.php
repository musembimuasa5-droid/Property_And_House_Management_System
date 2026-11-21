<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Staff see every reading; the definition is shared with the landlord and caretaker portals.
crud_run(utility_reading_resource());
