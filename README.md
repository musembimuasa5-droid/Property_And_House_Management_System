# Nairobi Property & House Management System

An existing PHP/PDO and MySQL application for browsing Nairobi rental properties and supporting tenant workflows. The current checkout contains a public property directory, tenant portal, one management dashboard, and shared authentication/database components. Several management modules referenced by the original navigation are not present yet.

## Local setup

1. Place the project at `C:\xampp\htdocs\Property_And_House_Management_System` and start Apache and MySQL in XAMPP.
2. Create the `nairobi_property_db` database in phpMyAdmin.
3. Import `database/schema.sql`, then `database/seed.sql`. The schema no longer drops existing tables; seed inserts use `INSERT IGNORE` to avoid duplicating the provided sample IDs.
   **Upgrading an existing database?** Apply each file in `database/migrations/` in number order (each is safe to re-run). You can also re-run `seed.sql` to add the sample properties without touching existing rows:
   ```powershell
   Get-Content database\migrations\001_create_enquiries.sql | & C:\xampp\mysql\bin\mysql.exe -u root nairobi_property_db
   ```
4. Copy `.env.example` to `.env` and configure the database values. The default local connection uses `localhost:3306`, database `nairobi_property_db`, user `root`, and an empty password.
5. Visit `http://localhost/Property_And_House_Management_System/`.

## Configuration

The app reads `.env` when present. Set `APP_ENV=production` and `APP_DEBUG=false` for production use. Protect the `.env` file and replace development database credentials before deployment. `APP_URL` defaults to the XAMPP URL above.

## Seed accounts

Seeded user rows use a bundled development bcrypt hash. The original seed comment and README password did not match reliably, so do not use these accounts outside a local development database. For a fresh development database, verify/reset the account password with PHP `password_hash()` and keep credentials local.

## Current feature scope

- Public property search and property viewing requests.
- Tenant account registration, portal, payment history with printable receipts, rent statement with live balances, utility bills, maintenance requests (submit, track, cancel), complaints with management responses, profile, lease list, and notices.
- Management dashboard with KPI tiles, a six-month collections vs expenses chart, occupancy meters, arrears, expiring leases, recent payments, open maintenance and pending viewings.
- Full create/read/update/delete for every management module, with search, filters, pagination and CSV export (see below).
- Eight printable reports with CSV export.
- Landlord dashboard with a photo card for each property. Landlords can add and edit their own properties, photos and units; reports are limited to their own properties. Caretaker dashboard for updating assigned maintenance jobs.
- One app shell for every role: sidebar with live counts of items waiting for action; top bar with breadcrumbs, staff-wide search, a notifications bell, a user menu and a link to the public website. Account Settings lets every user change their password.
- The public site and the back office are connected: Contact messages are saved as Enquiries, and new enquiries, viewing requests, tenant repair requests and complaints alert managers through the bell.
- M-Pesa/Daraja is not integrated; payment methods are recorded manually.

## Management modules and roles

`config/modules.php` is the single list that drives the sidebar, page access and dashboard shortcuts. Super Admin can open everything.

| Area | Modules | Roles |
| --- | --- | --- |
| Portfolio | Properties, Units, Landlords, Locations | Super Admin, Property Manager |
| Tenancy | Tenants, Leases, Viewing Requests | Super Admin, Property Manager |
| Finance | Rent Charges, Payments, Utility Readings, Utility Types, Expenses | Super Admin, Property Manager, Accountant |
| Operations | Maintenance, Complaints, Enquiries, Notices | Super Admin, Property Manager |
| Insights | Reports | Super Admin, Property Manager, Accountant |
| Administration | User Accounts | Super Admin |
| Landlord portal | My Properties, My Units, Reports | Landlord (own properties only) |

The landlord pages reuse the staff property and unit definitions (`includes/resources.php`) with a *scope*. The engine adds the scope to every lookup, list, export, edit and delete, so a landlord cannot read or change another landlord's records, even by editing URLs. Leases, payments and tenants stay with management.

## Sample data and photos

`seed.sql` adds four sample properties for the seeded landlord (14 units, most of them vacant so they appear on the public site), using `assets/house1.jpg` to `house4.jpg`. Two bundled images are deliberately **not** used as listing photos: `house5.jpg` shows a real, branded development, and `house6.jpg` carries a stock-photo watermark. Replace all sample photos with your own licensed pictures before going live; landlords can upload a photo from **Edit** on each property.

Only Super Admin and Accountant can delete payments. Each module page in `admin/` describes its table, fields, list columns and business rules, and passes that description to the shared engine in `includes/crud.php`. The engine handles validation, CSRF checks, audit logging, deletes blocked by linked records, and CSV export.

## Billing rules

- **Generate monthly charges** (Rent Charges page) creates one charge per active lease: rent + the unit's service charge + that month's utility readings. Leases already charged for the period are skipped.
- Payments are applied to the oldest unpaid charge on the lease first, and overpayments carry forward. Balances are recalculated after every payment, charge or reading change (`includes/finance.php`). Charge status (Paid, Partial, Unpaid, Overdue) is calculated live.
- Activating a lease marks its unit Occupied. Terminating or expiring it frees the unit. A unit and a tenant can each have only one active lease.
- Records with financial history (properties with units or expenses, units with leases, leases with charges) cannot be deleted; archive or deactivate them instead.

## Reports

Staff: `admin/reports.php`. Landlords: `landlord/reports.php` (own properties only). Available reports: rent collection, arrears, income statement, occupancy, expenses, maintenance, lease expiry and tenant statement (staff only). Every report can be printed or saved as PDF from the browser, and downloaded as CSV for Excel.

## Tenant self-registration

When staff create a tenant profile first, the tenant can register with the same national ID and email to link a portal login to it. Other duplicate national IDs are refused with a message.

The `database/schema.sql` file defines the database structure. Existing databases require a reviewed migration before schema changes; importing the schema will not alter their current tables.
