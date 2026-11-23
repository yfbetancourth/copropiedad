/*
 * DataGrid: tabla de datos para listas largas del panel.
 * Paginación, filas por página, columnas visibles, orden por columna, selección de varias filas
 * con acciones masivas y exportación CSV.
 *
 * Trabaja sobre las filas que ya están en el DOM y nunca las destruye: pueden contener campos
 * editables cuyo valor se envía después en un formulario. Las filas fuera de la página actual
 * solo se ocultan.
 *
 * Marcado esperado en el <thead>: cada <th> con data-column="clave" (y data-label si el texto no
 * sirve como nombre). data-sortable activa el orden; data-hideable="false" impide ocultarla;
 * data-export="false" la excluye del CSV.
 */
(() => {
    'use strict';

    const collator = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });
    const numberFormat = new Intl.NumberFormat('es-CO');
    const storage = {
        read(key) {
            try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch { return null; }
        },
        write(key, value) {
            try { window.localStorage.setItem(key, JSON.stringify(value)); } catch { /* navegador sin almacenamiento */ }
        },
    };
    const el = (tag, props = {}, children = []) => {
        const node = Object.assign(document.createElement(tag), props);
        node.append(...children);
        return node;
    };
    const icon = (name) => {
        const node = el('i', { className: `bi ${name}` });
        node.setAttribute('aria-hidden', 'true');
        return node;
    };
    let gridCounter = 0;

    class DataGrid {
        /**
         * @param {HTMLTableElement} table
         * @param {object} options
         *   storageKey   recuerda filas por página y columnas ocultas en este navegador
         *   filter(row)  true si la fila coincide con los filtros de la página
         *   sortValue(row, key)  valor para ordenar (por defecto, el campo o el texto de la celda)
         *   selectable   agrega la columna de selección
         *   bulkActions  [{ label, icon, className, run(rows) }] para las filas seleccionadas
         *   toolbar, bulkBar, pager  contenedores donde se dibujan los controles
         *   exportName   nombre base del archivo CSV (sin exportación si se omite)
         *   onChange()   se llama tras cada actualización
         */
        constructor(table, options = {}) {
            this.table = table;
            this.body = table.tBodies[0];
            this.options = {
                pageSizes: [10, 25, 50, 100, 0],
                pageSize: 25,
                rowSelector: 'tr',
                filter: () => true,
                sortValue: null,
                selectable: false,
                bulkActions: [],
                exportName: null,
                onChange: () => {},
                ...options,
            };
            this.id = `data-grid-${++gridCounter}`;
            table.dataset.gridId = this.id;
            this.storageKey = this.options.storageKey ? `datagrid:${this.options.storageKey}` : null;
            const saved = (this.storageKey && storage.read(this.storageKey)) || {};
            this.pageSize = this.options.pageSizes.includes(saved.pageSize) ? saved.pageSize : this.options.pageSize;
            this.page = 1;
            this.sort = null;
            this.nextOrder = 0;
            this.selection = new Set();
            this.matched = [];
            this.style = document.head.appendChild(el('style'));
            this.headerRow = table.tHead.rows[0];

            if (this.options.selectable) {
                this.selectAll = el('input', { type: 'checkbox', className: 'form-check-input' });
                this.selectAll.setAttribute('aria-label', 'Seleccionar las filas de esta página');
                const th = el('th', { scope: 'col', style: 'width: 2.25rem' }, [this.selectAll]);
                th.dataset.export = 'false';
                th.dataset.hideable = 'false';
                th.dataset.gridSelect = '1';
                this.headerRow.prepend(th);
                this.selectAll.addEventListener('change', () => {
                    this.pageRows().forEach((row) => this.setSelected(row, this.selectAll.checked));
                    this.update();
                });
                this.body.addEventListener('change', (event) => {
                    const box = event.target.closest('[data-grid-select]');
                    if (!box) return;
                    this.setSelected(box.closest('tr'), box.checked);
                    this.update();
                });
            }

            this.columns = [...this.headerRow.cells].map((cell) => ({
                cell,
                key: cell.dataset.column || '',
                label: cell.dataset.label || cell.textContent.trim(),
                hideable: Boolean(cell.dataset.column) && cell.dataset.hideable !== 'false',
                exportable: Boolean(cell.dataset.column) && cell.dataset.export !== 'false',
            }));
            const hideableKeys = this.columns.filter((c) => c.hideable).map((c) => c.key);
            this.hidden = new Set((Array.isArray(saved.hidden) ? saved.hidden : []).filter((key) => hideableKeys.includes(key)));

            this.columns.filter((column) => column.cell.dataset.sortable !== undefined).forEach((column) => {
                const button = el('button', { type: 'button', className: 'btn btn-link p-0 text-reset text-decoration-none fw-semibold text-nowrap' });
                button.append(...column.cell.childNodes, ' ', icon('bi-arrow-down-up small text-secondary'));
                column.cell.append(button);
                column.sortButton = button;
                button.addEventListener('click', () => this.toggleSort(column.key));
            });

            this.buildToolbar();
            this.buildBulkBar();
            this.buildPager();
            this.applyColumnVisibility();
            this.refresh();
        }

        /** Filas de datos en el orden en que se muestran. */
        rows() {
            return [...this.body.rows].filter((row) => row.matches(this.options.rowSelector));
        }

        /** Filas en su orden original (el de la carga y el de creación), sin importar el orden visual. */
        rowsInOrder() {
            this.prepareRows();
            return this.rows().sort((a, b) => Number(a.dataset.gridOrder) - Number(b.dataset.gridOrder));
        }

        /** Filas que coinciden con el filtro, en todas las páginas y en el orden visual. */
        matching() {
            return this.rows().filter((row) => this.options.filter(row));
        }

        /** Filas seleccionadas que siguen en la tabla, en orden original. */
        selected() {
            return this.rowsInOrder().filter((row) => this.selection.has(row));
        }

        pageRows() {
            if (this.pageSize === 0) return this.matched;
            const start = (this.page - 1) * this.pageSize;
            return this.matched.slice(start, start + this.pageSize);
        }

        prepareRows() {
            this.rows().forEach((row) => {
                if (row.dataset.gridOrder === undefined) row.dataset.gridOrder = String(this.nextOrder++);
                if (this.options.selectable && !row.querySelector('[data-grid-select]')) {
                    const box = el('input', { type: 'checkbox', className: 'form-check-input' });
                    box.dataset.gridSelect = '1';
                    box.setAttribute('aria-label', 'Seleccionar fila');
                    row.prepend(el('td', {}, [box]));
                }
            });
        }

        setSelected(row, selected) {
            if (selected) this.selection.add(row); else this.selection.delete(row);
            const box = row.querySelector('[data-grid-select]');
            if (box) box.checked = selected;
        }

        clearSelection() {
            [...this.selection].forEach((row) => this.setSelected(row, false));
            this.update();
        }

        /** Vuelve a aplicar filtro y paginación. resetPage=true regresa a la primera página. */
        refresh(resetPage = false) {
            this.prepareRows();
            [...this.selection].forEach((row) => { if (!row.isConnected) this.selection.delete(row); });
            this.matched = this.matching();
            if (resetPage) this.page = 1;
            this.page = Math.min(Math.max(1, this.page), this.pageCount());
            const visible = new Set(this.pageRows());
            this.rows().forEach((row) => { row.hidden = !visible.has(row); });
            this.update();
        }

        /** Lleva a la página donde está la fila (si coincide con el filtro). */
        showRow(row) {
            this.refresh();
            const index = this.matched.indexOf(row);
            if (index >= 0 && this.pageSize !== 0) {
                this.page = Math.floor(index / this.pageSize) + 1;
                this.refresh();
            }
            row.scrollIntoView({ block: 'center' });
        }

        pageCount() {
            return this.pageSize === 0 ? 1 : Math.max(1, Math.ceil(this.matched.length / this.pageSize));
        }

        goTo(page) {
            this.page = page;
            this.refresh();
            this.table.closest('.table-responsive')?.scrollTo({ top: 0 });
        }

        save() {
            if (this.storageKey) storage.write(this.storageKey, { pageSize: this.pageSize, hidden: [...this.hidden] });
        }

        cellValue(row, key) {
            const index = this.columns.findIndex((column) => column.key === key);
            const cell = index >= 0 ? row.cells[index] : null;
            if (!cell) return '';
            const input = cell.querySelector('input:not([data-grid-select]), select, textarea');
            return (input ? input.value : cell.textContent).trim().replace(/\s+/g, ' ');
        }

        toggleSort(key) {
            if (this.sort?.key !== key) this.sort = { key, dir: 1 };
            else if (this.sort.dir === 1) this.sort = { key, dir: -1 };
            else this.sort = null;

            const value = (row) => {
                const custom = this.options.sortValue ? this.options.sortValue(row, key) : undefined;
                return String(custom ?? this.cellValue(row, key));
            };
            const rows = this.rowsInOrder();
            if (this.sort) {
                const { dir } = this.sort;
                const values = new Map(rows.map((row) => [row, value(row)]));
                rows.sort((a, b) => {
                    const va = values.get(a);
                    const vb = values.get(b);
                    if (va === '' || vb === '') return va === vb ? 0 : va === '' ? 1 : -1; // vacíos al final
                    return collator.compare(va, vb) * dir;
                });
            }
            this.body.append(...rows);
            this.columns.forEach((column) => {
                if (!column.sortButton) return;
                const active = this.sort?.key === column.key;
                column.cell.setAttribute('aria-sort', active ? (this.sort.dir === 1 ? 'ascending' : 'descending') : 'none');
                column.sortButton.lastChild.className = `bi small ${active ? (this.sort.dir === 1 ? 'bi-sort-down-alt' : 'bi-sort-up') : 'bi-arrow-down-up text-secondary'}`;
            });
            this.refresh(true);
        }

        applyColumnVisibility() {
            const selectors = this.columns
                .map((column, index) => (this.hidden.has(column.key) ? `[data-grid-id="${this.id}"] tr > :nth-child(${index + 1})` : null))
                .filter(Boolean);
            this.style.textContent = selectors.length ? `${selectors.join(',\n')} { display: none; }` : '';
            this.columnChecks?.forEach((check) => { check.checked = !this.hidden.has(check.value); });
        }

        buildToolbar() {
            const toolbar = this.options.toolbar;
            if (!toolbar) return;
            const hideable = this.columns.filter((column) => column.hideable);
            if (hideable.length > 0) {
                const menu = el('div', { className: 'dropdown-menu dropdown-menu-end p-2', style: 'min-width: 14rem' });
                this.columnChecks = hideable.map((column) => {
                    const check = el('input', { type: 'checkbox', className: 'form-check-input', value: column.key, id: `${this.id}-col-${column.key}` });
                    check.addEventListener('change', () => {
                        if (check.checked) this.hidden.delete(column.key); else this.hidden.add(column.key);
                        this.applyColumnVisibility();
                        this.save();
                    });
                    menu.append(el('div', { className: 'form-check' }, [check, el('label', { className: 'form-check-label', htmlFor: check.id, textContent: column.label })]));
                    return check;
                });
                const showAll = el('button', { type: 'button', className: 'btn btn-link btn-sm p-0 mt-1', textContent: 'Mostrar todas' });
                showAll.addEventListener('click', () => {
                    this.hidden.clear();
                    this.applyColumnVisibility();
                    this.save();
                });
                menu.append(el('hr', { className: 'dropdown-divider' }), showAll);
                const button = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary dropdown-toggle' }, [icon('bi-layout-three-columns me-1'), 'Columnas']);
                button.dataset.bsToggle = 'dropdown';
                button.dataset.bsAutoClose = 'outside';
                button.setAttribute('aria-expanded', 'false');
                toolbar.append(el('div', { className: 'dropdown' }, [button, menu]));
            }

            if (this.options.exportName) {
                const item = (label, rows) => {
                    const button = el('button', { type: 'button', className: 'dropdown-item' }, [label]);
                    button.addEventListener('click', () => this.exportCsv(rows()));
                    return el('li', {}, [button]);
                };
                this.exportFiltered = el('span');
                const items = [item(el('span', {}, ['Filas filtradas (', this.exportFiltered, ')']), () => this.matching())];
                if (this.options.selectable) {
                    this.exportSelected = el('span');
                    const selectedItem = item(el('span', {}, ['Filas seleccionadas (', this.exportSelected, ')']), () => this.selected());
                    this.exportSelectedButton = selectedItem.firstChild;
                    items.push(selectedItem);
                }
                const button = el('button', { type: 'button', className: 'btn btn-sm btn-outline-secondary dropdown-toggle' }, [icon('bi-download me-1'), 'Exportar']);
                button.dataset.bsToggle = 'dropdown';
                button.setAttribute('aria-expanded', 'false');
                toolbar.append(el('div', { className: 'dropdown' }, [button, el('ul', { className: 'dropdown-menu dropdown-menu-end' }, items)]));
            }
        }

        buildBulkBar() {
            const bar = this.options.bulkBar;
            if (!bar || !this.options.selectable) return;
            this.bulkCount = el('strong');
            this.selectMatching = el('button', { type: 'button', className: 'btn btn-link btn-sm p-0 align-baseline' });
            this.selectMatching.addEventListener('click', () => {
                this.matched.forEach((row) => this.setSelected(row, true));
                this.update();
            });
            const clear = el('button', { type: 'button', className: 'btn btn-link btn-sm p-0 align-baseline', textContent: 'Quitar selección' });
            clear.addEventListener('click', () => this.clearSelection());
            const actions = this.options.bulkActions.map((action) => {
                const button = el('button', { type: 'button', className: `btn btn-sm ${action.className || 'btn-outline-primary'}` });
                if (action.icon) button.append(icon(`${action.icon} me-1`));
                button.append(action.label);
                button.addEventListener('click', () => {
                    const rows = this.selected();
                    if (rows.length > 0) action.run(rows, this);
                });
                return button;
            });
            bar.replaceChildren(
                el('div', { className: 'd-flex flex-wrap align-items-center gap-2' }, [
                    el('span', { className: 'small' }, [this.bulkCount, ' seleccionado(s)']),
                    this.selectMatching,
                    clear,
                    el('div', { className: 'd-flex flex-wrap gap-2 ms-auto' }, actions),
                ]),
            );
            bar.setAttribute('aria-live', 'polite');
        }

        buildPager() {
            const pager = this.options.pager;
            if (!pager) return;
            const sizeSelect = el('select', { className: 'form-select form-select-sm w-auto', id: `${this.id}-size` },
                this.options.pageSizes.map((size) => el('option', { value: String(size), textContent: size === 0 ? 'Todas' : String(size), selected: size === this.pageSize })));
            sizeSelect.addEventListener('change', () => {
                this.pageSize = Number(sizeSelect.value);
                this.save();
                this.refresh(true);
            });
            this.info = el('span', { className: 'small text-secondary' });
            this.info.setAttribute('aria-live', 'polite');
            this.pagination = el('ul', { className: 'pagination pagination-sm mb-0 flex-wrap' });
            const nav = el('nav', { className: 'ms-auto' }, [this.pagination]);
            nav.setAttribute('aria-label', 'Paginación');
            pager.replaceChildren(el('div', { className: 'd-flex flex-wrap align-items-center gap-2' }, [
                el('label', { className: 'small text-secondary d-flex align-items-center gap-1', htmlFor: sizeSelect.id }, ['Mostrar', sizeSelect, 'por página']),
                this.info,
                nav,
            ]));
        }

        renderPagination() {
            if (!this.pagination) return;
            const pages = this.pageCount();
            const current = this.page;
            const item = (label, page, { disabled = false, active = false, aria = '' } = {}) => {
                const button = el('button', { type: 'button', className: 'page-link', disabled }, [label]);
                if (aria) button.setAttribute('aria-label', aria);
                if (active) button.setAttribute('aria-current', 'page');
                if (!disabled && !active) button.addEventListener('click', () => this.goTo(page));
                return el('li', { className: `page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}` }, [button]);
            };
            const items = [
                item(icon('bi-chevron-double-left'), 1, { disabled: current === 1, aria: 'Primera página' }),
                item(icon('bi-chevron-left'), current - 1, { disabled: current === 1, aria: 'Página anterior' }),
            ];
            const numbers = new Set([1, pages, current - 1, current, current + 1].filter((n) => n >= 1 && n <= pages));
            let previous = 0;
            [...numbers].sort((a, b) => a - b).forEach((n) => {
                if (n - previous > 1) items.push(item('…', 0, { disabled: true }));
                items.push(item(String(n), n, { active: n === current, aria: `Página ${n}` }));
                previous = n;
            });
            items.push(
                item(icon('bi-chevron-right'), current + 1, { disabled: current === pages, aria: 'Página siguiente' }),
                item(icon('bi-chevron-double-right'), pages, { disabled: current === pages, aria: 'Última página' }),
            );
            this.pagination.replaceChildren(...items);
        }

        update() {
            const total = this.rows().length;
            const matched = this.matched.length;
            if (this.info) {
                const shown = this.pageRows().length;
                const start = shown === 0 ? 0 : this.pageSize === 0 ? 1 : (this.page - 1) * this.pageSize + 1;
                this.info.textContent = matched === 0
                    ? (total === 0 ? 'No hay filas.' : 'Ninguna fila coincide con el filtro.')
                    : `Mostrando ${numberFormat.format(start)}–${numberFormat.format(start + shown - 1)} de ${numberFormat.format(matched)}`
                        + (matched !== total ? ` (filtradas de ${numberFormat.format(total)})` : '');
            }
            this.renderPagination();

            if (this.options.selectable) {
                const onPage = this.pageRows();
                const selectedOnPage = onPage.filter((row) => this.selection.has(row)).length;
                this.selectAll.checked = onPage.length > 0 && selectedOnPage === onPage.length;
                this.selectAll.indeterminate = selectedOnPage > 0 && selectedOnPage < onPage.length;
                const count = this.selection.size;
                if (this.options.bulkBar) {
                    this.options.bulkBar.hidden = count === 0;
                    this.bulkCount.textContent = numberFormat.format(count);
                    const pending = this.matched.filter((row) => !this.selection.has(row)).length;
                    this.selectMatching.hidden = pending === 0 || count === 0;
                    this.selectMatching.textContent = `Seleccionar las ${numberFormat.format(this.matched.length)} filas filtradas`;
                }
                if (this.exportSelected) {
                    this.exportSelected.textContent = numberFormat.format(count);
                    this.exportSelectedButton.disabled = count === 0;
                }
            }
            if (this.exportFiltered) this.exportFiltered.textContent = numberFormat.format(matched);
            this.options.onChange(this);
        }

        exportCsv(rows) {
            const columns = this.columns.filter((column) => column.exportable && !this.hidden.has(column.key));
            // Evita que Excel interprete un valor como fórmula (inyección CSV).
            const cell = (value) => {
                const text = /^[=+\-@\t\r]/.test(value) ? `'${value}` : value;
                return `"${text.replace(/"/g, '""')}"`;
            };
            const lines = [columns.map((column) => cell(column.label)).join(';')];
            rows.forEach((row) => {
                lines.push(columns.map((column) => cell(this.cellValue(row, column.key))).join(';'));
            });
            const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
            const link = el('a', { href: URL.createObjectURL(blob), download: `${this.options.exportName}-${new Date().toISOString().slice(0, 10)}.csv` });
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        }
    }

    window.DataGrid = DataGrid;
})();
