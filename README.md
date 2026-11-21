# Urban Escape Property & House Management System

A PHP/PDO and MySQL (MariaDB) web application for managing residential rental property in Nairobi: a public listings website, a management back office, and portals for landlords, caretakers and tenants. Rent and payments are recorded in the system; it does not connect to any payment service.

## Local setup

1. Place the project at `C:\xampp\htdocs\Property_And_House_Management_System` and start Apache and MySQL in XAMPP.
2. Create the `nairobi_property_db` database in phpMyAdmin.
3. Import `database/schema.sql`, then `database/seed.sql`. The schema no longer drops existing tables; seed inserts use `INSERT IGNORE` to avoid duplicating the provided sample IDs.
   **Upgrading an existing database?** Back up first, then apply each file in `database/migrations/` in number order (each only adds tables and columns and is safe to re-run). You can also re-run `seed.sql` to add the sample properties without touching existing rows:
   ```powershell
   C:\xampp\mysql\bin\mysqldump.exe -u root nairobi_property_db > backup-before-migration.sql
   Get-Content database\migrations\001_create_enquiries.sql | & C:\xampp\mysql\bin\mysql.exe -u root nairobi_property_db
   Get-Content database\migrations\002_condition_deposit_security.sql | & C:\xampp\mysql\bin\mysql.exe -u root nairobi_property_db
   Get-Content database\migrations\003_landlord_caretaker_portals.sql | & C:\xampp\mysql\bin\mysql.exe -u root nairobi_property_db
   ```
4. Copy `.env.example` to `.env` and configure the database values. The default local connection uses `localhost:3306`, database `nairobi_property_db`, user `root`, and an empty password.
5. Visit `http://localhost/Property_And_House_Management_System/`.

## Configuration

The app reads `.env` when present. Set `APP_ENV=production` and `APP_DEBUG=false` for production use. Protect the `.env` file and replace development database credentials before deployment. `APP_URL` defaults to the XAMPP URL above.

## Seed accounts

Seeded user rows use a bundled development bcrypt hash. The original seed comment and README password did not match reliably, so do not use these accounts outside a local development database. For a fresh development database, verify/reset the account password with PHP `password_hash()` and keep credentials local.

## Current feature scope

<<<<<<< HEAD
- Public property discovery with database-backed neighbourhood, property type, unit type, bedroom, and rent filters; each result represents one genuinely vacant unit in an active property, with unit-level pagination, responsive details, contextual contact enquiries, and database-backed viewing requests.
- Tenant registration and portal workflows for profile updates, leases and documents, rent charges, payment history, notices, complaints, and maintenance requests with one optional JPG/PNG image (the existing schema stores one image path per request).
- Super Admin property and unit creation, editing, search/filtering, pagination, status changes, safe archival, and deletion only when no linked records exist. Landlords can create and manage only the properties/units linked to their own landlord profile.
- Caretaker-assigned maintenance, meter readings, viewing feedback, incident reports, and recorded expenses.
- Management dashboards and a payment-recording API. These do not yet provide the complete lease, payment reversal, or portfolio-scoped manager workflows requested for a production rollout.
- M-Pesa/Daraja is not integrated; payment methods are recorded manually. Google sign-in is also not configured and remains disabled.

Property/unit changes, tenant maintenance submissions, and contact-message status changes use server-side validation, CSRF checks, prepared statements, and audit records. Property images use validated JPG/PNG uploads; replacement/removal preserves images still referenced by another property. Permanent deletion is blocked when historical or operational records are linked.

