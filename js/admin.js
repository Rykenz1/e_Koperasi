// Pre-fill Edit Outlet Modal
function openEditOutletModal(outlet) {
    document.getElementById('edit_outlet_id').value = outlet.outlet_id;
    document.getElementById('edit_outlet_name').value = outlet.outlet_name;
    document.getElementById('edit_location').value = outlet.location || outlet.location_description || '';
    document.getElementById('edit_contact_number').value = outlet.contact_number || '';
    document.getElementById('edit_status').value = outlet.status || 'Active';

    // Split "08:00 AM - 10:00 PM" into Open and Close time inputs
    let openVal = '', closeVal = '';
    if (outlet.operating_hours && outlet.operating_hours.includes('-')) {
        const parts = outlet.operating_hours.split('-');
        openVal = parseTo24Hour(parts[0]);
        closeVal = parseTo24Hour(parts[1]);
    }
    document.getElementById('edit_open_time').value = openVal;
    document.getElementById('edit_close_time').value = closeVal;

    openModal('editOutletModal');
}

// Helper: Convert "8 am" or "08:00 AM" to "08:00" for <input type="time">
function parseTo24Hour(timeStr) {
    if (!timeStr) return '';
    const match = timeStr.trim().match(/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i);
    if (!match) return '';

    let hours = parseInt(match[1], 10);
    const minutes = match[2] || '00';
    const period = match[3] ? match[3].toLowerCase() : null;

    if (period === 'pm' && hours < 12) hours += 12;

    if (period === 'am' && hours === 12) hours = 0;

    return String(hours).padStart(2, '0') + ':' + minutes;
}

// Hide Outlet Dropdown if 'Superadmin' role is selected
function toggleOutletDropdown() {
    const role = document.getElementById('staff_role_select').value;
    const outletGroup = document.getElementById('outlet_assign_group');
    outletGroup.style.display = (role === 'Staff') ? 'block' : 'none';
}

// --- BULK ADD PRODUCTS -----------------------------------------------------

document.addEventListener('DOMContentLoaded', () => {
    const addButton = document.getElementById('addBulkRowBtn');
    if (addButton) addButton.addEventListener('click', createBulkRow);

    const form = document.getElementById('bulkAddForm');
    if (!form) return;

    // `novalidate` is set on the form so every failure uses the one inline
    // alert at the top of the modal instead of native browser tooltips.
    form.addEventListener('submit', validateBulkForm);

    // Clear the message as soon as the user changes anything in the form.
    form.addEventListener('input', hideBulkError);
    form.addEventListener('change', hideBulkError);
    form.addEventListener('click', event => {
        if (event.target.closest('button')) hideBulkError();
    });
});

// --- BULK ADD VALIDATION ---------------------------------------------------

function showBulkError(message) {
    const box = document.getElementById('bulkError');
    if (!box) return;
    box.textContent = message;
    box.hidden = false;
}

function hideBulkError() {
    const box = document.getElementById('bulkError');
    if (!box || box.hidden) return;
    box.textContent = '';
    box.hidden = true;
}

function bulkRowHasContent(row) {
    const values = [
        row.querySelector('input[name="product_name[]"]').value.trim(),
        row.querySelector('input[name="variant_name[]"]').value.trim(),
        row.querySelector('input[name="price[]"]').value.trim(),
        row.querySelector('[data-combobox="category"] .combobox-value').value.trim(),
        row.querySelector('[data-combobox="group"] .combobox-value').value.trim()
    ];
    const file = row.querySelector('input[type="file"]');
    const hasImage = file && file.files && file.files.length > 0;
    return !!(values.some(Boolean) || hasImage);
}

