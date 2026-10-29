/**
 * near:me support for the search widget and PowerSearch.
 *
 * HTAX_Geo.wantsLocation( text ) — whether a query contains a near:me token.
 * HTAX_Geo.get() — Promise resolving to the browser's { lat, lng } or null, from
 *   As the Crow Flies' location chain (atcfGeo.getBrowserLocation(): the current
 *   position, otherwise the last one the browser gave on this device). The server
 *   applies the rest of the chain — CDN headers, IP geolocation, the site's fallback
 *   location — and tells the visitor when it had to.
 */
(function () {
    'use strict';

    window.HTAX_Geo = {
        wantsLocation: (text) => /(^|\s)[+-]?near:me(\/[\d.]+)?(?=\s|$)/i.test(text || ''),
        get: () => (window.atcfGeo && window.atcfGeo.getBrowserLocation)
            ? window.atcfGeo.getBrowserLocation()
            : Promise.resolve(null)
    };
})();
