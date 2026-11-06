CREATE DATABASE IF NOT EXISTS nairobi_property_db;
USE nairobi_property_db;

-- Insert Roles
INSERT IGNORE INTO roles (id, name, description) VALUES
(1, 'Super Admin', 'Full system access across all modules and organizations'),
(2, 'Property Manager', 'Manages assigned properties, units, leases, and maintenance'),
(3, 'Landlord', 'Manages own properties and units; views financial collections and net income'),
(4, 'Caretaker', 'Views property units, tenants, records selected payments and reports maintenance'),
(5, 'Accountant', 'Manages payments, utility bills, expenses, and financial reports'),
(6, 'Tenant', 'Accesses personal lease, rent balances, bills, and submits requests');

-- Development only: the bundled hash corresponds to the password "password".
INSERT IGNORE INTO users (id, role_id, full_name, email, phone, password_hash, status) VALUES
(1, 1, 'System Admin', 'admin@nairobiproperty.co.ke', '+254700000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(2, 2, 'Main Manager', 'manager@nairobiproperty.co.ke', '+254700000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(3, 3, 'Main Landlord', 'landlord@nairobiproperty.co.ke', '+254700000003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(4, 4, 'Caretaker John', 'caretaker@nairobiproperty.co.ke', '+254700000004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(5, 5, 'Chief Accountant', 'accountant@nairobiproperty.co.ke', '+254700000005', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(6, 6, 'Tenant Jane', 'tenant@nairobiproperty.co.ke', '+254700000006', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active');

-- Insert Nairobi Locations
INSERT IGNORE INTO locations (id, county, sub_county, estate) VALUES
(1, 'Nairobi', 'Kasarani', 'Kasarani Main'),
(2, 'Nairobi', 'Roysambu', 'Roysambu Stage'),
(3, 'Nairobi', 'Westlands', 'Kilimani'),
(4, 'Nairobi', 'Langata', 'South B'),
(5, 'Nairobi', 'Embakasi', 'Donholm');

-- Insert Landlord Profile
INSERT IGNORE INTO landlords (id, user_id, full_name, company_name, phone, email, id_number, address, bank_details, mpesa_number) VALUES
(1, 3, 'Main Landlord', 'Nairobi Prime Holdings Ltd', '+254700000003', 'landlord@nairobiproperty.co.ke', '12345678', 'P.O. Box 45678, Nairobi', 'Equity Bank, Westlands Branch - Acc: 01234567890', '+254700000003');

-- Insert Sample Properties
INSERT IGNORE INTO properties (id, landlord_id, name, property_type, description, location_id, physical_address, latitude, longitude, number_of_units, year_built, amenities, status) VALUES
(1, 1, 'Sunrise Apartments', 'Apartment', 'Modern 2-bedroom and 1-bedroom apartments with secure parking and borehole water.', 1, 'Thika Road, Kasarani near Seasons Stage', -1.22100000, 36.89200000, 12, 2023, 'Borehole, CCTV, Perimeter Wall, Ample Parking', 'Active'),
(2, 1, 'Green View Residences', 'Flats', 'Quiet residential flats located in a serene environment close to shopping malls.', 2, 'Outering Road, Roysambu', -1.21500000, 36.88500000, 8, 2022, 'Wi-Fi, Garbage Collection, Security Guard', 'Active');

-- Insert Sample Units
INSERT IGNORE INTO units (id, property_id, unit_number, floor, house_type, bedrooms, bathrooms, monthly_rent, deposit, service_charge, water_billing_method, electricity_billing_method, status, description) VALUES
(1, 1, 'A101', '1st', '2 Bedroom', 2, 1, 25000.00, 25000.00, 1500.00, 'Meter Reading', 'Prepaid', 'Occupied', 'Spacious master ensuite with balcony'),
(2, 1, 'A102', '1st', '1 Bedroom', 1, 1, 15000.00, 15000.00, 1000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Standard 1 bedroom with open kitchen'),
(3, 2, 'B201', '2nd', 'Bedsitter', 1, 1, 9500.00, 9500.00, 500.00, 'Fixed Rate', 'Prepaid', 'Occupied', 'Self-contained bedsitter with tiles');

-- Insert Tenant Profile
INSERT IGNORE INTO tenants (id, user_id, full_name, phone, email, national_id, gender, date_of_birth, emergency_contact_name, emergency_contact_phone, occupation, employer, current_address) VALUES
(1, 6, 'Tenant Jane', '+254700000006', 'tenant@nairobiproperty.co.ke', '34567890', 'Female', '1995-06-15', 'James Doe', '+254711111111', 'Software Developer', 'Tech Solutions Ltd', 'Nairobi, Kenya');

-- Insert Active Lease
INSERT IGNORE INTO leases (id, tenant_id, property_id, unit_id, start_date, end_date, monthly_rent, deposit, payment_due_day, notice_period_days, status, special_terms) VALUES
(1, 1, 1, 1, '2026-01-01', '2026-12-31', 25000.00, 25000.00, 5, 30, 'Active', 'No pets allowed without prior written consent.');

-- Insert Utility Types
INSERT IGNORE INTO utility_types (id, name, unit_of_measure, default_rate, description) VALUES
(1, 'Water', 'M3', 150.00, 'Municipal / Borehole metered water consumption rate per cubic meter'),
(2, 'Garbage Collection', 'Month', 300.00, 'Monthly waste management fee');

-- More sample properties for the landlord, using the bundled photos in assets/.
-- (house5.jpg shows a real branded development and house6.jpg is a watermarked stock image,
-- so neither is used here.) Most units are Vacant so they appear on the public website.
INSERT IGNORE INTO locations (id, county, sub_county, estate) VALUES
(6, 'Kajiado', 'Kajiado North', 'Rongai'),
(7, 'Nairobi', 'Dagoretti North', 'Lavington'),
(8, 'Nairobi', 'Dagoretti North', 'Kileleshwa'),
(9, 'Nairobi', 'Lang''ata', 'Lang''ata');

INSERT IGNORE INTO properties (id, landlord_id, name, property_type, description, location_id, physical_address, latitude, longitude, number_of_units, year_built, amenities, status, property_image) VALUES
(3, 1, 'Acacia Gardens Bungalows', 'Townhouses', 'Gated community of three-bedroom bungalows with private parking, paved driveways and landscaped gardens.', 6, 'Magadi Road, Ongata Rongai', -1.39600000, 36.75500000, 6, 2024, 'Gated community, Perimeter wall, Borehole, Private parking, Garden', 'Active', 'assets/house4.jpg'),
(4, 1, 'Lavington Court Homes', 'Single Family', 'Four-bedroom two-storey family homes with double garages and private lawns in a quiet Lavington court.', 7, 'James Gichuru Road, Lavington', -1.27800000, 36.76900000, 3, 2021, 'Double garage, Private lawn, Backup generator, 24-hour security, CCTV', 'Active', 'assets/house1.jpg'),
(5, 1, 'Kileleshwa Ridge Villas', 'Maisonettes', 'Spacious maisonettes on a landscaped hillside close to schools and shopping centres.', 8, 'Othaya Road, Kileleshwa', -1.28100000, 36.78300000, 4, 2019, 'Borehole, Backup generator, Ample parking, Children''s play area, Security guards', 'Active', 'assets/house2.jpg'),
(6, 1, 'Lang''ata Garden Bungalow', 'Single Family', 'A three-bedroom bungalow with a covered porch and mature garden in a quiet Lang''ata neighbourhood.', 9, 'Lang''ata Road, near Galleria Mall', -1.33500000, 36.77000000, 1, 2018, 'Garden, Covered porch, Borehole, Electric fence, Parking', 'Active', 'assets/house3.jpg');

INSERT IGNORE INTO units (id, property_id, unit_number, floor, house_type, bedrooms, bathrooms, monthly_rent, deposit, service_charge, water_billing_method, electricity_billing_method, status, description) VALUES
(4, 3, 'G1', 'Ground', '3 Bedroom', 3, 2, 45000.00, 45000.00, 2000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Corner bungalow with a pergola and side garden'),
(5, 3, 'G2', 'Ground', '3 Bedroom', 3, 2, 45000.00, 45000.00, 2000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Master en-suite, open-plan kitchen and parking for two'),
(6, 3, 'G3', 'Ground', '3 Bedroom', 3, 2, 45000.00, 45000.00, 2000.00, 'Meter Reading', 'Prepaid', 'Reserved', 'Held for an applicant pending lease signing'),
(7, 3, 'G4', 'Ground', '3 Bedroom', 3, 2, 47000.00, 47000.00, 2000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Larger plot facing the green'),
(8, 3, 'G5', 'Ground', '3 Bedroom', 3, 2, 45000.00, 45000.00, 2000.00, 'Meter Reading', 'Prepaid', 'Under Maintenance', 'Roof and paint touch-ups in progress'),
(9, 3, 'G6', 'Ground', '3 Bedroom', 3, 2, 45000.00, 45000.00, 2000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Close to the main gate and visitor parking'),
(10, 4, 'H1', 'Ground + 1st', '4 Bedroom', 4, 4, 180000.00, 180000.00, 7500.00, 'Meter Reading', 'Postpaid Sub-Meter', 'Vacant', 'All bedrooms en-suite, double garage, DSQ'),
(11, 4, 'H2', 'Ground + 1st', '4 Bedroom', 4, 4, 180000.00, 180000.00, 7500.00, 'Meter Reading', 'Postpaid Sub-Meter', 'Vacant', 'Family room, study and private lawn'),
(12, 4, 'H3', 'Ground + 1st', '4 Bedroom', 4, 4, 185000.00, 185000.00, 7500.00, 'Meter Reading', 'Postpaid Sub-Meter', 'Reserved', 'End house with a larger garden'),
(13, 5, 'M1', 'Ground + 1st', 'Maisonette', 4, 3, 150000.00, 150000.00, 6000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Hillside views, balcony off the master bedroom'),
(14, 5, 'M2', 'Ground + 1st', 'Maisonette', 4, 3, 150000.00, 150000.00, 6000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Fitted kitchen, laundry room and DSQ'),
(15, 5, 'M3', 'Ground + 1st', 'Maisonette', 4, 3, 155000.00, 155000.00, 6000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Corner unit next to the play area'),
(16, 5, 'M4', 'Ground + 1st', 'Maisonette', 4, 3, 150000.00, 150000.00, 6000.00, 'Meter Reading', 'Prepaid', 'Under Maintenance', 'Kitchen refit in progress'),
(17, 6, 'B1', 'Ground', '3 Bedroom', 3, 2, 85000.00, 85000.00, 0.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Whole bungalow with a covered porch and mature garden');