function validateBulkForm(event) {
    hideBulkError();

    const rows = Array.from(document.querySelectorAll('#bulkRows .bulk-row'));
    const filled = rows.filter(bulkRowHasContent);

    if (!filled.length) {
        event.preventDefault();
        showBulkError('Add at least one product before saving.');
        return;
    }

    function fail(row, message) {
        event.preventDefault();
        showBulkError('Row ' + (rows.indexOf(row) + 1) + ': ' + message);
    }

    for (const row of filled) {
        const grouped = bulkGroupSelected(row);
        const name = row.querySelector('input[name="product_name[]"]').value.trim();
        const variant = row.querySelector('input[name="variant_name[]"]').value.trim();
        const price = row.querySelector('input[name="price[]"]').value.trim();
        const priceValue = Number(price);

        if (grouped) {
            // The name field is derived from group + variant, so only the
            // variant itself needs to be supplied by the user.
            if (!variant) {
                fail(row, 'variant / flavour name is required for a grouped product.');
                return;
            }
        } else {
            if (!name) {
                fail(row, 'product name is required.');
                return;
            }
            const category = row.querySelector('[data-combobox="category"] .combobox-value').value.trim();
            if (!category || category === 'id:0') {
                fail(row, 'category is required.');
                return;
            }
        }

        if (price === '' || !Number.isFinite(priceValue)) {
            fail(row, 'a valid price is required.');
            return;
        }
        if (priceValue < 0) {
            fail(row, 'price cannot be negative.');
            return;
        }
    }
}

// Names typed into "+ Create ..." are kept client-side so other rows in the
// same batch can select them immediately; the server de-duplicates by name.
const bulkCreated = { category: [], group: [] };

function bulkOptions(kind) {
    const source = kind === 'category' ? (window.CATEGORIES || []) : (window.PRODUCT_GROUPS || []);
    const base = source.map(item => kind === 'category'
        ? { id: parseInt(item.category_id, 10), label: item.category_name, isNew: false }
        : { id: parseInt(item.group_id, 10), label: item.group_name, isNew: false });
    return base.concat(bulkCreated[kind] || []);
}

function bulkComboValue(combo) {
    return combo.querySelector('.combobox-value').value.trim();
}

function bulkGroupSelected(row) {
    const combo = row.querySelector('[data-combobox="group"]');
    const value = combo ? bulkComboValue(combo) : '';
    return value !== '' && value !== 'id:0';
}

function updateBulkDerivedName(row) {
    if (!bulkGroupSelected(row)) return; // standalone names belong to the user
    const groupCombo = row.querySelector('[data-combobox="group"]');
    const groupLabel = groupCombo.querySelector('.combobox-input').value.trim();
    const variant = row.querySelector('input[name="variant_name[]"]').value.trim();
    row.querySelector('.bulk-name').value = variant ? (groupLabel + ' ' + variant) : groupLabel;
}

function onBulkGroupChange(combo) {
    const row = combo.closest('.bulk-row');
    if (!row) return;
    const grouped = bulkGroupSelected(row);
    row.querySelector('.bulk-variant-field').hidden = !grouped;
    row.querySelector('.bulk-name').readOnly = grouped;
    updateBulkDerivedName(row);
}

function initBulkCombobox(combo, kind) {
    const input = combo.querySelector('.combobox-input');
    const hiddenInput = combo.querySelector('.combobox-value');
    const panel = combo.querySelector('.combobox-panel');
    const optionsBox = combo.querySelector('.combobox-options');
    const createButton = combo.querySelector('.combobox-create');

    function renderOptions(query) {
        optionsBox.textContent = '';
        const needle = (query || '').trim().toLowerCase();
        const matches = bulkOptions(kind).filter(item =>
            !needle || item.label.toLowerCase().includes(needle));

        if (!matches.length) {
            const empty = document.createElement('div');
            empty.className = 'combobox-empty';
            empty.textContent = 'No matches \u2014 use + Create';
            optionsBox.appendChild(empty);
            return;
        }

        matches.forEach(item => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'combobox-option';
            option.textContent = item.label;
            // Prevent blur before the click registers.
            option.addEventListener('mousedown', event => event.preventDefault());
            option.addEventListener('click', () => {
                input.value = item.label;
                hiddenInput.value = item.isNew ? 'name:' + item.label : 'id:' + item.id;
                panel.hidden = true;
                onBulkGroupChange(combo);
            });
            optionsBox.appendChild(option);
        });
    }

    function closePanel() {
        panel.hidden = true;
        panel.classList.remove('drop-up');
    }

    function openPanel(query) {
        renderOptions(query);
        panel.hidden = false;
        // Rows live in a scrollable box; flip the menu above the input when
        // there is not enough room below before the box clips it.
        const bounds = document.getElementById('bulkRows').getBoundingClientRect();
        const rect = panel.getBoundingClientRect();
        const roomAbove = rect.top - bounds.top;
        panel.classList.toggle('drop-up', rect.bottom > bounds.bottom && roomAbove > rect.height);
    }

    input.addEventListener('focus', () => openPanel(''));

    input.addEventListener('input', () => {
        // A typed value is no longer a confirmed selection until picked/created.
        hiddenInput.value = '';
        openPanel(input.value);
        onBulkGroupChange(combo);
    });

    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') closePanel();
    });

    createButton.addEventListener('mousedown', event => event.preventDefault());
    createButton.addEventListener('click', () => {
        const name = input.value.trim();
        if (!name) {
            input.focus();
            return;
        }
        const existing = bulkOptions(kind).find(item =>
            item.label.toLowerCase() === name.toLowerCase());
        if (existing) {
            input.value = existing.label;
            hiddenInput.value = existing.isNew ? 'name:' + existing.label : 'id:' + existing.id;
        } else {
            bulkCreated[kind].push({ id: 0, label: name, isNew: true });
            input.value = name;
            hiddenInput.value = 'name:' + name;
        }
        closePanel();
        onBulkGroupChange(combo);
    });

    // One listener per combobox keeps the component self-contained; panels are
    // cheap and the listener exits immediately for unrelated clicks.
    document.addEventListener('click', event => {
        if (!combo.contains(event.target)) closePanel();
    });
}

