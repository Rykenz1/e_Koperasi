<?php
// index.php - Public customer storefront
require_once 'includes/db_connect.php';
require_once 'includes/storefront_helpers.php';

$is_logged_in = isset($_SESSION['user_id']);
$role         = $_SESSION['role'] ?? '';
$is_customer  = $is_logged_in && $role === 'Customer';
$user_id      = $is_logged_in ? intval($_SESSION['user_id']) : 0;
$display_name = trim((string)($_SESSION['fullname'] ?? $_SESSION['username'] ?? ''));

$requested_outlet_id = isset($_GET['outlet_id']) ? intval($_GET['outlet_id']) : 0;
$outlets             = storefront_active_outlets($conn);
$selected_outlet     = storefront_pick_outlet($outlets, $requested_outlet_id);
$outlet_id           = $selected_outlet ? intval($selected_outlet['outlet_id']) : 0;
$catalog             = $selected_outlet
    ? storefront_catalog($conn, $outlet_id)
    : ['grouped' => [], 'standalone' => [], 'categories' => [], 'items' => 0];

$store_url    = 'index.php' . ($outlet_id ? '?outlet_id=' . $outlet_id : '');
$checkout_url = 'checkout.php' . ($outlet_id ? '?outlet_id=' . $outlet_id : '');
$login_url    = 'login.php?redirect=' . rawurlencode($checkout_url);

