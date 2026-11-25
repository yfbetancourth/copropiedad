// Comportamientos declarativos compartidos. Reemplazan los manejadores en línea (onsubmit, onclick, onchange)
// para que la CSP pueda prohibir 'unsafe-inline' en scripts.
//   data-confirm="¿…?"       en un <form> o en su botón de envío: pide confirmación antes de enviar.
//   data-disable-on-submit   en un <form>: desactiva sus botones al enviarlo (evita el doble envío).
//   data-autosubmit          en un campo: envía su formulario al cambiar el valor.
//   data-print               en un botón: abre el diálogo de impresión.
//   data-back                en un enlace: vuelve a la página anterior (el href queda como alternativa).
(() => {
    'use strict';

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const message = event.submitter?.dataset.confirm ?? form.dataset.confirm;
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }
        if (form.hasAttribute('data-disable-on-submit')) {
            // Tras el envío: desactivarlos antes impediría que el botón pulsado viaje con el formulario.
            setTimeout(() => form.querySelectorAll('[type=submit]').forEach((button) => { button.disabled = true; }));
        }
    });

    document.addEventListener('change', (event) => {
        const field = event.target instanceof Element ? event.target.closest('[data-autosubmit]') : null;
        field?.form?.requestSubmit();
    });

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        if (event.target.closest('[data-print]')) {
            window.print();
            return;
        }
        if (event.target.closest('a[data-back]') && history.length > 1) {
            event.preventDefault();
            history.back();
        }
    });
})();
