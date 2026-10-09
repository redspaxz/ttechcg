const header = document.querySelector('[data-site-header]');
const toggle = document.querySelector('[data-nav-toggle]');
const navigation = document.querySelector('[data-navigation]');
const pageRegions = document.querySelectorAll('main, footer');

if (toggle && navigation) {
    const firstNavigationLink = navigation.querySelector('a');

    const setNavigationState = (open, restoreFocus = false) => {
        toggle.setAttribute('aria-expanded', String(open));
        navigation.toggleAttribute('data-open', open);
        document.body.toggleAttribute('data-nav-open', open);
        pageRegions.forEach((region) => region.toggleAttribute('inert', open));

        if (open && firstNavigationLink) {
            window.requestAnimationFrame(() => firstNavigationLink.focus());
        } else if (restoreFocus) {
            toggle.focus();
        }
    };

    toggle.addEventListener('click', () => {
        setNavigationState(toggle.getAttribute('aria-expanded') !== 'true');
    });

    navigation.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => setNavigationState(false));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setNavigationState(false, true);
        }
    });

    const desktopNavigation = window.matchMedia('(min-width: 821px)');
    desktopNavigation.addEventListener('change', (event) => {
        if (event.matches) {
            setNavigationState(false);
        }
    });
}

if (header) {
    const updateHeader = () => header.toggleAttribute('data-scrolled', window.scrollY > 12);
    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });
}

const revealItems = document.querySelectorAll('[data-reveal]');
if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.setAttribute('data-visible', '');
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px 8% 0px', threshold: 0.08 });
    revealItems.forEach((item) => observer.observe(item));
} else {
    revealItems.forEach((item) => item.setAttribute('data-visible', ''));
}

const pickupForm = document.querySelector('[data-pickup-form]')
    ?? document.querySelector('[data-customer-autocomplete-form]');
