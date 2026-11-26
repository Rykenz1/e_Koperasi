/* ==========================================================================
   e-Koperasi - Outlet Dashboard
   Page-specific logic for outlet_dashboard.php only.
   Functions stay global because the PHP renders inline onclick handlers
   that pass database rows in as arguments.
   ========================================================================== */

// Switch the Add-Item modal between "New Product" and "From Catalog"
function switchAddMode(mode) {
    const newForm = document.getElementById('newProductForm');
    const exForm = document.getElementById('existingProductForm');
    const tabNew = document.getElementById('tabNewBtn');
    const tabEx = document.getElementById('tabExistingBtn');

    if (mode === 'existing' && exForm) {
        newForm.style.display = 'none';
        exForm.style.display = 'block';
        tabNew.classList.remove('active');
        tabEx.classList.add('active');
    } else {
        newForm.style.display = 'block';
        if (exForm) exForm.style.display = 'none';
        tabNew.classList.add('active');
        if (tabEx) tabEx.classList.remove('active');
    }
}

// Pre-fill Edit Inventory Item modal
function openEditProductModal(product) {
    const grouped = parseInt(product.group_id, 10) > 0;

    document.getElementById('edit_product_id').value = product.product_id;
    document.getElementById('edit_product_name').value = product.product_name;
    document.getElementById('edit_category_id').value = product.category_id;
    document.getElementById('edit_price').value = product.price;
    document.getElementById('edit_reorder_level').value = product.reorder_level;
    document.getElementById('edit_status').value = product.status;
    setDropzoneImage('editProductDropzone', product.image_url);

    // Admin-managed variant: master fields are read-only at the outlet.
    document.getElementById('edit_product_name').readOnly = grouped;
    document.getElementById('edit_category_id').disabled = grouped;
    document.getElementById('edit_price').readOnly = grouped;
    const imageInput = document.querySelector('#editProductDropzone input[type="file"]');
    if (imageInput) imageInput.disabled = grouped;
    const dropzone = document.getElementById('editProductDropzone');
    dropzone.style.opacity = grouped ? '0.6' : '';
    dropzone.style.pointerEvents = grouped ? 'none' : '';
    document.getElementById('edit_grouped_notice').style.display = grouped ? 'block' : 'none';

    openModal('editProductModal');
}

// Pre-fill Restock / Adjust Stock modal
function openStockModal(product) {
    document.getElementById('stock_product_id').value = product.product_id;
    document.getElementById('stock_product_label').textContent = product.product_name;
    document.getElementById('stock_current_qty').textContent = product.stock_quantity;
    openModal('stockModal');
}

// Inventory search + stock-state filtering
let currentStockFilter = 'all';

function setStockFilter(filter, btn) {
    currentStockFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterInventory();
}

function filterInventory() {
    const query = document.getElementById('inventorySearch').value.toLowerCase();
    document.querySelectorAll('#inventoryList .item-card').forEach(card => {
        const nameMatch = card.dataset.name.includes(query);
        let filterMatch = true;
        if (currentStockFilter === 'low') filterMatch = (card.dataset.stock === 'low');
        else if (currentStockFilter === 'out') filterMatch = (card.dataset.stock === 'out');
        else if (currentStockFilter === 'inactive') filterMatch = (card.dataset.status === 'inactive');
        card.style.display = (nameMatch && filterMatch) ? 'flex' : 'none';
    });
}

/* ==========================================================================
   Live outlet order queue
   Polls api/fetch_orders.php every 4 seconds. The sidebar badge updates on
   every tab; the cards are rebuilt only when the queue data changes.
   ========================================================================== */
