(() => {
    'use strict';

    const printButton = document.querySelector('[data-print-pickup]');
    const openPrintDialog = () => window.print();
    let autoPrintStarted = false;

    printButton?.addEventListener('click', openPrintDialog);

    const autoPrint = () => {
        if (autoPrintStarted) return;
        autoPrintStarted = true;
        window.setTimeout(openPrintDialog, 150);
    };

    if (document.readyState === 'complete') {
        autoPrint();
    } else {
        window.addEventListener('load', autoPrint, { once: true });
    }
})();
