const header = document.querySelector('[data-site-header]');
const toggle = document.querySelector('[data-nav-toggle]');
const navigation = document.querySelector('[data-navigation]');

if (toggle && navigation) {
    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!open));
        navigation.toggleAttribute('data-open', !open);
        document.body.toggleAttribute('data-nav-open', !open);
    });

    navigation.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
        toggle.setAttribute('aria-expanded', 'false');
        navigation.removeAttribute('data-open');
        document.body.removeAttribute('data-nav-open');
    }));
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
    }, { threshold: 0.12 });
    revealItems.forEach((item) => observer.observe(item));
} else {
    revealItems.forEach((item) => item.setAttribute('data-visible', ''));
}