if (pickupForm) {
    const rowsContainer = pickupForm.querySelector('[data-shipment-rows]');
    const rowTemplate = document.querySelector('[data-shipment-template]');
    const addButton = pickupForm.querySelector('[data-add-shipment]');
    const countOutput = pickupForm.querySelector('[data-shipment-count]');
    const totalOutput = pickupForm.querySelector('[data-shipment-total]');
    const consignorSuggestionList = pickupForm.querySelector('[data-consignor-suggestions]');
    const consignorSortKey = (value) => value
        .trim()
        .replace(/\s+/g, ' ')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('en');
    const compareConsignorSuggestions = (left, right) => {
        const leftKey = consignorSortKey(left);
        const rightKey = consignorSortKey(right);
        if (leftKey < rightKey) return -1;
        if (leftKey > rightKey) return 1;
        return left.trim().localeCompare(right.trim(), 'en', { sensitivity: 'variant', numeric: false });
    };
    const compareConsignorRelevance = (left, right, query) => {
        const leftKey = consignorSortKey(left);
        const rightKey = consignorSortKey(right);
        const leftExact = leftKey === query;
        const rightExact = rightKey === query;
        if (leftExact !== rightExact) return leftExact ? -1 : 1;
        const completionOrder = (leftKey.length - query.length) - (rightKey.length - query.length);
        return completionOrder || compareConsignorSuggestions(left, right);
    };
    const consignorSuggestionNames = Array.from(consignorSuggestionList?.querySelectorAll('option') ?? [])
        .map((option) => option.value.trim())
        .filter(Boolean)
        .sort(compareConsignorSuggestions);
    const consignorSearchEndpoint = consignorSuggestionList?.dataset?.searchEndpoint || '';
    const consignorSuggestionLabel = consignorSuggestionList?.dataset?.suggestionLabel || 'Consignor suggestions';
    const maximumRows = 50;
    const fieldLabels = {
        consignor: 'consignor',
        awb_number: 'AWB number',
        destination: 'destination',
        amount: 'amount in XAF',
        pieces: 'pieces',
        weight_kg: 'weight in kilograms',
        collection_time: 'collection time',
        checked_by: 'checked by',
    };
    const numberFormatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });

    const rows = () => Array.from(rowsContainer?.querySelectorAll('[data-shipment-row]') ?? []);

    const updateSummary = () => {
        let shipmentCount = 0;
        let total = 0;

        rows().forEach((row) => {
            const inputs = Array.from(row.querySelectorAll('[data-field]:not([data-identity-field])'));
            const populated = inputs.some((input) => input.value.trim() !== '');
            if (populated) shipmentCount += 1;

            const amount = row.querySelector('[data-field="amount"]');
            const parsedAmount = Number.parseInt(amount?.value ?? '0', 10);
            if (Number.isFinite(parsedAmount) && parsedAmount > 0) total += parsedAmount;
        });

        if (countOutput) countOutput.textContent = String(shipmentCount);
        if (totalOutput) totalOutput.textContent = numberFormatter.format(total);
    };

    let consignorPopup = null;
    let activeConsignorInput = null;
    let visibleConsignorSuggestions = [];
    let activeConsignorSuggestion = -1;
    let consignorSearchTimer = null;
    let consignorSearchRequest = null;
    let consignorSearchGeneration = 0;

    const setConsignorSearchLoading = (loading) => {
        if (!consignorPopup) return;
        consignorPopup.toggleAttribute('data-loading', loading);
        consignorPopup.setAttribute('aria-busy', String(loading));
    };

    const closeConsignorSuggestions = () => {
        consignorSearchGeneration += 1;
        window.clearTimeout(consignorSearchTimer);
        consignorSearchTimer = null;
        consignorSearchRequest?.abort();
        consignorSearchRequest = null;
        setConsignorSearchLoading(false);
        if (activeConsignorInput) {
            activeConsignorInput.setAttribute('aria-expanded', 'false');
            activeConsignorInput.removeAttribute('aria-activedescendant');
        }
        consignorPopup?.removeAttribute('data-open');
        consignorPopup?.setAttribute('aria-hidden', 'true');
        activeConsignorInput = null;
        visibleConsignorSuggestions = [];
        activeConsignorSuggestion = -1;
    };

    const positionConsignorPopup = () => {
        if (!consignorPopup || !activeConsignorInput) return;
        const inputBounds = activeConsignorInput.getBoundingClientRect();
        const viewportPadding = 12;
        const width = Math.min(Math.max(inputBounds.width, 260), window.innerWidth - (viewportPadding * 2));
        const left = Math.min(
            Math.max(inputBounds.left, viewportPadding),
            window.innerWidth - width - viewportPadding,
        );
        const popupHeight = Math.min(consignorPopup.scrollHeight, 300);
        const spaceBelow = window.innerHeight - inputBounds.bottom;
        const openAbove = spaceBelow < Math.min(popupHeight + 16, 230) && inputBounds.top > popupHeight;

        consignorPopup.style.width = `${width}px`;
        consignorPopup.style.left = `${left}px`;
        consignorPopup.style.top = `${openAbove ? Math.max(viewportPadding, inputBounds.top - popupHeight - 8) : inputBounds.bottom + 8}px`;
        consignorPopup.dataset.placement = openAbove ? 'above' : 'below';
    };

    const setActiveConsignorSuggestion = (index) => {
        if (!consignorPopup || visibleConsignorSuggestions.length === 0) return;
        activeConsignorSuggestion = (index + visibleConsignorSuggestions.length) % visibleConsignorSuggestions.length;
        const options = Array.from(consignorPopup.querySelectorAll('[data-consignor-suggestion]'));
        options.forEach((option, optionIndex) => option.setAttribute('aria-selected', String(optionIndex === activeConsignorSuggestion)));
        const activeOption = options[activeConsignorSuggestion];
        if (activeOption && activeConsignorInput) {
            activeConsignorInput.setAttribute('aria-activedescendant', activeOption.id);
            const optionTop = activeOption.offsetTop;
            const optionBottom = optionTop + activeOption.offsetHeight;
            if (optionTop < consignorPopup.scrollTop) consignorPopup.scrollTop = optionTop;
            if (optionBottom > consignorPopup.scrollTop + consignorPopup.clientHeight) {
                consignorPopup.scrollTop = optionBottom - consignorPopup.clientHeight;
            }
        }
    };

    const selectConsignorSuggestion = (name) => {
        if (!activeConsignorInput) return;
        const input = activeConsignorInput;
        input.value = name;
        closeConsignorSuggestions();
        updateSummary();
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event('consignor-suggestion-selected', { bubbles: true }));
        input.focus({ preventScroll: true });
    };

    const animateConsignorOptions = (options) => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        options.forEach((option, index) => {
            if (typeof option.animate !== 'function') return;
            option.dataset.jsAnimated = '';
            option.animate(
                [
                    { opacity: 0, transform: 'translateY(-6px)' },
                    { opacity: 1, transform: 'translateY(0)' },
                ],
                {
                    duration: 180,
                    delay: Math.min(index * 22, 154),
                    easing: 'cubic-bezier(0.2, 0.75, 0.25, 1)',
                    fill: 'backwards',
                },
            );
        });
    };

    const showConsignorSuggestions = (input, source = consignorSuggestionNames, preserveRanking = false) => {
        if (!consignorPopup) return;
        const query = consignorSortKey(input.value);
        if (query === '') {
            closeConsignorSuggestions();
            return;
        }
        const seenMatches = new Set();
        const matches = source
            .map((name) => name.trim())
            .filter((name) => consignorSortKey(name).startsWith(query))
            .filter((name) => {
                const normalizedName = consignorSortKey(name);
                if (seenMatches.has(normalizedName)) return false;
                seenMatches.add(normalizedName);
                return true;
            });
        if (!preserveRanking) {
            matches.sort((left, right) => compareConsignorRelevance(left, right, query));
        }
        matches.splice(12);
        if (matches.length === 0) {
            closeConsignorSuggestions();
            return;
        }
        if (activeConsignorInput && activeConsignorInput !== input) closeConsignorSuggestions();
        activeConsignorInput = input;
        visibleConsignorSuggestions = matches;
        activeConsignorSuggestion = -1;
        consignorPopup.replaceChildren();
        const renderedOptions = [];
        matches.forEach((name, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = `consignor-suggestion-${index}`;
            option.className = 'consignor-autocomplete-option';
            option.dataset.consignorSuggestion = name;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.setAttribute('tabindex', '-1');
            option.textContent = name;
            option.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                selectConsignorSuggestion(name);
            });
            option.addEventListener('pointermove', () => setActiveConsignorSuggestion(index));
            consignorPopup.append(option);
            renderedOptions.push(option);
        });
        animateConsignorOptions(renderedOptions);
        input.setAttribute('aria-expanded', 'true');
        consignorPopup.setAttribute('aria-hidden', 'false');
        positionConsignorPopup();
        window.requestAnimationFrame(() => {
            if (activeConsignorInput === input) consignorPopup?.setAttribute('data-open', '');
        });
    };

    const searchConsignorSuggestions = (input) => {
        const query = input.value.trim();
        window.clearTimeout(consignorSearchTimer);
        consignorSearchTimer = null;
        consignorSearchRequest?.abort();
        consignorSearchRequest = null;
        setConsignorSearchLoading(false);
        showConsignorSuggestions(input);
        if (consignorSearchEndpoint === '' || query === '') return;
        const searchGeneration = ++consignorSearchGeneration;
        activeConsignorInput = input;
        setConsignorSearchLoading(true);
        consignorSearchTimer = window.setTimeout(async () => {
            consignorSearchTimer = null;
            const request = new AbortController();
            consignorSearchRequest = request;
            try {
                const endpoint = new URL(consignorSearchEndpoint, window.location.href);
                endpoint.searchParams.set('q', query);
                const response = await fetch(endpoint, {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: request.signal,
                });
                if (response.redirected && response.url && new URL(response.url).pathname.endsWith('/dhl/pickupsheet/login')) {
                    window.location.assign(response.url);
                    return;
                }
                if (!response.ok) return;
                const payload = await response.json();
                const suggestions = Array.isArray(payload.suggestions)
                    ? payload.suggestions
                        .filter((name) => typeof name === 'string' && name.trim() !== '')
                        .map((name) => name.trim())
                    : [];
                if (searchGeneration === consignorSearchGeneration && activeConsignorInput === input && input.value.trim() === query) {
                    showConsignorSuggestions(input, suggestions, true);
                    input.dispatchEvent(new CustomEvent('consignor-search-results', { detail: { query, payload } }));
                }
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    // Keep the immediate local matches when background search is unavailable.
                }
            } finally {
                if (consignorSearchRequest === request) consignorSearchRequest = null;
                if (searchGeneration === consignorSearchGeneration) setConsignorSearchLoading(false);
            }
        }, 140);
    };

    const initializeConsignorInput = (input) => {
        if (!input || input.dataset.consignorAutocompleteReady === 'true' || (consignorSuggestionNames.length === 0 && consignorSearchEndpoint === '')) return;
        input.dataset.consignorAutocompleteReady = 'true';
        input.removeAttribute('list');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-haspopup', 'listbox');
        input.setAttribute('aria-controls', 'consignor-autocomplete-listbox');
        input.setAttribute('aria-expanded', 'false');
        input.addEventListener('focus', () => searchConsignorSuggestions(input));
        input.addEventListener('input', () => searchConsignorSuggestions(input));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (activeConsignorInput !== input || !consignorPopup?.hasAttribute('data-open')) {
                    showConsignorSuggestions(input);
                }
                setActiveConsignorSuggestion(activeConsignorSuggestion + (event.key === 'ArrowDown' ? 1 : -1));
            } else if (event.key === 'Enter' && activeConsignorInput === input && activeConsignorSuggestion >= 0) {
                event.preventDefault();
                selectConsignorSuggestion(visibleConsignorSuggestions[activeConsignorSuggestion]);
            } else if (event.key === 'Escape') {
                closeConsignorSuggestions();
            } else if (event.key === 'Tab') {
                closeConsignorSuggestions();
            }
        });
    };

    if (consignorSuggestionNames.length > 0 || consignorSearchEndpoint !== '') {
        consignorPopup = document.createElement('div');
        consignorPopup.id = 'consignor-autocomplete-listbox';
        consignorPopup.className = 'consignor-autocomplete-popup';
        consignorPopup.setAttribute('role', 'listbox');
        consignorPopup.setAttribute('aria-label', consignorSuggestionLabel);
        consignorPopup.setAttribute('aria-hidden', 'true');
        consignorPopup.setAttribute('aria-busy', 'false');
        document.body.append(consignorPopup);
        pickupForm.querySelectorAll('[data-consignor-input]').forEach(initializeConsignorInput);
        document.addEventListener('pointerdown', (event) => {
            if (activeConsignorInput && event.target !== activeConsignorInput && !consignorPopup?.contains(event.target)) {
                closeConsignorSuggestions();
            }
        });
        document.addEventListener('scroll', positionConsignorPopup, { passive: true, capture: true });
        window.addEventListener('resize', positionConsignorPopup, { passive: true });
    }

    const reindexRows = () => {
        const currentRows = rows();
        currentRows.forEach((row, index) => {
            const rowNumber = index + 1;
            const number = row.querySelector('[data-row-number]');
            if (number) number.textContent = String(rowNumber);

            row.querySelectorAll('[data-field]').forEach((input) => {
                const field = input.dataset.field;
                input.name = `shipments[${index}][${field}]`;
                const label = input.closest('td')?.querySelector('[data-row-label]');
                if (label) label.textContent = `Shipment ${rowNumber} ${fieldLabels[field] ?? field}`;
            });

            const removeButton = row.querySelector('[data-remove-shipment]');
            if (removeButton) {
                removeButton.setAttribute('aria-label', `Remove shipment ${rowNumber}`);
                removeButton.disabled = currentRows.length === 1;
            }
        });

        if (addButton) addButton.disabled = currentRows.length >= maximumRows;
        updateSummary();
    };

    addButton?.addEventListener('click', () => {
        const index = rows().length;
        if (!rowsContainer || !rowTemplate || index >= maximumRows) return;

        const markup = rowTemplate.innerHTML
            .replaceAll('__INDEX__', String(index))
            .replaceAll('__NUMBER__', String(index + 1));
        rowsContainer.insertAdjacentHTML('beforeend', markup);
        reindexRows();
        const consignorInput = rows().at(-1)?.querySelector('[data-field="consignor"]');
        initializeConsignorInput(consignorInput);
        consignorInput?.focus();
    });

    rowsContainer?.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-shipment]');
        if (!removeButton || rows().length === 1) return;
        if (activeConsignorInput && removeButton.closest('[data-shipment-row]')?.contains(activeConsignorInput)) {
            closeConsignorSuggestions();
        }
        removeButton.closest('[data-shipment-row]')?.remove();
        reindexRows();
    });

    rowsContainer?.addEventListener('input', (event) => {
        if (event.target.matches('[data-field="destination"]')) {
            event.target.value = event.target.value.toUpperCase();
        }
        updateSummary();
    });

    pickupForm.addEventListener('submit', () => {
        closeConsignorSuggestions();
        reindexRows();
    });
    reindexRows();
}

