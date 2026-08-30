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
let filterHandler;
let clearHandler;
let popstateHandler;
let pushedUrl;
let fetchRequest;
let resolveFetch;
const pager = {
    dataset: {
        ajaxPagerId: 'submitted-sheets',
        pageEndpoint: '/dhl/pickupsheet/submissions/page',
        pageParam: 'page',
        currentPage: '1',
        errorMessage: 'Pickup records could not be loaded. Please try again.',
    },
    querySelector(selector) {
        if (selector === '[data-ajax-pager-content]') return content;
        if (selector === '[data-ajax-pager-spinner]') return spinner;
        return null;
    },
    addEventListener(event, handler) {
        if (event === 'click') clickHandler = handler;
    },
};
const filterForm = {
    action: 'https://ttechcg.com/dhl/pickupsheet/submissions',
    dataset: { ajaxPagerForm: 'submitted-sheets' },
    addEventListener(event, handler) {
        if (event === 'submit') filterHandler = handler;
    },
};
const clearLink = {
    href: 'https://ttechcg.com/dhl/pickupsheet/submissions',
    dataset: { ajaxPagerClear: 'submitted-sheets' },
    addEventListener(event, handler) {
        if (event === 'click') clearHandler = handler;
    },
};

const document = {
    body: { toggleAttribute() {} },
    querySelector() { return null; },
    querySelectorAll(selector) {
        if (selector === '[data-ajax-pager]') return [pager];
        if (selector === '[data-ajax-pager-form]') return [filterForm];
        if (selector === '[data-ajax-pager-clear]') return [clearLink];
        return [];
    },
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
    location: {
        href: 'https://ttechcg.com/dhl/pickupsheet/submissions?page=1',
        assign() {},
    },
    history: {
        pushState(_state, _title, url) { pushedUrl = String(url); },
    },
    matchMedia() { return { matches: false, addEventListener() {} }; },
    addEventListener(event, handler) {
        if (event === 'popstate') popstateHandler = handler;
    },
    requestAnimationFrame(callback) { callback(); },
};
class FormDataMock {
    forEach(callback) { callback('controller', 'q'); }
}

const context = {
    AbortController,
    Error,
    FormData: FormDataMock,
    Intl,
    Map,
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
    assert.equal(typeof filterHandler, 'function', 'AJAX filter handler should be registered');
    assert.equal(typeof clearHandler, 'function', 'AJAX clear handler should be registered');
    assert.equal(typeof popstateHandler, 'function', 'pagination history handler should be registered');

    let prevented = false;
    clickHandler({
        preventDefault() { prevented = true; },
        target: {
            closest() {
                return {
                    dataset: { ajaxPage: '2' },
                    href: 'https://ttechcg.com/dhl/pickupsheet/submissions?page=2',
                };
            },
        },
    });
    await Promise.resolve();

    assert.equal(prevented, true, 'AJAX pagination should intercept valid page links');
    assert.equal(spinnerStates.at(-1), false, 'spinner should be visible while loading');
    assert.equal(content.attributes.get('aria-busy'), 'true', 'table region should be marked busy while loading');
    assert.match(fetchRequest.url, /submissions\/page\?page=2$/);
    assert.equal(fetchRequest.options.credentials, 'same-origin');
    assert.equal(fetchRequest.options.headers['X-Requested-With'], 'XMLHttpRequest');

    resolveFetch({ ok: true, redirected: false, status: 200, text: async () => '<p>Page two</p>' });
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(content.innerHTML, '<p>Page two</p>', 'AJAX response should replace the table fragment');
    assert.equal(spinnerStates.at(-1), true, 'spinner should hide after loading');
    assert.equal(content.attributes.get('aria-busy'), 'false', 'table region should clear its busy state');
    assert.match(pushedUrl, /submissions\?page=2$/);
    assert.equal(pushedUrl.includes('page=2'), true, 'the loaded page should update history without moving the viewport');

    let filterPrevented = false;
    filterHandler({ preventDefault() { filterPrevented = true; } });
    await Promise.resolve();
    assert.equal(filterPrevented, true, 'qualified filters should avoid a full page refresh');
    assert.match(fetchRequest.url, /submissions\/page\?q=controller&page=1$/);
    resolveFetch({ ok: true, redirected: false, status: 200, text: async () => '<p>Filtered</p>' });
    await new Promise((resolve) => setTimeout(resolve, 0));

    let clearPrevented = false;
    clearHandler({ preventDefault() { clearPrevented = true; } });
    await Promise.resolve();
    assert.equal(clearPrevented, true, 'clearing a qualified filter should avoid a full page refresh');
    assert.match(fetchRequest.url, /submissions\/page\?page=1$/);
    resolveFetch({ ok: true, redirected: false, status: 200, text: async () => '<p>Cleared</p>' });
    await new Promise((resolve) => setTimeout(resolve, 0));

    console.log('Application pagination tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
