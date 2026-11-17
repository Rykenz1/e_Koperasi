/* ==========================================================================
   e-Koperasi - Shared Dashboard Behaviour
   Chrome that exists on both admin_dashboard.php and outlet_dashboard.php.
   Guarded so the file is harmless on any page without a sidebar.
   ========================================================================== */

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');

    if (sidebar && toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });
    }
});