const ajaxPagerControllers = new Map();

document.querySelectorAll('[data-ajax-pager]').forEach((pager) => {
    const content = pager.querySelector('[data-ajax-pager-content]');
    const spinner = pager.querySelector('[data-ajax-pager-spinner]');
    const endpoint = pager.dataset.pageEndpoint;
    const pageParameter = pager.dataset.pageParam || 'page';
    const pageSizeParameter = pager.dataset.pageSizeParam || 'per_page';
    const pagerId = pager.dataset.ajaxPagerId || pageParameter;
    const filterParameters = (pager.dataset.filterParams || '')
        .split(',')
        .map((parameter) => parameter.trim())
        .filter(Boolean);
    let activeRequest = null;

    const stateSignature = (url) => [pageParameter, pageSizeParameter, ...filterParameters]
        .map((parameter) => `${parameter}=${url.searchParams.get(parameter) || ''}`)
        .join('&');
    let currentState = stateSignature(new URL(window.location.href));

    const setLoading = (loading) => {
        if (content) content.setAttribute('aria-busy', loading ? 'true' : 'false');
        if (spinner) spinner.hidden = !loading;
        pager.toggleAttribute?.('data-page-size-loading', loading);
    };

    const showLoadError = () => {
        if (!content) return;
        content.querySelector('[data-pagination-error]')?.remove();
        const notice = document.createElement('div');
        notice.className = 'notice notice-error';
        notice.dataset.paginationError = '';
        notice.setAttribute('role', 'alert');
        notice.textContent = pager.dataset.errorMessage || 'This table could not be loaded. Please try again.';
        content.prepend(notice);
    };

    const pageFromUrl = (url) => {
        const page = Number.parseInt(url.searchParams.get(pageParameter) || '1', 10);
        return Number.isInteger(page) && page > 0 ? page : 1;
    };

    const syncFilterControls = (browserUrl) => {
        document.querySelectorAll(`[data-ajax-pager-form="${pagerId}"]`).forEach((form) => {
            if (typeof form.querySelectorAll !== 'function') return;
            form.querySelectorAll('[name]').forEach((control) => {
                if (!filterParameters.includes(control.name) || !('value' in control)) return;
                control.value = browserUrl.searchParams.get(control.name) || '';
            });
        });
        const hasActiveFilter = filterParameters.some((parameter) => (browserUrl.searchParams.get(parameter) || '') !== '');
        document.querySelectorAll(`[data-ajax-pager-clear="${pagerId}"]`).forEach((link) => {
            link.hidden = !hasActiveFilter;
        });
        pager.querySelectorAll?.('[data-ajax-page-size]').forEach((control) => {
            control.value = browserUrl.searchParams.get(pageSizeParameter) || pager.dataset.pageSize || '10';
        });
    };

    const loadUrl = async (browserUrl, updateHistory = true) => {
        if (!content || !endpoint) return;
        const requestedPage = pageFromUrl(browserUrl);
        const activeTab = new URL(window.location.href).searchParams.get('tab');
        if (activeTab && !browserUrl.searchParams.has('tab')) browserUrl.searchParams.set('tab', activeTab);

        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;
        setLoading(true);

        try {
            const pageEndpoint = new URL(endpoint, window.location.href);
            pageEndpoint.search = browserUrl.search;
            pageEndpoint.searchParams.set(pageParameter, String(requestedPage));
            const response = await fetch(pageEndpoint, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: request.signal,
            });
            if (response.redirected && response.url && new URL(response.url).pathname.endsWith('/dhl/pickupsheet/login')) {
                window.location.assign(response.url);
                return;
            }
            if (!response.ok) throw new Error(`Pagination request failed with ${response.status}`);

            content.innerHTML = await response.text();
            bindPageSizeControls();
            const pageState = content.querySelector('[data-ajax-current-page]');
            const actualPage = Number.parseInt(pageState?.dataset.ajaxCurrentPage || String(requestedPage), 10);
            pager.dataset.currentPage = String(Number.isInteger(actualPage) && actualPage > 0 ? actualPage : requestedPage);
            browserUrl.searchParams.set(pageParameter, pager.dataset.currentPage);
            currentState = stateSignature(browserUrl);
            syncFilterControls(browserUrl);
            if (updateHistory) {
                window.history.pushState({ ajaxPager: pagerId }, '', browserUrl);
            }
        } catch (error) {
            if (error?.name !== 'AbortError') showLoadError();
        } finally {
            if (activeRequest === request) {
                activeRequest = null;
                setLoading(false);
            }
        }
    };

    const refreshForPageSize = (control) => {
        const browserUrl = new URL(window.location.href);
        browserUrl.searchParams.set(pageParameter, '1');
        browserUrl.searchParams.set(pageSizeParameter, control.value);
        loadUrl(browserUrl);
    };

    const bindPageSizeControls = () => {
        pager.querySelectorAll?.('[data-ajax-page-size]').forEach((control) => {
            if (control.dataset.ajaxPageSizeReady === 'true') return;
            control.dataset.ajaxPageSizeReady = 'true';
            control.addEventListener('change', () => refreshForPageSize(control));
            control.addEventListener('focus', () => control.closest('.pickup-pagination-size')?.setAttribute('data-active', ''));
            control.addEventListener('blur', () => control.closest('.pickup-pagination-size')?.removeAttribute('data-active'));
        });
    };

    bindPageSizeControls();

    pager.addEventListener('click', (event) => {
        const pageSizeControl = event.target.closest?.('[data-ajax-page-size]');
        if (pageSizeControl?.dataset && Object.prototype.hasOwnProperty.call(pageSizeControl.dataset, 'ajaxPageSize')) return;
        const link = event.target.closest('[data-ajax-page]');
        if (!link) return;
        const page = Number.parseInt(link.dataset.ajaxPage || '', 10);
        if (!Number.isInteger(page) || page < 1) return;
        event.preventDefault();
        const browserUrl = new URL(link.href || link.getAttribute('href'), window.location.href);
        browserUrl.searchParams.set(pageParameter, String(page));
        browserUrl.searchParams.set(pageSizeParameter, pager.querySelector('[data-ajax-page-size]')?.value || pager.dataset.pageSize || '10');
        loadUrl(browserUrl);
    });

    ajaxPagerControllers.set(pagerId, {
        currentPage: () => Number.parseInt(pager.dataset.currentPage || '1', 10),
        loadUrl,
        needsLoad: (url) => stateSignature(url) !== currentState,
        pageParameter,
        pageSizeParameter,
        currentPageSize: () => pager.querySelector?.('[data-ajax-page-size]')?.value || pager.dataset.pageSize || '10',
        pageFromUrl,
    });
});

