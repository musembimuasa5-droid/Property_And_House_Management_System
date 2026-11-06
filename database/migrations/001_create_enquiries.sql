-- Migration 001: store messages sent from the public Contact page.
-- Safe to re-run: creates the table only if it is missing and changes nothing else.
-- Apply to an existing database (PowerShell):
--   Get-Content database\migrations\001_create_enquiries.sql | & C:\xampp\mysql\bin\mysql.exe -u root nairobi_property_db

CREATE TABLE IF NOT EXISTS enquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(20) NULL,
    message TEXT NOT NULL,
    status ENUM('New', 'In Progress', 'Resolved', 'Closed') DEFAULT 'New',
    response_notes TEXT NULL,
    handled_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enquiries_status (status),
    FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
