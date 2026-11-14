/** DX Pixel Suite: one Google dispatcher, using WooCommerce payloads rather than DOM price scraping. */
(function (w) {
    'use strict';
    var cfg = w.dxpsCfg || {};
    if (!cfg.engineDirect) return;
    w.dxpsGoogleRoute = function (name, params, id) {
        if (!cfg.ga4Id || (w.dxpsMarketingAllowed && !w.dxpsMarketingAllowed())) return;
        if (name === 'purchase' && cfg.ga4ServerPurchase) return;
        var data = Object.assign({}, params, {send_to: cfg.ga4Id, event_id: id});
        if (typeof w.gtag === 'function') w.gtag('event', name, data);
    };
    // Preserve the theme integration interface; all events now pass through DX's shared consent/PII checks.
    w.EynnaTracking = {
        push: function (name, data) {
            if (!w.dxpsFire) return;
            data = data || {};
            var params = Object.assign({}, data, data.ecommerce || {});
            if (params.items) params.items = params.items.map(function (i) {
                return {id: i.item_id || i.id, name: i.item_name || i.name, price: i.price, quantity: i.quantity, category: i.item_category || i.category};
            });
            delete params.ecommerce;
            w.dxpsFire({name: name, params: params, event_id: data.event_id});
        },
        setAttributes: function () {} // Deprecated inferred customer attributes are not sent to ad platforms.
    };
})(window);
