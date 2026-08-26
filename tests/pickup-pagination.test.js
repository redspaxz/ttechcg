'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'app.js'), 'utf8');

const content = {
    attributes: new Map(),
    innerHTML: '<p>Page one</p>',
    prepended: [],
    setAttribute(name, value) { this.attributes.set(name, value); },
    querySelector() { return null; },
    prepend(node) { this.prepended.unshift(node); },
};
const spinnerStates = [];
const spinner = {};
Object.defineProperty(spinner, 'hidden', {
    get() { return spinnerStates.at(-1); },
    set(value) { spinnerStates.push(value); },
});

let clickHandler;
let popstateHandler;
let pushedUrl;
let fetchRequest;
let resolveFetch;
let scrollOptions;
const pickupRecords = {
    dataset: { pageEndpoint: '/dhl/pickupsheet/submissions/page' },
    querySelector(selector) {
        if (selector === '[data-pickup-records-content]') return content;
        if (selector === '[data-pickup-records-spinner]') return spinner;
        return null;
    },
    addEventListener(event, handler) {
        if (event === 'click') clickHandler = handler;
    },
    scrollIntoView(options) { scrollOptions = options; },
};

const document = {
    body: { toggleAttribute() {} },
    querySelector(selector) {
        return selector === '[data-pickup-records]' ? pickupRecords : null;
    },
    querySelectorAll() { return []; },
    createElement() {
        return {
            className: '',
            dataset: {},
            textContent: '',
            setAttribute() {},
        };
    },
    addEventListener() {},
};
const window = {
    location: { href: 'https://ttechcg.com/dhl/pickupsheet/submissions?page=1' },
    history: {
        pushState(_state, _title, url) { pushedUrl = String(url); },
    },
    matchMedia() { return { matches: false, addEventListener() {} }; },
    addEventListener(event, handler) {
        if (event === 'popstate') popstateHandler = handler;
    },
    requestAnimationFrame(callback) { callback(); },
};

const context = {
    AbortController,
    Error,
    Intl,
    Number,
    URL,
    console,
    document,
    fetch(url, options) {
        fetchRequest = { url: String(url), options };
        return new Promise((resolve) => { resolveFetch = resolve; });
    },
    window,
};

vm.runInNewContext(script, context);

(async () => {
    assert.equal(typeof clickHandler, 'function', 'pagination click handler should be registered');
    assert.equal(typeof popstateHandler, 'function', 'pagination history handler should be registered');

    let prevented = false;
    clickHandler({
        preventDefault() { prevented = true; },
        target: {
            closest() { return { dataset: { pickupPage: '2' } }; },
        },
    });
    await Promise.resolve();

    assert.equal(prevented, true, 'AJAX pagination should intercept valid page links');
    assert.equal(spinnerStates.at(-1), false, 'spinner should be visible while loading');
    assert.equal(content.attributes.get('aria-busy'), 'true', 'records region should be marked busy while loading');
    assert.match(fetchRequest.url, /submissions\/page\?page=2$/);
    assert.equal(fetchRequest.options.credentials, 'same-origin');
    assert.equal(fetchRequest.options.headers['X-Requested-With'], 'XMLHttpRequest');

    resolveFetch({ ok: true, text: async () => '<p>Page two</p>' });
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(content.innerHTML, '<p>Page two</p>', 'AJAX response should replace the records fragment');
    assert.equal(spinnerStates.at(-1), true, 'spinner should hide after loading');
    assert.equal(content.attributes.get('aria-busy'), 'false', 'records region should clear its busy state');
    assert.match(pushedUrl, /submissions\?page=2$/);
    assert.equal(scrollOptions.block, 'start', 'a loaded page should return the user to the start of the records');

    console.log('Pickup pagination tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
