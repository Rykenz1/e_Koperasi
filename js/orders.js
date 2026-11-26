/* ==========================================================================
   e-Koperasi - Customer Order History
   Clears the storefront cart after a successful simulated payment.
   ========================================================================== */

(function () {
    'use strict';

    const page = window.ORDERS_PAGE || {};

    if (page.clearCart && window.StorefrontCart) {
        window.StorefrontCart.clear();
    }
})();
