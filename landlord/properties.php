<?php
require_once __DIR__ . '/../includes/resources.php';

// Landlords add and edit only their own properties; the scope enforces it on every query.
crud_run(property_resource((int)requireLandlordProfile()['id']));
