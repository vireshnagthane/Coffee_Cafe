# Product Requirements

## Product

**Brew & Bean Coffee Shop Management System** is a local-first web application for managing the daily operations of one cafe. It runs on PHP and MySQL/MariaDB through XAMPP and is intended for use on the local computer or trusted local network, not as an internet-facing production service.

## Users

- **Administrator:** manages the cafe, team accounts, settings, purchases, expenses, reports, and order cancellation.
- **Cashier:** uses the point of sale and day-to-day catalog, customer, order, and inventory views according to the permissions enforced by the application.

## Goals

1. Record sales using the current database product prices, selected customer (optional), payment method, discount permissions, and configured tax.
2. Keep sales and inventory records consistent when an order or purchase is completed.
3. Provide practical cafe administration for products, categories, ingredients, recipes, suppliers, customers, expenses, and settings.
4. Give administrators operational summaries and printable order receipts.
5. Make local installation, first-use setup, and backup understandable to a non-developer using XAMPP.

## Functional requirements

- Authenticate active users with password hashes and enforce administrator/cashier authorization on the server.
- Let visitors create and immediately use a cashier account; reserve administrator creation for the local setup utility or an existing administrator.
- Let signed-in staff explicitly sign out of the authenticated workspace.
- Create and maintain categories, products, ingredients, recipes, suppliers, customers, purchases, and expenses.
- Support product image uploads with validation and generated filenames.
- Build POS orders from available products; recalculate prices, tax, discount, and totals on the server.
- Record orders transactionally, deduct recipe ingredient stock, and prevent duplicate form submissions.
- Track stock movements for sales, purchases, manual adjustments, wastage, and order cancellations.
- Allow administrators to cancel eligible orders once and restore only stock recorded for the original sale.
- Configure cafe details, currency, tax rate, and receipt footer.
- Show dashboard and date-filtered reports for sales, orders, products, stock, purchases, and expenses.
- Let staff share short shift handover notes on the dashboard and mark open notes complete.
- Let administrators inspect, edit, and delete application database records with safeguards for passwords, active administrator access, and relational integrity.
- Provide optional, clearly labeled demonstration catalog and sales data without creating an administrator with a default password.

## Quality and operational requirements

- Run on PHP 8.1 or newer with PDO MySQL, `mbstring`, and `fileinfo`, plus a compatible MySQL/MariaDB server.
- Use prepared database statements, CSRF tokens for state-changing forms, server-side validation, and role checks.
- Use strict cookie-only sessions, refresh active-account/role authorization on protected requests, apply browser security headers and a restrictive Content Security Policy, and prevent caching of dynamic pages.
- Keep money in decimal database columns and perform checkout totals with integer minor units.
- Preserve referential integrity and audit history using InnoDB, foreign keys, and stock movement records.
- Work without external JavaScript or CSS services and remain usable on narrow screens.
- Explain setup, database import, administrator bootstrap, local security controls, deployment limitations, troubleshooting, and backup in `README.md`.

## Out of scope and known limitations

- Payment processing, UPI/card gateway integration, and external refunds.
- Multi-cafe tenancy, cloud hosting, or production hardening.
- Complete accounting, profit reporting, or weighted-average inventory valuation.
- Automatic ingredient deductions for products that have no configured recipe.
- Internet-facing deployment security, login rate limiting, and deployment-specific secrets/monitoring infrastructure.

See [README.md](./README.md) for installation, detailed behavior, limitations, and the manual validation checklist.
