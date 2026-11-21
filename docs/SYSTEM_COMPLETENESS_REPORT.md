# Urban Escape PHMS: System Completeness and Gap Report

**Date:** 21 November 2026 · **Code baseline:** commit `7379f38` (`main`) · **Measured against:** the approved proposal (`Musembi Muasa proposal final.pdf`, 5 October 2026), the Phase 1 audit (10 Nov) and the Phase 3 validation report (10 Nov).

---

## 1. Verdict

**The software is close to feature-complete for specific objectives SO1 to SO4, but the project as a whole is not complete.**

- **Built and working (last verified 11 Nov 2026, 72/72 automated tests passing):** property and unit registry, tenants and leases, rent charges, payment recording with oldest-first allocation, receipts, arrears reminders, utility billing, maintenance with photos and history, condition reports, the deposit ledger, public listings with viewing requests, enquiries, nine reports and an audit log.
- **Blocking right now:** the local MariaDB server does not start, and the main development database is one migration behind the code. Nothing can be demonstrated until both are fixed (Section 3).
- **Not done:** SO5 (user acceptance testing and usability review), cloud deployment, the Chapter 8 user manual and the combined final report.
- **Functional gaps:** 6 high (3 since closed: F4, F5, F6 on 21 Nov 2026), 7 medium and 5 low-priority gaps against the proposal and normal expectations for this kind of system (Section 5). There are also 7 security or robustness weaknesses (Section 6) and 5 clean-up items (Section 7).

## 2. What was checked

| Check | Result |
| --- | --- |
| Read the proposal, the Phase 1 audit, the validation report and the README | Done |
| Read the schema, both migrations, config, auth, the CRUD engine, finance rules and every portal's pages | Done |
| `php -l` on all 83 tracked PHP files (PHP 8.2.12) | **0 errors** |
| Automated test suite (`tests/run_tests.php`) | **Not run today**: MariaDB is down. Last recorded run: 11 Nov 2026, commit `7379f38`, 72 PASS, 0 FAIL (`Documentation/06_Quality_Assurance/Test_Run_2026-11-11/run4_test_results.json`) |
| Browser and UI check | Not run today (needs the database) |
| Git state | `main` is 4 commits ahead of `origin/main`; one remote branch has unmerged work |

Status words used: **done** · **partial** · **missing** · **blocked**.

## 3. Blockers (fix first)

