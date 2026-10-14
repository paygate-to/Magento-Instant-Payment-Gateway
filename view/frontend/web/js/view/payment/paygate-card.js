/**
 * Registers the PayGate.to card payment method renderers.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push(
        {
            type: 'paygate_hosted',
            component: 'PayGate_InstantPayment/js/view/payment/method-renderer/paygate-redirect'
        },
        {
            type: 'paygate_provider',
            component: 'PayGate_InstantPayment/js/view/payment/method-renderer/paygate-redirect'
        }
    );

    return Component.extend({});
});
