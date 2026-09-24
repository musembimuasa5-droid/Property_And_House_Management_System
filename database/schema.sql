SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS viewing_requests;
DROP TABLE IF EXISTS property_images;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS complaints;
DROP TABLE IF EXISTS maintenance_requests;
DROP TABLE IF EXISTS utility_bills;
DROP TABLE IF EXISTS utility_readings;
DROP TABLE IF EXISTS utility_types;
DROP TABLE IF EXISTS payment_allocations;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS rent_charges;
DROP TABLE IF EXISTS leases;
DROP TABLE IF EXISTS tenants;
DROP TABLE IF EXISTS units;
DROP TABLE IF EXISTS properties;
DROP TABLE IF EXISTS landlords;
DROP TABLE IF EXISTS locations;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Roles Table
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('Active', 'Inactive', 'Suspended') DEFAULT 'Active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 3. Locations Table (Nairobi Estates / Sub-counties)
CREATE TABLE locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    county VARCHAR(100) DEFAULT 'Nairobi',
    sub_county VARCHAR(100) NOT NULL,
    estate VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. Landlords Table
CREATE TABLE landlords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    full_name VARCHAR(100) NOT NULL,
    company_name VARCHAR(150) NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(150) NOT NULL,
    id_number VARCHAR(50) NOT NULL,
    address TEXT,
    bank_details TEXT,
    mpesa_number VARCHAR(20),
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5. Properties Table
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    property_type ENUM('Apartment', 'Flats', 'Bedsitters', 'Maisonettes', 'Townhouses', 'Commercial', 'Student Housing', 'Mixed Use', 'Single Family', 'Other') NOT NULL,
    description TEXT,
    location_id INT NOT NULL,
    physical_address VARCHAR(255) NOT NULL,
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    number_of_units INT NOT NULL DEFAULT 0,
    year_built YEAR NULL,
    amenities TEXT,
    status ENUM('Active', 'Under Maintenance', 'Archived') DEFAULT 'Active',
    property_image VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE RESTRICT,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 6. Units Table
CREATE TABLE units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    unit_number VARCHAR(50) NOT NULL,
    floor VARCHAR(20) DEFAULT 'Ground',
    house_type ENUM('Bedsitter', 'Studio', '1 Bedroom', '2 Bedroom', '3 Bedroom', '4 Bedroom', 'Maisonette', 'Shop', 'Office', 'Other') NOT NULL,
    bedrooms INT DEFAULT 1,
    bathrooms INT DEFAULT 1,
    monthly_rent DECIMAL(12, 2) NOT NULL,
    deposit DECIMAL(12, 2) NOT NULL,
    service_charge DECIMAL(12, 2) DEFAULT 0.00,
    water_billing_method ENUM('Meter Reading', 'Fixed Rate', 'Per Unit', 'Shared') DEFAULT 'Meter Reading',
    electricity_billing_method ENUM('Prepaid', 'Postpaid Sub-Meter', 'Fixed') DEFAULT 'Prepaid',
    status ENUM('Vacant', 'Occupied', 'Reserved', 'Under Maintenance', 'Unavailable') DEFAULT 'Vacant',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    UNIQUE KEY unique_property_unit (property_id, unit_number)
) ENGINE=InnoDB;

-- 7. Tenants Table
CREATE TABLE tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(150) NOT NULL,
    national_id VARCHAR(50) NOT NULL UNIQUE,
    gender ENUM('Male', 'Female', 'Other') NOT NULL,
    date_of_birth DATE NULL,
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    occupation VARCHAR(100),
    employer VARCHAR(100),
    current_address TEXT,
    profile_photo VARCHAR(255),
    status ENUM('Active', 'Inactive', 'Evicted', 'Blacklisted') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 8. Leases Table
CREATE TABLE leases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    property_id INT NOT NULL,
    unit_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    monthly_rent DECIMAL(12, 2) NOT NULL,
    deposit DECIMAL(12, 2) NOT NULL,
    payment_due_day INT DEFAULT 5,
    notice_period_days INT DEFAULT 30,
    status ENUM('Active', 'Expired', 'Terminated', 'Pending', 'Renewed') DEFAULT 'Pending',
    special_terms TEXT,
    document_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE RESTRICT,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 9. Rent Charges Table
