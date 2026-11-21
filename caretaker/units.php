<?php
require_once __DIR__ . '/../includes/resources.php';

// Caretakers see, but cannot change, the units of the properties assigned to them.
crud_run(caretaker_unit_resource(caretaker_user_id()));
