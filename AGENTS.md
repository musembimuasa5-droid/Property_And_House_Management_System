# AGENTS.md: Urban Escape Property & House Management System

Project rules for any AI coding agent (Claude Code reads this through `CLAUDE.md`). These rules add to the
global rules in `~/DevTools/ai/AGENTS.md`.

## What this is
- Final-year KCA University project (student: Musembi Muasa; supervisor: Dr. Charles Kihungi). It is **academic
  work**: the approved proposal is `../Musembi Muasa proposal final.pdf`. The KCA chapter guides are in
  `../REFERENCES DOCUMENTS/`, and the audit, WIP chapters, validation and test evidence are in `../Documentation/`.
- A PHP 8 and MariaDB/MySQL web app for Nairobi rental property, with a public listings site and portals for
  Super Admin, Property Manager, Accountant, Landlord, Caretaker and Tenant.
- **Scope limits from the proposal:** residential only. Payments are *recorded* by staff and **never** collected
  through M-Pesa/Daraja or any other gateway. There is no native mobile app.
- The current gap list is in `docs/SYSTEM_COMPLETENESS_REPORT.md`. Update it when you close a gap.

## Stack and paths
- Plain PHP 8.2 (XAMPP) with PDO and no Composer packages; MariaDB 10.4 locally; Apache `.htaccess` rules; Tailwind CSS v3
  compiled to `assets/tailwind.css` (committed); `assets/app.css` and `assets/app.js`; Font Awesome from cdnjs.
- The repo lives on `D:` and `C:\xampp\htdocs\Property_And_House_Management_System` is a **junction** to it.
  App URL: `http://localhost/Property_And_House_Management_System/`.