$storefront_config = [
    'outletId'     => $outlet_id,
    'outletName'   => $selected_outlet['outlet_name'] ?? '',
    'isLoggedIn'   => $is_logged_in,
    'userId'       => $user_id,
    'role'         => $role,
    'cartKey'      => STOREFRONT_CART_KEY,
    'defaultImage' => PRODUCT_IMAGE_DEFAULT,
    'loginUrl'     => $login_url,
    'checkoutUrl'  => $checkout_url,
    'storeUrl'     => $store_url,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>e-Koperasi Storefront</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/storefront.css">
</head>
<body class="storefront-body">

<header class="store-header">
    <div class="store-header-inner">
        <a class="store-brand" href="<?php echo htmlspecialchars($store_url, ENT_QUOTES, 'UTF-8'); ?>">
            <span class="store-brand-mark">e</span>
            <span>e-Koperasi</span>
        </a>

        <nav class="store-nav" aria-label="Storefront navigation">
            <?php if ($is_logged_in): ?>
                <span class="store-user">Hi, <?php echo htmlspecialchars($display_name ?: ($_SESSION['username'] ?? 'there')); ?></span>
                <?php if ($is_customer): ?>
                    <a class="store-nav-link" href="my_orders.php">My Orders</a>
                <?php else: ?>
                    <a class="store-nav-link" href="<?php echo $role === 'Admin' ? 'admin_dashboard.php' : 'outlet_dashboard.php'; ?>">Dashboard</a>
                <?php endif; ?>
                <a class="store-nav-link" href="logout.php" data-storefront-logout>Logout</a>
            <?php else: ?>
                <a class="store-nav-link" href="login.php">Login</a>
                <a class="store-nav-cta" href="register.php">Register</a>
            <?php endif; ?>

            <button type="button" class="store-cart-btn" id="cartOpenBtn" aria-controls="cartDrawer" aria-expanded="false">
                <span>Cart</span>
                <span class="cart-count is-empty" id="cartCount">0</span>
            </button>
        </nav>
    </div>
</header>

<main class="store-main">
    <noscript>
        <div class="store-alert">The shopping cart and checkout need JavaScript enabled.</div>
    </noscript>

    <section class="store-hero" aria-labelledby="storefrontHeading">
        <div class="store-hero-copy">
            <p class="store-eyebrow">Campus essentials, ready when you are</p>
            <h1 id="storefrontHeading">Skip the queue. Pick up from your campus koperasi.</h1>
            <p>Browse live branch stock, build your cart, and collect your order at the counter.</p>
        </div>

        <div class="outlet-panel">
            <?php if ($selected_outlet): ?>
                <form class="outlet-form" method="get" action="index.php">
                    <label for="outletSelect">Shopping at</label>
                    <div class="outlet-select-row">
                        <select id="outletSelect" name="outlet_id">
                            <?php foreach ($outlets as $outlet): ?>
                                <option value="<?php echo intval($outlet['outlet_id']); ?>" <?php echo intval($outlet['outlet_id']) === $outlet_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($outlet['outlet_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn-outline">Switch</button></noscript>
                    </div>
                </form>

                <div class="outlet-details">
                    <p><span class="outlet-detail-label">Location</span><span><?php echo htmlspecialchars($selected_outlet['location'] ?: 'On campus'); ?></span></p>
                    <p><span class="outlet-detail-label">Hours</span><span><?php echo htmlspecialchars($selected_outlet['operating_hours'] ?: 'See outlet notice'); ?></span></p>
                    <?php if (!empty($selected_outlet['contact_number'])): ?>
                        <p><span class="outlet-detail-label">Contact</span><span><?php echo htmlspecialchars($selected_outlet['contact_number']); ?></span></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="catalog-empty">
                    <h3>No active outlets</h3>
                    <p>Please check back later.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="catalog-section" aria-labelledby="catalogTitle">
        <div class="catalog-heading">
            <div>
                <h2 id="catalogTitle">Branch catalog</h2>
                <p id="catalogCount">
                    <?php echo $catalog['items']; ?> item<?php echo $catalog['items'] === 1 ? '' : 's'; ?> available
                </p>
            </div>
            <label class="stock-toggle">
                <input type="checkbox" id="inStockOnly">
                <span>In stock only</span>
            </label>
        </div>

        <div class="catalog-toolbar">
            <label class="sr-only" for="storefrontSearch">Search products</label>
            <input type="search" id="storefrontSearch" class="storefront-search"
                   placeholder="Search products, flavours, or categories..." autocomplete="off">
        </div>

        <div class="category-pills" id="categoryPills" role="group" aria-label="Filter by category">
            <button type="button" class="category-pill is-active" data-category="all" aria-pressed="true">
                All <span class="pill-count"><?php echo $catalog['items']; ?></span>
            </button>
            <?php foreach ($catalog['categories'] as $category): ?>
                <button type="button" class="category-pill" data-category="<?php echo intval($category['category_id']); ?>" aria-pressed="false">
                    <?php echo htmlspecialchars($category['category_name']); ?>
                    <span class="pill-count"><?php echo intval($category['count']); ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="product-grid" id="productGrid">
            <?php foreach ($catalog['grouped'] as $group): ?>
                <?php
                $default_variant = $group['variants'][$group['default_index']];
                $default_state   = storefront_stock_state($default_variant['stock_quantity'], $default_variant['reorder_level']);
                ?>
                <article class="store-card group-card"
                         data-kind="group"
                         data-category="<?php echo intval($group['category_id']); ?>"
                         data-search="<?php echo htmlspecialchars($group['search'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-has-stock="<?php echo $group['has_stock'] ? '1' : '0'; ?>">
                    <div class="store-card-media">
                        <img class="store-card-image"
                             src="<?php echo htmlspecialchars($group['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                             alt="<?php echo htmlspecialchars($group['group_name'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-role="card-image"
                             onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(PRODUCT_IMAGE_DEFAULT, ENT_QUOTES, 'UTF-8'); ?>';">
                        <span class="badge <?php echo storefront_stock_badge_class($default_state); ?>" data-role="stock-badge">
                            <?php echo storefront_stock_label($default_state); ?>
                        </span>
                    </div>

                    <div class="store-card-body">
                        <div class="store-card-top">
                            <h3><?php echo htmlspecialchars($group['group_name']); ?></h3>
                            <span class="badge badge-cat"><?php echo htmlspecialchars($group['category_name']); ?></span>
                        </div>
                        <p class="store-card-hint">Choose a variant</p>

                        <div class="variant-picker" role="group" aria-label="Choose <?php echo htmlspecialchars($group['group_name'], ENT_QUOTES, 'UTF-8'); ?> variant">
                            <?php foreach ($group['variants'] as $index => $variant): ?>
                                <?php
                                $variant_state = storefront_stock_state($variant['stock_quantity'], $variant['reorder_level']);
                                $is_selected   = $index === $group['default_index'];
                                ?>
                                <button type="button"
                                        class="variant-chip <?php echo $is_selected ? 'is-selected' : ''; ?> <?php echo $variant['stock_quantity'] <= 0 ? 'is-out' : ''; ?>"
                                        data-role="variant"
                                        data-product-id="<?php echo intval($variant['product_id']); ?>"
                                        data-name="<?php echo htmlspecialchars($variant['display_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-variant="<?php echo htmlspecialchars($variant['variant_label'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-price="<?php echo number_format($variant['price'], 2, '.', ''); ?>"
                                        data-stock="<?php echo intval($variant['stock_quantity']); ?>"
                                        data-reorder="<?php echo intval($variant['reorder_level']); ?>"
                                        data-image="<?php echo htmlspecialchars($variant['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-stock-label="<?php echo storefront_stock_label($variant_state); ?>"
                                        data-stock-state="<?php echo $variant_state; ?>"
                                        aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>"
                                        title="<?php echo htmlspecialchars(storefront_stock_label($variant_state) . ' - ' . $variant['stock_quantity'] . ' in stock', ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($variant['variant_label']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="store-card-meta">
                            <span class="store-price" data-role="price"><?php echo storefront_money($default_variant['price']); ?></span>
                            <span class="store-stock-text" data-role="stock-text">
                                <?php echo $default_variant['stock_quantity'] <= 0 ? 'Out of stock' : intval($default_variant['stock_quantity']) . ' in stock'; ?>
                            </span>
                        </div>

                        <button type="button" class="btn-add" data-role="add-to-cart" <?php echo $default_variant['stock_quantity'] <= 0 ? 'disabled' : ''; ?>>
                            <span data-role="add-label"><?php echo $default_variant['stock_quantity'] <= 0 ? 'Out of stock' : 'Add to cart'; ?></span>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php foreach ($catalog['standalone'] as $product): ?>
                <?php $product_state = storefront_stock_state($product['stock_quantity'], $product['reorder_level']); ?>
                <article class="store-card"
                         data-kind="single"
                         data-category="<?php echo intval($product['category_id']); ?>"
                         data-search="<?php echo htmlspecialchars($product['search'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-has-stock="<?php echo $product['stock_quantity'] > 0 ? '1' : '0'; ?>">
                    <div class="store-card-media">
                        <img class="store-card-image"
                             src="<?php echo htmlspecialchars($product['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                             alt="<?php echo htmlspecialchars($product['display_name'], ENT_QUOTES, 'UTF-8'); ?>"
                             onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(PRODUCT_IMAGE_DEFAULT, ENT_QUOTES, 'UTF-8'); ?>';">
                        <span class="badge <?php echo storefront_stock_badge_class($product_state); ?>">
                            <?php echo storefront_stock_label($product_state); ?>
                        </span>
                    </div>

                    <div class="store-card-body">
                        <div class="store-card-top">
                            <h3><?php echo htmlspecialchars($product['display_name']); ?></h3>
                            <span class="badge badge-cat"><?php echo htmlspecialchars($product['category_name']); ?></span>
                        </div>
                        <p class="store-card-hint"><?php echo htmlspecialchars($product['category_name']); ?></p>

                        <div class="store-card-meta">
                            <span class="store-price"><?php echo storefront_money($product['price']); ?></span>
                            <span class="store-stock-text">
                                <?php echo $product['stock_quantity'] <= 0 ? 'Out of stock' : intval($product['stock_quantity']) . ' in stock'; ?>
                            </span>
                        </div>

                        <button type="button" class="btn-add" data-role="add-to-cart"
                                data-product-id="<?php echo intval($product['product_id']); ?>"
                                data-name="<?php echo htmlspecialchars($product['display_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-price="<?php echo number_format($product['price'], 2, '.', ''); ?>"
                                data-stock="<?php echo intval($product['stock_quantity']); ?>"
                                data-image="<?php echo htmlspecialchars($product['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $product['stock_quantity'] <= 0 ? 'disabled' : ''; ?>>
                            <?php echo $product['stock_quantity'] <= 0 ? 'Out of stock' : 'Add to cart'; ?>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="catalog-empty" id="catalogEmpty" <?php echo $catalog['items'] > 0 ? 'hidden' : ''; ?>>
            <h3><?php echo $catalog['items'] > 0 ? 'No products match your filters' : 'No products available'; ?></h3>
            <p><?php echo $catalog['items'] > 0 ? 'Try a different search term or category.' : 'This outlet has no active catalog items right now.'; ?></p>
        </div>
    </section>
</main>

<footer class="store-footer">
    <p>e-Koperasi &middot; Campus convenience store</p>
</footer>

<div class="cart-backdrop" id="cartBackdrop" hidden></div>

<aside class="cart-drawer" id="cartDrawer" role="dialog" aria-modal="true" aria-labelledby="cartTitle" aria-hidden="true">
    <div class="cart-header">
        <div>
            <h2 id="cartTitle">Your cart</h2>
            <p class="cart-subtitle">Pickup from <strong><?php echo htmlspecialchars($selected_outlet['outlet_name'] ?? 'your outlet'); ?></strong></p>
        </div>
        <button type="button" class="cart-close" id="cartClose" aria-label="Close cart">&times;</button>
    </div>

    <div class="cart-body">
        <div class="cart-empty" id="cartEmpty">
            <p>Your cart is empty.</p>
            <button type="button" class="btn-outline" id="cartBrowseBtn">Browse products</button>
        </div>
        <div class="cart-items" id="cartItems" hidden></div>
    </div>

    <div class="cart-footer">
        <div class="cart-total-row">
            <span>Total</span>
            <strong id="cartTotal">RM 0.00</strong>
        </div>
        <p class="cart-note">Final stock and price are confirmed at checkout.</p>
        <button type="button" class="btn-checkout" id="cartCheckoutBtn" disabled>Proceed to Checkout</button>
    </div>
</aside>

<div class="modal-overlay" id="outletSwitchModal" role="dialog" aria-modal="true" aria-labelledby="outletSwitchTitle">
    <div class="modal-box storefront-modal">
        <div class="modal-header">
            <h2 id="outletSwitchTitle">Switch outlet?</h2>
            <button type="button" class="close-modal" data-cancel-outlet-switch aria-label="Close">&times;</button>
        </div>
        <p>
            Your cart contains items from <strong id="switchCurrentOutlet">your current outlet</strong>.
            Switching to <strong id="switchTargetOutlet">the new outlet</strong> will clear it.
        </p>
        <div class="modal-actions">
            <button type="button" class="btn-outline" data-cancel-outlet-switch>Stay here</button>
            <button type="button" class="btn-danger" id="confirmOutletSwitch">Switch &amp; clear cart</button>
        </div>
    </div>
</div>

<div class="store-toast" id="storeToast" role="status" aria-live="polite"></div>

<script>
window.STOREFRONT = <?php echo json_encode($storefront_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="js/main.js"></script>
<script src="js/storefront.js"></script>
</body>
</html>