The People Directory supports Super Admin-managed tenant, landlord, caretaker, manager, and accountant accounts, including role/status changes, password resets, and a CSRF-protected delete action. Unlinked accounts can be deleted; accounts with property, lease, payment, maintenance, complaint, document, notification, assignment, or audit history are deactivated instead. The current administrator and last active Super Admin are protected. The application still needs manager tenancy/finance operations, additional caretaker inspection workflows, tenant payment/receipt workflows, property-portfolio assignment for managers, and dedicated reporting/settings/terms/privacy pages. Review those requirements before treating the system as production-complete.
=======
- Public property search and property viewing requests.
- Tenant account registration, portal, payment history with printable receipts, rent statement with live balances, utility bills, maintenance requests (submit, track, cancel), complaints with management responses, profile, lease list, and notices.
- Management dashboard with KPI tiles, a six-month collections vs expenses chart, occupancy meters, arrears, expiring leases, recent payments, open maintenance and pending viewings.
- Full create/read/update/delete for every management module, with search, filters, pagination and CSV export (see below).
- Nine printable reports with CSV export, including a deposit ledger and an arrears report split into ageing bands (not yet due, 1–30, 31–60, 61–90 and over 90 days) with an ageing summary.
- Move-in and move-out condition reports: a fixed checklist of areas with optional photos. Tenants agree with or dispute each report; a move-out report is compared line by line with the move-in report, and deposit deductions link to the report that justifies them.
- Deposit ledger: deposits received, itemised deductions with reasons, and refunds, with checks that deductions and refunds never exceed the deposit held.
- Maintenance requests accept an optional photo and keep a time-stamped status history.
- Security and privacy: sign-in throttling (5 failures per email in 15 minutes), a 30-minute idle timeout, an audit log with before and after values that a Super Admin can search, a privacy notice with consent at registration, and private photo folders served only after an access check (`account/attachment.php`).
- Landlord self-service, so a small landlord can run lettings without a manager: their own properties, units, tenants, leases, condition reports, monthly charges, payments with receipts, deposit ledger, meter readings and reports (including tenant statements), all limited to their own properties.
- Caretaker portal for the properties assigned to them (Caretaker field on each property): units with the current tenant's phone, move-in and move-out condition reports (management is notified; caretakers cannot delete reports or see deposit amounts), meter readings, and assigned repairs with a status, cost and note.
- One app shell for every role: sidebar with live counts of items waiting for action; top bar with breadcrumbs, staff-wide search, a notifications bell, a user menu and a link to the public website. Account Settings lets every user change their password.
- The public site and the back office are connected: Contact messages are saved as Enquiries, and new enquiries, viewing requests, tenant repair requests and complaints alert managers through the bell.
- M-Pesa/Daraja is not integrated; payment methods are recorded manually.
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d

## Management modules and roles

`config/modules.php` is the single list that drives the sidebar, page access and dashboard shortcuts. Super Admin can open everything.

| Area | Modules | Roles |
| --- | --- | --- |
| Portfolio | Properties, Units, Landlords, Locations | Super Admin, Property Manager |
| Tenancy | Tenants, Leases, Viewing Requests, Condition Reports | Super Admin, Property Manager |
| Finance | Rent Charges, Payments, Deposit Ledger, Utility Readings, Utility Types, Expenses | Super Admin, Property Manager, Accountant |
| Operations | Maintenance, Complaints, Enquiries, Notices | Super Admin, Property Manager |
| Insights | Reports | Super Admin, Property Manager, Accountant |
| Administration | User Accounts, Audit Log | Super Admin |
| Landlord portal | My Properties, My Units, My Tenants, Leases, Condition Reports, Rent Charges, Payments, Deposit Ledger, Utility Readings, Reports | Landlord (own properties only; cannot delete payments or deposit entries) |
| Caretaker portal | Assigned Requests, Units (read-only), Meter Readings (no delete), Condition Reports (no delete) | Caretaker (assigned properties only) |

The landlord and caretaker pages reuse the staff definitions (`includes/resources.php` for properties and units, `includes/tenancy_resources.php` for tenants, leases, charges, payments, deposits and readings) with a *scope*. The engine adds the scope to every lookup, list, export, select option, edit and delete, so a landlord or caretaker cannot read or change records outside their properties, even by editing URLs or form values. A landlord sees the tenants they registered (`tenants.landlord_id`) and anyone who has leased one of their units.

## Sample data and photos

`seed.sql` adds four sample properties for the seeded landlord (14 units, most of them vacant so they appear on the public site), using `assets/house1.jpg` to `house4.jpg`. Two bundled images are deliberately **not** used as listing photos: `house5.jpg` shows a real, branded development, and `house6.jpg` carries a stock-photo watermark. Replace all sample photos with your own licensed pictures before going live; landlords can upload a photo from **Edit** on each property. A property without a photo shows a neutral "Photo coming soon" image (`assets/property-placeholder.svg`) rather than reusing another listing's picture.

