<?php
/**
 * Property and unit module definitions shared by the staff and landlord portals.
 * Passing a landlord id builds the landlord's own version: the landlord column
 * disappears and every query is scoped to that landlord's properties.
 */

require_once __DIR__ . '/crud.php';
require_once __DIR__ . '/../config/constants.php';

const PROPERTY_STATUSES = ['Active', 'Under Maintenance', 'Archived'];
const UNIT_STATUSES = ['Vacant', 'Occupied', 'Reserved', 'Under Maintenance', 'Unavailable'];

/** List thumbnail; decorative because the property name sits beside it. */
function property_thumbnail(array $row): string {
    [$path, $isSample] = property_image($row);
    return '<img src="' . e(BASE_URL . '/' . $path) . '" alt="" class="w-20 h-14 object-cover rounded-lg border border-gray-200" loading="lazy">'
        . ($isSample ? '<span class="block text-[10px] text-gray-500 mt-0.5">Sample photo</span>' : '');
}

function property_resource(?int $landlordId = null): array {
    $own = $landlordId !== null;
    $landlordOptions = "SELECT id, CONCAT(full_name, COALESCE(CONCAT(' — ', company_name), '')) FROM landlords ORDER BY full_name";
    $unitsModule = $own ? 'my_units' : 'units';

    $fields = [
        'name' => ['label' => 'Property name', 'required' => true, 'max' => 150],
        'property_type' => ['label' => 'Property type', 'type' => 'select', 'options' => PROPERTY_TYPES, 'required' => true],
        'landlord_id' => ['label' => 'Landlord', 'type' => 'select', 'options_sql' => $landlordOptions, 'required' => true],
        'location_id' => ['label' => 'Location', 'type' => 'select', 'options_sql' => "SELECT id, CONCAT(estate, ', ', sub_county) FROM locations ORDER BY sub_county, estate", 'required' => true,
            'help' => $own ? 'Missing an estate? Ask management to add it.' : 'Missing an estate? Add it under Locations first.'],
        'physical_address' => ['label' => 'Physical address', 'required' => true, 'max' => 255, 'wide' => true],
        'number_of_units' => ['label' => 'Planned number of units', 'type' => 'number', 'min' => 0, 'max' => 10000, 'required' => true, 'default' => 0],
        'year_built' => ['label' => 'Year built', 'type' => 'number', 'min' => 1901, 'max' => (int)date('Y') + 2],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => PROPERTY_STATUSES, 'required' => true, 'default' => 'Active', 'help' => 'Only Active properties appear on the public website.'],
        'latitude' => ['label' => 'Latitude', 'type' => 'decimal', 'decimals' => 8, 'min' => -90, 'max' => 90],
        'longitude' => ['label' => 'Longitude', 'type' => 'decimal', 'decimals' => 8, 'min' => -180, 'max' => 180],
        'amenities' => ['label' => 'Amenities', 'type' => 'textarea', 'help' => 'Comma-separated, e.g. Borehole, CCTV, Parking.'],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'help' => 'Shown on the public listing.'],
        'property_image' => ['label' => 'Property photo', 'type' => 'image', 'folder' => 'properties', 'help' => 'JPG or PNG up to 5 MB. Uploading a new photo replaces the current one.'],
    ];
    $columns = [
        'property_image' => ['label' => 'Photo', 'format' => fn($row) => property_thumbnail($row)],
        'name' => ['label' => 'Property', 'format' => fn($row) => '<strong>' . e($row['name']) . '</strong><div class="text-xs text-gray-500">' . e($row['physical_address']) . '</div>'],
        'property_type' => ['label' => 'Type'],
        'estate' => ['label' => 'Location', 'format' => fn($row) => e($row['estate'] . ', ' . $row['sub_county'])],
        'landlord_name' => ['label' => 'Landlord'],
        'unit_count' => ['label' => 'Units', 'format' => fn($row) => (int)$row['occupied_count'] . ' of ' . (int)$row['unit_count'] . ' occupied'],
        'status' => ['label' => 'Status', 'format' => 'badge'],
    ];
    $filters = [
        'status' => ['label' => 'Status', 'column' => 'p.status', 'options' => PROPERTY_STATUSES],
        'property_type' => ['label' => 'Type', 'column' => 'p.property_type', 'options' => PROPERTY_TYPES],
        'landlord_id' => ['label' => 'Landlord', 'column' => 'p.landlord_id', 'options_sql' => $landlordOptions],
    ];
    if ($own) unset($fields['landlord_id'], $columns['landlord_name'], $filters['landlord_id']);

    $resource = [
        'module' => $own ? 'my_properties' : 'properties',
        'table' => 'properties',
        'singular' => 'Property',
        'plural' => $own ? 'My Properties' : 'Properties',
        'description' => $own
            ? 'Your buildings, their details and listing photos. Active properties with vacant units appear on the public website.'
            : 'Buildings in the portfolio with their landlord, location and listing photo.',
        'fields' => $fields,
        'list' => [
            'sql' => "SELECT p.id, p.name, p.property_type, p.status, p.physical_address, p.property_image, l.estate, l.sub_county, ld.full_name AS landlord_name,
                    (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id) AS unit_count,
                    (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'Occupied') AS occupied_count
                FROM properties p JOIN locations l ON l.id = p.location_id JOIN landlords ld ON ld.id = p.landlord_id",
            'columns' => $columns,
            'search' => ['p.name', 'p.physical_address', 'l.estate', 'ld.full_name'],
            'filters' => $filters,
            'order' => 'p.name',
            'row_actions' => function ($row) use ($unitsModule) {
                $html = '<a class="btn btn-small btn-secondary" href="' . e(module_url($unitsModule, ['property_id' => $row['id']])) . '">Units</a>';
                if ($row['status'] === 'Active') $html .= '<a class="btn btn-small btn-secondary" href="' . e(BASE_URL . '/public/property-details.php?id=' . (int)$row['id']) . '" target="_blank" rel="noopener">Listing</a>';
                return $html;
            },
        ],
        'hooks' => [
            // Units, expenses and history cascade from a property, so block deletes that would erase them.
            'before_delete' => function (PDO $db, array $row) {
                $stmt = $db->prepare('SELECT (SELECT COUNT(*) FROM units WHERE property_id = ?) + (SELECT COUNT(*) FROM expenses WHERE property_id = ?)');
                $stmt->execute([$row['id'], $row['id']]);
                if ((int)$stmt->fetchColumn() > 0) throw new UserFacingException('This property still has units or expenses. Set its status to Archived instead of deleting it.');
            },
        ],
    ];
    if ($own) {
        $resource['scope'] = ['find' => 'landlord_id = ?', 'list' => 'p.landlord_id = ?', 'params' => [$landlordId]];
        $resource['hooks']['prepare'] = function (array $data) use ($landlordId) {
            $data['landlord_id'] = $landlordId;
            return $data;
        };
    }
    return $resource;
}

