# e_Koperasi

e_Koperasi multi-outlet inventory and express pre-order system built with a LAMP
stack and vanilla HTML/CSS/JS.

## Features

- Role-based login for `Admin`, `Staff`, and `Customer` accounts.
- **Admin dashboard**
  - Manage outlets and staff accounts.
  - Master catalog with search and category/group/branch/stock filters.
  - Product groups/variants (e.g. Fanta Orange, Fanta Grape), each variant with
    its own price and image.
  - Per-branch stock breakdown and branch-scoped inventory views.
  - Master product, group, and variant management.
- **Outlet dashboard**
  - Outlet-scoped inventory, stock adjustment, reorder levels, and availability.
  - Add standalone products or assign existing catalog products to the outlet.
  - For grouped variants, master fields are admin-managed; staff edit only
    branch stock settings.
  - Live order queue: active orders refresh every 4 seconds, with the sidebar
    counter updating on every tab.
- **Customer storefront**
  - Guest browsing and customer login/register; admin/staff see a Dashboard link.
  - Branch selector for active outlets with location, contact, and operating hours.
  - Live search, category pills, in-stock filter, grouped variants, and stock badges.
  - Per-outlet `localStorage` cart with quantity limits and outlet/account scoping.
  - Pickup-only checkout, simulated payment, retry/cancel, and order history.
- Product image uploads with drag & drop and server-side validation.
- Stock audit history (`inventory_transaction`) and low/out-of-stock indicators.

## Requirements

- PHP 8.2+ (local development uses the XAMPP build)
- MariaDB 10.4+ or MySQL
- Apache, or the PHP built-in server

## Local setup (XAMPP on Linux)

```bash
# Start Apache + MariaDB
sudo /opt/lampp/lampp start

# Create and import the database (first time)
/opt/lampp/bin/mysql -uroot -e \
  "CREATE DATABASE IF NOT EXISTS e_koperasi_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
/opt/lampp/bin/mysql -uroot e_koperasi_db < e_koperasi_db.sql

# Serve the app from the repository root
/opt/lampp/bin/php -S localhost:8000
```

Open <http://localhost:8000/index.php> for the storefront, or
<http://localhost:8000/login.php> to sign in.

Database defaults live in `includes/db_connect.php` and can be overridden with
the `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME` environment variables.

> `includes/db_connect.php`, `e_koperasi_db.sql`, and `run_php.sh` are
> intentionally gitignored. They exist locally but are not guaranteed in a
> fresh clone, so keep them in sync with the schema and code.

## Project structure

```text
e_Koperasi/
├── css/
│   ├── style.css       # Shared theme: tokens, reset, alerts, badges, modal, forms
│   ├── dashboard.css   # Shared dashboard chrome: topbar, sidebar, cards, FAB
│   ├── inventory.css   # Shared inventory cards, filters, badges, stock tables
│   ├── outlet.css      # Outlet dashboard only (loads after the shared files)
│   ├── auth.css        # login.php + register.php (self-contained)
│   ├── storefront.css  # Shared storefront header, catalog, cards, cart drawer
│   ├── checkout.css    # Checkout + simulated payment
│   └── orders.css      # Customer order history
├── js/
│   ├── main.js         # Shared helpers: openModal, closeModal, toggleMenu
│   ├── upload.js       # Drag & drop image component for [data-dropzone]
│   ├── dashboard.js    # Sidebar toggle (used by both dashboards)
│   ├── admin.js        # admin_dashboard.php only
│   ├── outlet.js       # outlet_dashboard.php only
│   ├── storefront.js   # Cart helpers + storefront UI; exposes window.StorefrontCart
│   ├── checkout.js     # Builds and submits the checkout payload
│   └── orders.js       # Clears the cart after a paid order
├── api/
│   └── fetch_orders.php    # JSON order-queue feed for the outlet dashboard
├── includes/
│   ├── db_connect.php        # DB connection + session_start() (local, gitignored)
│   ├── upload_image.php      # Image validation, storage, and deletion
│   ├── storefront_helpers.php # Outlet/catalog/stock/CSRF/audit helpers
│   └── order_queue_helpers.php # Shared order queue query with item lines
├── uploads/products/   # Uploaded product images (placeholder + .htaccess tracked)
├── index.php           # Public customer storefront
├── checkout.php        # Pickup checkout (CSRF + stock locking)
├── payment.php         # Simulated payment success/failure/cancel
├── my_orders.php       # Customer order history
├── login.php
├── register.php
├── logout.php
├── admin_dashboard.php
├── outlet_dashboard.php
├── e_koperasi_db.sql   # Local schema/data dump (gitignored)
├── run_php.sh          # Local convenience launcher (gitignored)
├── AGENTS.md           # Guidance for coding agents
└── README.md
```

