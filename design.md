# Interface Design

## Design direction

The interface presents a calm, cafe-inspired workspace for daily operations. It uses coffee-brown accents, a warm cream canvas, restrained status colors, clear data tables, and compact forms rather than a public marketing-site layout.

## Visual system

The current design tokens are defined in `assets/css/style.css`:

- Main background: warm cream `#faf8f4`; panels and inputs: white.
- Primary text: deep brown `#332b25`; secondary text: muted taupe `#908880`.
- Coffee accents: `#6f452e` and `#4d3021`; dark navigation: `#2d2925`.
- Positive/stock accent: green `#49745d`; lines and separators: `#eee9e2`.
- Typography: Inter when available, then Segoe UI and Arial; Georgia is used for brand and prominent headings.

## Layout and navigation

- Authenticated pages use a fixed dark sidebar, a light top bar, and a centered content area.
- The sidebar footer shows the signed-in staff member.
- The page header keeps a clearly labeled **Sign out** button in the top-right corner, styled consistently with the shared light buttons, including on narrow screens.
- The sidebar navigation scrolls independently so the signed-in account remains accessible when the menu is taller than the viewport.
- The sidebar links to overview, products, categories, POS, orders, inventory, purchases, suppliers, and customers. Administrator-only links expose expenses, reports, settings, and team accounts.
- The dashboard prioritizes daily metrics, seven-day sales, attention items, and recent orders.
- A dashboard shift-handover panel lets workers post brief team updates and mark open notes done.
- Management pages pair concise forms with searchable/filterable tables where appropriate.
- The administrator-only database browser lists a selected table in a horizontally scrollable grid, with separate edit forms and explicitly confirmed delete actions.
- POS emphasizes product discovery and a persistent cart/checkout summary.
- Login has a distinct split story/form layout.
- The layout adapts for narrow screens using the existing responsive CSS and sidebar toggle in `assets/js/app.js`.

## Interaction and feedback

- Use the existing button, panel, alert, table, form, badge, and empty-state patterns.
- Keep destructive or stateful operations explicit and submit them through POST forms with CSRF protection.
- Keep sign-out visible and clearly labeled; submit it by POST with the session CSRF token.
- Make each purchase action show a confirmation that matches the action being performed.
- Show a visible warning before database maintenance actions; require an explicit confirmation checkbox before deletion.
- Provide visible validation errors and success feedback; use confirmation for consequential actions where the existing flow does so.
- POS totals may update in the browser for responsiveness, but the server recalculates and validates every order.
- Keep sample/demo records visibly identified and do not present them as real transactions.

## Accessibility and consistency

- Associate form labels with controls and provide accessible names for icon-only buttons.
- Preserve keyboard-operable native controls, visible focus states, semantic headings, table structures, and status messages.
- Maintain readable contrast and avoid relying on color alone to communicate errors or stock status.
- Reuse the established color, spacing, and component patterns. Update this document when a deliberate design-system change is made.
