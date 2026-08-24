'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('public/assets/analytics.js', 'utf8');

const runConsentLoader = (savedPreference = null) => {
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
    const appendedScripts = [];
    const storage = new Map();
    if (savedPreference !== null) {
        storage.set('ttechcg_analytics_consent', savedPreference);
    }

    const document = {
        cookie: '',
        head: { append: (element) => appendedScripts.push(element) },
        querySelector: (selector) => ({
            '[data-analytics-consent]': panel,
            '[data-analytics-accept]': accept,
            '[data-analytics-decline]': decline,
            '[data-google-analytics]': null,
        })[selector] ?? null,
        querySelectorAll: (selector) => selector === '[data-analytics-settings]' ? [settings] : [],
        createElement: () => ({
            async: false,
            src: '',
            dataset: {},
            addEventListener: (name, handler) => listeners.set('script:' + name, handler),
        }),
    };
    const window = {
        dataLayer: [],
        document,
        localStorage: {
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
        },
        location: { hostname: 'ttechcg.com', reload: () => {} },
        requestAnimationFrame: (callback) => callback(),
    };

    vm.runInNewContext(source, { window, document, console, Date });
    return { panel, listeners, appendedScripts, storage, window };
};

const undecided = runConsentLoader();
assert.equal(undecided.appendedScripts.length, 0, 'Google must not be requested before a choice.');
assert.equal(undecided.panel.hidden, false, 'Visitors without a choice should see the consent panel.');
undecided.listeners.get('accept:click')();
assert.equal(undecided.appendedScripts.length, 1, 'Accepting should load one Google tag.');
assert.equal(undecided.appendedScripts[0].src, 'https://www.googletagmanager.com/gtag/js?id=G-WVFXFB5H3M');
assert.equal(undecided.storage.get('ttechcg_analytics_consent'), 'granted');
undecided.listeners.get('script:load')();
assert.equal(undecided.window.dataLayer.at(-1)[0], 'config');
assert.equal(undecided.window.dataLayer.at(-1)[1], 'G-WVFXFB5H3M');

const newlyDenied = runConsentLoader();
newlyDenied.listeners.get('decline:click')();
assert.equal(newlyDenied.appendedScripts.length, 0, 'Declining must not request the Google tag.');
assert.equal(newlyDenied.storage.get('ttechcg_analytics_consent'), 'denied');

const denied = runConsentLoader('denied');
assert.equal(denied.appendedScripts.length, 0, 'A saved decline must keep Google blocked.');
assert.equal(denied.panel.hidden, true, 'A saved choice should keep the panel closed.');

const granted = runConsentLoader('granted');
assert.equal(granted.appendedScripts.length, 1, 'A saved grant should load Google once.');
assert.equal(granted.window.dataLayer[0][0], 'consent');
assert.equal(granted.window.dataLayer[0][1], 'default');
assert.equal(granted.window.dataLayer[0][2].analytics_storage, 'denied');

console.log('Analytics consent tests passed.');