(function () {
    'use strict';

    const POLL_INTERVAL = 4000;
    const ENDPOINT = 'api/fetch_orders.php';
    const badge = document.getElementById('orderQueueCount');
    const queue = document.getElementById('orderQueueList');

    if (!badge && !queue) {
        return;
    }

    const outletName = queue && queue.dataset.outletName
        ? queue.dataset.outletName
        : 'this outlet';
    let lastSignature = null;
    let inFlight = false;
    // Items are expanded by default; remember only the orders staff collapse so
    // the choice survives the next poll-driven rebuild.
    const collapsedOrders = new Set();

    function signature(data) {
        return data.orders.map(order => [
            order.order_id,
            order.order_status,
            order.order_date,
            order.fulfilment_method,
            order.total_amount,
            order.customer_name,
            (Array.isArray(order.items) ? order.items : []).map(item => [
                item.product_name,
                item.quantity,
                item.unit_price,
                item.subtotal
            ].join('\u0003')).join('\u0004')
        ].join('\u0001')).join('\u0002');
    }

    function applyCollapsed(card, collapsed) {
        card.classList.toggle('items-collapsed', collapsed);
        const toggle = card.querySelector('.order-toggle');
        if (!toggle) {
            return;
        }
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const count = toggle.dataset.itemCount || '0';
        toggle.textContent = (collapsed ? 'Show Items' : 'Hide Items') + ' (' + count + ')';
    }

    function bindToggle(toggle) {
        if (toggle.dataset.bound === '1') {
            return;
        }
        toggle.dataset.bound = '1';
        toggle.addEventListener('click', () => {
            const orderId = parseInt(toggle.dataset.orderId, 10);
            const card = toggle.closest('.order-card');
            if (!Number.isInteger(orderId) || !card) {
                return;
            }
            const collapsed = !collapsedOrders.has(orderId);
            if (collapsed) {
                collapsedOrders.add(orderId);
            } else {
                collapsedOrders.delete(orderId);
            }
            applyCollapsed(card, collapsed);
        });
    }

    function makeItemsList(order) {
        const list = document.createElement('ul');
        list.className = 'order-items';
        list.id = 'order-items-' + order.order_id;

        const items = Array.isArray(order.items) ? order.items : [];
        if (items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'order-items-empty';
            empty.textContent = 'No item details available.';
            list.appendChild(empty);
            return list;
        }

        items.forEach(item => {
            const row = document.createElement('li');

            const name = document.createElement('span');
            name.className = 'order-item-name';
            name.textContent = item.product_name;

            const qty = document.createElement('span');
            qty.className = 'order-item-qty';
            qty.textContent = '\u00d7' + item.quantity;

            const price = document.createElement('span');
            price.className = 'order-item-price';
            price.textContent = 'RM ' + item.subtotal;

            row.append(name, qty, price);
            list.appendChild(row);
        });

        return list;
    }

    function makeHiddenInput(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }

    function makeCard(order) {
        const card = document.createElement('div');
        card.className = 'item-card order-card';
        card.dataset.orderId = String(order.order_id);

        const info = document.createElement('div');
        info.className = 'item-info';

        const heading = document.createElement('h3');
        heading.append('Order #' + order.order_id + ' \u2014 ' + order.customer_name + ' ');
        const statusBadge = document.createElement('span');
        statusBadge.className = 'badge ' + (order.order_status === 'Ready' ? 'badge-active' : 'badge-low');
        statusBadge.textContent = order.order_status;
        heading.appendChild(statusBadge);

        const details = document.createElement('p');
        details.append('Method: ');
        const method = document.createElement('strong');
        method.textContent = order.fulfilment_method;
        details.append(method, ' \u2022 Total: ');
        const total = document.createElement('strong');
        total.textContent = 'RM ' + order.total_amount;
        details.append(total, ' \u2022 Placed: ', order.order_date);

        const items = Array.isArray(order.items) ? order.items : [];
        info.append(heading, details, makeItemsList(order));

        const actions = document.createElement('div');
        actions.className = 'card-actions';

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn-outline order-toggle';
        toggle.dataset.orderId = String(order.order_id);
        toggle.dataset.itemCount = String(items.length);
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-controls', 'order-items-' + order.order_id);
        actions.appendChild(toggle);

        let nextStatus = null;
        let buttonLabel = '';
        if (order.order_status === 'Pending') {
            nextStatus = 'Ready';
            buttonLabel = 'Mark Ready';
        } else if (order.order_status === 'Ready') {
            nextStatus = 'Completed';
            buttonLabel = 'Complete Handover';
        }

        if (nextStatus) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.append(
                makeHiddenInput('action', 'update_order_status'),
                makeHiddenInput('order_id', order.order_id),
                makeHiddenInput('order_status', nextStatus)
            );
            const button = document.createElement('button');
            button.type = 'submit';
            button.className = 'btn-quick';
            button.textContent = buttonLabel;
            form.appendChild(button);
            actions.appendChild(form);
        }

        card.append(info, actions);
        bindToggle(toggle);
        applyCollapsed(card, collapsedOrders.has(order.order_id));
        return card;
    }

    function render(data) {
        if (badge) {
            badge.textContent = String(data.count);
        }
        if (!queue) {
            return;
        }

        const nextSignature = signature(data);
        if (nextSignature === lastSignature) {
            return;
        }
        lastSignature = nextSignature;

        if (data.orders.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'queue-empty';
            empty.textContent = 'No pending or ready orders for ' + outletName + ' right now.';
            queue.replaceChildren(empty);
            return;
        }

        const fragment = document.createDocumentFragment();
        data.orders.forEach(order => fragment.appendChild(makeCard(order)));
        queue.replaceChildren(fragment);
    }

    async function poll() {
        if (inFlight) {
            return;
        }
        inFlight = true;

        try {
            const response = await fetch(ENDPOINT, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            if (!data || !Array.isArray(data.orders)) {
                return;
            }
            render(data);
        } catch (error) {
            // Keep the last rendered queue; the next tick retries.
        } finally {
            inFlight = false;
        }
    }

    // The first paint is PHP-rendered; wire up its toggles before polling.
    if (queue) {
        queue.querySelectorAll('.order-toggle').forEach(bindToggle);
    }

    poll();
    setInterval(() => {
        if (!document.hidden) {
            poll();
        }
    }, POLL_INTERVAL);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            poll();
        }
    });
})();
