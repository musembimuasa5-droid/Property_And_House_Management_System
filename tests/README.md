# Tests

Plain PHP, no extra packages. Everything runs against a separate database whose name must end in `_test`; the scripts refuse any other name, so the real database (`nairobi_property_db`) is never touched.

| File | Purpose |
| --- | --- |
| `setup_test_database.ps1` | Drops and rebuilds the test database: schema, seed, every migration, then `fixtures/synthetic_data.php` |
| `fixtures/synthetic_data.php` | Fictitious tenants, leases, charges, payments, deposits, condition reports, repairs, complaints and enquiries |
| `fixtures/photos/` | Synthetic images labelled "SYNTHETIC TEST PHOTO" used as inspection and repair photos |
| `run_tests.php` | 71 test cases: unit (UT), integration (IT, rolled back), system (ST, over HTTP), security (SEC) and non-functional (NFR) |
| `lib/TestKit.php` | Assertions and a small HTTP client that keeps a session and reads CSRF tokens |

## Running

```powershell
pwsh -File tests\setup_test_database.ps1
$env:DB_NAME = 'urban_escape_test'; $env:APP_URL = 'http://127.0.0.1:8090'
C:\xampp\php\php.exe -S 127.0.0.1:8090 -t .        # second window, leave running
C:\xampp\php\php.exe tests\run_tests.php --base-url=http://127.0.0.1:8090 --json=results.json
```

Options: `--suite=UT,IT` runs some suites, `--only=ST-01,ST-02` runs single cases, and `--apache-url=http://localhost/Property_And_House_Management_System` enables SEC-13, which checks the Apache `.htaccess` rules (the PHP development server ignores them).

System tests change data, so rebuild the test database before every full run. Test accounts use `TEST_PASSWORD` (default `UrbanEscape-Test-2026`); they exist only in the test database. The fixture copies its photos into `uploads/maintenance` and `uploads/condition` each time it runs.
