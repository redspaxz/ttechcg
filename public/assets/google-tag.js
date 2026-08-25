'use strict';

window.dataLayer = window.dataLayer || [];
window.gtag = window.gtag || function gtag() {
    window.dataLayer.push(arguments);
};

window.gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
    functionality_storage: 'granted',
    security_storage: 'granted',
    wait_for_update: 500,
});
window.gtag('js', new Date());

const suppressPageAnalytics = document.documentElement.dataset.analyticsPageView === 'disabled';
if (suppressPageAnalytics) {
    const sanitizedLocation = `${window.location.origin}${window.location.pathname}`;
    window.gtag('set', {
        page_location: sanitizedLocation,
        page_referrer: '',
    });
    window.gtag('config', 'G-WVFXFB5H3M', {
        allow_ad_personalization_signals: false,
        allow_google_signals: false,
        page_location: sanitizedLocation,
        page_referrer: '',
        send_page_view: false,
    });
} else {
    window.gtag('config', 'G-WVFXFB5H3M');
}
