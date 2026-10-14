/**
 * Card payment method: after "Place Order" the customer is sent to the PayGate checkout page.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader'
], function (Component, fullScreenLoader) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'PayGate_InstantPayment/payment/paygate-redirect',
            redirectAfterPlaceOrder: false
        },

        /**
         * @return {Object}
         */
        getMethodConfig: function () {
            return (window.checkoutConfig.payment || {})[this.getCode()] || {};
        },

        /**
         * @return {String}
         */
        getDescription: function () {
            return this.getMethodConfig().description || '';
        },

        /**
         * The order is placed; the redirect page sends the customer to the payment page.
         */
        afterPlaceOrder: function () {
            fullScreenLoader.startLoader();
            window.location.replace(this.getMethodConfig().redirectUrl);
        }
    });
});