document.querySelectorAll('[data-ajax-pager-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const controller = ajaxPagerControllers.get(form.dataset.ajaxPagerForm);
        // Buttons with their own formaction (such as Export) submit normally.
        if (!controller || event.submitter?.hasAttribute('formaction')) return;
        event.preventDefault();
        const browserUrl = new URL(form.action, window.location.href);
        new FormData(form).forEach((value, key) => {
            if (typeof value === 'string' && value !== '') browserUrl.searchParams.set(key, value);
        });
        browserUrl.searchParams.set(controller.pageParameter, '1');
        browserUrl.searchParams.set(controller.pageSizeParameter, controller.currentPageSize());
        controller.loadUrl(browserUrl);
    });
});

document.querySelectorAll('[data-ajax-pager-clear]').forEach((link) => {
    link.addEventListener('click', (event) => {
        const controller = ajaxPagerControllers.get(link.dataset.ajaxPagerClear);
        if (!controller) return;
        event.preventDefault();
        const browserUrl = new URL(link.href, window.location.href);
        browserUrl.searchParams.set(controller.pageSizeParameter, controller.currentPageSize());
        controller.loadUrl(browserUrl);
    });
});

if (ajaxPagerControllers.size > 0) {
    window.addEventListener('popstate', () => {
        const browserUrl = new URL(window.location.href);
        ajaxPagerControllers.forEach((controller) => {
            if (controller.needsLoad(browserUrl)) controller.loadUrl(new URL(browserUrl), false);
        });
    });
}

const dashboardTabs = document.querySelector('[data-dashboard-tabs]');
if (dashboardTabs) {
    const tabs = Array.from(dashboardTabs.querySelectorAll('[data-dashboard-tab]'));
    const panels = Array.from(dashboardTabs.querySelectorAll('[data-dashboard-panel]'));
    const tablist = dashboardTabs.querySelector('[role="tablist"]');

    const revealTab = (tab) => {
        if (!tablist || tablist.scrollWidth <= tablist.clientWidth) return;
        tablist.scrollLeft = Math.max(0, tab.offsetLeft - ((tablist.clientWidth - tab.offsetWidth) / 2));
    };

    const activateTab = (key, { focus = false, updateUrl = true } = {}) => {
        const activeTab = tabs.find((tab) => tab.dataset.dashboardTab === key) ?? tabs[0];
        if (!activeTab) return;
        tabs.forEach((tab) => {
            const selected = tab === activeTab;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.dashboardPanel !== activeTab.dataset.dashboardTab;
        });
        revealTab(activeTab);
        if (focus) activeTab.focus({ preventScroll: true });
        if (updateUrl) {
            const browserUrl = new URL(window.location.href);
            browserUrl.searchParams.set('tab', activeTab.dataset.dashboardTab);
            window.history.replaceState(window.history.state, '', browserUrl);
        }
    };

    dashboardTabs.addEventListener('click', (event) => {
        const tab = event.target.closest?.('[data-dashboard-tab]');
        if (!tab) return;
        event.preventDefault();
        activateTab(tab.dataset.dashboardTab);
    });

    tablist?.addEventListener('keydown', (event) => {
        const currentIndex = tabs.indexOf(document.activeElement);
        if (currentIndex < 0) return;
        const targetIndex = {
            ArrowRight: (currentIndex + 1) % tabs.length,
            ArrowLeft: (currentIndex - 1 + tabs.length) % tabs.length,
            Home: 0,
            End: tabs.length - 1,
        }[event.key];
        if (targetIndex === undefined) return;
        event.preventDefault();
        activateTab(tabs[targetIndex].dataset.dashboardTab, { focus: true });
    });

    window.addEventListener('popstate', () => {
        activateTab(new URL(window.location.href).searchParams.get('tab') || 'market', { updateUrl: false });
    });

    const initialTab = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true');
    if (initialTab) revealTab(initialTab);
}

// Payment and receipt-correction modals. A receipt number needs at least 6 digits, the payment form also
// needs the receipt amount to equal the pickup sheet amount, and a correction must change the number.
// The server repeats every check.
const RECORD_DIALOG_FORMS = '[data-pickup-payment], [data-pickup-receipt-edit]';
const RECORD_DIALOG_TEXT = {
    payment: {
        idle: 'Confirm paid',
        busy: 'Confirming...',
        success: 'Payment confirmed',
        failure: 'Payment not confirmed',
        done: 'The pickup sheet is now marked paid.',
        action: 'confirm the payment',
    },
    receipt: {
        idle: 'Save receipt',
        busy: 'Saving...',
        success: 'Receipt number updated',
        failure: 'Receipt number not changed',
        done: 'The receipt number was updated.',
        action: 'change the receipt number',
    },
};
const recordDialogText = (form) => RECORD_DIALOG_TEXT[form.matches('[data-pickup-receipt-edit]') ? 'receipt' : 'payment'];
const RECEIPT_HINT = 'At least 6 digits. Letters, dots, slashes, underscores, and hyphens are also allowed.';

