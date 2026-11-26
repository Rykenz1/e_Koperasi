/* ==========================================================================
   e-Koperasi - Customer Storefront
   Shared cart storage helpers plus the index.php catalog and cart UI.
   ========================================================================== */

(function () {
    'use strict';

    const config = window.STOREFRONT || {};
    const CART_KEY = config.cartKey || 'ekoperasi_storefront_cart_v1';
    const DEFAULT_IMAGE = config.defaultImage || 'uploads/products/default.png';
    const outletId = Number(config.outletId) || 0;
    const currentUserId = config.isLoggedIn && config.userId ? String(config.userId) : 'guest';

    function safeParse(raw) {
        try {
            return JSON.parse(raw);
        } catch (error) {
            return null;
        }
    }

    function storageGet() {
        try {
            return window.localStorage.getItem(CART_KEY);
        } catch (error) {
            return null;
        }
    }

    function storageSet(value) {
        try {
            window.localStorage.setItem(CART_KEY, value);
        } catch (error) {
            /* Storage can be unavailable in private mode. */
        }
    }

    function storageRemove() {
        try {
            window.localStorage.removeItem(CART_KEY);
        } catch (error) {
            /* Ignore storage errors. */
        }
    }

    function emptyCart() {
        return { outletId: outletId, owner: currentUserId, items: {} };
    }

    function normalizeCart(raw) {
        if (!raw || typeof raw !== 'object') {
            return emptyCart();
        }

        const items = {};
        const sourceItems = raw.items && typeof raw.items === 'object' ? raw.items : {};

        Object.keys(sourceItems).forEach(function (key) {
            const item = sourceItems[key];
            if (!item || typeof item !== 'object') {
                return;
            }

            const id = Number(item.productId);
            const quantity = Number(item.quantity);
            if (!id || !Number.isFinite(quantity) || quantity <= 0) {
                return;
            }

            const image = typeof item.image === 'string' && item.image.indexOf('uploads/products/') === 0
                ? item.image
                : DEFAULT_IMAGE;

            items[String(id)] = {
                productId: id,
                name: String(item.name || 'Product'),
                price: Number(item.price) || 0,
                image: image,
                stock: Number(item.stock) || 0,
                quantity: Math.max(1, Math.floor(quantity)),
            };
        });

        return {
            outletId: Number(raw.outletId) || 0,
            owner: String(raw.owner || 'guest'),
            items: items,
        };
    }

    function readCart() {
        const raw = storageGet();
        return raw ? normalizeCart(safeParse(raw)) : emptyCart();
    }

    function writeCart(cart) {
        storageSet(JSON.stringify(cart));
    }

    function clearCart() {
        storageRemove();
    }

    function itemCount(cart) {
        return Object.keys(cart.items).reduce(function (sum, key) {
            return sum + cart.items[key].quantity;
        }, 0);
    }

    function cartTotal(cart) {
        return Object.keys(cart.items).reduce(function (sum, key) {
            const item = cart.items[key];
            return sum + (item.price * item.quantity);
        }, 0);
    }

    function formatMoney(value) {
        return 'RM ' + (Number(value) || 0).toFixed(2);
    }

    function cartOwnerMatches(cart) {
        if (!cart.owner) {
            return true;
        }
        if (currentUserId === 'guest') {
            return cart.owner === 'guest';
        }
        if (cart.owner === 'guest') {
            return true;
        }
        return cart.owner === currentUserId;
    }

    function ensureCartContext(notify) {
        let cart = readCart();
        let changed = false;

        if (!outletId) {
            clearCart();
            return emptyCart();
        }

        if (cart.outletId !== outletId) {
            cart = emptyCart();
            changed = true;
            if (notify) {
                notify('Your cart was cleared because you switched outlets.');
            }
        } else if (!cartOwnerMatches(cart)) {
            cart = emptyCart();
            changed = true;
            if (notify) {
                notify('Your cart was cleared because the account changed.');
            }
        } else if (cart.owner === 'guest' && currentUserId !== 'guest') {
            cart.owner = currentUserId;
            changed = true;
        }

        if (changed) {
            writeCart(cart);
        }

        return cart;
    }

    window.StorefrontCart = {
        key: CART_KEY,
        outletId: outletId,
        currentUserId: currentUserId,
        defaultImage: DEFAULT_IMAGE,
        read: readCart,
        write: writeCart,
        clear: clearCart,
        normalize: normalizeCart,
        empty: emptyCart,
        ensureContext: ensureCartContext,
        itemCount: itemCount,
        total: cartTotal,
        formatMoney: formatMoney,
    };

    document.querySelectorAll('[data-storefront-logout]').forEach(function (link) {
        link.addEventListener('click', clearCart);
    });

    const grid = document.getElementById('productGrid');
    if (!grid) {
        return;
    }

    const cartDrawer = document.getElementById('cartDrawer');
    const cartBackdrop = document.getElementById('cartBackdrop');
    const cartOpenBtn = document.getElementById('cartOpenBtn');
    const cartCloseBtn = document.getElementById('cartClose');
    const cartItemsEl = document.getElementById('cartItems');
    const cartEmptyEl = document.getElementById('cartEmpty');
    const cartCountEl = document.getElementById('cartCount');
    const cartTotalEl = document.getElementById('cartTotal');
    const cartCheckoutBtn = document.getElementById('cartCheckoutBtn');
    const cartBrowseBtn = document.getElementById('cartBrowseBtn');
    const searchInput = document.getElementById('storefrontSearch');
    const stockToggle = document.getElementById('inStockOnly');
    const categoryPills = document.getElementById('categoryPills');
    const catalogCount = document.getElementById('catalogCount');
    const catalogEmpty = document.getElementById('catalogEmpty');
    const toast = document.getElementById('storeToast');
    const outletSelect = document.getElementById('outletSelect');
    const switchModal = document.getElementById('outletSwitchModal');
    const switchCurrent = document.getElementById('switchCurrentOutlet');
    const switchTarget = document.getElementById('switchTargetOutlet');
    const confirmSwitch = document.getElementById('confirmOutletSwitch');

    let cart = ensureCartContext(showToast);
    let activeCategory = 'all';
    let pendingOutletId = null;
    let lastFocusedElement = null;
    let toastTimer = null;

    function showToast(message) {
        if (!toast) {
            return;
        }
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2600);
    }

    function stockMapFromDom() {
        const map = {};

        document.querySelectorAll('[data-role="variant"]').forEach(function (chip) {
            const id = Number(chip.dataset.productId);
            if (!id) {
                return;
            }
            map[id] = {
                productId: id,
                name: chip.dataset.name || 'Product',
                price: Number(chip.dataset.price) || 0,
                image: chip.dataset.image || DEFAULT_IMAGE,
                stock: Number(chip.dataset.stock) || 0,
            };
        });

        document.querySelectorAll('[data-role="add-to-cart"][data-product-id]').forEach(function (button) {
            const id = Number(button.dataset.productId);
            if (!id) {
                return;
            }
            map[id] = {
                productId: id,
                name: button.dataset.name || 'Product',
                price: Number(button.dataset.price) || 0,
                image: button.dataset.image || DEFAULT_IMAGE,
                stock: Number(button.dataset.stock) || 0,
            };
        });

        return map;
    }

    function reconcileCart() {
        const stockMap = stockMapFromDom();
        let changed = false;
        let adjusted = false;

        Object.keys(cart.items).forEach(function (key) {
            const item = cart.items[key];
            const current = stockMap[item.productId];

            if (!current || current.stock <= 0) {
                delete cart.items[key];
                changed = true;
                adjusted = true;
                return;
            }

            const nextQuantity = Math.min(item.quantity, current.stock);
            if (nextQuantity !== item.quantity) {
                adjusted = true;
                changed = true;
            }

            cart.items[key] = {
                productId: current.productId,
                name: current.name,
                price: current.price,
                image: current.image,
                stock: current.stock,
                quantity: Math.max(1, nextQuantity),
            };
        });

        if (changed) {
            writeCart(cart);
        }
        if (adjusted) {
            showToast('Some cart items were updated to match current branch stock.');
        }
    }

    function badgeClassForState(state) {
        if (state === 'out') {
            return 'badge-out';
        }
        if (state === 'low') {
            return 'badge-low';
        }
        return 'badge-active';
    }

    function addItem(payload) {
        const stock = Number(payload.stock) || 0;
        if (stock <= 0) {
            showToast('That item is out of stock.');
            return;
        }

        const key = String(payload.productId);
        const existing = cart.items[key];
        const nextQuantity = existing ? existing.quantity + 1 : 1;

        if (nextQuantity > stock) {
            showToast('Only ' + stock + ' left in stock at this outlet.');
            return;
        }

        cart.items[key] = {
            productId: Number(payload.productId),
            name: payload.name || 'Product',
            price: Number(payload.price) || 0,
            image: payload.image || DEFAULT_IMAGE,
            stock: stock,
            quantity: nextQuantity,
        };

        writeCart(cart);
        renderCart();
        showToast('Added ' + cart.items[key].name + ' to your cart.');
    }

    function changeQuantity(productId, delta) {
        const key = String(productId);
        const item = cart.items[key];
        if (!item) {
            return;
        }

        const nextQuantity = item.quantity + delta;
        if (nextQuantity <= 0) {
            delete cart.items[key];
            writeCart(cart);
            renderCart();
            return;
        }

        if (nextQuantity > item.stock) {
            showToast('Only ' + item.stock + ' left in stock at this outlet.');
            return;
        }

        item.quantity = nextQuantity;
        writeCart(cart);
        renderCart();
    }

    function removeItem(productId) {
        delete cart.items[String(productId)];
        writeCart(cart);
        renderCart();
    }

    function makeCartItem(item) {
        const row = document.createElement('div');
        row.className = 'cart-item';
        row.dataset.productId = String(item.productId);

        const image = document.createElement('img');
        image.className = 'cart-item-image';
        image.src = item.image || DEFAULT_IMAGE;
        image.alt = item.name;
        image.addEventListener('error', function () {
            image.onerror = null;
            image.src = DEFAULT_IMAGE;
        });

        const body = document.createElement('div');
        body.className = 'cart-item-body';

        const name = document.createElement('p');
        name.className = 'cart-item-name';
        name.textContent = item.name;

        const price = document.createElement('p');
        price.className = 'cart-item-price';
        price.textContent = formatMoney(item.price) + ' each';

        const controls = document.createElement('div');
        controls.className = 'cart-item-controls';

        const stepper = document.createElement('div');
        stepper.className = 'qty-stepper';

        const decrease = document.createElement('button');
        decrease.type = 'button';
        decrease.className = 'qty-btn';
        decrease.dataset.cartAction = 'decrease';
        decrease.dataset.productId = String(item.productId);
        decrease.setAttribute('aria-label', 'Decrease quantity');
        decrease.textContent = '\u2212';

        const quantity = document.createElement('span');
        quantity.className = 'qty-value';
        quantity.textContent = String(item.quantity);

        const increase = document.createElement('button');
        increase.type = 'button';
        increase.className = 'qty-btn';
        increase.dataset.cartAction = 'increase';
        increase.dataset.productId = String(item.productId);
        increase.setAttribute('aria-label', 'Increase quantity');
        increase.disabled = item.quantity >= item.stock;
        increase.textContent = '+';

        stepper.append(decrease, quantity, increase);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'cart-remove';
        remove.dataset.cartAction = 'remove';
        remove.dataset.productId = String(item.productId);
        remove.textContent = 'Remove';

        const lineTotal = document.createElement('span');
        lineTotal.className = 'cart-line-total';
        lineTotal.textContent = formatMoney(item.price * item.quantity);

        controls.append(stepper, remove, lineTotal);
        body.append(name, price, controls);
        row.append(image, body);

        return row;
    }

    function renderCart() {
        const count = itemCount(cart);
        cartCountEl.textContent = String(count);
        cartCountEl.classList.toggle('is-empty', count === 0);
        cartTotalEl.textContent = formatMoney(cartTotal(cart));
        cartCheckoutBtn.disabled = count === 0;

        cartItemsEl.textContent = '';
        const items = Object.keys(cart.items)
            .map(function (key) { return cart.items[key]; })
            .sort(function (a, b) { return a.name.localeCompare(b.name); });

        cartEmptyEl.hidden = items.length > 0;
        cartItemsEl.hidden = items.length === 0;

        items.forEach(function (item) {
            cartItemsEl.appendChild(makeCartItem(item));
        });
    }

    function openCart() {
        lastFocusedElement = document.activeElement;
        cartBackdrop.hidden = false;
        cartBackdrop.classList.add('is-open');
        cartDrawer.classList.add('is-open');
        cartDrawer.setAttribute('aria-hidden', 'false');
        cartOpenBtn.setAttribute('aria-expanded', 'true');
        document.body.classList.add('cart-open');
        if (cartCloseBtn) {
            cartCloseBtn.focus();
        }
    }

    function closeCart() {
        cartBackdrop.classList.remove('is-open');
        cartBackdrop.hidden = true;
        cartDrawer.classList.remove('is-open');
        cartDrawer.setAttribute('aria-hidden', 'true');
        cartOpenBtn.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('cart-open');
        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
    }

    function applyVariant(card, chip) {
        card.querySelectorAll('[data-role="variant"]').forEach(function (button) {
            button.classList.toggle('is-selected', button === chip);
            button.setAttribute('aria-pressed', button === chip ? 'true' : 'false');
        });

        const stock = Number(chip.dataset.stock) || 0;
        const state = chip.dataset.stockState || (stock <= 0 ? 'out' : 'in');
        const image = card.querySelector('[data-role="card-image"]');
        const badge = card.querySelector('[data-role="stock-badge"]');
        const price = card.querySelector('[data-role="price"]');
        const stockText = card.querySelector('[data-role="stock-text"]');
        const addButton = card.querySelector('[data-role="add-to-cart"]');
        const addLabel = card.querySelector('[data-role="add-label"]');

        if (image) {
            image.src = chip.dataset.image || image.src;
            image.alt = chip.dataset.name || image.alt;
        }
        if (badge) {
            badge.className = 'badge ' + badgeClassForState(state);
            badge.textContent = chip.dataset.stockLabel || (stock <= 0 ? 'Out of Stock' : 'In Stock');
        }
        if (price) {
            price.textContent = formatMoney(chip.dataset.price);
        }
        if (stockText) {
            stockText.textContent = stock <= 0 ? 'Out of stock' : stock + ' in stock';
        }
        if (addButton) {
            addButton.disabled = stock <= 0;
        }
        if (addLabel) {
            addLabel.textContent = stock <= 0 ? 'Out of stock' : 'Add to cart';
        }
    }

    function applyFilters() {
        const query = (searchInput.value || '').trim().toLowerCase();
        const cards = grid.querySelectorAll('.store-card');
        let visible = 0;

        cards.forEach(function (card) {
            const searchMatch = !query || (card.dataset.search || '').indexOf(query) !== -1;
            const categoryMatch = activeCategory === 'all' || card.dataset.category === activeCategory;
            const stockMatch = !stockToggle.checked || card.dataset.hasStock === '1';
            const show = searchMatch && categoryMatch && stockMatch;
            card.hidden = !show;
            if (show) {
                visible += 1;
            }
        });

        catalogCount.textContent = 'Showing ' + visible + ' of ' + cards.length + ' items';

        if (visible === 0) {
            catalogEmpty.hidden = false;
            catalogEmpty.innerHTML = '';
            const title = document.createElement('h3');
            title.textContent = cards.length === 0 ? 'No products available' : 'No products match your filters';
            const text = document.createElement('p');
            text.textContent = cards.length === 0
                ? 'This outlet has no active catalog items right now.'
                : 'Try a different search term or category.';
            catalogEmpty.append(title, text);
        } else {
            catalogEmpty.hidden = true;
        }
    }

    function navigateToOutlet(targetId) {
        if (!targetId || targetId === String(outletId)) {
            return;
        }
        window.location.href = 'index.php?outlet_id=' + encodeURIComponent(targetId);
    }

    function resetOutletSelect() {
        if (outletSelect) {
            outletSelect.value = String(outletId);
        }
        pendingOutletId = null;
    }

    function handleOutletChange() {
        if (!outletSelect) {
            return;
        }
        const targetId = outletSelect.value;
        if (targetId === String(outletId)) {
            return;
        }

        if (itemCount(cart) > 0 && switchModal) {
            pendingOutletId = targetId;
            if (switchCurrent) {
                switchCurrent.textContent = config.outletName || 'your current outlet';
            }
            if (switchTarget) {
                const option = outletSelect.options[outletSelect.selectedIndex];
                switchTarget.textContent = option ? option.textContent.split(' \u2014 ')[0] : 'the new outlet';
            }
            if (window.openModal) {
                window.openModal('outletSwitchModal');
            }
            return;
        }

        navigateToOutlet(targetId);
    }

    if (!outletId) {
        if (cartOpenBtn) {
            cartOpenBtn.disabled = true;
        }
        if (cartCheckoutBtn) {
            cartCheckoutBtn.disabled = true;
        }
        return;
    }

    reconcileCart();
    renderCart();
    applyFilters();

    cartOpenBtn.addEventListener('click', openCart);
    cartCloseBtn.addEventListener('click', closeCart);
    cartBackdrop.addEventListener('click', closeCart);
    cartBrowseBtn.addEventListener('click', closeCart);

    cartItemsEl.addEventListener('click', function (event) {
        const button = event.target.closest('[data-cart-action]');
        if (!button) {
            return;
        }
        const productId = button.dataset.productId;
        if (button.dataset.cartAction === 'increase') {
            changeQuantity(productId, 1);
        } else if (button.dataset.cartAction === 'decrease') {
            changeQuantity(productId, -1);
        } else if (button.dataset.cartAction === 'remove') {
            removeItem(productId);
        }
    });

    grid.addEventListener('click', function (event) {
        const chip = event.target.closest('[data-role="variant"]');
        if (chip) {
            const card = chip.closest('.store-card');
            if (card) {
                applyVariant(card, chip);
            }
            return;
        }

        const addButton = event.target.closest('[data-role="add-to-cart"]');
        if (!addButton) {
            return;
        }

        const card = addButton.closest('.store-card');
        if (card && card.dataset.kind === 'group') {
            const selected = card.querySelector('[data-role="variant"].is-selected');
            if (!selected) {
                showToast('Choose a variant first.');
                return;
            }
            addItem({
                productId: selected.dataset.productId,
                name: selected.dataset.name,
                price: selected.dataset.price,
                image: selected.dataset.image,
                stock: selected.dataset.stock,
            });
        } else {
            addItem({
                productId: addButton.dataset.productId,
                name: addButton.dataset.name,
                price: addButton.dataset.price,
                image: addButton.dataset.image,
                stock: addButton.dataset.stock,
            });
        }
    });

    searchInput.addEventListener('input', applyFilters);
    stockToggle.addEventListener('change', applyFilters);

    categoryPills.addEventListener('click', function (event) {
        const pill = event.target.closest('.category-pill');
        if (!pill) {
            return;
        }
        activeCategory = pill.dataset.category || 'all';
        categoryPills.querySelectorAll('.category-pill').forEach(function (button) {
            const active = button === pill;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        applyFilters();
    });

    cartCheckoutBtn.addEventListener('click', function () {
        if (itemCount(cart) === 0) {
            showToast('Your cart is empty.');
            return;
        }
        if (config.isLoggedIn && config.role !== 'Customer') {
            showToast('Checkout is available for customer accounts.');
            return;
        }
        window.location.href = config.isLoggedIn ? config.checkoutUrl : config.loginUrl;
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && cartDrawer.classList.contains('is-open')) {
            closeCart();
        }

        if (event.key === 'Escape' && switchModal && switchModal.classList.contains('active')) {
            if (window.closeModal) {
                window.closeModal('outletSwitchModal');
            }
            resetOutletSelect();
        }

        if (event.key !== 'Tab' || !cartDrawer.classList.contains('is-open')) {
            return;
        }

        const focusable = cartDrawer.querySelectorAll(
            'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if (!focusable.length) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    if (outletSelect) {
        outletSelect.addEventListener('change', handleOutletChange);
    }

    document.querySelectorAll('[data-cancel-outlet-switch]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (window.closeModal) {
                window.closeModal('outletSwitchModal');
            }
            resetOutletSelect();
        });
    });

    if (confirmSwitch) {
        confirmSwitch.addEventListener('click', function () {
            if (!pendingOutletId) {
                return;
            }
            const targetId = pendingOutletId;
            clearCart();
            cart = emptyCart();
            if (window.closeModal) {
                window.closeModal('outletSwitchModal');
            }
            navigateToOutlet(targetId);
        });
    }

    window.addEventListener('storage', function (event) {
        if (event.key !== CART_KEY) {
            return;
        }
        cart = readCart();
        reconcileCart();
        renderCart();
    });
})();
