/* ==========================================================================
   e-Koperasi - Checkout
   Loads the localStorage cart, validates it against the server catalog and
   submits a minimal product/quantity payload.
   ========================================================================== */

(function () {
    'use strict';

    const config = window.STOREFRONT || {};
    const cartApi = window.StorefrontCart;
    const form = document.getElementById('checkoutForm');

    if (!cartApi || !form) {
        return;
    }

    const catalog = config.catalog || {};
    const itemsEl = document.getElementById('checkoutItems');
    const emptyEl = document.getElementById('checkoutEmpty');
    const totalEl = document.getElementById('checkoutTotal');
    const cartJsonEl = document.getElementById('cartJson');
    const placeOrderBtn = document.getElementById('placeOrderBtn');
    const noticeEl = document.getElementById('checkoutNotice');

    function setNotice(message) {
        if (!noticeEl) {
            return;
        }
        noticeEl.textContent = message;
        noticeEl.hidden = !message;
    }

    function catalogItem(productId) {
        return catalog[String(productId)] || catalog[productId] || null;
    }

    function render() {
        let cart = cartApi.read();
        cart = cartApi.ensureContext(setNotice);

        const validItems = [];
        let changed = false;

        Object.keys(cart.items).forEach(function (key) {
            const item = cart.items[key];
            const current = catalogItem(item.productId);

            if (!current || Number(current.stock) <= 0) {
                delete cart.items[key];
                changed = true;
                return;
            }

            const stock = Number(current.stock) || 0;
            const quantity = Math.min(Math.max(1, Number(item.quantity) || 1), stock);
            if (quantity !== item.quantity) {
                changed = true;
            }

            cart.items[key] = {
                productId: Number(item.productId),
                name: current.name || item.name,
                price: Number(current.price) || 0,
                image: current.image || cartApi.defaultImage,
                stock: stock,
                quantity: quantity,
            };
            validItems.push(cart.items[key]);
        });

        if (changed) {
            cartApi.write(cart);
            setNotice('Some cart items were adjusted to match current branch stock.');
        }

        itemsEl.textContent = '';

        if (!validItems.length) {
            emptyEl.hidden = false;
            totalEl.textContent = cartApi.formatMoney(0);
            placeOrderBtn.disabled = true;
            cartJsonEl.value = '[]';
            return;
        }

        emptyEl.hidden = true;
        let total = 0;

        validItems.forEach(function (item) {
            const lineTotal = item.price * item.quantity;
            total += lineTotal;

            const row = document.createElement('div');
            row.className = 'checkout-item';

            const image = document.createElement('img');
            image.src = item.image || cartApi.defaultImage;
            image.alt = item.name;
            image.addEventListener('error', function () {
                image.onerror = null;
                image.src = cartApi.defaultImage;
            });

            const info = document.createElement('div');
            const name = document.createElement('p');
            name.className = 'checkout-item-name';
            name.textContent = item.name;
            const meta = document.createElement('p');
            meta.className = 'checkout-item-meta';
            meta.textContent = item.quantity + ' \u00d7 ' + cartApi.formatMoney(item.price);
            info.append(name, meta);

            const line = document.createElement('span');
            line.className = 'checkout-item-total';
            line.textContent = cartApi.formatMoney(lineTotal);

            row.append(image, info, line);
            itemsEl.appendChild(row);
        });

        totalEl.textContent = cartApi.formatMoney(total);
        placeOrderBtn.disabled = false;
        cartJsonEl.value = JSON.stringify(validItems.map(function (item) {
            return { product_id: item.productId, quantity: item.quantity };
        }));
    }

    form.addEventListener('submit', function (event) {
        render();

        let payload = [];
        try {
            payload = JSON.parse(cartJsonEl.value);
        } catch (error) {
            payload = [];
        }

        if (!Array.isArray(payload) || payload.length === 0) {
            event.preventDefault();
            setNotice('Your cart is empty or no longer valid for this outlet.');
            return;
        }

        placeOrderBtn.disabled = true;
        placeOrderBtn.textContent = 'Placing order...';
    });

    window.addEventListener('storage', function (event) {
        if (event.key === cartApi.key) {
            render();
        }
    });

    render();
})();