// Shows the field's status text and its green tick once the value is correct.
const showFieldState = (form, input, statusSelector, state, message) => {
    const status = form.querySelector(statusSelector);
    if (status) {
        status.textContent = message;
        status.dataset.state = state;
    }
    const field = input.closest('.pickup-payment-field');
    if (field) field.dataset.valid = state === 'match' ? 'true' : 'false';
    input.setAttribute('aria-invalid', state === 'mismatch' ? 'true' : 'false');
};
// Each modal answers a single-use security question fetched from the server when it opens.
const applyRecordCaptcha = (form, captcha) => {
    const nonce = form.querySelector('[data-captcha-nonce]');
    const question = form.querySelector('[data-captcha-question]');
    const answer = form.querySelector('[data-captcha-answer]');
    const status = form.querySelector('[data-captcha-status]');
    if (!nonce || !question || !answer) return;
    const ready = typeof captcha?.nonce === 'string' && typeof captcha?.question === 'string';
    nonce.value = ready ? captcha.nonce : '';
    question.textContent = ready ? captcha.question : '…';
    answer.value = '';
    answer.disabled = !ready;
    if (status) {
        status.textContent = ready ? 'Answer the calculation to confirm you are human.' : 'The security check could not be loaded. Close this window and try again.';
        status.dataset.state = ready ? '' : 'mismatch';
    }
    delete form.dataset.captchaStale;
    syncReceiptSubmit(form);
};
const loadRecordCaptcha = async (form) => {
    const endpoint = form?.dataset.captchaEndpoint;
    if (!endpoint || !form.querySelector('[data-captcha-nonce]')) return;
    applyRecordCaptcha(form, null);
    const status = form.querySelector('[data-captcha-status]');
    if (status) {
        status.textContent = 'Loading security check...';
        status.dataset.state = '';
    }
    try {
        const response = await fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const result = await response.json();
        applyRecordCaptcha(form, response.ok && result?.ok === true ? result.captcha : null);
    } catch {
        applyRecordCaptcha(form, null);
    }
};

const syncReceiptSubmit = (form) => {
    const receipt = form?.querySelector('[name="receipt_number"]');
    const submit = form?.querySelector('button[type="submit"]');
    if (!receipt || !submit) return;
    const receiptValue = receipt.value.trim();
    const digitCount = (receiptValue.match(/[0-9]/g) || []).length;
    const receiptValid = /^[A-Za-z0-9][A-Za-z0-9._\/-]{5,63}$/.test(receiptValue) && digitCount >= 6;
    const current = form.dataset.currentReceipt || '';
    const unchanged = current !== '' && receiptValue.toUpperCase() === current.toUpperCase();
    if (receiptValue === '') {
        showFieldState(form, receipt, '[data-payment-receipt-status]', '', RECEIPT_HINT);
    } else if (unchanged) {
        showFieldState(form, receipt, '[data-payment-receipt-status]', '', 'This is the current receipt number. Enter the corrected number.');
    } else if (receiptValid) {
        showFieldState(form, receipt, '[data-payment-receipt-status]', 'match', 'Receipt number is valid.');
    } else {
        showFieldState(form, receipt, '[data-payment-receipt-status]', 'mismatch', digitCount < 6
            ? 'Receipt number is invalid. It must contain at least 6 digits.'
            : 'Receipt number is invalid. Use only letters, numbers, dots, slashes, underscores, or hyphens.');
    }
    let blocked = !receiptValid || unchanged;

    const amount = form.querySelector('[name="receipt_amount"]');
    if (amount) {
        const expected = form.dataset.expectedAmount || '';
        const entered = amount.value.replace(/[\s,]/g, '');
        let message = 'Enter the amount shown on the receipt.';
        let state = '';
        if (entered !== '' && !/^[0-9]{1,12}$/.test(entered)) {
            message = 'Amount is incorrect. Enter a whole number of XAF.';
            state = 'mismatch';
        } else if (entered !== '' && Number(entered) !== Number(expected)) {
            message = 'Amount is incorrect. It does not match the pickup sheet amount.';
            state = 'mismatch';
        } else if (entered !== '') {
            message = 'Amount is correct.';
            state = 'match';
        }
        showFieldState(form, amount, '[data-payment-amount-status]', state, message);
        blocked = blocked || state !== 'match';
    }
    const captchaAnswer = form.querySelector('[data-captcha-answer]');
    if (captchaAnswer) {
        const captchaNonce = form.querySelector('[data-captcha-nonce]')?.value || '';
        blocked = blocked || captchaNonce === '' || !/^[0-9]{1,2}$/.test(captchaAnswer.value.trim());
    }
    submit.disabled = blocked;
};

const showPaymentResult = (dialog, success, title, message) => {
    const form = dialog.querySelector(RECORD_DIALOG_FORMS);
    const result = dialog.querySelector('[data-payment-result]');
    if (!form || !result) return;
    result.dataset.outcome = success ? 'success' : 'failure';
    result.querySelector('[data-payment-result-title]').textContent = title;
    result.querySelector('[data-payment-result-message]').textContent = message;
    result.querySelector('[data-payment-retry]').hidden = success;
    form.hidden = true;
    result.hidden = false;
    result.querySelector(success ? '[data-payment-done]' : '[data-payment-retry]')?.focus();
};
const paymentFailureMessage = (status, text) => {
    if (status === 419) return 'Your session form expired. Reload the page and try again.';
    if (status === 403) return `You do not have permission to ${text.action}.`;
    if (status === 429) return 'Too many attempts. Wait a few minutes and try again.';
    return `Could not ${text.action}. Check your connection and try again.`;
};
const submitPayment = async (form) => {
    const dialog = form.closest('dialog');
    const submit = form.querySelector('button[type="submit"]');
    if (!dialog || !submit || submit.disabled || submit.dataset.submitting === '1') return;
    const text = recordDialogText(form);
    submit.dataset.submitting = '1';
    submit.disabled = true;
    submit.textContent = text.busy;
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (response.redirected && response.url && new URL(response.url).pathname.endsWith('/dhl/pickupsheet/login')) {
            showPaymentResult(dialog, false, text.failure, `Your session has ended. Sign in again, then ${text.action}.`);
            return;
        }
        let result = null;
        try {
            result = await response.json();
        } catch {
            result = null;
        }
        if (response.ok && result?.ok === true) {
            dialog.dataset.paid = '1';
            showPaymentResult(dialog, true, text.success, result.message || text.done);
            return;
        }
        if (result?.captcha) {
            applyRecordCaptcha(form, result.captcha);
        } else {
            form.dataset.captchaStale = '1';
        }
        showPaymentResult(dialog, false, text.failure, result?.message || paymentFailureMessage(response.status, text));
    } catch {
        form.dataset.captchaStale = '1';
        showPaymentResult(dialog, false, text.failure, paymentFailureMessage(0, text));
    } finally {
        submit.removeAttribute('data-submitting');
        submit.textContent = text.idle;
        syncReceiptSubmit(form);
    }
};
const resetPaymentDialog = (dialog) => {
    const form = dialog.querySelector(RECORD_DIALOG_FORMS);
    const result = dialog.querySelector('[data-payment-result]');
    if (result) result.hidden = true;
    if (form) form.hidden = false;
    return form;
};

document.addEventListener('click', (event) => {
    const opener = event.target.closest?.('[data-payment-dialog-open]');
    if (opener) {
        const dialog = document.getElementById(opener.dataset.paymentDialogOpen);
        if (!dialog || typeof dialog.showModal !== 'function') return;
        const form = resetPaymentDialog(dialog);
        delete dialog.dataset.paid;
        form?.reset();
        form?.querySelector('button[type="submit"]')?.removeAttribute('data-submitting');
        syncReceiptSubmit(form);
        dialog.showModal();
        loadRecordCaptcha(form);
        const receipt = form?.querySelector('[name="receipt_number"]');
        receipt?.focus();
        receipt?.select?.();
        return;
    }
    if (event.target.closest?.('[data-payment-retry]')) {
        const form = resetPaymentDialog(event.target.closest('dialog'));
        if (form?.dataset.captchaStale === '1') loadRecordCaptcha(form);
        form?.querySelector('[aria-invalid="true"], [name="receipt_number"]')?.focus();
        return;
    }
    if (event.target.closest?.('[data-payment-dialog-close], [data-payment-done]')) {
        event.target.closest('dialog')?.close();
        return;
    }
    // A click on the backdrop lands on the dialog element itself.
    if (event.target.matches?.('.pickup-payment-dialog')) event.target.close();
});
// The Pickupsheet privacy notice opens in a modal so staff never leave the app to read it.
document.addEventListener('click', (event) => {
    const dialog = document.querySelector('[data-privacy-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    if (event.target.closest?.('[data-privacy-dialog-open]')) {
        event.preventDefault();
        if (!dialog.open) dialog.showModal();
        dialog.querySelector('.pickup-privacy-copy')?.scrollTo?.(0, 0);
        return;
    }
    if (event.target.closest?.('[data-privacy-dialog-close]') || event.target === dialog) dialog.close();
});
// After a successful save, closing the dialog reloads the list so the sheet shows its new state.
document.addEventListener('close', (event) => {
    if (event.target.matches?.('.pickup-payment-dialog') && event.target.dataset.paid === '1') {
        window.location.reload();
    }
}, true);

