<?php
require_once __DIR__ . '/../includes/tenancy_resources.php';

// Caretakers record meter readings for the properties assigned to them; they cannot delete readings.
crud_run(utility_reading_resource(['caretaker_id', (int)caretaker_user_id()]));
