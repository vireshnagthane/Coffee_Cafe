# Project Tasks and Status

This file tracks verified project readiness and follow-up work. Keep statuses factual; update this file as part of future AI-assisted code changes.

## Verified locally

- [x] PHP 8.2 CLI is available and all 27 project PHP files pass `php -l`.
- [x] XAMPP PHP has `PDO`, `pdo_mysql`, `mbstring`, and `fileinfo` enabled.
- [x] Apache serves the project at `http://localhost/coffee_management/`; the root redirects to the sign-in page when no session exists.
- [x] MariaDB 10.4.32 is reachable and the `coffee_management` database contains all 15 expected application tables.
- [x] An active administrator record exists; use the credentials already configured for this local database.
- [x] Dashboard shift handover notes support posting and completing staff updates; a migration is available for existing installations.
- [x] Note creation, listing, completion, and repeat-completion behavior passed a database check in a rolled-back test transaction.
- [x] Administrator-only database record browser added with schema-derived tables, paginated reads, protected password hashes, CSRF-protected edits/deletes, and active-administrator safeguards.
- [x] Database browser rendered successfully for all 15 application tables; edit, confirmed-delete, and administrator-protection checks passed using test requests.
- [x] Authenticated page header has a visible top-right sign-out button that submits the existing CSRF-protected logout action; the rendered form includes its token and GET requests to the logout endpoint are rejected.
- [x] Fixed product creation rejecting new products because their ID field is intentionally blank; exercised the save handler with an empty ID and verified the product was inserted and the temporary test record removed.
- [x] Corrected pending-purchase confirmation prompts so receiving stock and cancelling a purchase each describe their own action.
- [x] Authenticated browser checks: all 14 management pages loaded with HTTP 200 and no JavaScript errors; POS add-to-cart updated the cart/total and enabled checkout; purchase-line add/edit/remove updated its payload and total; purchase action prompts matched their respective actions.
- [x] Restyled the top-right sign-out control with the shared button/button-light styles so it matches the rest of the application.
- [x] Added strict cookie-only session settings, browser security headers, a restrictive Content Security Policy, no-store PHP responses, and versioned CSS/JavaScript asset URLs.
- [x] Protected authenticated requests now refresh the user's current account status and role from the database; inactive sessions are revoked and authorization fails closed when verification is unavailable.
- [x] CSRF validation rejects malformed non-string tokens without a type error; successful sign-in rehashes passwords when the configured password algorithm requires it.
- [x] Removed inline JavaScript event handlers; purchase/delete confirmations and print controls continue to work through the external app script.
- [x] Verified live security headers; all 14 authenticated management pages returned HTTP 200 with CSP and no browser console errors; purchase-specific confirmation prompts and the report print action worked under CSP.
- [x] Deactivating a signed-in test account redirected it to sign-in; malformed and valid CSRF tokens behaved as expected; temporary security-test accounts were removed.
- [x] Added a sign-in-page account-creation link and CSRF-protected staff registration; verified valid submission creates a cashier account. Registration now activates and signs in the new cashier immediately; public registration cannot create administrators.
- [x] Corrected username form patterns for modern browser regex validation on both public registration and Team accounts.

## Recommended next checks

- [ ] Sign in using the existing administrator credentials and perform the manual workflow checklist in `README.md`.
- [ ] Verify the database browser in the signed-in browser and confirm foreign-key-protected deletion against a disposable record.
- [ ] Test shift note creation and completion while signed in.
- [ ] Verify product/recipe setup, checkout, purchase receiving, cancellation, reports, and receipt printing with non-production data.
- [ ] Sign in and verify product creation in the browser using a new name and existing category.
- [ ] Manually verify purchase receiving and cancellation with disposable purchase data.
- [ ] Configure a dedicated least-privilege MySQL account before using shared local real business data.
- [ ] Do not expose the XAMPP server to the internet; login rate limiting, deployment secret management and production HTTPS/monitoring remain out of scope.
- [ ] Make a database and project-file backup before using real business data.

## Current limitations

- [ ] No automated application test suite is present; business workflows still need the documented manual checks.
- [ ] Payment gateway processing, external refunds, complete accounting/profit reporting, and production deployment hardening are out of scope. See `prd.md` and `README.md`.

## Task update convention

For each future assistant-made project change, mark only verified work complete, add relevant unfinished follow-up items, and remove or close items that no longer apply. Do not treat this document as a substitute for a test report.
