<?php
require_once __DIR__ . '/../includes/crud.php';

crud_run([
    'module' => 'locations',
    'table' => 'locations',
    'singular' => 'Location',
    'plural' => 'Locations',
    'description' => 'Estates and sub-counties used to place properties and power the public search.',
    'fields' => [
        'county' => ['label' => 'County', 'required' => true, 'max' => 100, 'default' => 'Nairobi'],
        'sub_county' => ['label' => 'Sub-county', 'required' => true, 'max' => 100],
        'estate' => ['label' => 'Estate', 'required' => true, 'max' => 100],
    ],
    'list' => [
        'sql' => 'SELECT l.id, l.county, l.sub_county, l.estate, (SELECT COUNT(*) FROM properties p WHERE p.location_id = l.id) AS property_count FROM locations l',
        'columns' => [
            'estate' => ['label' => 'Estate'],
            'sub_county' => ['label' => 'Sub-county'],
            'county' => ['label' => 'County'],
            'property_count' => ['label' => 'Properties'],
        ],
        'search' => ['l.estate', 'l.sub_county', 'l.county'],
        'order' => 'l.sub_county, l.estate',
    ],
    'messages' => ['in_use' => 'Properties are still assigned to this location, so it cannot be deleted.'],
]);
