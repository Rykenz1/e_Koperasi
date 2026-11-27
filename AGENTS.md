# e-Koperasi — Agent Guide

Read this file first, then `README.md` for setup and the human-facing file layout.

## Project snapshot

e_Koperasi multi-outlet inventory and express pre-order system.
LAMP stack, vanilla PHP/HTML/CSS/JS. No framework, package manager, or build step.

- PHP 8.2+ / MariaDB 10.4+ (local XAMPP)
- Database: `e_koperasi_db`
- Auth roles: `Admin`, `Staff`, `Customer`
- Entry points: `index.php` (public storefront), `login.php` (authentication)

## Local commands

```bash
# Start XAMPP services (Linux)
sudo /opt/lampp/lampp start

# Lint PHP after editing
/opt/lampp/bin/php -l admin_dashboard.php
/opt/lampp/bin/php -l outlet_dashboard.php
/opt/lampp/bin/php -l index.php
/opt/lampp/bin/php -l checkout.php
/opt/lampp/bin/php -l payment.php
/opt/lampp/bin/php -l my_orders.php
/opt/lampp/bin/php -l login.php
/opt/lampp/bin/php -l includes/storefront_helpers.php

# Serve from the repo root
/opt/lampp/bin/php -S localhost:8000

# Database shell
/opt/lampp/bin/mysql -uroot e_koperasi_db

# Import schema (first setup)
/opt/lampp/bin/mysql -uroot e_koperasi_db < e_koperasi_db.sql
```

`includes/db_connect.php` supports `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME`,
falling back to `127.0.0.1` / `root` / empty password / `e_koperasi_db`.

## Architecture map

| Path | Responsibility |
| :--- | :--- |
| `login.php`, `register.php`, `logout.php` | Authentication, session setup, safe storefront redirect-back |
| `admin_dashboard.php` | Admin-only outlets, staff, and master inventory; POST handlers and all admin UI, including the bulk add-products repeater |
| `outlet_dashboard.php` | Outlet-scoped inventory, stock adjustment, catalog assignment, orders/audit views |
| `api/fetch_orders.php` | JSON feed of active orders for the session's outlet; RBAC + 401 for unauthorized callers |
| `index.php` | Public storefront: outlet selector, catalog filters, grouped variants, cart drawer |
| `checkout.php` | Pickup checkout; CSRF, stock locking, order/payment creation |
| `payment.php` | Simulated payment success/failure/retry and cancel with stock restoration |
| `my_orders.php` | Customer order history; clears the cart after a paid order |
| `includes/db_connect.php` | mysqli connection + session bootstrap (cookie hardening; env-driven config) |
| `includes/csrf_helpers.php` | `csrf_token()`, `csrf_valid()`, `csrf_field()` shared by dashboards and storefront |
| `includes/upload_image.php` | Image validation, storage, and deletion |
| `includes/storefront_helpers.php` | Outlet/catalog/stock/audit helpers; CSRF wrappers delegate to `csrf_helpers.php` |
| `includes/order_queue_helpers.php` | Shared outlet order-queue query (headers + item lines) used by the dashboard and JSON feed |
| `css/style.css` | Shared tokens, reset, alerts, badges, modal, forms |
| `css/dashboard.css` | Shared dashboard chrome: topbar, sidebar, cards, FAB |
| `css/inventory.css` | Shared inventory cards, filters, badges, stock tables, add-product tabs |
| `css/outlet.css` | Outlet-only overrides |
| `css/auth.css` | Login/register pages |
| `css/storefront.css` | Shared storefront header, catalog, cards, cart drawer |
| `css/checkout.css` | Checkout and payment simulation styles |
| `css/orders.css` | Customer order history styles |
| `js/main.js` | `openModal`, `closeModal`, `toggleMenu`, outside-click close |
| `js/upload.js` | Generic `[data-dropzone]` drag/drop image component |
| `js/dashboard.js` | Sidebar toggle |
| `js/admin.js` | Admin-only modal prefill and product-group/variant helpers |
| `js/outlet.js` | Outlet-only add/edit modal logic, inventory filtering, and 4s order-queue polling |
| `js/storefront.js` | Cart storage helpers + storefront UI; exposes `window.StorefrontCart` |
| `js/checkout.js` | Validates the localStorage cart and submits the order payload |
| `js/orders.js` | Clears the cart when `window.ORDERS_PAGE.clearCart` is true |
| `e_koperasi_db.sql` | Local schema/data dump (gitignored) |
| `run_php.sh` | Local convenience launcher (gitignored) |

## Data model rules

- `product_group` represents a variant family (e.g. Fanta).
- Each variant is still a normal `product` row:
  - `product.group_id` points at the group, or `NULL` for a standalone product.
  - `product.variant_name` holds the flavour/variant label.
  - `UNIQUE (group_id, variant_name)` prevents duplicate variants in a group.
  - Display name is rebuilt as `group_name + " " + variant_name`.