document.addEventListener('toggle', (event) => {
    if (!event.target.open || !event.target.matches?.('[data-audit-log-entry]')) return;
    event.target.closest('[data-audit-log-accordion]')
        ?.querySelectorAll('[data-audit-log-entry][open]')
        .forEach((entry) => {
            if (entry !== event.target) entry.open = false;
        });
}, true);
document.addEventListener('input', (event) => {
    if (event.target.matches?.('[data-pickup-payment] [name="receipt_number"], [data-pickup-payment] [name="receipt_amount"], [data-pickup-receipt-edit] [name="receipt_number"], [data-captcha-answer]')) {
        syncReceiptSubmit(event.target.form);
    }
});

const accountEditors = Array.from(document.querySelectorAll('[data-user-editor]'));
const closeAccountEditor = (editor) => {
    if (!editor) return;
    editor.hidden = true;
    document.querySelectorAll('[data-user-edit-toggle]').forEach((button) => {
        if (button.dataset.userEditToggle === editor.id) button.setAttribute('aria-expanded', 'false');
    });
};

document.querySelectorAll('[data-user-edit-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const editor = document.getElementById(button.dataset.userEditToggle || '');
        if (!editor) return;
        const opening = editor.hidden;
        accountEditors.forEach(closeAccountEditor);
        if (!opening) return;
        editor.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        editor.querySelector('input:not([type="hidden"])')?.focus({ preventScroll: true });
    });
});

document.querySelectorAll('[data-user-edit-cancel]').forEach((button) => {
    button.addEventListener('click', () => closeAccountEditor(button.closest('[data-user-editor]')));
});

const loginMethodForm = document.querySelector('[data-login-method-form]');
if (loginMethodForm) {
    const methodToggles = Array.from(loginMethodForm.querySelectorAll('[data-login-method-toggle]'));
    const notice = loginMethodForm.querySelector('[data-login-method-notice]');
    const saveButton = loginMethodForm.querySelector('[data-login-method-save]');
    let savedState = Object.fromEntries(methodToggles.map((toggle) => [toggle.dataset.loginMethodToggle, toggle.checked]));
    let saving = false;

    const showMethodNotice = (message, failed = false) => {
        if (!notice) return;
        notice.hidden = false;
        notice.textContent = message;
        notice.dataset.failed = failed ? 'true' : 'false';
    };
    const renderMethodState = (key, enabled) => {
        const card = loginMethodForm.querySelector(`[data-login-method-card="${key}"]`);
        const status = loginMethodForm.querySelector(`[data-login-method-status="${key}"]`);
        if (!card || !status) return;
        card.dataset.enabled = enabled ? 'true' : 'false';
        status.textContent = card.dataset.configured === 'true' ? (enabled ? 'Enabled' : 'Disabled') : 'Unavailable';
    };

    loginMethodForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (saving) return;
        saving = true;
        methodToggles.forEach((toggle) => { toggle.disabled = true; });
        if (saveButton) {
            saveButton.disabled = true;
            saveButton.textContent = 'Saving...';
        }

        const payload = new FormData(loginMethodForm);
        methodToggles.forEach((toggle) => payload.set(toggle.name, toggle.checked ? '1' : '0'));
        try {
            const response = await fetch(loginMethodForm.action, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true) throw new Error(result.message || 'Sign-in methods could not be saved.');
            const state = {
                local: result.localLoginEnabled === true,
                jumpcloud: result.jumpCloudLoginEnabled === true,
            };
            methodToggles.forEach((toggle) => {
                toggle.checked = state[toggle.dataset.loginMethodToggle] === true;
                renderMethodState(toggle.dataset.loginMethodToggle, toggle.checked);
            });
            savedState = state;
            showMethodNotice(result.message || 'Sign-in methods updated.');
        } catch (error) {
            methodToggles.forEach((toggle) => {
                toggle.checked = savedState[toggle.dataset.loginMethodToggle] === true;
                renderMethodState(toggle.dataset.loginMethodToggle, toggle.checked);
            });
            showMethodNotice(error instanceof Error ? error.message : 'Sign-in methods could not be saved.', true);
        } finally {
            saving = false;
            methodToggles.forEach((toggle) => {
                const card = toggle.closest('[data-login-method-card]');
                toggle.disabled = card?.dataset.configured !== 'true';
            });
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = 'Save sign-in methods';
            }
        }
    });

    methodToggles.forEach((toggle) => toggle.addEventListener('change', () => loginMethodForm.requestSubmit()));
}

document.querySelector('[data-copy-recovery-codes]')?.addEventListener('click', async (event) => {
    const codes = Array.from(document.querySelectorAll('[data-recovery-code-list] code'))
        .map((code) => code.textContent?.trim())
        .filter(Boolean)
        .join('\n');
    if (codes === '') return;
    try {
        await navigator.clipboard.writeText(codes);
        event.currentTarget.textContent = 'Codes copied';
    } catch {
        event.currentTarget.textContent = 'Copy unavailable';
    }
});

document.querySelector('[data-print-recovery-codes]')?.addEventListener('click', () => window.print());

// Picking a suggested customer name in a search box runs the search straight away.
document.querySelectorAll('[data-submit-on-suggestion]').forEach((input) => {
    input.addEventListener('consignor-suggestion-selected', () => input.form?.requestSubmit());
});

const existingCustomerHint = document.querySelector('[data-customer-autocomplete-form] [data-existing-customer-hint]');
const existingCustomerInput = document.querySelector('[data-customer-autocomplete-form] [data-consignor-input]');
if (existingCustomerHint && existingCustomerInput) {
    const hideExistingCustomer = () => {
        existingCustomerHint.hidden = true;
        existingCustomerHint.replaceChildren();
    };
    existingCustomerInput.addEventListener('input', hideExistingCustomer);
    existingCustomerInput.addEventListener('consignor-search-results', (event) => {
        const existing = event.detail?.payload?.existing;
        if (!existing || typeof existing.name !== 'string' || typeof existing.url !== 'string' || !existing.url.startsWith('/') || existing.url.startsWith('//')) {
            hideExistingCustomer();
            return;
        }
        const link = document.createElement('a');
        link.href = existing.url;
        link.textContent = 'Open ' + existing.name;
        const message = typeof existing.alias === 'string'
            ? existing.alias + ' was merged into ' + existing.name + '. '
            : existing.name + ' already has a CRM profile. ';
        existingCustomerHint.replaceChildren(document.createTextNode(message), link);
        existingCustomerHint.hidden = false;
    });
}