| ID | Blocker | Evidence | What to do |
| --- | --- | --- | --- |
| **B1** | **XAMPP MariaDB will not start.** Since 20 Nov 2026 InnoDB has reported "log sequence number is in the future" (possible corruption), and later starts abort with "innodb_system data file 'ibdata1' must be writable". The log files have been renamed `ib_logfile*.corrupt-2026-11-20`. | `C:\xampp\mysql\data\mysql_error.log`; port 3306 not listening; no `mysqld` process | 1. Copy the whole `C:\xampp\mysql\data` folder somewhere safe before touching anything (other projects share this server). 2. Check that no other MySQL/MariaDB service or antivirus is locking `ibdata1`, and that XAMPP has write access. 3. If it still fails, restore from a backup or use MariaDB's `innodb_force_recovery` to dump the data. 4. The test database can always be rebuilt from `schema.sql`, `seed.sql` and the migrations. **Decide this yourself: a wrong recovery step can lose data for every XAMPP project.** |
| **B2** | **`nairobi_property_db` lacks migration 002.** Its data folder has no `condition_reports`, `condition_report_items`, `deposit_transactions`, `maintenance_updates` or `login_attempts` tables. `public/login.php` calls `login_is_throttled()` before checking the password, so **every sign-in on this database fails with a database error**. Condition reports, deposits and the maintenance history would fail the same way. | `C:\xampp\mysql\data\nairobi_property_db\` file list; [config/auth.php:100-108](../config/auth.php#L100-L108) | After B1, back up the database and apply `database/migrations/002_condition_deposit_security.sql` (see the README, Local setup step 3). |
| **B3** | **The repository has diverged.** `main` has 4 local commits not pushed to GitHub (condition reports, deposit ledger, tests, Tailwind build). `origin/improve/tenant-complaint-actions-password-toggle` has 3 commits never merged into `main`: an About page, a Super Admin people directory, and changes to the contact, profile and complaints pages (15 files, +688/−58). | `git rev-list origin/main..main` = 4; `git rev-list main..origin/improve/…` = 3 | Agree with the team: push `main` (through a pull request), then either merge that branch after review or close it. Any useful parts, such as the About page, need rework because `main` has changed a lot since. |

## 4. Status against the proposal

| Proposal commitment | Status | Notes |
| --- | --- | --- |
| **SO1** Property, unit and tenant registry; digital tenancy records; shared rent book | **Done** | Rent review date added in migration 002. Onboarding is two separate forms (tenant, then lease), not the "guided process" the proposal describes. Landlords cannot onboard tenants (see F4). |
| **SO2** Invoices, payment recording and allocation, receipts, arrears flagging and reminders | **Mostly done** | Charges are generated by a manual button, not automatically (F3). Reminders are in-app only (F9). Allocation is oldest charge first, not "to the month" (this is documented). |
| **SO3** Maintenance tracker with photos and history; condition reports; deposit ledger | **Done** | Built in commit `9394a0a`. Caretakers are left out of inspections (F5). |
| **SO4** Vacancy listing and enquiries; reports and dashboards | **Mostly done** | One photo per property and no unit photos (F7). Arrears report has no ageing bands (F6). PDF output uses the browser's Print to PDF. |
| **SO5** Usability, security and user acceptance evaluation | **Partial** | The OWASP-aligned security tests (SEC-01 to SEC-14) exist and passed. **UAT with landlords, caretakers and tenants and the usability review have not been done** (planned 15–28 Dec). |
| Security and privacy (RBAC, audit log, Data Protection Act 2019) | **Mostly done** | See Section 6 and F12. |
| Cloud deployment (Sprint 4) | **Missing** | `deploy/` holds only an `.htaccess`. There is no hosting, HTTPS, production configuration, backup or scheduled-task setup. |
| Documentation (Chapters 4–8, final report) | **Partial** | Chapters 4–7 work in progress exists. `04_Chapter_8_User_Manual/` is empty and `05_Final_Report/` holds only figures. Proposal Section 3.9.1 still names Next.js, PostgreSQL and Prisma. |

## 5. Missing functionality

### High priority: expected by the proposal, the survey or basic use

| ID | Gap | Evidence | Impact | Recommendation |
| --- | --- | --- | --- | --- |
| **F1** | **No password recovery.** The login page says "ask management to reset it". Staff-set passwords are never forced to change on first sign-in. | [public/login.php:110](../public/login.php#L110); `admin/users.php` | Survey Q14 lists *password recovery* as an expected feature, and staff end up knowing tenants' passwords. | Without email: a Super Admin issues a one-time reset link (random token, stored hashed, expires in 30 minutes, single use). Add a `must_change_password` flag for passwords that staff set. With email: the same token sent by SMTP. |
| **F2** | **No document storage.** The `documents` table, `leases.document_path`, `expenses.receipt_path` and `tenants.profile_photo` are never used. The tenant page titled *Lease Agreements & Documents* shows lease terms but no documents. The CRUD engine only accepts image uploads. | `grep` finds no reads or writes; [tenant/documents.php](../tenant/documents.php); [includes/crud.php:196](../includes/crud.php#L196) | The signed agreement, which the proposal calls key dispute evidence, cannot be stored or shared. | Add a `file` field type (PDF/JPG/PNG) to the CRUD engine, saving to the private `uploads/documents`. Serve files through `account/attachment.php` with the same access checks. Use it for lease agreements and expense receipts, and list them on the tenant page. |
| **F3** | **Nothing runs on a schedule.** Leases past their `end_date` stay *Active*, so units stay *Occupied*. Monthly charges and arrears reminders run only when staff click a button. | No lease-expiry code; [includes/finance.php:75](../includes/finance.php#L75), [:116](../includes/finance.php#L116) | Occupancy and vacancy listings drift out of date, and the proposal's "automatic monthly invoices" are manual. | Add `tools/daily_tasks.php` (CLI only): expire ended leases and resync units, generate the current period's charges from day 1, and send reminders. Schedule it with Windows Task Scheduler locally and cron on the host. Record each run in the audit log. |
| **F4** | ~~Small landlords cannot run their own tenancies.~~ **Done (21 Nov 2026):** landlords now have scoped Tenants, Leases, Rent Charges, Payments with receipts, Deposit Ledger, Utility Readings and Condition Reports, plus tenant statements. | `includes/tenancy_resources.php`; migration 003 (`tenants.landlord_id`) | Closes the "fit for small owner-managed properties" gap. | Tests ST-39 to ST-42, IT-09, SEC-15. Landlords cannot delete payments or deposit entries. |
| **F5** | ~~Caretakers have no role in inspections.~~ **Done (21 Nov 2026):** each property has an optional caretaker (migration 003). Caretakers see those units, record move-in/move-out reports (management is notified) and meter readings, and add a note to repair updates. | `caretaker/`; `includes/condition_reports_page.php` | Delivers the caretaker workflow promised in proposal Section 1.6. | Tests ST-43 to ST-45, SEC-16. Still open: a formal manager *approval* step for caretaker reports. |
| **F6** | ~~No arrears ageing.~~ **Done (21 Nov 2026):** the arrears report splits each balance into Not yet due / 1–30 / 31–60 / 61–90 / Over 90 days and adds an ageing summary, on screen and in the CSV. | `arrears_age_band()` in `includes/reports.php` | Matches the proposal's "arrears ageing". | Tests UT-11, IT-10, ST-46. |

### Medium priority: completes a workflow

| ID | Gap | Evidence | Recommendation |
| --- | --- | --- | --- |
| **F7** | **Listings are thin.** There is one photo per property: no gallery and no unit photos. Units have no *publish/hide* switch, so every vacant unit is public. The viewing form ignores the `viewing_requests.unit_id` column. Contact enquiries are not linked to a property. | `properties.property_image`; [public/property-details.php:35](../public/property-details.php#L35) | Add a `property_photos` table with up to 6 photos per property, a unit selector on the viewing form, and an optional `property_id` on enquiries. |
| **F8** | **There is no invoice document.** Rent charges act as invoices, but neither tenants nor staff can print a per-charge invoice the way they can a receipt. | `admin/receipt.php` exists; no invoice view | Add an invoice view that reuses `includes/receipt_view.php`. |
| **F9** | **Notifications reach only portal users, inside the app.** No email or SMS is sent. Tenants without a login get no arrears reminders (they are counted as "skipped"). Visitors who request a viewing are never told the outcome by the system. | [includes/finance.php:116-137](../includes/finance.php#L116-L137) | Within scope: send email by SMTP (credentials in `.env`, with a timeout and error logging). SMS needs a paid gateway, so treat it as future work. At minimum, document the limitation in Chapters 4 and 8. |
| **F10** | **No lease renewal workflow.** A *Renewed* status exists, but nothing creates the next lease or carries over the deposit, and nothing reminds anyone of the stored `rent_review_date`. | [admin/leases.php](../admin/leases.php) | Add a *Renew* row action that copies the lease with new dates and rent and moves the deposit balance. Show upcoming rent reviews on the Lease expiry report. |
| **F11** | **Status-only workflows.** Tenants can be set to *Evicted* or *Blacklisted*, but nothing checks these when a new lease is created. | [admin/tenants.php](../admin/tenants.php), [admin/leases.php](../admin/leases.php) | Block or warn when activating a lease for a Blacklisted tenant. Eviction processing is out of scope (proposal 1.5). |
| **F12** | **Data protection rights are stated but have no tools.** The privacy notice lists data subject rights, but there is no way to export or anonymise a person's data. Consent is recorded only as an audit-log entry, with no consent timestamp or notice version on the user. The retention periods in the notice are not enforced. | [public/privacy.php](../public/privacy.php); `users` table | Add `privacy_accepted_at` and `privacy_version` to `users`. Add a Super Admin action that exports one tenant's records as CSV, and one that anonymises former tenants after the retention period while keeping financial totals. |
| **F13** | **No backup or restore** from the app or in the docs, beyond one `mysqldump` line in the README. | — | Document a backup routine (a nightly `mysqldump` plus a copy of `uploads/`) in a deployment guide. B1 shows why this matters. |

### Low priority or future work (outside the proposal's core scope)

- Late-payment penalties and interest (the proposal mentions competitors offering them; it does not require them).
- M-Pesa Daraja STK push and C2B reconciliation. This is **explicitly excluded** by the proposal (Sections 1.5 and 1.7.2), so list it only as future work.
- Bulk import of tenants and units from CSV, to help landlords moving from spreadsheets.
- Electricity sub-meter billing: only the billing-method label is stored; utility types cover water and garbage.
- A guided onboarding wizard (tenant → lease → deposit received → move-in report) that chains the existing forms.

## 6. Security and robustness weaknesses

The baseline is strong. Prepared statements, output escaping, CSRF tokens, bcrypt, session renewal, throttling, upload checks and private photo serving are all present and tested. These gaps remain:

| ID | Weakness | Evidence | Fix |
| --- | --- | --- | --- |
| **S1** | **Changes to an account do not reach sessions already open.** Role and status are read from the session only. A suspended or demoted user keeps their access until they sign out or sit idle for 30 minutes, and an active user can keep it indefinitely. | [config/auth.php:36-55](../config/auth.php#L36-L55) | On each request, re-read `users.status` and `role_id` with one indexed query. Sign the user out when the account is no longer *Active* or the role has changed. |
| **S2** | **No HTTP security headers.** There is no `Content-Security-Policy`, `X-Frame-Options`/`frame-ancestors`, `Referrer-Policy`, or HSTS in production, so pages could be framed (clickjacking). | `grep` finds none | Add these headers in the root `.htaccess`, or in `config/config.php` once for every page. |
| **S3** | **Payments can be edited after a receipt has been issued.** Property Managers and Accountants can change the amount or lease of a recorded payment. The change is audit-logged, but the printed receipt no longer matches the record. | [admin/payments.php](../admin/payments.php); `allow_edit` defaults to true ([includes/crud.php:63](../includes/crud.php#L63)) | Set `allow_edit => false` for payments. Add a *Reverse* action that records a negative entry with a reason, which is standard bookkeeping. |
| **S4** | **The public viewing-request form is weakly validated.** It has no length checks, so a name over 100 characters triggers an uncaught database error under strict SQL mode. Invalid input gives no message, a CSRF failure shows in the *success* box, and there is no spam trap (the Contact form has one). | [public/property-details.php:24-37](../public/property-details.php#L24-L37) | Copy the Contact form's validation, error list and honeypot. |
| **S5** | **The tenant profile update is not validated.** The phone number can be blank or too long (a database error), the change is not audit-logged, and the labels are not linked to their inputs. | [tenant/profile.php:17-31](../tenant/profile.php#L17-L31) | Use the same phone regex as `register.php`, call `logAuditAction()` with the old and new values, and add `for`/`id` pairs. |
| **S6** | **There is no global error handler.** With `APP_DEBUG=false`, an uncaught exception shows a blank page. | No `set_exception_handler` | Add one in `config/config.php` that logs the error and shows a friendly 500 page. Add `ErrorDocument 404/403` pages as well. |
| **S7** | **A password change does not end other sessions,** and there is no breached-password check. | [account/settings.php](../account/settings.php) | Store a `password_changed_at` value and reject sessions that started before it. This can share the per-request check from S1. |

## 7. Clean-up and maintainability

| ID | Item | Recommendation |
| --- | --- | --- |
| H1 | `api/create.php` is a JSON payment endpoint that nothing calls. It duplicates the payment logic, skips the future-date check, and adds attack surface. | Delete it, or document and test it. |
| H2 | `uploads/*.sample.txt` are leftover PHP snippets with `// Save … via PDO...` placeholders. They are blocked from the web but are dead code. | Delete them. |
| H3 | Font Awesome loads from cdnjs ([includes/header.php:17](../includes/header.php#L17)), so all icons disappear on an offline demo. | Bundle the CSS and webfonts locally in `assets/vendor/`, as was done for Tailwind. |
| H4 | MariaDB 10.4 (bundled with XAMPP) is end of life. | Use a supported LTS release (for example MariaDB 10.11 or 11.4, or MySQL 8.4) on the host, and confirm current support dates in the official docs. |
| H5 | The schema is defined twice (`schema.sql` and the migrations), which can drift apart. | Keep the "fresh install = upgraded install" comparison from the validation phase as a repeatable script in `tests/`. |

## 8. Project deliverables still outstanding

1. **UAT and usability review (SO5):** recruit consenting landlords, caretakers and tenants. Run scripted tasks on the test database, then the TAM questionnaire (already drafted in WIP Appendix B), plus SUS if the supervisor wants it. Keep the raw responses. **Report only real results.**
2. **Deployment:** choose a host (budget KSh 4,500 for hosting plus KSh 2,000 for the domain). Write `deploy/README.md` covering the PHP/MariaDB versions, `.env` for production, importing `reference_data.sql`, `tools/create_admin.php`, HTTPS, the scheduled task from F3, and backups from F13.
3. **Chapter 8 user manual,** one section per role. The 36 screenshots from Phase 3 can be reused if they still match the interface.
4. **Final report (Chapters 1–8):** correct proposal Section 3.9.1 (the stack is PHP 8 and MariaDB/MySQL, not Next.js, PostgreSQL and Prisma). Describe the real iteration history from Git, and list F1–F13 that are not built as limitations or future work.
5. **Replace the sample photos** with licensed or the student's own photos before going public (README, *Sample data and photos*).

## 9. Recommended order

| Step | Work | Why first |
| --- | --- | --- |
| 1 | B1 → B2 → re-run the full test suite and save the results | Nothing can be demonstrated or documented until the database works |
| 2 | B3: push `main` through a pull request, then decide on the unmerged branch | Keeps the team's copies the same |
| 3 | S1, S3, S4, S5, H1, H2 | Small fixes that close real holes before UAT participants use the system |
| 4 | F3 (daily tasks), F2 (documents), F1 (password reset); F6 done 21 Nov 2026 | Closest to the proposal's wording; each takes hours, not days |
| 5 | ~~F4 and F5~~ done 21 Nov 2026 | Needed to defend the "small landlord" and "caretaker" claims |
| 6 | Deployment, then UAT, then Chapter 8 and the final report | Sprint 4 deliverables, 15–28 Dec |
| 7 | F7–F13 as time allows; otherwise record them as future work | Improves quality; not required by the objectives |

Every fix should come with a test in `tests/run_tests.php` and a re-run on `urban_escape_test`. Each schema change should be a new numbered migration (`003_…sql`) and be mirrored in `schema.sql`.
