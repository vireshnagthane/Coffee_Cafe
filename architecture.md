# Architecture

## Runtime and dependencies

- **Application:** server-rendered PHP 8.1+ pages with native HTML, CSS, and JavaScript.
- **Database:** MySQL/MariaDB through PDO with the `pdo_mysql` driver.
- **Local hosting:** Apache and MySQL/MariaDB from XAMPP; the expected URL is `http://localhost/coffee_management/`.
- **External runtime dependencies:** none. The browser UI does not load remote libraries.

## Request flow

1. A browser requests a PHP page from Apache.
2. The page loads `config/app.php`, which configures strict cookie-only sessions and emits the shared security headers. Protected pages load `includes/auth.php`, which verifies the account is still active and refreshes its role from the database before applying page-level role checks.
3. Database-backed pages load `config/database.php` and use the shared `db(): PDO` connection.
4. A POST handler validates the request and CSRF token. Multi-row business operations use PDO transactions and database constraints.
5. PHP renders HTML using shared escaping, navigation, and footer helpers. `assets/css/style.css` supplies the responsive interface; `assets/js/app.js` supplies client-side interactions such as POS cart calculations and dashboard chart drawing.
6. The authenticated page header offers a labeled top-right sign-out form that posts to `logout.php` with the session CSRF token; the handler clears the session and redirects to sign-in. Confirmation and print actions use the local external JavaScript file so the Content Security Policy does not require inline script exceptions.

## Main areas

- **Entry and authentication:** `index.php`, `login.php`, `logout.php`, `includes/auth.php`, and `includes/csrf.php`.
- **Business screens:** root PHP pages for dashboard, catalog, POS, orders/receipts, inventory, purchases, customers, suppliers, expenses, reports, settings, users, and the administrator-only `database.php` record browser.
- **Shared code/configuration:** `includes/` and `config/`.
- **Database lifecycle:** `database/coffee_management.sql` creates the schema and default settings. `sample_data.sql` and `seed_demo_sales.php` are optional demo-data tools. `create_admin.php` and `create_admin.ps1` bootstrap the first administrator.
- **Static assets:** `assets/css/`, `assets/js/`, and `assets/images/`.

## Data model

The schema is defined in `database/coffee_management.sql`. Its main groups are:

- **Identity and configuration:** `users`, `settings`.
- **Catalog and recipes:** `categories`, `products`, `ingredients`, `product_recipes`.
- **Sales:** `customers`, `orders`, `order_items`, `ingredient_stock_movements`.
- **Procurement:** `suppliers`, `purchases`, `purchase_items`, `ingredient_stock_movements`.
- **Operating costs:** `expenses`.
- **Shift coordination:** `shift_handover_notes`, linked to the staff member who created a note and, when completed, the staff member who resolved it.

The schema uses InnoDB, foreign keys, unique constraints, decimal quantities and amounts, and order-item snapshots. The database is the source of truth for prices, permissions, stock, and persisted totals; browser calculations are for immediate feedback only.

## Transaction boundaries and invariants

- **POS checkout:** validate input, lock relevant product and ingredient rows, derive totals from stored prices/settings, verify stock, then commit the order, order items, and stock movements together.
- **Purchase receipt:** lock the purchase and affected ingredients, then change purchase state, stock, and stock movement records together; receiving twice must not add stock twice.
- **Order cancellation:** lock and verify the order, retain its history, and restore only stock associated with its sale movements once.
- Shift handover notes are created and completed by authenticated staff through CSRF-protected dashboard POST actions; unresolved notes are shown newest first.
- The database record browser derives tables, columns, and primary keys from the connected application schema, limits listings to 50 rows per page, uses prepared value parameters, and quotes validated metadata identifiers. Password hashes are never selected or editable. Deletes require CSRF validation and explicit confirmation, protect the signed-in and last active administrator accounts, and are subject to foreign-key constraints.
- When schema or SQL behavior changes, update `database/coffee_management.sql` and any affected seed/bootstrap scripts and setup documentation together. Do not silently delete or replace business data.

For an existing database, apply `database/migrations/001_shift_handover_notes.sql`; fresh installations receive the same table from the main schema script.

## Configuration and deployment

- `config/app.php` sets `APP_BASE_URL`; it must match the folder name beneath the Apache document root.
- `config/database.php` accepts `COFFEE_DB_HOST`, `COFFEE_DB_NAME`, `COFFEE_DB_USER`, and `COFFEE_DB_PASSWORD`; local defaults are documented in `README.md`.
- `config/app.php` also sets a restrictive Content Security Policy and browser security headers on PHP responses, and prevents dynamic application pages from being cached.
- The `.htaccess` files deny direct web access to configuration, includes, and database scripts.
- Uploaded product images are stored under `assets/images/products/`.
- This architecture targets local XAMPP use. It does not provide login rate limiting or deployment-specific secret/monitoring infrastructure; internet-facing deployment requires a separate security and operational design.
