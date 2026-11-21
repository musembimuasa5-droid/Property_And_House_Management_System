<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Staff see the whole deposit ledger; the definition is shared with the landlord portal.
crud_run(deposit_resource());
