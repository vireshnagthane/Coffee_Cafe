# Coffee Shop Management System

A local-first coffee shop management application built with native PHP, MySQL/MariaDB, HTML, CSS and JavaScript. It uses PDO and does not need Node.js, React, WordPress, a payment gateway or an internet connection after XAMPP is installed.

## Current features

- Password-hashed administrator and cashier accounts, role checks, CSRF protection and POST-only state changes.
- A clearly labeled **Sign out** button in the top-right corner of authenticated pages, styled to match the shared light buttons.
- Database-backed dashboard, categories and product management.
- Validated product-image uploads, stored under a generated filename.
- POS with server-side prices and totals, optional customers, cash/UPI/card recording, payment status and administrator-only discounts.
- Tax-exclusive checkout using the rate configured in settings.
- Transactional sales, recipe-based ingredient deductions, stock movement history, double-submit protection and once-only cancellation restoration.
- Suppliers, purchase records, action-specific confirmation for purchase receiving/cancellation, and once-only receipt of purchased stock.
- Customers and order history, expenses, sales/stock/purchase reports, settings and printable receipts.
- Responsive offline UI with a locally drawn seven-day sales chart.
- Dashboard shift handover notes so staff can pass short updates to the next shift and mark them complete.
- Administrator-only database browser for viewing, editing, and explicitly confirmed deletion of database records.
- Optional, repeat-safe demonstration sales for the last seven days, clearly marked `DEMO-`.