// Profile merge: look up the typed duplicate and preview it before the merge can be reviewed.
const profileMergeForm = document.querySelector('[data-profile-merge]');
if (profileMergeForm) {
    const mergeInput = profileMergeForm.querySelector('[name="source_customer_name"]');
    const mergeCard = profileMergeForm.querySelector('[data-merge-source-card]');
    const mergeFacts = profileMergeForm.querySelector('[data-merge-source-facts]');
    const mergeStatus = profileMergeForm.querySelector('[data-merge-status]');
    const mergeSubmit = profileMergeForm.querySelector('[data-merge-submit]');
    const keepKey = profileMergeForm.dataset.keepKey || '';
    const keepName = profileMergeForm.dataset.keepName || 'this customer';
    const mergeStatusLabels = { lead: 'Lead', active: 'Active', attention: 'Needs attention', inactive: 'Inactive' };
    let mergeLookupTimer = null;
    let mergeLookupRequest = null;
    const setMergeState = (state, message) => {
        if (mergeCard) mergeCard.dataset.state = state;
        if (mergeStatus) mergeStatus.textContent = message;
        if (mergeFacts && state !== 'ready') mergeFacts.hidden = true;
        if (mergeSubmit) mergeSubmit.disabled = state !== 'ready';
    };
    const setMergeFact = (name, value) => {
        const fact = profileMergeForm.querySelector(`[data-merge-fact="${name}"]`);
        if (fact) fact.textContent = value;
    };
    const lookUpMergeSource = async () => {
        const query = mergeInput.value.trim();
        mergeLookupRequest?.abort();
        if (query.length < 2) {
            setMergeState('empty', 'Pick the duplicate profile from the suggestions.');
            return;
        }
        setMergeState('searching', 'Looking up this customer...');
        const request = new AbortController();
        mergeLookupRequest = request;
        try {
            const url = new URL(profileMergeForm.dataset.lookupEndpoint || '', window.location.href);
            url.searchParams.set('q', query);
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: request.signal,
            });
            const payload = await response.json();
            if (mergeInput.value.trim() !== query) return;
            const existing = payload?.existing;
            if (!response.ok || !existing || typeof existing.name !== 'string') {
                setMergeState('error', 'No customer profile has this exact name. Pick one from the suggestions.');
                return;
            }
            if (existing.key === keepKey) {
                setMergeState('error', typeof existing.alias === 'string'
                    ? `${existing.alias} is already merged into ${keepName}.`
                    : `That is ${keepName}, the profile you are viewing.`);
                return;
            }
            setMergeFact('shipments', Number(existing.shipmentCount || 0).toLocaleString('en-US'));
            setMergeFact('last', existing.lastShipmentOn || 'None');
            setMergeFact('contact', existing.contactName || 'Not recorded');
            setMergeFact('status', mergeStatusLabels[existing.status] || existing.status || 'Unknown');
            if (mergeFacts) mergeFacts.hidden = false;
            setMergeState('ready', typeof existing.alias === 'string'
                ? `${existing.alias} now belongs to ${existing.name}. ${existing.name} will be merged into ${keepName}.`
                : `${existing.name} will be merged into ${keepName}.`);
        } catch (error) {
            if (error?.name !== 'AbortError') setMergeState('error', 'This customer could not be looked up. Try again.');
        }
    };
    const scheduleMergeLookup = () => {
        window.clearTimeout(mergeLookupTimer);
        if (mergeSubmit) mergeSubmit.disabled = true;
        mergeLookupTimer = window.setTimeout(lookUpMergeSource, 300);
    };
    mergeInput?.addEventListener('input', scheduleMergeLookup);
    mergeInput?.addEventListener('change', scheduleMergeLookup);
}

// One shared confirmation modal replaces the browser's confirm() prompt for every sensitive form.
let confirmDialog = null;
const buildConfirmDialog = () => {
    const make = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    };
    const dialog = make('dialog', 'pickup-payment-dialog app-confirm-dialog');
    dialog.setAttribute('aria-labelledby', 'app-confirm-title');
    dialog.setAttribute('aria-describedby', 'app-confirm-message');
    const body = make('div', 'app-confirm-body');
    const header = make('header');
    const eyebrow = make('p');
    const title = make('h2');
    title.id = 'app-confirm-title';
    header.append(eyebrow, title);
    const message = make('p', 'app-confirm-message');
    message.id = 'app-confirm-message';
    const actions = make('div', 'pickup-payment-actions');
    const cancel = make('button', '', 'Cancel');
    cancel.type = 'button';
    cancel.setAttribute('data-payment-dialog-close', '');
    const confirm = make('button', 'pickup-payment-confirm');
    confirm.type = 'button';
    actions.append(cancel, confirm);
    body.append(header, message, actions);
    dialog.append(body);
    document.body.append(dialog);
    return { dialog, eyebrow, title, message, cancel, confirm };
};
const confirmInModal = ({ eyebrow = 'Please confirm', title, message, confirmLabel, danger = false }) => new Promise((resolve) => {
    if (typeof HTMLDialogElement === 'undefined') {
        resolve(window.confirm(message));
        return;
    }
    confirmDialog ??= buildConfirmDialog();
    const parts = confirmDialog;
    parts.eyebrow.textContent = eyebrow;
    parts.title.textContent = title;
    parts.message.textContent = message;
    parts.confirm.textContent = confirmLabel;
    parts.dialog.dataset.tone = danger ? 'danger' : 'default';
    let confirmed = false;
    parts.confirm.onclick = () => {
        confirmed = true;
        parts.dialog.close();
    };
    parts.dialog.addEventListener('close', () => resolve(confirmed), { once: true });
    parts.dialog.showModal();
    // Destructive actions start on Cancel so Enter never confirms them by accident.
    (danger ? parts.cancel : parts.confirm).focus();
});

