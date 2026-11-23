<?php

declare(strict_types=1);

/**
 * Dibuja en el navegador los QR de los elementos [data-qr="URL"] (SVG nítido para pantalla e impresión) y
 * activa los botones [data-qr-download="id del elemento"] (imagen GIF) y [data-copy="texto"].
 * Se incluye una sola vez por página, después de los elementos.
 */
?>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"
        integrity="sha384-8FWZA6BGMXhsfO+BLtrJK0We6gg5o1JyO8xQm6peWDEUs17ACA5ziE/NIAkl9z2k"
        crossorigin="anonymous"></script>
<script>
(() => {
    const build = (text) => { const qr = qrcode(0, 'M'); qr.addData(text); qr.make(); return qr; };
    document.querySelectorAll('[data-qr]').forEach((element) => {
        if (typeof qrcode !== 'function') {
            element.textContent = 'No se pudo cargar el generador de QR.';
            return;
        }
        const qr = build(element.dataset.qr);
        element.innerHTML = qr.createSvgTag({ cellSize: Number(element.dataset.qrCell || 5), margin: 2, scalable: true });
        const svg = element.querySelector('svg');
        if (svg) {
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', element.dataset.qrLabel || 'Código QR del enlace de pago');
            svg.style.width = '100%';
            svg.style.height = 'auto';
        }
    });
    document.querySelectorAll('[data-qr-download]').forEach((link) => {
        const source = document.getElementById(link.dataset.qrDownload);
        if (!source || typeof qrcode !== 'function') { link.hidden = true; return; }
        link.href = build(source.dataset.qr).createDataURL(10, 4);
    });
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const original = button.innerHTML;
            try {
                await navigator.clipboard.writeText(button.dataset.copy);
                button.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Copiado';
            } catch {
                window.prompt('Copia el enlace:', button.dataset.copy);
            }
            setTimeout(() => { button.innerHTML = original; }, 2000);
        });
    });
})();
</script>
