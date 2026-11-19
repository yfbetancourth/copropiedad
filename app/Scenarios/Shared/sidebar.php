<?php

declare(strict_types=1);

/**
 * Barra lateral compartida por el panel administrativo y el portal de residentes.
 *
 * Espera:
 * - $escape            callable(string): string
 * - $propertyName      string
 * - $sidebarBrandHref  string
 * - $sidebarUser       array{name: string, role: string, initial: string}
 * - $sidebarSections   list<array{title: ?string, items: list<array{href: string, icon: string, label: string, active?: bool, badge?: int, newTab?: bool}>}>
 *                      Las secciones vacías no se muestran y entre secciones se dibuja un separador.
 */
$sidebarSections = array_values(array_filter($sidebarSections, static fn (array $section): bool => $section['items'] !== []));
?>
<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
        <a class="brand-link w-100 px-3" style="min-width: 0" href="<?= $escape($sidebarBrandHref) ?>" title="<?= $escape($propertyName) ?>">
            <span class="brand-text fw-semibold text-truncate d-block"><?= $escape($propertyName) ?></span>
        </a>
    </div>
    <div class="sidebar-wrapper">
        <div class="d-flex align-items-center gap-2 px-3 py-3 border-bottom border-secondary-subtle">
            <span class="rounded-circle bg-primary text-white fw-semibold d-inline-flex align-items-center justify-content-center flex-shrink-0"
                  style="width: 2.25rem; height: 2.25rem" aria-hidden="true"><?= $escape($sidebarUser['initial']) ?></span>
            <div class="lh-sm" style="min-width: 0">
                <div class="fw-semibold text-truncate text-white"><?= $escape($sidebarUser['name']) ?></div>
                <?php if ($sidebarUser['role'] !== ''): ?>
                    <div class="small text-secondary text-truncate"><?= $escape($sidebarUser['role']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <nav class="mt-2" aria-label="Menú principal">
            <ul class="nav sidebar-menu flex-column" role="menu">
                <?php foreach ($sidebarSections as $sectionIndex => $section): ?>
                    <?php if ($sectionIndex > 0): ?>
                        <li class="mx-3 my-2 border-top border-secondary-subtle" role="separator" aria-hidden="true"></li>
                    <?php endif; ?>
                    <?php if (($section['title'] ?? null) !== null): ?>
                        <li class="nav-header text-uppercase small fw-semibold pt-1"><?= $escape($section['title']) ?></li>
                    <?php endif; ?>
                    <?php foreach ($section['items'] as $item): ?>
                        <?php $active = (bool) ($item['active'] ?? false); $newTab = (bool) ($item['newTab'] ?? false); ?>
                        <li class="nav-item">
                            <a href="<?= $escape($item['href']) ?>"
                               class="nav-link<?= $active ? ' active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?><?= $newTab ? ' target="_blank" rel="noopener"' : '' ?>>
                                <i class="nav-icon bi <?= $escape($item['icon']) ?>" aria-hidden="true"></i>
                                <p class="d-flex align-items-center w-100">
                                    <span class="flex-grow-1"><?= $escape($item['label']) ?></span>
                                    <?php if (($item['badge'] ?? 0) > 0): ?>
                                        <span class="badge rounded-pill text-bg-primary ms-2"><?= (int) $item['badge'] ?><span class="visually-hidden"> sin leer</span></span>
                                    <?php endif; ?>
                                    <?php if ($newTab): ?>
                                        <i class="bi bi-box-arrow-up-right small opacity-50 ms-2" aria-hidden="true"></i><span class="visually-hidden"> (se abre en una pestaña nueva)</span>
                                    <?php endif; ?>
                                </p>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</aside>
