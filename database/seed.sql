-- Insert Roles
INSERT INTO roles (id, name, description) VALUES
(1, 'Super Admin', 'Full system access across all modules and organizations'),
(2, 'Property Manager', 'Manages assigned properties, units, leases, and maintenance'),
(3, 'Landlord', 'Views owned properties, financial collections, and net income'),
(4, 'Caretaker', 'Views property units, tenants, records selected payments and reports maintenance'),
(5, 'Accountant', 'Manages payments, utility bills, expenses, and financial reports'),
(6, 'Tenant', 'Accesses personal lease, rent balances, bills, and submits requests');

-- Insert Initial Users (Default Password: Password123! hashed with PASSWORD_BCRYPT)
-- Hash for Password123! is: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
INSERT INTO users (id, role_id, full_name, email, phone, password_hash, status) VALUES
(1, 1, 'System Admin', 'admin@nairobiproperty.co.ke', '+254700000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(2, 2, 'Main Manager', 'manager@nairobiproperty.co.ke', '+254700000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(3, 3, 'Main Landlord', 'landlord@nairobiproperty.co.ke', '+254700000003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(4, 4, 'Caretaker John', 'caretaker@nairobiproperty.co.ke', '+254700000004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(5, 5, 'Chief Accountant', 'accountant@nairobiproperty.co.ke', '+254700000005', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active'),
(6, 6, 'Tenant Jane', 'tenant@nairobiproperty.co.ke', '+254700000006', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Active');

-- Insert Nairobi Locations
INSERT INTO locations (id, county, sub_county, estate) VALUES
(1, 'Nairobi', 'Kasarani', 'Kasarani Main'),
(2, 'Nairobi', 'Roysambu', 'Roysambu Stage'),
(3, 'Nairobi', 'Westlands', 'Kilimani'),
(4, 'Nairobi', 'Langata', 'South B'),
(5, 'Nairobi', 'Embakasi', 'Donholm');

-- Insert Landlord Profile
INSERT INTO landlords (id, user_id, full_name, company_name, phone, email, id_number, address, bank_details, mpesa_number) VALUES
(1, 3, 'Main Landlord', 'Nairobi Prime Holdings Ltd', '+254700000003', 'landlord@nairobiproperty.co.ke', '12345678', 'P.O. Box 45678, Nairobi', 'Equity Bank, Westlands Branch - Acc: 01234567890', '+254700000003');

-- Insert Sample Properties
INSERT INTO properties (id, landlord_id, name, property_type, description, location_id, physical_address, latitude, longitude, number_of_units, year_built, amenities, status) VALUES
(1, 1, 'Sunrise Apartments', 'Apartments', 'Modern 2-bedroom and 1-bedroom apartments with secure parking and borehole water.', 1, 'Thika Road, Kasarani near Seasons Stage', -1.22100000, 36.89200000, 12, 2023, 'Borehole, CCTV, Perimeter Wall, Ample Parking', 'Active'),
(2, 1, 'Green View Residences', 'Flats', 'Quiet residential flats located in a serene environment close to shopping malls.', 2, 'Outering Road, Roysambu', -1.21500000, 36.88500000, 8, 2022, 'Wi-Fi, Garbage Collection, Security Guard', 'Active');

-- Insert Sample Units
INSERT INTO units (id, property_id, unit_number, floor, house_type, bedrooms, bathrooms, monthly_rent, deposit, service_charge, water_billing_method, electricity_billing_method, status, description) VALUES
(1, 1, 'A101', '1st', '2 Bedroom', 2, 1, 25000.00, 25000.00, 1500.00, 'Meter Reading', 'Prepaid', 'Occupied', 'Spacious master ensuite with balcony'),
(2, 1, 'A102', '1st', '1 Bedroom', 1, 1, 15000.00, 15000.00, 1000.00, 'Meter Reading', 'Prepaid', 'Vacant', 'Standard 1 bedroom with open kitchen'),
(3, 2, 'B201', '2nd', 'Bedsitter', 1, 1, 9500.00, 9500.00, 500.00, 'Fixed Rate', 'Prepaid', 'Occupied', 'Self-contained bedsitter with tiles');

-- Insert Tenant Profile
INSERT INTO tenants (id, user_id, full_name, phone, email, national_id, gender, date_of_birth, emergency_contact_name, emergency_contact_phone, occupation, employer, current_address) VALUES
(1, 6, 'Tenant Jane', '+254700000006', 'tenant@nairobiproperty.co.ke', '34567890', 'Female', '1995-06-15', 'James Doe', '+254711111111', 'Software Developer', 'Tech Solutions Ltd', 'Nairobi, Kenya');

-- Insert Active Lease
INSERT INTO leases (id, tenant_id, property_id, unit_id, start_date, end_date, monthly_rent, deposit, payment_due_day, notice_period_days, status, special_terms) VALUES
(1, 1, 1, 1, '2026-01-01', '2026-12-31', 25000.00, 25000.00, 5, 30, 'Active', 'No pets allowed without prior written consent.');

-- Insert Utility Types
INSERT INTO utility_types (id, name, unit_of_measure, default_rate, description) VALUES
(1, 'Water', 'M3', 150.00, 'Municipal / Borehole metered water consumption rate per cubic meter'),
(2, 'Garbage Collection', 'Month', 300.00, 'Monthly waste management fee');