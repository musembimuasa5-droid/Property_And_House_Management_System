-- Migration 003: self-service for landlords and caretakers.
-- * properties.caretaker_id: the caretaker who looks after a property. They can see its units,
--   record move-in/move-out condition reports and meter readings for it.
-- * tenants.landlord_id: the landlord who registered a tenant, so a landlord who manages
--   their own property sees the tenants they added before any lease exists.
-- Safe to re-run: it only adds columns, indexes and foreign keys, and never changes data.
-- Apply to an existing database (PowerShell), after a backup:
--   Get-Content database\migrations\003_landlord_caretaker_portals.sql | & C:\xampp\mysql\bin\mysql.exe -u root nairobi_property_db

ALTER TABLE properties ADD COLUMN IF NOT EXISTS caretaker_id INT NULL AFTER landlord_id;
ALTER TABLE properties ADD CONSTRAINT fk_properties_caretaker FOREIGN KEY IF NOT EXISTS (caretaker_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE tenants ADD COLUMN IF NOT EXISTS landlord_id INT NULL AFTER user_id;
ALTER TABLE tenants ADD CONSTRAINT fk_tenants_landlord FOREIGN KEY IF NOT EXISTS (landlord_id) REFERENCES landlords(id) ON DELETE SET NULL;
