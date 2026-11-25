// Comportamientos compartidos del sitio, sin dependencias. Reemplaza el JavaScript de Bootstrap y AdminLTE con
// elementos nativos (<dialog>, popover, comandos de invocación) y los manejadores en línea (onsubmit, onclick)
// para que la CSP prohíba 'unsafe-inline' en scripts.
//
//   data-confirm="¿…?"        en un <form> o su botón de envío: confirma con un diálogo antes de enviar.
//   data-loading-label="…"    en el botón de envío: lo desactiva y muestra «procesando» (evita el doble envío).
//   data-disable-on-submit    en un <form>: desactiva sus botones al enviarlo.
//   data-autosubmit           en un campo: envía su formulario al cambiar el valor.
//   data-print                en un botón: abre el diálogo de impresión.
//   data-back                 en un enlace: vuelve a la página anterior (el href queda como alternativa).
//   data-collapse             en un botón con aria-controls: muestra u oculta ese elemento (.collapse.show).
//   data-tab                  en un role="tab" con aria-controls: pestañas con teclado; emite «tab:shown».
//   data-sidebar-toggle       abre o contrae el menú lateral.
//   <dialog class="modal">    se abre con command="show-modal" commandfor="id" o dialog.showModal().
//   [popover].dropdown-menu   se abre con popovertarget="id"; se ubica junto a su botón.
//
// Para scripts de las vistas: await appConfirm(mensaje) -> true/false, y en un manejador de envío
// appConfirm.submit(event, mensaje) detiene el envío y lo repite si se acepta (devuelve true si lo detuvo).
(() => {
    'use strict';

    // ---- Diálogo de confirmación (reemplaza window.confirm) ------------------------------------------------
    let confirmDialog = null;
    const buildConfirmDialog = () => {
        const dialog = document.createElement('dialog');
        dialog.className = 'modal app-confirm';
        dialog.setAttribute('aria-labelledby', 'app-confirm-title');
        dialog.setAttribute('aria-describedby', 'app-confirm-message');
        const wrapper = document.createElement('div');
        wrapper.className = 'modal-dialog modal-dialog-centered';
        const form = document.createElement('form');
        form.method = 'dialog';
        form.className = 'modal-content';
        const body = document.createElement('div');
        body.className = 'modal-body';
        const title = Object.assign(document.createElement('h2'), { id: 'app-confirm-title', className: 'h5 mb-2', textContent: 'Confirmar' });
        const message = Object.assign(document.createElement('p'), { id: 'app-confirm-message', className: 'mb-0 app-confirm-message' });
        body.append(title, message);
        const footer = document.createElement('div');
        footer.className = 'modal-footer';
        const cancel = Object.assign(document.createElement('button'), { type: 'submit', value: 'cancel', className: 'btn btn-outline-secondary', textContent: 'Cancelar' });
        const accept = Object.assign(document.createElement('button'), { type: 'submit', value: 'ok', className: 'btn btn-primary' });
        footer.append(cancel, accept);
        form.append(body, footer);
        wrapper.append(form);
        dialog.append(wrapper);
        document.body.append(dialog);
        return { dialog, message, cancel, accept };
    };
    const appConfirm = (text, { accept = 'Aceptar', danger = false } = {}) => new Promise((resolve) => {
        confirmDialog ??= buildConfirmDialog();
        const { dialog, message, cancel, accept: acceptButton } = confirmDialog;
        message.textContent = text;
        acceptButton.textContent = accept;
        acceptButton.className = `btn ${danger ? 'btn-danger' : 'btn-primary'}`;
        dialog.returnValue = '';
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'ok'), { once: true });
        dialog.showModal();
        // La opción segura recibe el foco: Enter por reflejo no ejecuta la acción.
        cancel.focus();
    });
    appConfirm.submit = (event, text, options) => {
        const form = event.target;
        if (form.dataset.confirmed === '1') {
            return false;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter?.form === form ? event.submitter : null;
        appConfirm(text, options).then((ok) => {
            if (!ok) {
                return;
            }
            // requestSubmit despacha «submit» de forma síncrona: la marca vale solo para ese envío.
            form.dataset.confirmed = '1';
            submitter ? form.requestSubmit(submitter) : form.requestSubmit();
            delete form.dataset.confirmed;
        });
        return true;
    };
    window.appConfirm = appConfirm;

    // ---- Formularios ---------------------------------------------------------------------------------------
    // Fase de captura: la confirmación ocurre antes que los manejadores propios del formulario.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        const message = event.submitter?.dataset.confirm ?? form.dataset?.confirm;
        if (form instanceof HTMLFormElement && message) {
            appConfirm.submit(event, message);
        }
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return;
        }
        const busy = event.submitter?.matches('[data-loading-label]') ? event.submitter : form.querySelector('[type=submit][data-loading-label]');
        // Tras el envío: desactivarlos antes impediría que el botón pulsado viaje con el formulario.
        setTimeout(() => {
            if (busy && !busy.disabled) {
                busy.disabled = true;
                const spinner = document.createElement('span');
                spinner.className = 'spinner-border spinner-border-sm me-1';
                spinner.setAttribute('aria-hidden', 'true');
                busy.replaceChildren(spinner, busy.dataset.loadingLabel);
            }
            if (form.hasAttribute('data-disable-on-submit')) {
                form.querySelectorAll('[type=submit]').forEach((button) => { button.disabled = true; });
            }
        });
    });

    document.addEventListener('change', (event) => {
        const field = event.target instanceof Element ? event.target.closest('[data-autosubmit]') : null;
        field?.form?.requestSubmit();
    });

    // ---- Colapsables y pestañas ----------------------------------------------------------------------------
    const collapseTarget = (button) => document.getElementById(button.getAttribute('aria-controls'));
    document.querySelectorAll('[data-collapse]').forEach((button) => {
        button.setAttribute('aria-expanded', String(collapseTarget(button)?.classList.contains('show') ?? false));
    });

    const showTab = (tab) => {
        const list = tab.closest('[role=tablist]');
        list.querySelectorAll('[data-tab]').forEach((other) => {
            const selected = other === tab;
            other.classList.toggle('active', selected);
            other.setAttribute('aria-selected', String(selected));
            other.tabIndex = selected ? 0 : -1;
            document.getElementById(other.getAttribute('aria-controls'))?.classList.toggle('active', selected);
            document.getElementById(other.getAttribute('aria-controls'))?.classList.toggle('show', selected);
        });
        tab.dispatchEvent(new CustomEvent('tab:shown', { bubbles: true }));
    };
    document.querySelectorAll('[role=tablist]').forEach((list) => {
        const tabs = [...list.querySelectorAll('[data-tab]')];
        const active = tabs.find((tab) => tab.classList.contains('active')) ?? tabs[0];
        tabs.forEach((tab) => {
            tab.setAttribute('aria-selected', String(tab === active));
            tab.tabIndex = tab === active ? 0 : -1;
        });
        // Flechas, Inicio y Fin mueven entre pestañas (patrón de pestañas de WAI-ARIA).
        list.addEventListener('keydown', (event) => {
            const index = tabs.indexOf(document.activeElement);
            const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }[event.key];
            if (index < 0 || next === undefined) {
                return;
            }
            event.preventDefault();
            const tab = tabs[(next + tabs.length) % tabs.length];
            tab.focus();
            showTab(tab);
        });
    });

    // ---- Menú lateral (reemplaza AdminLTE) -----------------------------------------------------------------
    const desktop = window.matchMedia('(min-width: 992px)');
    let overlay = null;
    const sidebarOpen = () => (desktop.matches ? !document.body.classList.contains('sidebar-collapse') : document.body.classList.contains('sidebar-open'));
    const syncSidebar = () => document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => button.setAttribute('aria-expanded', String(sidebarOpen())));
    const closeMobileSidebar = () => {
        document.body.classList.remove('sidebar-open');
        syncSidebar();
    };
    if (document.querySelector('[data-sidebar-toggle]')) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        overlay.addEventListener('click', closeMobileSidebar);
        document.querySelector('.app-wrapper')?.append(overlay);
        desktop.addEventListener('change', closeMobileSidebar);
        syncSidebar();
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
            closeMobileSidebar();
        }
    });
    // Sin transiciones durante la carga (AdminLTE las activa con esta clase).
    requestAnimationFrame(() => document.body.classList.add('app-loaded'));

    // ---- Clics ---------------------------------------------------------------------------------------------
    const invokersSupported = 'commandForElement' in HTMLButtonElement.prototype;
    // Dónde empezó la pulsación: arrastrar desde el contenido del modal hasta el fondo no debe cerrarlo.
    let pressedOn = null;
    document.addEventListener('pointerdown', (event) => { pressedOn = event.target; }, true);
    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        const target = event.target;
        if (target.closest('[data-print]')) {
            window.print();
            return;
        }
        if (target.closest('a[data-back]') && history.length > 1) {
            event.preventDefault();
            history.back();
            return;
        }
        const collapse = target.closest('[data-collapse]');
        if (collapse) {
            const shown = collapseTarget(collapse)?.classList.toggle('show') ?? false;
            collapse.setAttribute('aria-expanded', String(shown));
            return;
        }
        const tab = target.closest('[data-tab]');
        if (tab) {
            showTab(tab);
            return;
        }
        if (target.closest('[data-sidebar-toggle]')) {
            document.body.classList.toggle(desktop.matches ? 'sidebar-collapse' : 'sidebar-open');
            syncSidebar();
            return;
        }
        // Un modal (.modal) ocupa toda la ventana: el clic fuera de su contenido cae en el propio <dialog>.
        if (target instanceof HTMLDialogElement && target.classList.contains('modal') && target.open && pressedOn === target) {
            target.close();
            return;
        }
        // Elegir una opción de un menú desplegable lo cierra.
        const item = target.closest('[popover] .dropdown-item');
        if (item) {
            item.closest('[popover]').hidePopover();
        }
        // Comandos de invocación (command / commandfor) en navegadores que aún no los soportan.
        const invoker = invokersSupported ? null : target.closest('button[commandfor]');
        if (invoker) {
            const controlled = document.getElementById(invoker.getAttribute('commandfor'));
            const command = invoker.getAttribute('command');
            if (command === 'show-modal' && !controlled?.open) controlled?.showModal();
            if (command === 'close' || command === 'request-close') controlled?.close(invoker.value);
        }
    });

    // ---- Menús desplegables (popover) ----------------------------------------------------------------------
    // Con anchor positioning, el CSS los ubica junto a su botón; si el navegador no lo soporta, se ubican aquí.
    if (!CSS.supports('position-area: block-end')) {
        document.addEventListener('toggle', (event) => {
            const menu = event.target;
            if (!(menu instanceof HTMLElement) || !menu.matches('[popover].dropdown-menu') || event.newState !== 'open') {
                return;
            }
            const trigger = document.querySelector(`[popovertarget="${menu.id}"]`);
            if (!trigger) {
                return;
            }
            const box = trigger.getBoundingClientRect();
            const width = menu.offsetWidth;
            const left = menu.classList.contains('dropdown-menu-end') ? box.right - width : box.left;
            menu.style.left = `${Math.max(8, Math.min(left, window.innerWidth - width - 8))}px`;
            menu.style.top = `${box.bottom + 4}px`;
        }, true);
    }
})();
