-- Migration 002: move-in/move-out condition reports, the deposit ledger, a time-stamped
-- maintenance history, sign-in throttling, the lease rent review date and before/after
-- values in the audit log.
-- Safe to re-run: it only adds tables, columns and indexes, and never changes or removes data.
-- Uses MariaDB's "IF NOT EXISTS" column syntax (XAMPP ships MariaDB).
-- Apply to an existing database (PowerShell):
--   Get-Content database\migrations\002_condition_deposit_security.sql | & C:\xampp\mysql\bin\mysql.exe -u root nairobi_property_db

-- Inspection of a unit at the start (Move-in) or end (Move-out) of a lease. One of each per lease.
CREATE TABLE IF NOT EXISTS condition_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    report_type ENUM('Move-in', 'Move-out') NOT NULL,
    inspection_date DATE NOT NULL,
    overall_condition ENUM('Excellent', 'Good', 'Fair', 'Poor') NOT NULL,
    general_notes TEXT NULL,
    inspected_by INT NOT NULL,
    tenant_response ENUM('Pending', 'Agreed', 'Disputed') NOT NULL DEFAULT 'Pending',
    tenant_comment TEXT NULL,
    responded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_lease_report (lease_id, report_type),
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE RESTRICT,
    FOREIGN KEY (inspected_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- One checklist line per area of the unit, with an optional photo kept in uploads/condition.
CREATE TABLE IF NOT EXISTS condition_report_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    area VARCHAR(60) NOT NULL,
    item_condition ENUM('Good', 'Fair', 'Poor', 'Damaged', 'Missing') NOT NULL,
    remarks VARCHAR(255) NULL,
    photo_path VARCHAR(255) NULL,
    UNIQUE KEY unique_report_area (report_id, area),
    FOREIGN KEY (report_id) REFERENCES condition_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Deposit ledger: money received, itemised deductions (with reasons) and refunds per lease.
CREATE TABLE IF NOT EXISTS deposit_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_id INT NOT NULL,
    transaction_type ENUM('Received', 'Deduction', 'Refund') NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    transaction_date DATE NOT NULL,
    reason VARCHAR(255) NULL,
    condition_report_id INT NULL,
    reference VARCHAR(100) NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_deposit_lease (lease_id),
    FOREIGN KEY (lease_id) REFERENCES leases(id) ON DELETE RESTRICT,
    FOREIGN KEY (condition_report_id) REFERENCES condition_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Every status change of a maintenance request, with who made it and when.
CREATE TABLE IF NOT EXISTS maintenance_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    status ENUM('Submitted', 'Reviewed', 'Assigned', 'In Progress', 'Waiting', 'Completed', 'Cancelled') NOT NULL,
    note VARCHAR(255) NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maintenance_updates_request (request_id),
    FOREIGN KEY (request_id) REFERENCES maintenance_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Failed sign-in attempts, used to slow down password guessing.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email (email, attempted_at),
    INDEX idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB;

ALTER TABLE leases ADD COLUMN IF NOT EXISTS rent_review_date DATE NULL AFTER notice_period_days;

ALTER TABLE audit_logs
    ADD COLUMN IF NOT EXISTS old_values TEXT NULL AFTER description,
    ADD COLUMN IF NOT EXISTS new_values TEXT NULL AFTER old_values;

CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs (created_at);
