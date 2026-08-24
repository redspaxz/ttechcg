(() => {
    'use strict';

    const measurementId = 'G-WVFXFB5H3M';
    const preferenceKey = 'ttechcg_analytics_consent';
    const consentPanel = document.querySelector('[data-analytics-consent]');
    const acceptButton = document.querySelector('[data-analytics-accept]');
    const declineButton = document.querySelector('[data-analytics-decline]');
    const settingsButtons = document.querySelectorAll('[data-analytics-settings]');
    let analyticsLoaded = false;

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
    });

    const readPreference = () => {
        try {
            return window.localStorage.getItem(preferenceKey);
        } catch (error) {
            return null;
        }
    };

    const savePreference = (choice) => {
        try {
            window.localStorage.setItem(preferenceKey, choice);
        } catch (error) {
            // A blocked storage API should not prevent the current-page choice.
        }
    };

    const clearAnalyticsCookies = () => {
        document.cookie.split(';').forEach((cookie) => {
            const name = cookie.split('=')[0].trim();
            if (name === '_ga' || name.startsWith('_ga_')) {
                document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax`;
                document.cookie = `${name}=; Max-Age=0; Path=/; Domain=.${window.location.hostname}; SameSite=Lax`;
            }
        });
    };

    const hidePanel = () => {
        if (consentPanel) {
            consentPanel.hidden = true;
        }
    };

    const showPanel = () => {
        if (consentPanel) {
            consentPanel.hidden = false;
            window.requestAnimationFrame(() => acceptButton?.focus());
        }
    };

    const loadGoogleAnalytics = () => {
        if (analyticsLoaded || document.querySelector('[data-google-analytics]')) {
            return;
        }

        analyticsLoaded = true;
        window.gtag('consent', 'update', {
            analytics_storage: 'granted',
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
        });

        const script = document.createElement('script');
        script.async = true;
        script.src = `https://www.googletagmanager.com/gtag/js?id=${measurementId}`;
        script.dataset.googleAnalytics = '';
        script.addEventListener('load', () => {
            window.gtag('js', new Date());
            window.gtag('config', measurementId);
        }, { once: true });
        document.head.append(script);
    };

    const grantAnalytics = () => {
        savePreference('granted');
        hidePanel();
        loadGoogleAnalytics();
    };

    const denyAnalytics = () => {
        const reloadWithoutAnalytics = analyticsLoaded;
        savePreference('denied');
        window.gtag('consent', 'update', {
            analytics_storage: 'denied',
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
        });
        clearAnalyticsCookies();
        hidePanel();

        if (reloadWithoutAnalytics) {
            window.location.reload();
        }
    };

    acceptButton?.addEventListener('click', grantAnalytics);
    declineButton?.addEventListener('click', denyAnalytics);
    settingsButtons.forEach((button) => button.addEventListener('click', showPanel));

    const preference = readPreference();
    if (preference === 'granted') {
        loadGoogleAnalytics();
    } else if (preference !== 'denied') {
        showPanel();
    }
})();
