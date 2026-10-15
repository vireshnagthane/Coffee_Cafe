# Project Tasks and Status

This file tracks verified project readiness and follow-up work. Keep statuses factual; update this file as part of future AI-assisted code changes.

## Verified locally

- [x] PHP 8.2 CLI is available and all 26 project PHP files pass `php -l`.
- [x] XAMPP PHP has `PDO`, `pdo_mysql`, `mbstring`, and `fileinfo` enabled.
- [x] Apache serves the project at `http://localhost/coffee_management/`; the root redirects to the sign-in page when no session exists.
- [x] MariaDB 10.4.32 is reachable and the `coffee_management` database contains all 15 expected application tables.
- [x] An active administrator record exists; use the credentials already configured for this local database.
- [x] Dashboard shift handover notes support posting and completing staff updates; a migration is available for existing installations.
- [x] Note creation, listing, completion, and repeat-completion behavior passed a database check in a rolled-back test transaction.

## Recommended next checks

- [ ] Sign in using the existing administrator credentials and perform the manual workflow checklist in `README.md`.
- [ ] Test shift note creation and completion while signed in.
- [ ] Verify product/recipe setup, checkout, purchase receiving, cancellation, reports, and receipt printing with non-production data.
- [ ] Make a database and project-file backup before using real business data.

## Current limitations

- [ ] No automated application test suite is present; business workflows still need the documented manual checks.
- [ ] Payment gateway processing, external refunds, complete accounting/profit reporting, and production deployment hardening are out of scope. See `prd.md` and `README.md`.

## Task update convention

For each future assistant-made project change, mark only verified work complete, add relevant unfinished follow-up items, and remove or close items that no longer apply. Do not treat this document as a substitute for a test report.
