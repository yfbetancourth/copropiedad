<?php

declare(strict_types=1);

/**
 * Avatar circular con la inicial del usuario y, si hay pendientes, un contador en la esquina.
 * Compartido por la cabecera y la barra lateral del panel administrativo, la consola y el portal.
 *
 * Espera:
 * - $escape        callable(string): string
 * - $avatarInitial string
 * - $avatarSize    string tamaño CSS (p. ej. '2.25rem')
 * - $avatarBadge   int    pendientes; 0 no dibuja el contador
 * - $avatarClass   string clases extra del círculo (opcional)
 */
$avatarBadge = max(0, (int) ($avatarBadge ?? 0));
?>
<span class="position-relative d-inline-flex flex-shrink-0" aria-hidden="true">
    <span class="rounded-circle bg-primary text-white fw-semibold d-inline-flex align-items-center justify-content-center <?= $escape($avatarClass ?? '') ?>"
          style="width: <?= $escape($avatarSize) ?>; height: <?= $escape($avatarSize) ?>"><?= $escape($avatarInitial) ?></span>
    <?php if ($avatarBadge > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger border border-2"
              style="border-color: var(--bs-body-bg) !important; font-size: .65rem"><?= min($avatarBadge, 99) ?><?= $avatarBadge > 99 ? '+' : '' ?></span>
    <?php endif; ?>
</span>
