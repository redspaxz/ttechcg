'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const tagSource = fs.readFileSync('public/assets/google-tag.js', 'utf8');
const consentSource = fs.readFileSync('public/assets/analytics.js', 'utf8');

const runGoogleTag = (pageView = 'enabled') => {
    const document = {
        documentElement: { dataset: { analyticsPageView: pageView } },
    };
    const window = {
        dataLayer: [],
        location: {
            origin: 'https://ttechcg.com',
            pathname: '/dhl/pickupsheet/submissions/print',
            search: '?reference=PS-SECRET-REFERENCE',
        },
    };

    vm.runInNewContext(tagSource, { window, document, Date });
    return window.dataLayer;
};

const publicTagCalls = runGoogleTag();
assert.equal(publicTagCalls[0][0], 'consent');
assert.equal(publicTagCalls[0][1], 'default');
assert.equal(publicTagCalls[0][2].analytics_storage, 'denied');
assert.equal(publicTagCalls[0][2].ad_storage, 'denied');
assert.equal(publicTagCalls[1][0], 'js');
assert.equal(publicTagCalls[2][0], 'config');
assert.equal(publicTagCalls[2][1], 'G-WVFXFB5H3M');

const sensitiveTagCalls = runGoogleTag('disabled');
assert.equal(sensitiveTagCalls[2][0], 'set');
assert.equal(sensitiveTagCalls[2][1].page_location, 'https://ttechcg.com/dhl/pickupsheet/submissions/print');
assert.equal(sensitiveTagCalls[2][1].page_location.includes('reference='), false);
assert.equal(sensitiveTagCalls[3][0], 'config');
assert.equal(sensitiveTagCalls[3][1], 'G-WVFXFB5H3M');
assert.equal(sensitiveTagCalls[3][2].send_page_view, false);
assert.equal(sensitiveTagCalls[3][2].page_referrer, '');

const runConsentController = (savedPreference = null, pageView = 'enabled') => {
    const listeners = new Map();
    const panel = { hidden: true };
    const accept = {
        addEventListener: (name, handler) => listeners.set('accept:' + name, handler),
        focus: () => {},
    };
    const decline = {
        addEventListener: (name, handler) => listeners.set('decline:' + name, handler),
    };
    const settings = {
        addEventListener: (name, handler) => listeners.set('settings:' + name, handler),
    };
    const storage = new Map();
    const consentCalls = [];
    if (savedPreference !== null) {
        storage.set('ttechcg_analytics_consent', savedPreference);
    }

    const document = {
        cookie: '',
        documentElement: { dataset: { analyticsPageView: pageView } },
        querySelector: (selector) => ({
            '[data-analytics-consent]': panel,
            '[data-analytics-accept]': accept,
            '[data-analytics-decline]': decline,
        })[selector] ?? null,
        querySelectorAll: (selector) => selector === '[data-analytics-settings]' ? [settings] : [],
    };
    const window = {
        document,
        gtag: (...args) => consentCalls.push(args),
        localStorage: {
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
        },
        location: { hostname: 'ttechcg.com' },
        requestAnimationFrame: (callback) => callback(),
    };

    vm.runInNewContext(consentSource, { window, document, console });
    return { panel, listeners, storage, consentCalls };
};

const undecided = runConsentController();
assert.equal(undecided.consentCalls.length, 0, 'Default denied consent is established by the single Google tag initializer.');
assert.equal(undecided.panel.hidden, false, 'Visitors without a choice should see the consent panel.');
undecided.listeners.get('accept:click')();
assert.equal(undecided.storage.get('ttechcg_analytics_consent'), 'granted');
assert.equal(undecided.consentCalls.length, 1);
assert.equal(undecided.consentCalls[0][0], 'consent');
assert.equal(undecided.consentCalls[0][1], 'update');
assert.equal(undecided.consentCalls[0][2].analytics_storage, 'granted');

const newlyDenied = runConsentController();
newlyDenied.listeners.get('decline:click')();
assert.equal(newlyDenied.storage.get('ttechcg_analytics_consent'), 'denied');
assert.equal(newlyDenied.consentCalls[0][2].analytics_storage, 'denied');

const denied = runConsentController('denied');
assert.equal(denied.consentCalls.length, 0, 'A saved decline should retain the initializer default without duplicate updates.');
assert.equal(denied.panel.hidden, true, 'A saved choice should keep the panel closed.');

const granted = runConsentController('granted');
assert.equal(granted.consentCalls.length, 1, 'A saved grant should restore analytics consent once.');
assert.equal(granted.consentCalls[0][2].analytics_storage, 'granted');

const sensitiveGranted = runConsentController('granted', 'disabled');
assert.equal(sensitiveGranted.consentCalls.length, 0, 'Saved consent must not re-enable Analytics on pickup-sheet operational pages.');

console.log('Analytics consent tests passed.');
