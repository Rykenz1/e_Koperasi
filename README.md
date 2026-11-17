# e_Koperasi

CampusMart multi-outlet inventory & express pre-order system (LAMP stack, vanilla HTML/CSS/JS).

## Project structure

```text
e_Koperasi/
├── css/
│   ├── style.css       # Shared theme: tokens, reset, alerts, badges, modal, forms
│   ├── dashboard.css   # Shared dashboard chrome: topbar, sidebar, cards, FAB
│   ├── outlet.css      # Outlet dashboard only (loads after the two above)
│   └── auth.css        # login.php + register.php (self-contained)
├── js/
│   ├── main.js         # Shared helpers: openModal, closeModal, toggleMenu
│   ├── dashboard.js    # Sidebar toggle (used by both dashboards)
│   ├── admin.js        # admin_dashboard.php only
│   └── outlet.js       # outlet_dashboard.php only
├── includes/
│   └── db_connect.php  # DB connection + session_start()
├── uploads/products/   # Uploaded product images
├── login.php
├── register.php
├── logout.php
├── admin_dashboard.php
└── outlet_dashboard.php
```

### Conventions

- **No inline `<style>` or `<script>` blocks.** Everything lives in `css/` and `js/`.
- Load JS in dependency order: `main.js` → `upload.js` → `dashboard.js` → page-specific file.
- Page-specific CSS is loaded **after** the shared files so it can override them.
- Inline `onclick` attributes that pass PHP data stay in the PHP (e.g.
  `onclick='openEditProductModal(<?php echo json_encode($p); ?>)'`) — external
  `.js` files cannot execute PHP. Only the function definitions move into `.js`.

### Product images

Staff can attach a photo when creating an item, and replace it from the Edit
Item dialog. Uploads are drag-and-drop or click-to-browse.

| Piece | Location |
| :--- | :--- |
| Validation + storage | `includes/upload_image.php` (`save_product_image`, `delete_product_image`) |
| Drag & drop UI | `js/upload.js` — any `[data-dropzone]` element becomes a drop target |
| Styles | `.dropzone*` in `css/style.css`, `.item-thumb` in `css/outlet.css` |
| Stored path | `product.image_url`, files land in `uploads/products/` |

Rules enforced server-side: JPG/PNG/WEBP only (checked by content, not by the
filename), 5 MB cap, server-generated `prod_<uniqid>` filenames. Replacing a
photo deletes the old file only after the new row commits. `uploads/products/`
also denies script execution via `.htaccess`.

To move storage to S3 later, only `save_product_image()` and
`delete_product_image()` need to change.
