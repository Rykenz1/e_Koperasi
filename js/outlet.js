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