## Data model

Main tables:

| Table | Purpose |
| :--- | :--- |
| `user` | Accounts, roles, and outlet assignment |
| `outlet` | Branch details and operating hours |
| `category` | Product categories |
| `product_group` | Variant families such as "Fanta" |
| `product` | Catalog products and variants |
| `outlet_inventory` | Per-branch stock, reorder level, and availability |
| `inventory_transaction` | Stock audit trail |
| `orders`, `order_item`, `payment`, `delivery` | Order and fulfilment records |

### Product groups and variants

A product group represents a family of variants while every variant remains a
normal `product` row. This preserves the existing order and inventory foreign
keys.

- `product.group_id` points at a `product_group`, or is `NULL` for a standalone
  product.
- `product.variant_name` stores the flavour/variant label.
- `UNIQUE (group_id, variant_name)` prevents duplicate variants in one group.
- The display name is generated as `group_name + " " + variant_name`.
- The group owns the shared category and image; each variant has its own price
  and image.
- Deleting a group ungroups its variants (`ON DELETE SET NULL`); it does not
  delete the products.
- Deleting a product with order or stock history is blocked by foreign keys.

Admin inventory URLs:

- All products: `admin_dashboard.php?tab=inventories`
- Branch-scoped view: `admin_dashboard.php?tab=inventories&outlet_id=<id>`

### Customer storefront flow

- `index.php` works for guests and customers. It resolves the outlet from
  `?outlet_id=`, then the session, then the first active branch by name.
- The catalog reads only `outlet_inventory` rows with `status = 'Active'`.
  Grouped variants share the group image/category but keep their own price,
  image, and branch stock; each variant card chip switches the displayed data.
- The cart is stored in `localStorage` under `ekoperasi_storefront_cart_v1`,
  scoped by outlet and account. Switching outlets with items prompts before
  clearing; logging out clears it.
- Checkout is pickup only (delivery is disabled because
  `delivery.staff_delivery_id` is `UNIQUE`). The server re-reads prices, locks
  stock with `FOR UPDATE`, writes `order_item` plus `Stock_Out` audit rows, and
  creates a `payment` row with status `Pending`.
- `payment.php` is a demo gateway: "Payment Successful" sets the payment to
  `Success` while the order stays `Pending`; "Payment Failed" leaves it
  `Pending` for retry. Cancelling after a failure sets the order to `Cancelled`,
  restores stock, and writes `Stock_In` audit rows.
- A successful payment redirects to `my_orders.php` with a one-time cart-clear
  flag. `login.php` accepts `?redirect=` only for `index.php`, `checkout.php`,
  `payment.php`, and `my_orders.php`; admin/staff always go to their dashboard.

### Live order queue

- `js/outlet.js` polls `api/fetch_orders.php` every 4 seconds. The sidebar
  counter updates on every tab; the card list is rebuilt only on the orders tab
  and only when its data changes.
- The endpoint returns `{ count, orders }` and is restricted to Staff/Admin
  sessions with an assigned `outlet_id`; unauthorized callers receive HTTP 401.
  Orders are scoped to that outlet and limited to `Pending`/`Ready`. Each order
  includes an `items` array (name, quantity, unit price, subtotal).
- Required items are expanded by default on each order card. The `Hide Items` /
  `Show Items` button collapses just that order's list; collapsed orders are
  remembered across poll-driven rebuilds.
- Polled cards mirror the server-rendered markup and submit the same
  `update_order_status` POST (`Pending` → `Ready` → `Completed`). There is no
  `order_token` field in the schema.

## Conventions

- **No inline `<style>` blocks.** CSS lives in `css/` and behavior lives in `js/`.
  Small inline `<script>` blocks are allowed only for server-to-JS data payloads
  such as `window.BRANCH_STOCK` / `window.PRODUCT_GROUPS`.