- Databases: `nairobi_property_db` (developer's own data; never modify it without asking) and
  `urban_escape_test` (disposable, rebuilt by the test script).
- `php` and `mysql` are not on PATH: use `C:\xampp\php\php.exe` and `C:\xampp\mysql\bin\mysql.exe`.

## Commands (PowerShell)
```powershell
# Syntax-check every PHP file
git ls-files '*.php' | ForEach-Object { C:\xampp\php\php.exe -l $_ } | Select-String -NotMatch 'No syntax errors'

# Tests: rebuild the test DB, serve, run (second window keeps the server running)
pwsh -File tests\setup_test_database.ps1
$env:DB_NAME = 'urban_escape_test'; $env:APP_URL = 'http://127.0.0.1:8090'
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t .
C:\xampp\php\php.exe tests\run_tests.php --base-url=http://127.0.0.1:8090 --json=results.json
#   --suite=UT,IT   --only=ST-01   --apache-url=http://localhost/Property_And_House_Management_System (enables SEC-13)

# Rebuild Tailwind after adding new utility classes (needs Node)
npx tailwindcss@3.4.16 -i assets/src/tailwind.input.css -o assets/tailwind.css --minify

# First Super Admin on a fresh (production-style) database
C:\xampp\php\php.exe tools\create_admin.php --name="Full Name" --email=admin@example.com --phone=+254700000000
```
System tests change data, so rebuild the test database before every full run. Test scripts refuse any
database whose name does not end in `_test`.

## Architecture
- `config/config.php` loads `.env`, starts the session (30-minute idle timeout) and sets the timezone (Africa/Nairobi).
  `config/database.php` provides `db()`, a PDO singleton. `config/auth.php` holds login and role checks, the audit
  log and sign-in throttling.
- `config/modules.php` is **the single registry** of modules, roles and sidebar entries. To add a module, add it
  here and use `require_module()`. Never hard-code role lists in pages.
- `includes/crud.php` is a configuration-driven CRUD engine. A page in `admin/` describes its table (fields, list
  SQL, filters, hooks) and calls `crud_run()`. The engine handles validation, CSRF, transactions, audit logging,
  delete guards, pagination and CSV export. Put business rules in hooks (`prepare`, `after_save`,
  `before_delete`, `after_delete`) and throw `UserFacingException` for messages that are safe to show.
- `includes/resources.php` (properties, units) and `includes/tenancy_resources.php` (tenants, leases, rent charges,
  payments, deposits, utility readings) hold shared module definitions. Called with no owner they give the staff
  version; with an owner (`['landlord_id', id]` or `['caretaker_id', user id]`) every query, option list and export
  is scoped to that owner's properties. `admin/`, `landlord/` and `caretaker/` pages are one-line wrappers around them.
  Condition reports use the same idea through `$conditionOwner` in `includes/condition_reports_page.php`.
- `includes/finance.php` holds the billing rules: oldest-first allocation recomputed in cents, charge generation,
  occupancy sync, the deposit ledger and arrears reminders. After any change to payments, charges or readings, call
  `recalculate_lease_balances()`.
- `includes/reports.php` and `reports_page.php` hold the nine reports; `includes/dashboard.php` holds the KPIs.
  Layout comes from `includes/layout_top.php` and `layout_bottom.php` (signed-in pages) and `header.php`,
  `navbar.php` and `footer.php` (public pages).
- Private uploads (`uploads/maintenance`, `condition`, `documents`, `tenants`) are denied by `.htaccess` and
  served only through `account/attachment.php` after an access check. `uploads/properties` is public.

## Conventions
- Use prepared statements for every value. Table and column names come only from code.
- Escape output with `e()`. Put `csrf_field()` in every POST form and check it with `csrf_validate()`. Use
  Post/Redirect/Get with `set_flash()` and `redirect()`.
- Log every state change with `logAuditAction($action, $module, $id, $description, $old, $new)`. Secrets are removed
  automatically.
- Money uses `DECIMAL(12,2)` in SQL and is displayed with `format_currency()` (KSh). Dates go through
  `format_date()`.
- Records with financial history are never hard-deleted. Block the delete and tell the user to archive or deactivate.
- Schema changes need a new numbered `database/migrations/00N_*.sql` that is safe to re-run (`IF NOT EXISTS`),
  the same change mirrored in `database/schema.sql`, and a matching step in `tests/setup_test_database.ps1`.
- Keep the existing code style: small functions, a short comment explaining *why*, no frameworks, and no new
  dependencies without a stated reason.
- UI: Tailwind utilities plus the classes in `app.css` (`btn`, `btn-primary`, and so on), labelled inputs (`for`/`id`),
  mobile-first, checked at 360 px with no horizontal scroll.
- Add or extend a test in `tests/run_tests.php` (UT/IT/ST/SEC/NFR) for every behaviour change.

## Academic integrity
- Never invent survey results, UAT results, test results, screenshots or references. Survey and interview
  responses were **not retained**, so no statistics may be reported. Test evidence must come from saved runs.
- Use synthetic data only (the fixtures in `tests/fixtures/`). Never show seed passwords in docs or screenshots.
- Documentation follows the KCA guides and the formatting decided in the Phase 1 audit (A4, Times New Roman 12,
  1.5 spacing, APA 7th). TETILE is a reference project on the same topic, so never reuse its text.

## Known state (21 Nov 2026, re-check before relying on it)
- XAMPP MariaDB fails to start (InnoDB errors since 20 Nov). Back up `C:\xampp\mysql\data` before attempting recovery;
  other projects share this server.
- `nairobi_property_db` has not had migrations 002 and 003 applied, so sign-in fails there until they are applied after a backup.
- While XAMPP's MariaDB is down, tests can run on a throwaway server: create a data folder with
  `C:\xampp\mysql\bin\mysql_install_db.exe --datadir=<folder> --port=3307`, start `mysqld.exe --defaults-file=<folder>\my.ini`,
  then `pwsh -File tests\setup_test_database.ps1 -Port 3307` and set `$env:DB_PORT = '3307'` for the server and runner.
- `main` is ahead of `origin/main`, and the branch `origin/improve/tenant-complaint-actions-password-toggle` is
  unmerged. Ask before pushing or merging.