function updateBulkDeleteButtons() {
    const rows = document.querySelectorAll('#bulkRows .bulk-row');
    rows.forEach((row, index) => {
        const button = row.querySelector('.bulk-delete');
        // Keep the button's 32px slot so every row's image column lines up,
        // even when the first row cannot be deleted.
        const unavailable = rows.length <= 1 || index === 0;
        button.style.visibility = unavailable ? 'hidden' : 'visible';
        button.disabled = unavailable;
    });
}

function createBulkRow() {
    const template = document.getElementById('bulkRowTemplate');
    const rows = document.getElementById('bulkRows');
    if (!template || !rows) return null;

    const row = template.content.firstElementChild.cloneNode(true);
    rows.appendChild(row);

    row.querySelectorAll('[data-combobox]').forEach(combo => {
        initBulkCombobox(combo, combo.dataset.combobox);
    });
    const zone = row.querySelector('.bulk-thumb');
    initDropzone(zone);

    row.querySelector('.bulk-thumb-clear').addEventListener('click', event => {
        // Don't let the click bubble into the dropzone's click-to-browse.
        event.stopPropagation();
        const fileInput = zone.querySelector('input[type="file"]');
        if (fileInput) fileInput.value = '';
        setDropzoneImage(zone, '');
    });

    row.querySelector('.bulk-delete').addEventListener('click', () => {
        if (document.querySelectorAll('#bulkRows .bulk-row').length <= 1) return;
        row.remove();
        updateBulkDeleteButtons();
    });
    row.querySelector('input[name="variant_name[]"]').addEventListener('input', () => {
        updateBulkDerivedName(row);
    });

    updateBulkDeleteButtons();
    return row;
}

function resetBulkRows() {
    const rows = document.getElementById('bulkRows');
    if (!rows) return;
    rows.textContent = '';
    createBulkRow();
}

function openBulkAddModal() {
    resetBulkRows();
    openModal('bulkAddModal');
}

// Per-product "Add Variant" action: open the bulk form with the group picked.
function openBulkAddWithGroup(groupId) {
    openBulkAddModal();
    const id = parseInt(groupId, 10) || 0;
    const group = (window.PRODUCT_GROUPS || []).find(g => parseInt(g.group_id, 10) === id);
    const row = document.querySelector('#bulkRows .bulk-row');
    if (!group || !row) return;

    const combo = row.querySelector('[data-combobox="group"]');
    combo.querySelector('.combobox-input').value = group.group_name;
    combo.querySelector('.combobox-value').value = 'id:' + id;
    onBulkGroupChange(combo);
    row.querySelector('input[name="variant_name[]"]').focus();
}

