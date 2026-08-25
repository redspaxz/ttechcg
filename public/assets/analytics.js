(() => {
    'use strict';

    const preferenceKey = 'ttechcg_analytics_consent';
    const consentPanel = document.querySelector('[data-analytics-consent]');
    const acceptButton = document.querySelector('[data-analytics-accept]');
    const declineButton = document.querySelector('[data-analytics-decline]');
    const settingsButtons = document.querySelectorAll('[data-analytics-settings]');
    const analyticsSuppressed = document.documentElement.dataset.analyticsPageView === 'disabled';

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

    const grantGoogleAnalytics = () => {
        if (analyticsSuppressed) {
            return;
        }

        window.gtag('consent', 'update', {
            analytics_storage: 'granted',
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
        });
    };

    const grantAnalytics = () => {
        savePreference('granted');
        hidePanel();
        grantGoogleAnalytics();
    };

    const denyAnalytics = () => {
        savePreference('denied');
        window.gtag('consent', 'update', {
            analytics_storage: 'denied',
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
        });
        clearAnalyticsCookies();
        hidePanel();
    };

    acceptButton?.addEventListener('click', grantAnalytics);
    declineButton?.addEventListener('click', denyAnalytics);
    settingsButtons.forEach((button) => button.addEventListener('click', showPanel));

    const preference = readPreference();
    if (preference === 'granted') {
        grantGoogleAnalytics();
    } else if (preference !== 'denied') {
        showPanel();
    }
})();
