<?php
require_once __DIR__ . '/../includes/resources.php';

// Landlords manage units in their own properties only; the scope enforces it on every query.
crud_run(unit_resource((int)requireLandlordProfile()['id']));