// Keep product master fields consistent with the chosen group.
function toggleEditGroupFields() {
    const groupId = parseInt(document.getElementById('edit_master_group_id').value, 10) || 0;
    const grouped = groupId > 0;
    const variantField = document.getElementById('edit_master_variant_field');
    const nameInput = document.getElementById('edit_master_product_name');
    const categorySelect = document.getElementById('edit_master_category_id');

    variantField.style.display = grouped ? 'block' : 'none';
    document.getElementById('edit_master_variant_name').required = grouped;
    nameInput.readOnly = grouped;
    categorySelect.disabled = grouped;

    if (grouped) {
        const group = (window.PRODUCT_GROUPS || []).find(g => parseInt(g.group_id, 10) === groupId);
        if (group) {
            categorySelect.value = group.category_id;
            const variant = document.getElementById('edit_master_variant_name').value.trim();
            nameInput.value = variant ? (group.group_name + ' ' + variant) : group.group_name;
        }
    }
}

// Pre-fill Edit Master Product modal
function openEditMasterProductModal(product) {
    document.getElementById('edit_master_product_id').value = product.product_id;
    document.getElementById('edit_master_group_id').value = parseInt(product.group_id, 10) || 0;

    const variantName = product.variant_name || '';
    document.getElementById('edit_master_variant_name').value = variantName;
    document.getElementById('edit_master_category_id').value = product.category_id;
    document.getElementById('edit_master_price').value = product.price;
    setDropzoneImage('editMasterDropzone', product.image_url);

    toggleEditGroupFields();
    // Restore the exact current display name once readonly mode is applied.
    document.getElementById('edit_master_product_name').value = product.product_name;

    openModal('editMasterProductModal');
}

function openEditProductGroupModal(groupId) {
    const id = parseInt(groupId, 10) || 0;
    const group = (window.PRODUCT_GROUPS || []).find(g => parseInt(g.group_id, 10) === id);
    if (!group) return;

    document.getElementById('edit_group_id').value = group.group_id;
    document.getElementById('edit_group_name').value = group.group_name;
    document.getElementById('edit_group_category_id').value = group.category_id;
    setDropzoneImage('editGroupDropzone', group.image_url);
    openModal('editProductGroupModal');
}

// Build and open the per-branch stock breakdown for one master product.
function openProductBranches(productId, productName) {
    const rows = (window.BRANCH_STOCK && window.BRANCH_STOCK[productId])
        ? window.BRANCH_STOCK[productId]
        : [];

    document.getElementById('branchStockTitle').textContent = productName + ' \u2014 Branch Stock';
    const body = document.getElementById('branchStockBody');

    if (!rows.length) {
        body.innerHTML = '<p class="branch-stock-empty">This product is not carried by any branch yet. Outlet staff can add it from the catalog.</p>';
        openModal('branchStockModal');
        return;
    }

    let html = '<table class="log-table compact-table"><thead><tr>' +
        '<th>Branch</th><th>Stock</th><th>Reorder At</th><th>State</th>' +
        '</tr></thead><tbody>';

    rows.forEach(row => {
        const qty = parseInt(row.stock_quantity, 10) || 0;
        const reorder = parseInt(row.reorder_level, 10) || 0;

        let stateLabel = 'In stock';
        let stateBadge = 'badge-active';
        if (qty === 0) {
            stateLabel = 'Out of stock';
            stateBadge = 'badge-out';
        } else if (qty <= reorder) {
            stateLabel = 'Low stock';
            stateBadge = 'badge-low';
        }

        const isAvailable = row.status === 'Active';
        const availabilityLabel = isAvailable ? 'Available' : 'Unavailable';
        const availabilityBadge = isAvailable ? 'badge-active' : 'badge-muted';

        html += '<tr>' +
            '<td><a href="?tab=inventories&amp;outlet_id=' + encodeURIComponent(row.outlet_id) + '">' +
                escapeHtml(row.outlet_name) + '</a></td>' +
            '<td>' + qty + ' units</td>' +
            '<td>' + reorder + '</td>' +
            '<td>' +
                '<span class="badge ' + stateBadge + '">' + stateLabel + '</span> ' +
                '<span class="badge ' + availabilityBadge + '">' + availabilityLabel + '</span>' +
            '</td>' +
            '</tr>';
    });

    html += '</tbody></table>';
    body.innerHTML = html;
    openModal('branchStockModal');
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}