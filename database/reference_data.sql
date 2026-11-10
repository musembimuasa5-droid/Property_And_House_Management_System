-- Reference data for a PRODUCTION installation: roles, Nairobi locations and utility types.
-- Unlike seed.sql it creates no demonstration users, landlords or properties, so no account with a
-- published password exists. Create the first Super Admin with tools/create_admin.php.
-- Import into the selected database:  mysql -u <user> -p <database> < database/reference_data.sql

INSERT IGNORE INTO roles (id, name, description) VALUES
(1, 'Super Admin', 'Full system access across all modules'),
(2, 'Property Manager', 'Manages properties, units, tenancies, inspections and maintenance'),
(3, 'Landlord', 'Manages own properties and units; views own reports'),
(4, 'Caretaker', 'Updates maintenance requests assigned to them'),
(5, 'Accountant', 'Manages charges, payments, deposits, utilities, expenses and financial reports'),
(6, 'Tenant', 'Views own tenancy, rent, deposit and condition records; submits requests');

INSERT IGNORE INTO locations (id, county, sub_county, estate) VALUES
(1, 'Nairobi', 'Kasarani', 'Kasarani Main'),
(2, 'Nairobi', 'Roysambu', 'Roysambu Stage'),
(3, 'Nairobi', 'Westlands', 'Kilimani'),
(4, 'Nairobi', 'Langata', 'South B'),
(5, 'Nairobi', 'Embakasi', 'Donholm'),
(6, 'Kajiado', 'Kajiado North', 'Rongai'),
(7, 'Nairobi', 'Dagoretti North', 'Lavington'),
(8, 'Nairobi', 'Dagoretti North', 'Kileleshwa'),
(9, 'Nairobi', 'Lang''ata', 'Lang''ata');

INSERT IGNORE INTO utility_types (id, name, unit_of_measure, default_rate, description) VALUES
(1, 'Water', 'M3', 150.00, 'Metered water consumption per cubic metre'),
(2, 'Garbage Collection', 'Month', 300.00, 'Monthly waste management fee');
