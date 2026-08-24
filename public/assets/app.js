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