function unit_resource(?int $landlordId = null): array {
    $own = $landlordId !== null;
    $propertyOptions = 'SELECT id, name FROM properties' . ($own ? ' WHERE landlord_id = ' . (int)$landlordId : '') . ' ORDER BY name';

    $resource = [
        'module' => $own ? 'my_units' : 'units',
        'table' => 'units',
        'singular' => 'Unit',
        'plural' => $own ? 'My Units' : 'Units',
        'description' => $own
            ? 'Houses and rooms in your properties: rent, deposit and availability. Vacant units are advertised on the public website.'
            : 'Houses and rooms within each property. Occupancy updates automatically when leases start or end.',
        'fields' => [
            'property_id' => ['label' => 'Property', 'type' => 'select', 'options_sql' => $propertyOptions, 'required' => true],
            'unit_number' => ['label' => 'Unit number', 'required' => true, 'max' => 50],
            'floor' => ['label' => 'Floor', 'max' => 20, 'default' => 'Ground'],
            'house_type' => ['label' => 'House type', 'type' => 'select', 'options' => UNIT_TYPES, 'required' => true],
            'bedrooms' => ['label' => 'Bedrooms', 'type' => 'number', 'min' => 0, 'max' => 20, 'required' => true, 'default' => 1],
            'bathrooms' => ['label' => 'Bathrooms', 'type' => 'number', 'min' => 0, 'max' => 20, 'required' => true, 'default' => 1],
            'monthly_rent' => ['label' => 'Monthly rent (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true],
            'deposit' => ['label' => 'Deposit (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true],
            'service_charge' => ['label' => 'Service charge (KSh)', 'type' => 'decimal', 'min' => 0, 'required' => true, 'default' => 0],
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => UNIT_STATUSES, 'required' => true, 'default' => 'Vacant',
                'help' => 'Occupied and Vacant also update automatically when a lease starts or ends.'],
            'water_billing_method' => ['label' => 'Water billing', 'type' => 'select', 'options' => ['Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared'], 'required' => true, 'default' => 'Meter Reading'],
            'electricity_billing_method' => ['label' => 'Electricity billing', 'type' => 'select', 'options' => ['Prepaid', 'Postpaid Sub-Meter', 'Fixed'], 'required' => true, 'default' => 'Prepaid'],
            'description' => ['label' => 'Description', 'type' => 'textarea'],
        ],
        'list' => [
            'sql' => "SELECT u.id, u.unit_number, u.floor, u.house_type, u.monthly_rent, u.status, u.property_id, p.name AS property_name,
                    (SELECT t.full_name FROM leases l JOIN tenants t ON t.id = l.tenant_id WHERE l.unit_id = u.id AND l.status = 'Active' LIMIT 1) AS tenant_name
                FROM units u JOIN properties p ON p.id = u.property_id",
            'columns' => [
                'unit_number' => ['label' => 'Unit', 'format' => fn($row) => '<strong>' . e($row['unit_number']) . '</strong><div class="text-xs text-gray-500">' . e($row['floor'] ?? '') . ' floor</div>'],
                'property_name' => ['label' => 'Property'],
                'house_type' => ['label' => 'Type'],
                'monthly_rent' => ['label' => 'Rent', 'format' => 'currency'],
                'tenant_name' => ['label' => 'Current tenant'],
                'status' => ['label' => 'Status', 'format' => 'badge'],
            ],
            'search' => ['u.unit_number', 'p.name'],
            'filters' => [
                'property_id' => ['label' => 'Property', 'column' => 'u.property_id', 'options_sql' => $propertyOptions],
                'status' => ['label' => 'Status', 'column' => 'u.status', 'options' => UNIT_STATUSES],
                'house_type' => ['label' => 'House type', 'column' => 'u.house_type', 'options' => UNIT_TYPES],
            ],
            'order' => 'p.name, u.unit_number',
            'row_actions' => fn($row) => $row['status'] === 'Vacant' && user_can_access_module('leases')
                ? '<a class="btn btn-small btn-accent" href="' . e(module_url('leases', ['action' => 'create', 'unit_id' => $row['id']])) . '">New lease</a>' : '',
        ],
        'messages' => ['duplicate' => 'This property already has a unit with that number.'],
        'hooks' => [
            // Charges, readings and requests cascade from a unit; keep its history by refusing the delete.
            'before_delete' => function (PDO $db, array $row) {
                $stmt = $db->prepare('SELECT (SELECT COUNT(*) FROM leases WHERE unit_id = ?) + (SELECT COUNT(*) FROM utility_readings WHERE unit_id = ?)');
                $stmt->execute([$row['id'], $row['id']]);
                if ((int)$stmt->fetchColumn() > 0) throw new UserFacingException('This unit has lease or billing history. Mark it Unavailable instead of deleting it.');
            },
        ],
    ];
    if ($own) {
        $resource['scope'] = ['find' => 'property_id IN (SELECT id FROM properties WHERE landlord_id = ?)', 'list' => 'p.landlord_id = ?', 'params' => [$landlordId]];
    }
    return $resource;
}