- Load JS in dependency order: `main.js` → `upload.js` → `dashboard.js` → page-specific file.
  Storefront pages use `main.js` → `storefront.js` → page-specific file, and all
  of them expose `window.StorefrontCart`.
- Load CSS in dependency order: `style.css` → `dashboard.css` → `inventory.css` → page-specific CSS.
  Storefront pages use `style.css` → `storefront.css` → page-specific CSS.
- Inline `onclick` attributes that pass PHP data stay in the PHP (for example,
  `onclick='openEditProductModal(<?php echo json_encode($p); ?>)'`) because
  external `.js` files cannot execute PHP. Only the function definitions move
  into `.js`.
- Keep existing user changes and avoid broad rewrites of unrelated markup or CSS.

## Product images

Staff can attach a photo when creating an item and replace it from the Edit
Item dialog. Uploads are drag-and-drop or click-to-browse.

| Piece | Location |
| :--- | :--- |
| Validation + storage | `includes/upload_image.php` (`save_product_image`, `delete_product_image`) |
| Drag & drop UI | `js/upload.js` — any `[data-dropzone]` element becomes a drop target |
| Styles | `.dropzone*` in `css/style.css`, `.item-thumb` in `css/inventory.css` |
| Stored path | `product.image_url`, files land in `uploads/products/` |

Rules enforced server-side: JPG/PNG/WEBP only (checked by content, not by the
filename), 5 MB cap, and server-generated `prod_<uniqid>` filenames. Replacing
a photo deletes the old file only after the new row commits. `uploads/products/`
also denies script execution via `.htaccess`.

To move storage to S3 later, only `save_product_image()` and
`delete_product_image()` need to change.

## Verification

There is no automated test suite. At minimum:

```bash
/opt/lampp/bin/php -l admin_dashboard.php
/opt/lampp/bin/php -l outlet_dashboard.php
/opt/lampp/bin/php -l index.php
/opt/lampp/bin/php -l checkout.php
/opt/lampp/bin/php -l payment.php
/opt/lampp/bin/php -l my_orders.php
/opt/lampp/bin/php -l login.php
/opt/lampp/bin/php -l includes/storefront_helpers.php
/opt/lampp/bin/php -l outlet_dashboard.php
/opt/lampp/bin/php -l api/fetch_orders.php
```

Then check the affected page in a browser with a valid session and inspect the
database rows for writes. For inventory/variant changes, exercise add, edit,
attach/ungroup, group rename/delete, outlet catalog assignment, and the
grouped-variant edit restrictions.

For the storefront, check guest browsing, outlet switching with a non-empty
cart, variant switching, quantity/stock limits, guest checkout redirect-back,
pickup checkout, simulated success/failure/retry/cancel, stock restoration, and
cart clearing after a paid order.

For the live queue, check the endpoint as a guest/customer (401) and as staff
(JSON), confirm another outlet's orders are excluded, then insert an order and
verify the badge and cards update within 4 seconds with no console errors.

## Known gotchas

- Inactive inventory cards use `opacity`, which creates a stacking context.
  Open dropdown menus are lifted by `.inventory-list .item-card:focus-within`
  and `.inventory-list .item-card:has(.dropdown-menu.show)` in
  `css/inventory.css`.
- `mysqldump --routines` fails on this local MariaDB (`mysql.proc` column
  mismatch). Use plain `mysqldump -uroot --single-transaction e_koperasi_db`.
- Customer login defaults to `index.php`; a safe `?redirect=` override is only
  honoured for customer accounts on the four storefront pages.
- `payment.php` deliberately keeps `order_status = 'Pending'` after a successful
  payment; staff still mark orders `Ready`/`Completed` from their dashboards.
- The `localStorage` cart is client-side only. Server prices and stock are
  always revalidated in `checkout.php`, so tampered cart data cannot change
  totals or oversell stock.
- Admin accounts without an `outlet_id` are rejected by both
  `outlet_dashboard.php` and `api/fetch_orders.php`; assign an outlet first.
- `api/fetch_orders.php` uses `__DIR__ . '/../includes/db_connect.php'` so the
  include works regardless of the server's working directory.
