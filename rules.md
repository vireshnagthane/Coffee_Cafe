# Engineering Rules

These rules apply to project changes and should preserve the existing PHP/XAMPP workflow.

## Implementation

- Use the existing native PHP, MySQL/MariaDB, HTML, CSS, and JavaScript stack. Do not add dependencies or a build step unless the requirement needs one.
- Follow existing file organization and patterns. Use `declare(strict_types=1);` in PHP files and keep shared behavior in `config/` or `includes/` rather than duplicating it.
- Keep server-side validation authoritative. Never trust client-supplied prices, totals, roles, stock, or permissions.
- Escape values rendered into HTML with the shared `e()` helper. Use PDO prepared statements for values in SQL.
- Use explicit error messages/logging and repository-consistent failure handling; do not hide failed writes behind success-shaped responses.
- Use transactions and row locks for operations that must update multiple related business records atomically.
- Preserve history and idempotency for orders, cancellations, purchases, and stock movements.

## Security and data handling

- Require authentication and the narrowest appropriate role on every protected page and action.
- Recheck the current active account and role on authenticated requests; fail closed if the account cannot be verified.
- State-changing requests must use POST and validate the session CSRF token.
- Configure strict cookie-only sessions, sensible browser security headers, no-store responses for dynamic pages, and a Content Security Policy that does not require inline JavaScript.
- Passwords must be created and checked with PHP password-hashing APIs. Never add default credentials or commit real secrets.
- Validate uploaded file type and size server-side; do not trust the supplied filename or extension.
- Keep configuration, includes, and maintenance scripts inaccessible over HTTP.
- Keep the app within its documented local/trusted-network boundary; do not represent local controls as production hardening.
- Do not weaken foreign keys, uniqueness, validation, or transaction boundaries to make a failing operation appear successful.
- Keep the database browser administrator-only. Build its table/column choices from the connected schema, bind record values, safely quote validated identifiers, exclude password hashes, and protect the signed-in and last active administrator accounts from deletion/deactivation.

## Database changes

- Update `database/coffee_management.sql` for schema changes and keep it safe to import as described in `README.md`.
- Review existing rows, foreign keys, seed scripts, demo data, and PHP queries before changing a column or table.
- Update related seed/bootstrap utilities and document whether a schema change affects existing databases. Never imply that editing the create-schema SQL migrates an already-installed database unless a migration is actually provided.
- Keep monetary values in decimal database columns and preserve the POS integer-minor-unit calculations.

## Validation

- Lint all changed PHP files with the project's PHP CLI (`php -l`).
- For behavior changes, exercise the smallest relevant local workflow; for schema or persistence changes, verify against a disposable/test database where practical.
- Run the manual checks in `README.md` for workflows not covered by automated tests. Do not claim those checks passed unless they were performed.
- Report environmental blockers (such as stopped Apache/MySQL or missing PHP extensions) explicitly.

## Documentation upkeep

- Before finishing any AI-assisted project change, review `prd.md`, `architecture.md`, `rules.md`, `design.md`, `task.md`, and `README.md`.
- Update every document whose requirements, design, architecture, implementation rules, setup steps, or task status changed. Keep descriptions factual and consistent with the code.
- Record completed work and remaining validation in `task.md`; update `README.md` whenever installation, configuration, runtime behavior, or troubleshooting changes.
- Make these documentation updates as part of the same assistant change without asking for permission or waiting for a separate prompt. Do not claim that the files are automatically regenerated or monitored when they are maintained by assistant instructions.
