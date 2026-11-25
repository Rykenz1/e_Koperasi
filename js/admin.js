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

// --- PRODUCT GROUP / VARIANT HELPERS ---------------------------------------

function switchAddProductMode(mode) {
    const isVariant = mode === 'variant';
    const singleForm = document.getElementById('addMasterSingleForm');
    const variantForm = document.getElementById('addMasterVariantForm');
    if (!singleForm || !variantForm) return;

    singleForm.style.display = isVariant ? 'none' : 'block';
    variantForm.style.display = isVariant ? 'block' : 'none';

    document.getElementById('addTabSingle').classList.toggle('active', !isVariant);
    document.getElementById('addTabVariant').classList.toggle('active', isVariant);

    if (isVariant) {
        toggleVariantGroupFields();
    } else {
        setDropzoneImage('addMasterDropzone', '');
    }
}

function toggleVariantGroupFields() {
    const groupId = parseInt(document.getElementById('add_variant_group_id').value, 10) || 0;
    const newGroupFields = document.getElementById('add_variant_new_group_fields');
    const newCategoryField = document.getElementById('add_variant_new_group_category');
    const newNameInput = document.getElementById('add_variant_new_group_name');
    const categorySelect = document.getElementById('add_variant_category_id');

    newGroupFields.style.display = groupId === 0 ? 'block' : 'none';
    newCategoryField.style.display = groupId === 0 ? 'block' : 'none';
    newNameInput.required = groupId === 0;
    categorySelect.required = groupId === 0;
}

// Open the Add Product modal directly on the Variant tab, optionally with an
// existing group preselected (used by the per-product "Add Variant" action).
function openAddVariantModal(groupId) {
    openModal('addMasterProductModal');
    switchAddProductMode('variant');

    const groupSelect = document.getElementById('add_variant_group_id');
    const selected = parseInt(groupId, 10) || 0;
    groupSelect.value = selected;
    if (parseInt(groupSelect.value, 10) !== selected) {
        groupSelect.value = '0';
    }
    document.getElementById('add_variant_new_group_name').value = '';
    const variantInput = document.querySelector('#addMasterVariantForm input[name="variant_name"]');
    const priceInput = document.querySelector('#addMasterVariantForm input[name="price"]');
    if (variantInput) variantInput.value = '';
    if (priceInput) priceInput.value = '';
    setDropzoneImage('addVariantDropzone', '');
    toggleVariantGroupFields();
    variantInput && variantInput.focus();
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