CREATE TABLE rent_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    tenant_id INT NOT NULL,
    unit_id INT NOT NULL,
    billing_period VARCHAR(20) NOT NULL, -- e.g., '2026-09'
    rent_amount DECIMAL(12, 2) NOT NULL,
    service_charge DECIMAL(12, 2) DEFAULT 0.00,
    water_bill DECIMAL(12, 2) DEFAULT 0.00,
    previous_balance DECIMAL(12, 2) DEFAULT 0.00,
    total_due DECIMAL(12, 2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('Unpaid', 'Partial', 'Paid', 'Overdue') DEFAULT 'Unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 10. Payments Table
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    tenant_id INT NOT NULL,
    property_id INT NOT NULL,
    unit_id INT NOT NULL,
    lease_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    payment_method ENUM('M-Pesa', 'Bank', 'Cash', 'Card', 'Other') NOT NULL,
    transaction_reference VARCHAR(100) NOT NULL UNIQUE,
    payment_date DATE NOT NULL,
    payment_period VARCHAR(20) NOT NULL,
    recorded_by INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE RESTRICT,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE RESTRICT,
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE RESTRICT,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 11. Payment Allocations (Supports partial payment tracking across specific charges)
CREATE TABLE payment_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    rent_charge_id INT NOT NULL,
    allocated_amount DECIMAL(12, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (rent_charge_id) REFERENCES rent_charges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 12. Utility Types Table
CREATE TABLE utility_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    unit_of_measure VARCHAR(20) DEFAULT 'Units',
    default_rate DECIMAL(10, 2) NOT NULL,
    description VARCHAR(255)
) ENGINE=InnoDB;

-- 13. Utility Readings Table
CREATE TABLE utility_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_id INT NOT NULL,
    utility_type_id INT NOT NULL,
    previous_reading DECIMAL(10, 2) NOT NULL,
    current_reading DECIMAL(10, 2) NOT NULL,
    consumption DECIMAL(10, 2) NOT NULL,
    rate DECIMAL(10, 2) NOT NULL,
    total_amount DECIMAL(12, 2) NOT NULL,
    billing_period VARCHAR(20) NOT NULL,
    reading_date DATE NOT NULL,
    recorded_by INT NOT NULL,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    FOREIGN KEY (utility_type_id) REFERENCES utility_types(id) ON DELETE RESTRICT,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 14. Maintenance Requests Table
CREATE TABLE maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    property_id INT NOT NULL,
    unit_id INT NOT NULL,
    category ENUM('Plumbing', 'Electrical', 'Water', 'Security', 'Structural', 'Appliance', 'Cleaning', 'Other') NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('Low', 'Medium', 'High', 'Emergency') DEFAULT 'Medium',
    image_path VARCHAR(255),
    status ENUM('Submitted', 'Reviewed', 'Assigned', 'In Progress', 'Waiting', 'Completed', 'Cancelled') DEFAULT 'Submitted',
    assigned_staff INT NULL,
    estimated_cost DECIMAL(12, 2) DEFAULT 0.00,
    actual_cost DECIMAL(12, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_date TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_staff) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 15. Complaints Table
CREATE TABLE complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    property_id INT NOT NULL,
    category ENUM('Noise', 'Security', 'Water', 'Neighbors', 'Maintenance', 'Billing', 'Staff', 'Other') NOT NULL,
    subject VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('Open', 'Under Review', 'Resolved', 'Dismissed') DEFAULT 'Open',
    resolution_notes TEXT,
    assigned_to INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 16. Expenses Table
CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    category ENUM('Maintenance', 'Security', 'Utilities', 'Cleaning', 'Staff', 'Repairs', 'Management', 'Other') NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    expense_date DATE NOT NULL,
    vendor VARCHAR(150),
    receipt_path VARCHAR(255),
    recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 17. Notifications Table
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 18. Viewing Requests Table
CREATE TABLE viewing_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    unit_id INT NULL,
    visitor_name VARCHAR(100) NOT NULL,
    visitor_phone VARCHAR(20) NOT NULL,
    visitor_email VARCHAR(150) NOT NULL,
    preferred_date DATE NOT NULL,
    preferred_time TIME NOT NULL,
    message TEXT,
    status ENUM('Pending', 'Approved', 'Rejected', 'Rescheduled', 'Completed') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 19. Documents Table
CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NULL,
    tenant_id INT NULL,
    title VARCHAR(150) NOT NULL,
    document_type ENUM('Lease Agreement', 'ID Document', 'Receipt', 'Property Title', 'Maintenance Photo', 'Other') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 20. Audit Logs Table
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    record_id INT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;