This is a local XAMPP starter application, not a production deployment. Read [Known limitations](#known-limitations) before using it for real business accounting.

## Project documentation

- [Product requirements](./prd.md)
- [Architecture](./architecture.md)
- [Engineering and security rules](./rules.md)
- [Interface design](./design.md)
- [Project tasks and validation status](./task.md)

When an AI coding assistant changes this project, it must review these documents and update the ones affected in the same change. The repository instructions in `.github/copilot-instructions.md` make this a normal part of future assistant work; no separate prompt is needed.

## GitHub and sensitive files

The repository `.gitignore` excludes local environment files, machine-specific database config, database backups, uploaded product images, logs, and private keys. It does not exclude the application source, schema, or optional demonstration SQL data.

Before pushing to GitHub, especially to a public repository:

- Never commit real database passwords, `.env` files, private keys, customer/order exports, or uploaded shop images.
- Keep database passwords in the PHP process environment (`COFFEE_DB_*`); do not replace the documented local defaults with a real password in `config/database.php`.
- Review `git status` and the staged diff before committing. `.gitignore` only prevents new untracked files from being added; it does not remove a secret already committed. If a secret was pushed, rotate it immediately and remove it from Git history.
- Assume every committed source file, SQL script, and documentation page is publicly readable in a public repository.

## Requirements

- Windows and a supported XAMPP distribution with PHP 8.1 or newer.
- Apache, MySQL/MariaDB, PHP `pdo_mysql`, `mbstring` and `fileinfo`.
- The project folder at `C:\xampp\htdocs\coffee_management`.

### Check PHP and XAMPP services

1. Open **XAMPP Control Panel**.
2. Start **Apache** and **MySQL**. Both should show as running.
3. In PowerShell, check the PHP version:

   ```powershell
   C:\xampp\php\php.exe -v
   ```

4. Confirm PDO's MySQL driver is enabled:

   ```powershell
   C:\xampp\php\php.exe -m
   ```

   Look for `PDO`, `pdo_mysql`, `mbstring`, and `fileinfo`.

XAMPP installation paths and MySQL credentials vary. If PHP is installed elsewhere, use its actual `php.exe` path below and in the administrator setup command.

## Install and configure

1. Copy this project folder to:

   ```text
   C:\xampp\htdocs\coffee_management\
   ```

2. In a browser, open [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/).
3. Choose **New**, enter `coffee_management` as the database name, select `utf8mb4_unicode_ci` if offered, and choose **Create**. The schema also contains `CREATE DATABASE IF NOT EXISTS` and `USE`, so importing the SQL file can create the database when the account has permission.
4. Select the `coffee_management` database in phpMyAdmin.
5. Select the **Import** tab, choose `database/coffee_management.sql`, leave the format as SQL, and choose **Import** or **Go**. A successful import creates all application tables and the initial INR settings. Do not create tables manually.
6. Optional: to load clearly named demonstration menu and ingredient records, import `database/sample_data.sql` through the same Import tab. Import this only into a database without real business data; the script intentionally updates matching sample-name records and recipe values when rerun. It also adds inactive `sample.*` cashier records for display in Team accounts. Those demo entries cannot sign in and are not real staff accounts.
7. Optional: after the sample menu is loaded, seed 14 sample orders over the last seven days. Run this in PowerShell:

   ```powershell
   C:\xampp\php\php.exe "C:\xampp\htdocs\coffee_management\database\seed_demo_sales.php"
   ```

   Orders are marked `DEMO-` in the dashboard, order history, receipts and reports. The seeder uses existing recipe stock in one transaction and skips existing sample orders on rerun, so it will not double-deduct stock. This is demonstration data, not real sales.
8. Check the database connection defaults in [database.php](./config/database.php). The initial values are `localhost`, database `coffee_management`, and user `root`. An empty password is assumed only when `COFFEE_DB_PASSWORD` is not set. If your local MySQL root account has a password, set `COFFEE_DB_PASSWORD` in the environment used to run Apache/PHP, or update the local defaults in this file. Do not publish real credentials.
9. If you install the project in a folder other than `coffee_management`, update `APP_BASE_URL` in [app.php](./config/app.php) to match the URL folder.
10. Create the first administrator account from PowerShell. The password is prompted as a secure input; it is not included in the command line:

   ```powershell
   powershell -ExecutionPolicy Bypass -File "C:\xampp\htdocs\coffee_management\database\create_admin.ps1" -FullName "Cafe Administrator" -Username cafeadmin
   ```

   Use a unique password with at least 12 characters. If XAMPP is installed somewhere other than `C:\xampp`, pass its PHP executable:

   ```powershell
   powershell -ExecutionPolicy Bypass -File "C:\xampp\htdocs\coffee_management\database\create_admin.ps1" -FullName "Cafe Administrator" -Username cafeadmin -PhpPath "D:\xampp\php\php.exe"
   ```

   This setup utility refuses to create an administrator if one already exists. Do not expose the script through Apache; it explicitly exits unless run by PHP CLI.
11. Open [http://localhost/coffee_management/](http://localhost/coffee_management/) and sign in with the account you just created.
12. Open **Settings** to configure the cafe name, contact details, currency, tax rate and receipt footer. Tax is exclusive: it is calculated on subtotal minus discount, then added to the total. The default tax rate is zero.
13. Add categories and products. New products receive their IDs automatically; choose an existing category and use a unique product name. In **Inventory**, add ingredients, record opening quantities, set minimum levels and configure each product's recipe. Products with no recipe lines do not consume ingredient stock.
14. Add suppliers and customers if needed. Use the POS to record an order. A selected cash, UPI or card method is only recorded; no payment is sent to a gateway.
15. Use **Orders** to view the order and print a receipt. Verify the order, item snapshots and stock movements in phpMyAdmin.
16. Administrators can open **Database** to inspect application tables and edit records. Deleting records is permanent, requires confirmation, and may be blocked by linked records. Direct edits can bypass stock and sales workflows; back up first and use the dedicated management pages for routine work. Password hashes are not shown or editable; use Team accounts for account access and password changes.

### Upgrade an existing installation

The main schema script creates new tables on a fresh installation, but does not add tables to a database that has already been created. To enable shift handover notes on an existing installation, select the `coffee_management` database in phpMyAdmin, import `database/migrations/001_shift_handover_notes.sql`, then refresh the dashboard. The migration only creates the notes table; it does not change existing business records.

### Database connection configuration

[database.php](./config/database.php) reads these optional process environment variables and otherwise uses local defaults:

- `COFFEE_DB_HOST`
- `COFFEE_DB_NAME`
- `COFFEE_DB_USER`
- `COFFEE_DB_PASSWORD`

The connection uses `utf8mb4`, exceptions and native PDO prepared statements. Connection details are written to the PHP error log on failure; the browser receives a non-sensitive message. To locate the PHP log in XAMPP, inspect `C:\xampp\php\php.ini` for `error_log`; Apache errors are also available from **Logs** in XAMPP Control Panel.

## Project layout

```text
coffee_management/
|-- index.php
|-- login.php
|-- logout.php
|-- dashboard.php
|-- products.php
|-- categories.php
|-- pos.php
|-- orders.php
|-- order_details.php
|-- customers.php
|-- inventory.php
|-- suppliers.php
|-- purchases.php
|-- expenses.php
|-- reports.php
|-- settings.php
|-- users.php
|-- database.php (administrator-only record browser)
|-- config/
|   |-- .htaccess
|   |-- app.php
|   `-- database.php
|-- includes/
|   |-- .htaccess
|   |-- auth.php
|   |-- csrf.php
|   |-- functions.php
|   |-- header.php
|   `-- footer.php
|-- assets/
|   |-- css/style.css
|   |-- js/app.js
|   `-- images/products/.htaccess
|-- database/
|   |-- .htaccess
|   |-- coffee_management.sql
|   |-- migrations/001_shift_handover_notes.sql
|   |-- sample_data.sql (optional demo menu, ingredients and inactive sample users)
|   |-- seed_demo_sales.php (optional demo orders and stock movements)
|   |-- create_admin.php
|   `-- create_admin.ps1
`-- README.md
```

The schema uses InnoDB, foreign keys, decimal monetary values, explicit history snapshots and stock-movement records. The SQL file is safe to re-import for table initialization and default settings; it deliberately does not remove existing records.

## Testing checklist

Run each item in a local test database before entering business data.

1. **Database connection:** Start MySQL, import the schema, open the site and sign in. If the database is unavailable, verify the database name/user/password and inspect the PHP/Apache error log.
2. **Login/logout:** Verify a correct password signs in, an incorrect password does not, and the sign-out button ends the session.
3. **Administrator permissions:** Create a cashier from **Team accounts**; verify the cashier cannot open expenses, reports, settings, user management or purchase actions by entering those URLs directly.
4. **Cashier permissions:** Sign in as cashier; verify product/category viewing and POS access work while modifying those records is denied.
5. **Categories and products:** Add a category and product, edit both, search/filter, disable a product, and attempt to delete a category in use.
6. **Customer management:** Add/edit/search a customer, view order history, and create a walk-in order with no customer selected.
7. **Bill calculation:** Add multiple products/quantities, check subtotal, configured discount (administrator only), tax and final total. Change a browser-submitted price/total in developer tools; the server must still use database prices and recalculate.
8. **Ingredient deduction:** Configure a product recipe and add enough stock. Complete an order and verify the quantity and an `out`/`sale` movement.
9. **Insufficient stock:** Reduce an ingredient below the recipe requirement and attempt checkout. The order must fail and stock must not change.
10. **Duplicate order prevention:** Double-click checkout or replay the same submission. Verify only one order and one set of stock deductions are committed.
11. **Order cancellation:** As administrator, cancel a completed order. Verify stock restoration is logged once and the order status is retained as cancelled/refunded; attempt cancellation again.
12. **Purchase receiving:** Record a pending purchase, receive it, and verify stock changes by the purchase quantities. Try receiving it again and verify no second increase.
13. **Manual stock and wastage:** Record an addition and deduction, then try deducting more than available stock. Check the movement audit trail and low-stock warning.
14. **Reports:** Filter by valid dates and compare sales, product/category/payment summaries, stock, purchases and expenses with the underlying records.
15. **Receipt printing:** Print an order and confirm item names, price snapshots, tax, discount, customer and cafe settings appear.
16. **Persistence:** Stop and restart Apache/MySQL from XAMPP. Sign in again and confirm records remain in the database.
17. **Image uploads:** Try an allowed image, an oversized image and a non-image renamed as `.jpg`. Only a valid supported image of at most 3 MB should be accepted.
18. **CSRF and request method:** Submit state-changing forms normally; verify a GET request cannot delete, receive, cancel or complete anything and a missing/incorrect CSRF token is rejected.
19. **Demo sales:** Run `database/seed_demo_sales.php` twice. The first run should add 14 `DEMO-` orders; the second should add none and should not deduct stock again.
20. **Shift handover:** As a signed-in staff member, add a short note on the dashboard, confirm it appears with your name, then mark it done and confirm it leaves the open list.
21. **Database browser:** As an administrator, browse several tables, edit a disposable test record, delete it with the confirmation checkbox, and verify that deleting a linked record is blocked. Confirm password hashes never appear and the signed-in/last active administrator cannot be deleted or demoted.

## Backups

- In phpMyAdmin, select `coffee_management`, choose **Export**, use **Quick** and **SQL**, then save the export outside the XAMPP folder.
- Copy the project folder separately, including uploaded images under `assets/images/products`.
- Back up both the database export and the project files. A database export alone does not include uploaded images or PHP configuration.
- Test that a backup can be imported into a separate test database before relying on it.

## Troubleshooting

### Apache will not start / ports 80 or 443 are occupied

1. Open XAMPP Control Panel and read Apache's **Logs**.
2. In PowerShell, inspect listeners with `netstat -ano | findstr ":80"` and `netstat -ano | findstr ":443"`.
3. Identify the process in Task Manager or with `Get-Process -Id <PID>`. Stop/reconfigure the conflicting application, or change Apache's `Listen` and virtual-host port settings consistently. Do not assume the default port is free.

### MySQL will not start / port 3306 is occupied

1. Read the MySQL error log from XAMPP Control Panel (**Logs** next to MySQL).
2. Check `netstat -ano | findstr ":3306"` and identify the owning process before changing ports.
3. If another MySQL service is installed, decide which service should own the database port and configure XAMPP and phpMyAdmin to use the same port.
4. Do not delete or replace MySQL data files as a first troubleshooting step.

### Access denied, unknown database or missing table

1. Confirm MySQL is running in XAMPP and check the configured `COFFEE_DB_*` values or local defaults in `config/database.php`.
2. In phpMyAdmin, confirm the database is named exactly `coffee_management`.
3. Select it and check that tables such as `users`, `products` and `orders` exist. If not, import `database/coffee_management.sql` into that database.
4. If MySQL uses a non-default port, configure the connection DSN in `config/database.php` to include the correct port and confirm phpMyAdmin is using it too.

### HTTP 404 or incorrect paths

1. Confirm the files are under `C:\xampp\htdocs\coffee_management`, not an extra nested folder such as `coffee_management\coffee_management`.
2. Open `http://localhost/coffee_management/`.
3. If you renamed the folder, update `APP_BASE_URL` in `config/app.php`.
4. Confirm Apache's document root points to the XAMPP `htdocs` directory.

### HTTP 500, blank page, PHP syntax error or undefined variable

1. Inspect Apache's error log and the PHP `error_log` configured in `php.ini`.
2. From PowerShell, lint a file with `C:\xampp\php\php.exe -l "C:\xampp\htdocs\coffee_management\dashboard.php"`.
3. Confirm `pdo_mysql`, `mbstring` and `fileinfo` are enabled in `php.ini`; restart Apache after changing PHP extensions/configuration.
4. Keep `display_errors` disabled for normal browsing; use logs for diagnostics rather than exposing stack traces.

### Session or login issues

1. Confirm PHP sessions are enabled and the PHP session save path is writable.
2. Use the matching project URL path and clear stale localhost cookies if the session path/name changed.
3. Verify the account is active. Use the secure setup command only for the first administrator; a second administrator cannot be created by that bootstrap script.
4. If Apache cannot connect to MySQL, inspect the log and verify its environment variables. A shell's environment variables are not automatically the same as Apache's.

### Invalid database import

1. Verify the file selected is `database/coffee_management.sql`, not the optional sample data file alone.
2. Select the correct database before import. Confirm the database user can create tables and foreign keys.
3. Read phpMyAdmin's exact error. The application expects a MySQL/MariaDB server with InnoDB, `utf8mb4`, and support for the SQL features used by the schema.
4. After schema import succeeds, import `sample_data.sql` separately only if demonstration records are wanted.

### Missing CSS/images or uploads fail

1. Confirm the URL folder and `APP_BASE_URL` match.
2. For uploads, confirm Apache's PHP upload limits permit a 3 MB file (`upload_max_filesize` and `post_max_size`) and that `assets/images` is writable by Apache.
3. Only JPG, PNG and WebP images are accepted. The app creates a random filename and checks file MIME/content; renaming another file to `.jpg` is not sufficient.
4. Uploaded images are optional; the menu and POS work without them.

## Known limitations

- UPI and card selections are bookkeeping labels only; no money is processed by a payment gateway.
- Tax is exclusive and is calculated as the configured percentage of subtotal minus discount. The configured tax rate is validated from 0% through 100%; it defaults to 0%.
- Products with no configured recipe have no ingredient deductions. Configure recipes before relying on inventory accounting for a product.
- Cancelling an order is administrator-only. It retains the order record, marks an already paid order refunded in the local record, and restores only stock recorded in its sale movements. No external payment refund is sent.
- Purchase receipt updates an ingredient's displayed unit cost to that purchase's unit cost; it is not a weighted-average inventory valuation system.
- Sales by category use the product's current category, not a historical category snapshot.
- Reports intentionally do not show profit. Ingredient costing and accounting are not complete enough to present an accurate gross or net profit.
- The interface is designed for a local single-cafe XAMPP workflow. Internet-facing production use requires HTTPS, a least-privilege database account, deployment-specific secret management, server hardening, backups, rate limiting and a security review.

## Development phases completed

- **A:** Architecture and database design.
- **B:** MySQL/MariaDB schema and optional demonstration records.
- **C:** Reusable PDO connection.
- **D:** Login, logout, access control and safe initial administrator bootstrap.
- **E:** Shared layout and database-backed dashboard.
- **F:** Product/category management.
- **G–H:** POS, order processing, receipt and cancellation.
- **I–J:** Ingredient stock, recipes, suppliers and purchases.
- **K:** Customers and expenses.
- **L:** Reports and settings.
- **M–N:** PHP syntax checks and this installation/testing guide. A live database import and end-to-end browser workflow still need to be run on a machine with MySQL started.