- Group owns the shared category and image; each variant keeps its own price and image.
- `product.group_id` uses `ON DELETE SET NULL`; deleting a group ungroups its variants instead of deleting them.
- Deleting a product that has order or stock-history rows is intentionally blocked by foreign keys.
- `inventory_transaction` is the stock audit trail; writes to it must stay consistent with `outlet_inventory` changes.
- Customer checkout is pickup only; `delivery.staff_delivery_id` is `UNIQUE`, so do not add delivery without schema work.
- Checkout writes are transactional: lock `outlet_inventory` with `FOR UPDATE`, re-read prices from `product`, insert the order/items, decrement stock, and log `Stock_Out` with a branch Staff (or Admin) actor.
- `orders.order_status` and `payment.payment_status` are independent. A successful simulated payment sets payment `Success` but leaves the order `Pending`; only staff move it to `Ready`/`Completed`.
- The outlet dashboard only allows `Pending → Ready` and `Ready → Completed`. `Cancelled` is never accepted by the generic status endpoint.
- Cancelling an order restores stock and writes `Stock_In` audit rows in the same transaction as the `Cancelled` status change. Two paths do this: `payment.php` (customer, failed payment only) and `outlet_dashboard.php` `cancel_order` (staff, `Pending`/`Ready` only). A staff cancel also flips a `Success` payment to `Refunded`.
- `user.username` is `UNIQUE` (`uq_username`). `login.php` matches on `email OR username`, so duplicate usernames would make login non-deterministic.

## Current behavior that must be preserved

- Admin manages the master catalog, groups, variants, outlets, and staff.
- The Inventories "+" button opens a bulk add form. Each row has a trash button,
  a compact image dropzone between the trash and product name, product name,
  searchable Category/Group comboboxes (each with a
  sticky "+ Create ..." item), an optional Variant/Flavour Name column that is
  shown only when a group is selected, and price. Row 1 cannot be deleted;
  "Add product +" appends rows. New category/group names (`name:` combobox
  values) are find-or-created on submit inside the same transaction. Grouped
  rows require a variant name and derive `product_name` as group + variant.
  Un-touched blank rows are skipped; the batch is all-or-nothing and uploaded
  images are deleted on rollback. Client-side validation (`js/admin.js`) blocks
  submit with one `#bulkError` alert at the top of the modal for an empty form,
  a missing product name/category/variant, or an invalid price; the modal stays
  open and the error clears on the next edit. The form uses `novalidate` so
  native tooltips do not replace this alert. There is no server-side modal
  reopen flag; a full-page server error simply shows the page alert.
- Admin does **not** distribute products to branches; branch staff add catalog items to their outlet.
- Outlet staff may still create standalone products from the outlet dashboard.
- For grouped variants, outlet staff can edit only branch stock, reorder level, and availability.
  Name, category, price, and image are admin-managed and read-only at the outlet.
- Admin inventory URLs:
  - All products: `admin_dashboard.php?tab=inventories`
  - Branch-scoped view: `admin_dashboard.php?tab=inventories&outlet_id=<id>`
- Group filter sentinel: `0` = all groups, `-1` = ungrouped, `> 0` = group ID.
- Outlet catalog picker groups variants with `<optgroup>`.
- Storefront outlet selection precedence: `?outlet_id=`, then session, then first active outlet by name; `?outlet_id=` updates the session.
- Storefront catalog uses only `outlet_inventory.status = 'Active'`. Grouped cards share the group image/category, but each variant chip swaps price, image, stock badge, and add-button state.
- Guests browse freely; customer checkout requires login and returns via a whitelisted `login.php?redirect=`. Admin/staff see a Dashboard link on the storefront and are bounced to their dashboard from checkout/payment/orders.
- Cart localStorage key: `ekoperasi_storefront_cart_v1`. It is scoped by outlet and account; switching outlets with items prompts first, logout clears it, and a successful payment clears it on the orders page.
- Never trust cart prices or quantities: `checkout.php` revalidates both against the selected outlet's catalog.
- Every state-changing POST (storefront and both dashboards) requires a CSRF token. Dashboards guard at the top of the POST block and render `csrf_field()` in each form; `js/outlet.js` reads `<meta name="csrf-token">` for the forms it rebuilds during polling.
- The outlet order queue polls `api/fetch_orders.php` every 4 seconds from `js/outlet.js`. The sidebar `#orderQueueCount` badge updates on every tab; `#orderQueueList` exists only on the orders tab and is rebuilt only when its data signature changes (items included).
- Order cards show required items expanded by default; the `.order-toggle` button collapses only that order, and collapsed order IDs are kept in a `Set` so the state survives poll rebuilds. The PHP cards and JS cards share `.order-card`, `.order-items`, `.order-item-name`, `.order-item-qty`, `.order-item-price`, and `.items-collapsed` markup/classes.
- Order cards expose a "Cancel Order" button in both the PHP and JS builders. It posts `action=cancel_order`, confirms first, and the server restores stock in a transaction.
- `api/fetch_orders.php` mirrors the dashboard guard: no `user_id`, a non-Staff/Admin role, or an empty `outlet_id` returns HTTP 401 JSON. Admin accounts without an assigned outlet are intentionally unauthorized here.
- Polled cards must match the PHP markup: `.item-card` → `.item-info` (`h3` with status badge + detail `p`) and `.card-actions` POST form. Build them with `createElement`/`textContent`, never `innerHTML` with DB data. There is no `order_token` column; do not invent one.

