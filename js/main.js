/* ==========================================================================
   e-Koperasi - Shared Helpers
   Generic UI helpers available to every page. Functions are declared
   globally because inline onclick attributes in the PHP call them.
   ========================================================================== */

// Modal Controls
function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// 3-Dot Dropdown Menu Toggle
function toggleMenu(event, menuId) {
    event.stopPropagation();
    document.querySelectorAll('.dropdown-menu').forEach(menu => {
        if (menu.id !== menuId) menu.classList.remove('show');
    });
    document.getElementById(menuId).classList.toggle('show');
}

// Close dropdowns when clicking outside
window.addEventListener('click', () => {
    document.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.remove('show'));
});
