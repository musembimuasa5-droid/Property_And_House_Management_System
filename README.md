# Nairobi Property & House Management System

An existing PHP/PDO and MySQL application for browsing Nairobi rental properties and supporting tenant workflows. The current checkout contains a public property directory, tenant portal, one management dashboard, and shared authentication/database components. Several management modules referenced by the original navigation are not present yet.

## Local setup

1. Place the project at `C:\xampp\htdocs\Property_And_House_Management_System` and start Apache and MySQL in XAMPP.
2. Create the `nairobi_property_db` database in phpMyAdmin.
3. Import `database/schema.sql`, then `database/seed.sql`. The schema no longer drops existing tables; seed inserts use `INSERT IGNORE` to avoid duplicating the provided sample IDs.
4. Copy `.env.example` to `.env` and configure the database values. The default local connection uses `localhost:3306`, database `nairobi_property_db`, user `root`, and an empty password.
5. Visit `http://localhost/Property_And_House_Management_System/`.

## Configuration

The app reads `.env` when present. Set `APP_ENV=production` and `APP_DEBUG=false` for production use. Protect the `.env` file and replace development database credentials before deployment. `APP_URL` defaults to the XAMPP URL above.

## Seed accounts

Seeded user rows use a bundled development bcrypt hash. The original seed comment and README password did not match reliably, so do not use these accounts outside a local development database. For a fresh development database, verify/reset the account password with PHP `password_hash()` and keep credentials local.

## Current feature scope

- Public property search and property viewing requests.
- Tenant account registration, portal, payment history, rent charges, utility readings, maintenance, complaints, profile, lease list, and notices.
- Management dashboard metrics and payment recording API.
- M-Pesa/Daraja is not integrated; payment methods are recorded manually.

The `database/schema.sql` file defines the database structure. Existing databases require a reviewed migration before schema changes; importing the schema will not alter their current tables.
