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

const pickupForm = document.querySelector('[data-pickup-form]');
if (pickupForm) {
    const rowsContainer = pickupForm.querySelector('[data-shipment-rows]');
    const rowTemplate = document.querySelector('[data-shipment-template]');
    const addButton = pickupForm.querySelector('[data-add-shipment]');
    const countOutput = pickupForm.querySelector('[data-shipment-count]');
    const totalOutput = pickupForm.querySelector('[data-shipment-total]');
    const consignorSuggestionList = pickupForm.querySelector('[data-consignor-suggestions]');
    const consignorSuggestionNames = Array.from(consignorSuggestionList?.querySelectorAll('option') ?? [])
        .map((option) => option.value.trim())
        .filter(Boolean);
    const consignorSearchEndpoint = consignorSuggestionList?.dataset?.searchEndpoint || '';
    const compareConsignorSuggestions = (left, right) => (
        left.localeCompare(right, undefined, { sensitivity: 'base' }) || left.localeCompare(right)
    );
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

    const showConsignorSuggestions = (input, source = consignorSuggestionNames) => {
        if (!consignorPopup) return;
        const query = input.value.trim().toLowerCase();
        if (query === '') {
            closeConsignorSuggestions();
            return;
        }
        const seenMatches = new Set();
        const matches = source
            .filter((name) => name.toLowerCase().includes(query))
            .filter((name) => {
                const normalizedName = name.trim().toLowerCase();
                if (seenMatches.has(normalizedName)) return false;
                seenMatches.add(normalizedName);
                return true;
            })
            .sort(compareConsignorSuggestions)
            .slice(0, 12);
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
                    ? Array.from(new Set(payload.suggestions.filter((name) => typeof name === 'string' && name.trim() !== '')))
                        .sort(compareConsignorSuggestions)
                    : [];
                if (searchGeneration === consignorSearchGeneration && activeConsignorInput === input && input.value.trim() === query) {
                    showConsignorSuggestions(input, [...consignorSuggestionNames, ...suggestions]);
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
        consignorPopup.setAttribute('aria-label', 'Consignor suggestions');
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
    const pagerId = pager.dataset.ajaxPagerId || pageParameter;
    let activeRequest = null;

    const setLoading = (loading) => {
        if (content) content.setAttribute('aria-busy', loading ? 'true' : 'false');
        if (spinner) spinner.hidden = !loading;
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

    const loadUrl = async (browserUrl, updateHistory = true) => {
        if (!content || !endpoint) return;
        const requestedPage = pageFromUrl(browserUrl);

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
            const pageState = content.querySelector('[data-ajax-current-page]');
            const actualPage = Number.parseInt(pageState?.dataset.ajaxCurrentPage || String(requestedPage), 10);
            pager.dataset.currentPage = String(Number.isInteger(actualPage) && actualPage > 0 ? actualPage : requestedPage);
            browserUrl.searchParams.set(pageParameter, pager.dataset.currentPage);
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

    pager.addEventListener('click', (event) => {
        const link = event.target.closest('[data-ajax-page]');
        if (!link) return;
        const page = Number.parseInt(link.dataset.ajaxPage || '', 10);
        if (!Number.isInteger(page) || page < 1) return;
        event.preventDefault();
        const browserUrl = new URL(link.href || link.getAttribute('href'), window.location.href);
        browserUrl.searchParams.set(pageParameter, String(page));
        loadUrl(browserUrl);
    });

    ajaxPagerControllers.set(pagerId, {
        currentPage: () => Number.parseInt(pager.dataset.currentPage || '1', 10),
        loadUrl,
        pageFromUrl,
    });
});

document.querySelectorAll('[data-ajax-pager-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const controller = ajaxPagerControllers.get(form.dataset.ajaxPagerForm);
        if (!controller) return;
        event.preventDefault();
        const browserUrl = new URL(form.action, window.location.href);
        new FormData(form).forEach((value, key) => {
            if (typeof value === 'string' && value !== '') browserUrl.searchParams.set(key, value);
        });
        browserUrl.searchParams.set('page', '1');
        controller.loadUrl(browserUrl);
    });
});

document.querySelectorAll('[data-ajax-pager-clear]').forEach((link) => {
    link.addEventListener('click', (event) => {
        const controller = ajaxPagerControllers.get(link.dataset.ajaxPagerClear);
        if (!controller) return;
        event.preventDefault();
        controller.loadUrl(new URL(link.href, window.location.href));
    });
});

if (ajaxPagerControllers.size > 0) {
    window.addEventListener('popstate', () => {
        const browserUrl = new URL(window.location.href);
        ajaxPagerControllers.forEach((controller) => {
            const page = controller.pageFromUrl(browserUrl);
            if (page !== controller.currentPage()) controller.loadUrl(new URL(browserUrl), false);
        });
    });
}

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

document.addEventListener('submit', (event) => {
    if (event.target.matches('[data-pickup-delete]')
        && !window.confirm('Delete this pickup sheet from active records? Its audit history will be retained.')) {
        event.preventDefault();
    }
    if (event.target.matches('[data-user-delete-form]')) {
        const accountName = event.target.dataset.accountName || 'this local account';
        if (!window.confirm(`Permanently delete ${accountName}? This account will no longer be able to sign in.`)) {
            event.preventDefault();
            return;
        }
        const confirmation = event.target.querySelector('[data-confirm-delete]');
        if (confirmation) confirmation.value = '1';
    }
    if (event.target.matches('[data-user-mfa-reset-form]')) {
        const accountName = event.target.dataset.accountName || 'this local account';
        if (!window.confirm(`Reset two-factor authentication for ${accountName}? They must enroll again at the next sign-in.`)) {
            event.preventDefault();
            return;
        }
        const confirmation = event.target.querySelector('[data-confirm-mfa-reset]');
        if (confirmation) confirmation.value = '1';
    }
});
