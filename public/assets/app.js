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
        rows().at(-1)?.querySelector('[data-field="consignor"]')?.focus();
    });

    rowsContainer?.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-shipment]');
        if (!removeButton || rows().length === 1) return;
        removeButton.closest('[data-shipment-row]')?.remove();
        reindexRows();
    });

    rowsContainer?.addEventListener('input', (event) => {
        if (event.target.matches('[data-field="destination"]')) {
            event.target.value = event.target.value.toUpperCase();
        }
        updateSummary();
    });

    pickupForm.addEventListener('submit', reindexRows);
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

document.addEventListener('submit', (event) => {
    if (event.target.matches('[data-pickup-delete]')
        && !window.confirm('Delete this pickup sheet from active records? Its audit history will be retained.')) {
        event.preventDefault();
    }
});
