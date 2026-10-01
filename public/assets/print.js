(() => {
    'use strict';

    const printButton = document.querySelector('[data-print-pickup]');
    const openPrintDialog = () => window.print();
    let autoPrintStarted = false;

    printButton?.addEventListener('click', openPrintDialog);

    // Only pages that opt in open the print dialog on load; the pickup sheet preview waits for the button.
    const autoPrint = () => {
        if (autoPrintStarted || !printButton?.hasAttribute('data-print-auto')) return;
        autoPrintStarted = true;
        window.setTimeout(openPrintDialog, 150);
    };

    if (document.readyState !== 'loading') {
        autoPrint();
    } else {
        document.addEventListener('DOMContentLoaded', autoPrint, { once: true });
    }
})();