const CONFIRMED_ACTIONS = [
    {
        selector: '[data-crm-delete-form]',
        options: (form) => {
            const name = form.dataset.customerName || 'this customer';
            return {
                title: 'Delete customer?',
                message: `Permanently delete ${name}? Contact details, activity, reward adjustments, and merge records are removed and cannot be restored. Pickup sheets keep the consignor name.`,
                confirmLabel: 'Delete customer',
                danger: true,
            };
        },
    },
    {
        selector: '[data-crm-dismiss-merge-form]',
        options: (form) => {
            const mergeName = form.dataset.mergeName || 'the merged customer';
            const keepName = form.dataset.keepName || 'the retained customer';
            return {
                title: 'Keep this merge?',
                message: `Keep the merge of ${mergeName} into ${keepName}? It will be removed from Recent merges and can no longer be undone.`,
                confirmLabel: 'Keep merge',
            };
        },
    },
    {
        selector: '[data-crm-close-follow-up-form]',
        options: (form) => {
            const dueOn = form.dataset.followUpDate || '';
            return {
                title: 'Close this follow-up?',
                message: `Close the follow-up${dueOn !== '' ? ` due ${dueOn}` : ''}? A note saying it was closed is added to Activity. Schedule a new one from Activity at any time.`,
                confirmLabel: 'Close follow-up',
            };
        },
    },
    {
        selector: '[data-crm-dismiss-duplicate-form]',
        options: (form) => {
            const firstName = form.dataset.firstName || 'the first customer';
            const secondName = form.dataset.secondName || 'the second customer';
            return {
                title: 'Ignore this suggestion?',
                message: `${firstName} and ${secondName} will stay as separate customers and will not be suggested as duplicates again. You can still merge them from a customer profile with Merge a duplicate.`,
                confirmLabel: 'Ignore suggestion',
            };
        },
    },
    {
        selector: '[data-crm-undo-merge-form]',
        options: (form) => {
            const mergeName = form.dataset.mergeName || 'the merged customer';
            const keepName = form.dataset.keepName || 'the retained customer';
            return {
                title: 'Undo this merge?',
                message: `Undo the merge of ${mergeName} into ${keepName}? ${mergeName} will become a separate profile again with its original shipments and rewards.`,
                confirmLabel: 'Undo merge',
            };
        },
    },
    {
        selector: '[data-crm-merge-form]',
        options: (form) => {
            const keepName = form.dataset.keepName || 'the selected customer';
            const mergeName = form.dataset.mergeName || form.querySelector('[name="source_customer_name"]')?.value.trim() || 'the duplicate customer';
            return {
                title: 'Merge customers?',
                message: `Merge ${mergeName} into ${keepName}? The ${mergeName} profile will be removed after its data is transferred; you can undo this from Recent merges.`,
                confirmLabel: 'Merge customers',
            };
        },
    },
    {
        selector: '[data-pickup-delete]',
        options: () => ({
            title: 'Delete pickup sheet?',
            message: 'Delete this pickup sheet from active records? Its audit history will be retained.',
            confirmLabel: 'Delete sheet',
            danger: true,
        }),
    },
    {
        selector: '[data-user-delete-form]',
        confirmField: '[data-confirm-delete]',
        options: (form) => ({
            title: 'Delete account?',
            message: `Permanently delete ${form.dataset.accountName || 'this local account'}? This account will no longer be able to sign in.`,
            confirmLabel: 'Delete account',
            danger: true,
        }),
    },
    {
        selector: '[data-user-status-form]',
        confirmField: '[data-confirm-user-status]',
        // Only disabling an account needs confirmation; re-enabling goes straight through.
        applies: (form) => form.querySelector('[data-user-target-active]')?.value !== '1',
        options: (form) => ({
            title: 'Disable account?',
            message: `Disable ${form.dataset.accountName || 'this managed account'}? Their current session and future local sign-ins will be blocked.`,
            confirmLabel: 'Disable account',
            danger: true,
        }),
    },
    {
        selector: '[data-self-mfa-reset]',
        confirmField: '[data-confirm-self-mfa-reset]',
        options: (form) => ({
            title: 'Replace authenticator?',
            message: `Replace the authenticator for ${form.dataset.accountName || 'your account'}? The current authenticator and unused recovery codes will stop working.`,
            confirmLabel: 'Replace authenticator',
            danger: true,
        }),
    },
];

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.matches('[data-pickup-payment], [data-pickup-receipt-edit]')) {
        // Submitted in the background so the modal can show the outcome; a second submit is ignored.
        event.preventDefault();
        submitPayment(form);
        return;
    }
    const action = CONFIRMED_ACTIONS.find((candidate) => form.matches(candidate.selector));
    if (!action || (action.applies && !action.applies(form))) return;
    if (form.dataset.confirmed === '1') {
        // Second pass after the modal was confirmed: let the browser submit normally.
        delete form.dataset.confirmed;
        const confirmation = action.confirmField ? form.querySelector(action.confirmField) : null;
        if (confirmation) confirmation.value = '1';
        return;
    }
    event.preventDefault();
    const submitter = event.submitter;
    confirmInModal(action.options(form)).then((confirmed) => {
        if (!confirmed) return;
        form.dataset.confirmed = '1';
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            return;
        }
        delete form.dataset.confirmed;
        const confirmation = action.confirmField ? form.querySelector(action.confirmField) : null;
        if (confirmation) confirmation.value = '1';
        form.submit();
    });
});

// Cancel on a new pickup sheet leaves the form; entered data is only discarded after confirmation.
document.addEventListener('click', (event) => {
    const cancel = event.target.closest?.('[data-discard-pickup-sheet]');
    if (!cancel) return;
    const form = cancel.closest('form');
    const hasEntries = Array.from(form?.querySelectorAll('[name="agent_name"], [data-field]:not([readonly])') ?? [])
        .some((input) => input.value.trim() !== '');
    if (!hasEntries) return;
    event.preventDefault();
    confirmInModal({
        title: 'Discard this pickup sheet?',
        message: 'The details you entered have not been saved and will be lost.',
        confirmLabel: 'Discard sheet',
        danger: true,
    }).then((confirmed) => {
        if (confirmed) window.location.assign(cancel.href);
    });
});

// Login: reveal or hide the password. The button stays hidden without JavaScript.
document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
    const input = toggle.closest('.pickup-login-password')?.querySelector('[data-password-input]');
    if (!input) return;
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        toggle.textContent = reveal ? 'Hide' : 'Show';
        toggle.setAttribute('aria-pressed', String(reveal));
        toggle.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        input.focus({ preventScroll: true });
    });
});
// Mask the password again on submit so it is not left visible on screen.
document.querySelector('.pickup-login-form')?.addEventListener('submit', (event) => {
    const input = event.target.querySelector('[data-password-input]');
    if (input) input.type = 'password';
});

// Pickupsheet workspace menu: at 900px and below the header links fold behind a Menu button.
document.querySelectorAll('[data-pickup-nav]').forEach((nav) => {
    const toggle = nav.querySelector('[data-pickup-nav-toggle]');
    const label = nav.querySelector('[data-pickup-nav-label]');
    const menu = nav.querySelector('[data-pickup-nav-menu]');
    if (!toggle || !menu) return;
    toggle.hidden = false;
    nav.setAttribute('data-nav-ready', '');
    const setOpen = (open, restoreFocus = false) => {
        nav.toggleAttribute('data-nav-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        if (label) label.textContent = open ? 'Close' : 'Menu';
        if (!open && restoreFocus) toggle.focus();
    };
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    menu.addEventListener('click', (event) => {
        if (event.target.closest?.('a')) setOpen(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && nav.hasAttribute('data-nav-open')) setOpen(false, true);
    });
    document.addEventListener('click', (event) => {
        if (nav.hasAttribute('data-nav-open') && !nav.contains(event.target)) setOpen(false);
    });
    window.matchMedia('(min-width: 901px)').addEventListener?.('change', (event) => {
        if (event.matches) setOpen(false);
    });
});

// Name fields hold uppercase letters, digits, spaces, and hyphens only; the server enforces the same rule.
const disallowedNameCharacters = /[^\p{L}\p{M}\p{N}\s-]+/gu;
const normalizeNameField = (input) => {
    if (!input || input.readOnly || typeof input.value !== 'string') return;
    const { value } = input;
    const next = value.replace(disallowedNameCharacters, '').toUpperCase();
    if (next === value) return;
    const caret = typeof input.selectionStart === 'number' ? input.selectionStart : null;
    input.value = next;
    if (caret !== null && document.activeElement === input) {
        const position = value.slice(0, caret).replace(disallowedNameCharacters, '').toUpperCase().length;
        input.setSelectionRange?.(position, position);
    }
};
const normalizeNameFieldEvent = (event) => {
    if (event.target?.matches?.('[data-name-field]')) normalizeNameField(event.target);
};
document.addEventListener?.('input', normalizeNameFieldEvent, true);
document.addEventListener?.('consignor-suggestion-selected', normalizeNameFieldEvent, true);
document.querySelectorAll?.('[data-name-field]').forEach(normalizeNameField);