Only Super Admin and Accountant can delete payments. Each module page in `admin/` describes its table, fields, list columns and business rules, and passes that description to the shared engine in `includes/crud.php`. The engine handles validation, CSRF checks, audit logging, deletes blocked by linked records, and CSV export.

## Billing rules

- **Generate monthly charges** (Rent Charges page) creates one charge per active lease: rent + the unit's service charge + that month's utility readings. Leases already charged for the period are skipped.
- **Send reminders** (Rent Charges page) sends an in-system arrears notice to every tenant with an overdue balance, at most once a day each.
- A deposit deduction needs a reason, and money received cannot exceed the agreed deposit; deductions and refunds cannot exceed what is held.
- Payments are applied to the oldest unpaid charge on the lease first, and overpayments carry forward. Balances are recalculated after every payment, charge or reading change (`includes/finance.php`). Charge status (Paid, Partial, Unpaid, Overdue) is calculated live.
- Activating a lease marks its unit Occupied. Terminating or expiring it frees the unit. A unit and a tenant can each have only one active lease.
- Records with financial history (properties with units or expenses, units with leases, leases with charges) cannot be deleted; archive or deactivate them instead.

## Reports

Staff: `admin/reports.php`. Landlords: `landlord/reports.php` (own properties only). Available reports: rent collection, arrears, income statement, occupancy, expenses, maintenance, lease expiry, deposit ledger and tenant statement (landlords see their own tenants, with only the charges and payments on their properties). Every report can be printed or saved as PDF from the browser, and downloaded as CSV for Excel.

## Tenant self-registration

When staff create a tenant profile first, the tenant can register with the same national ID and email to link a portal login to it. Other duplicate national IDs are refused with a message.

<<<<<<< HEAD
The `database/schema.sql` file defines the database structure. Existing databases require a reviewed migration before schema changes; importing the schema will not alter their current tables.

## Illustrative property photography

The optimized WebP images in `assets/` are licensed illustrations from Unsplash. They depict generic contemporary homes and interiors; they are not verified photographs of the Nairobi listings shown beside them. The homepage and listing cards label illustrative or sample imagery where applicable.

- `modern-residential-building.webp` — [Unsplash photo](https://images.unsplash.com/photo-1600596542815-ffad4c1539a9)
- `modern-family-home.webp` — [Unsplash photo](https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde)
- `airy-apartment-living-room.webp` — [Unsplash photo](https://images.unsplash.com/photo-1600210492486-724fe5c67fb0)
- `bright-open-plan-lounge.webp` — [Unsplash photo](https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea)
- `modern-home-interior.webp` — [Unsplash photo](https://images.unsplash.com/photo-1600607687939-ce8a6c25118c)

These images are used under the [Unsplash License](https://unsplash.com/license). Photographer attribution is appreciated but not required by the license.

## Illustrative testimonial portraits

The homepage testimonial cards use local sample portraits, not photos of verified customers. They are illustrative assets from Unsplash and are used under the [Unsplash License](https://unsplash.com/license).

- `testimonial-wanjiku.jpg` — [Unsplash photo](https://images.unsplash.com/photo-1494790108377-be9c29b29330)
- `testimonial-david.jpg` — [Unsplash photo](https://images.unsplash.com/photo-1500648767791-00dcc994a43e)
- `testimonial-akinyi.jpg` — [Unsplash photo](https://images.unsplash.com/photo-1531123897727-8f129e1688ce)
=======
The `database/schema.sql` file defines the database structure (26 tables). `docs/DATABASE_SCHEMA.md` documents it with an entity relationship diagram and a data dictionary; after any schema change, regenerate it with `C:\xampp\php\php.exe tools\schema_doc.php`. Existing databases require a reviewed migration before schema changes; importing the schema will not alter their current tables.

## Testing

Tests run against a separate database whose name must end in `_test`, filled with fictitious data, so the real database is never touched. See `tests/README.md` for details.

```powershell
pwsh -File tests\setup_test_database.ps1                  # builds urban_escape_test
$env:DB_NAME = 'urban_escape_test'; $env:APP_URL = 'http://127.0.0.1:8090'
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t .                # leave running in a second window
C:\xampp\php\php.exe tests\run_tests.php --base-url=http://127.0.0.1:8090
```
>>>>>>> 821dfc5081008f5deeee9e752f0a38a164e17c2d
