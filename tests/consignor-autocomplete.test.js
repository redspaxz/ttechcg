'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'app.js'), 'utf8');

class FakeElement {
    constructor(tagName = 'div') {
        this.tagName = tagName;
        this.attributes = new Map();
        this.children = [];
        this.dataset = {};
        this.handlers = new Map();
        this.style = {};
        this.value = '';
        this.scrollHeight = 180;
        this.scrollTop = 0;
        this.clientHeight = 180;
        this.offsetTop = 0;
        this.offsetHeight = 40;
        this.animations = [];
    }

    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    removeAttribute(name) { this.attributes.delete(name); }
    hasAttribute(name) { return this.attributes.has(name); }
    toggleAttribute(name, force) {
        if (force === false) this.attributes.delete(name);
        else this.attributes.set(name, '');
    }
    addEventListener(name, handler) { this.handlers.set(name, handler); }
    emit(name, properties = {}) {
        const event = {
            target: this,
            preventDefault() { this.defaultPrevented = true; },
            ...properties,
        };
        this.handlers.get(name)?.(event);
        return event;
    }
    dispatchEvent(event) { this.handlers.get(event.type)?.(event); return true; }
    append(child) { this.children.push(child); }
    replaceChildren() { this.children = []; }
    contains(target) { return target === this || this.children.some((child) => child.contains?.(target)); }
    focus() { this.focused = true; }
    animate(keyframes, options) { this.animations.push({ keyframes, options }); return {}; }
    getBoundingClientRect() { return { left: 30, top: 100, bottom: 142, width: 190 }; }
    querySelectorAll(selector) {
        if (selector === '[data-consignor-suggestion]') return this.children;
        return [];
    }
}

const consignorInput = new FakeElement('input');
consignorInput.dataset.field = 'consignor';
consignorInput.setAttribute('list', 'consignor-suggestions');
consignorInput.closest = () => ({ querySelector: () => null });
consignorInput.matches = () => false;

const shipmentRow = {
    querySelector(selector) {
        if (selector === '[data-field="consignor"]') return consignorInput;
        return null;
    },
    querySelectorAll(selector) {
        if (selector === '[data-field]' || selector === '[data-field]:not([data-identity-field])') return [consignorInput];
        return [];
    },
};

const rowsContainer = {
    handlers: new Map(),
    querySelectorAll(selector) { return selector === '[data-shipment-row]' ? [shipmentRow] : []; },
    addEventListener(name, handler) { this.handlers.set(name, handler); },
};
const suggestionList = {
    dataset: { searchEndpoint: '/dhl/pickupsheet/consignors/search' },
    querySelectorAll(selector) {
        return selector === 'option'
            ? [{ value: 'Gamma Freight' }, { value: 'beta Logistics' }, { value: 'Alpha Cargo' }]
            : [];
    },
};
const pickupForm = {
    querySelector(selector) {
        if (selector === '[data-shipment-rows]') return rowsContainer;
        if (selector === '[data-consignor-suggestions]') return suggestionList;
        return null;
    },
    querySelectorAll(selector) { return selector === '[data-consignor-input]' ? [consignorInput] : []; },
    addEventListener() {},
};

const body = new FakeElement('body');
const documentHandlers = new Map();
const document = {
    body,
    querySelector(selector) { return selector === '[data-pickup-form]' ? pickupForm : null; },
    querySelectorAll() { return []; },
    createElement(tagName) { return new FakeElement(tagName); },
    addEventListener(name, handler) { documentHandlers.set(name, handler); },
};
const window = {
    innerWidth: 1280,
    innerHeight: 800,
    scrollY: 0,
    matchMedia() { return { matches: false, addEventListener() {} }; },
    addEventListener() {},
    clearTimeout() {},
    setTimeout(callback) { callback(); return 1; },
    requestAnimationFrame(callback) { callback(); },
    location: { href: 'https://example.test/dhl/pickupsheet/', assign() {} },
};
const requestedQueries = [];
const fetchMock = async (url) => {
    const query = new URL(url).searchParams.get('q');
    requestedQueries.push(query);
    return {
        redirected: false,
        ok: true,
        async json() {
            return { suggestions: query === 'b' ? ['Bravo Air', 'beta Logistics'] : ['beta Logistics'] };
        },
    };
};
class EventMock {
    constructor(type, options = {}) { this.type = type; this.bubbles = options.bubbles === true; }
}

vm.runInNewContext(script, {
    AbortController,
    Event: EventMock,
    Intl,
    Map,
    Number,
    URL,
    console,
    document,
    fetch: fetchMock,
    window,
});

const popup = body.children[0];
assert.ok(popup, 'autocomplete should append one animated listbox');
assert.equal(consignorInput.getAttribute('role'), 'combobox');
assert.equal(consignorInput.hasAttribute('list'), false, 'enhancement should suppress the competing native popup');

consignorInput.emit('focus');
assert.equal(popup.hasAttribute('data-open'), false, 'focus alone should keep the suggestion popup closed');
assert.equal(consignorInput.getAttribute('aria-expanded'), 'false', 'an empty consignor field should remain collapsed');

consignorInput.value = 'b';
consignorInput.emit('input');
assert.equal(popup.hasAttribute('data-open'), true, 'the first typed letter should open the suggestion popup');
assert.deepEqual(popup.children.map((option) => option.textContent), ['beta Logistics'], 'one typed letter should filter the A-Z source list');
assert.deepEqual(requestedQueries, ['b'], 'the first letter should start a protected AJAX search');
assert.equal(popup.children[0].animations.length, 1, 'visible results should receive a JavaScript entrance animation');
assert.equal(popup.getAttribute('aria-busy'), 'true', 'the listbox should announce progressive AJAX loading');

consignorInput.value = 'be';
consignorInput.emit('input');
assert.deepEqual(requestedQueries, ['b', 'be'], 'each additional letter should progressively refresh AJAX results');
assert.deepEqual(popup.children.map((option) => option.textContent), ['beta Logistics'], 'additional letters should immediately narrow visible local matches');

const downEvent = consignorInput.emit('keydown', { key: 'ArrowDown' });
assert.equal(downEvent.defaultPrevented, true);
assert.equal(consignorInput.getAttribute('aria-activedescendant'), 'consignor-suggestion-0');
assert.equal(popup.children[0].getAttribute('aria-selected'), 'true');

const enterEvent = consignorInput.emit('keydown', { key: 'Enter' });
assert.equal(enterEvent.defaultPrevented, true);
assert.equal(consignorInput.value, 'beta Logistics');
assert.equal(consignorInput.getAttribute('aria-expanded'), 'false');
assert.equal(popup.hasAttribute('data-open'), false, 'selection should animate the popup closed');

console.log('Consignor autocomplete tests passed.');