## UI conventions

- Do not add inline `<style>` blocks. Keep CSS in `css/` and JS in `js/`.
- Avoid inline `<script>` blocks too, except small server-to-JS data payloads such as
  `window.BRANCH_STOCK` / `window.PRODUCT_GROUPS` in `admin_dashboard.php`.
- JS load order: `main.js` → `upload.js` → `dashboard.js` → page-specific file.
  Storefront pages use `main.js` → `storefront.js` → page-specific file; `storefront.js`
  must load before `checkout.js` and `orders.js`.
- CSS load order: `style.css` → `dashboard.css` → `inventory.css` → page-specific CSS.
  Storefront pages use `style.css` → `storefront.css` → page-specific CSS.
- Inline `onclick` handlers may pass PHP data (external JS cannot execute PHP).
  Prefer `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`
  when embedding rows into attributes.
- Keep existing user changes and avoid broad rewrites of unrelated markup/CSS.

## Verification checklist

1. Run `php -l` on every edited PHP file.
2. Load the page with a valid Admin/Staff session and check the browser console.
3. For inventory changes, exercise:
   - add standalone product
   - add variant to new and existing group
   - duplicate variant rejection
   - edit group rename (children renamed)
   - attach/ungroup product
   - remove from group / delete group
   - outlet catalog assignment and grouped-variant edit locking
4. Check the database rows (`product`, `product_group`, `outlet_inventory`) after writes.
5. For storefront changes, exercise with guest and customer sessions:
   - browse two outlets and switch with/without items in the cart
   - search, category pills, in-stock toggle, and variant switching
   - add/increase/decrease/remove with stock limits
   - guest checkout redirect-back and login redirect whitelist
   - pickup checkout, simulated success, simulated failure/retry, and cancel
   - stock restoration + `Stock_In` audit rows after cancel, cart clearing after success
   - Admin/Staff storefront header and dashboard bounce
6. Check the browser console on `index.php`, `checkout.php`, `payment.php`, and `my_orders.php`.
7. For outlet queue polling, check `php -l api/fetch_orders.php`, hit the endpoint as guest/customer/staff (401 vs `{count, orders}`), verify cross-outlet orders are excluded, then insert an order and confirm the badge and cards update within 4 seconds without console errors.
8. Remove test rows and restore the database to its prior state.

## Known gotchas

- `.item-card.inactive-card` uses `opacity`, which creates a stacking context.
  Open dropdowns in those cards need the z-index lift in `css/inventory.css`.
- `mysqldump --routines` fails on this local MariaDB (`mysql.proc` column mismatch).
  Use plain `mysqldump -uroot --single-transaction e_koperasi_db`.
- `e_koperasi_db.sql` and `run_php.sh` are gitignored. `includes/db_connect.php` is
  tracked (it holds no secrets; config is env-driven). Schema changes live in `db/migrations/`.
- `index.php` is now the public storefront; customer login defaults to it but honours a safe `?redirect=` for storefront pages only.
- `mysqli` has no `in_transaction` property. Use `try { $conn->rollback(); } catch (Throwable $ignored) {}` in `catch` blocks (as in `checkout.php`, `payment.php`, and `outlet_dashboard.php`). Do not guard with `$conn->in_transaction`.
- Session cookies set `Secure` only when HTTPS is detected (`$_SERVER['HTTPS']` or `X-Forwarded-Proto`), so `php -S localhost:8000` still works. Do not hardcode `secure => true` locally.
- `api/fetch_orders.php` must include `db_connect.php` via `__DIR__ . '/../includes/db_connect.php'`; a bare `../includes/...` resolves against the server working directory, not the script directory.
- `js/storefront.js` returns early when there is no `#productGrid`, but still exposes `window.StorefrontCart`; `checkout.js` and `orders.js` rely on that.
- No automated test suite; verify with lint, browser checks, and direct SQL inspection.

## When changing the project

- Update `README.md` for user-facing setup/architecture changes.
- Update this file when conventions, commands, schema rules, or important behavior change.
- Keep changes consistent with existing PHP/HTML/CSS/JS style; do not introduce a framework
  or build tool unless explicitly requested.
