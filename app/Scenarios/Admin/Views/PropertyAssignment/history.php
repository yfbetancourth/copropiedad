<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$groupOf = static function (array $unit): string {
    $name = (string) ($unit['name'] ?? '');
    return preg_match('/^(Torre \S+)/u', $name, $match) === 1 ? $match[1] : (string) ($unit['category'] ?? 'Otros');
};
$selectedId = is_array($selectedUnit) ? (string) $selectedUnit['id'] : null;
$relationBadge = static fn (string $relation): string => match ($relation) {
    'propietario' => 'text-bg-primary',
    'inquilino' => 'text-bg-info',
    default => 'text-bg-secondary',
};
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Trazabilidad de inmuebles</h1>
        <p class="text-secondary mb-0">Quién ha estado vinculado a cada inmueble, como propietario, inquilino o residente, y durante qué fechas.</p>
        <a class="small" href="<?= $escape($basePath) ?>/inmuebles/asignaciones"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Asignación de inmuebles</a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($units === [] && $selectedUnit === null): ?>
        <div class="alert alert-info" role="status">
            Aún no hay estructura de inmuebles.
            <a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/conformacion">Ir a Conformación de la copropiedad</a>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent">
                        <h2 class="card-title h6 mb-2">Inmuebles</h2>
                        <input id="unit-filter" class="form-control form-control-sm" type="search" placeholder="Buscar inmueble o persona" aria-label="Buscar inmueble o persona">
                    </div>
                    <div class="list-group list-group-flush" id="unit-list" style="max-height: 65vh; overflow-y: auto">
                        <?php $currentGroup = null; ?>
                        <?php foreach ($units as $unit): ?>
                            <?php
                            $unitId = (string) ($unit['id'] ?? '');
                            $group = $groupOf($unit);
                            $owners = $unitOwners[$unitId] ?? [];
                            $isSelected = $unitId === $selectedId;
                            ?>
                            <?php if ($group !== $currentGroup): $currentGroup = $group; ?>
                                <div class="unit-group-header px-3 py-1 bg-body-tertiary small fw-semibold text-secondary border-bottom" data-group="<?= $escape($group) ?>"><?= $escape($group) ?></div>
                            <?php endif; ?>
                            <a class="list-group-item list-group-item-action<?= $isSelected ? ' active' : '' ?>" data-group="<?= $escape($group) ?>"
                               href="<?= $escape($basePath) ?>/inmuebles/historial?unidad=<?= $escape($unitId) ?>"
                               data-search="<?= $escape(mb_strtolower(($unit['name'] ?? '') . ' ' . ($unit['category'] ?? '') . ' ' . implode(' ', array_column($owners, 'username')), 'UTF-8')) ?>"
                               <?= $isSelected ? 'aria-current="true"' : '' ?>>
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="fw-semibold text-truncate"><?= $escape($unit['name'] ?? '') ?></span>
                                    <span class="badge rounded-pill <?= $isSelected ? 'text-bg-light' : 'text-bg-secondary' ?>" title="Registros en el historial"><?= (int) ($historyCounts[$unitId] ?? 0) ?></span>
                                </div>
                                <div class="small text-truncate <?= $isSelected ? '' : 'text-secondary' ?>">
                                    <?= $owners === []
                                        ? 'Sin personas vinculadas'
                                        : $escape(implode(', ', array_map(static fn (array $o): string => $o['username'] . ' (' . $o['relation'] . ')', $owners))) ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <p id="no-unit-results" class="text-secondary text-center small py-4 mb-0" hidden>Ningún inmueble coincide con la búsqueda.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php if (is_array($selectedUnit)): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-transparent">
                            <h2 class="h6 mb-0"><?= $escape($selectedUnit['name']) ?></h2>
                            <div class="small text-secondary">
                                <?= $escape($selectedUnit['category'] ?? '') ?>
                                <?php if (!empty($selectedUnit['removed'])): ?><span class="badge text-bg-warning">Ya no está en la estructura</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($history === []): ?>
                                <p class="text-secondary mb-0">Este inmueble aún no ha tenido personas vinculadas.</p>
                            <?php else: ?>
                                <ol class="list-unstyled mb-0">
                                    <?php foreach ($history as $entry): ?>
                                        <?php $open = $entry['ended_on'] === null; ?>
                                        <li class="d-flex gap-3 pb-3 mb-3 border-bottom">
                                            <span class="rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 <?= $open ? 'text-bg-success' : 'bg-body-secondary text-secondary' ?>"
                                                  style="width: 2rem; height: 2rem" aria-hidden="true">
                                                <i class="bi <?= $open ? 'bi-person-check' : 'bi-person-dash' ?>"></i>
                                            </span>
                                            <div class="flex-grow-1">
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <strong><?= $escape($entry['user_name']) ?></strong>
                                                    <span class="badge <?= $relationBadge((string) $entry['relation_type']) ?>"><?= $escape($entry['relation_label']) ?></span>
                                                    <?php if ($open): ?><span class="badge text-bg-success">Vigente</span><?php endif; ?>
                                                </div>
                                                <div class="small">
                                                    <?= $escape($entry['started_on']) ?> → <?= $open ? 'hoy' : $escape($entry['ended_on']) ?>
                                                    <?php if (!$open && $entry['end_reason_label'] !== ''): ?>
                                                        <span class="text-secondary">· <?= $escape($entry['end_reason_label']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-secondary">
                                                    Registró: <?= $escape($entry['started_by_name'] ?? 'sistema') ?>
                                                    <?php if (!$open): ?> · Cerró: <?= $escape($entry['ended_by_name'] ?? 'sistema') ?><?php endif; ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center text-secondary py-5">
                            <i class="bi bi-clock-history fs-1 d-block mb-2" aria-hidden="true"></i>
                            Elige un inmueble para ver quién ha estado vinculado a él.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<script>
(() => {
    const filter = document.getElementById('unit-filter');
    if (!filter) return;
    filter.addEventListener('input', () => {
        const query = filter.value.trim().toLocaleLowerCase();
        const visibleGroups = new Set();
        document.querySelectorAll('#unit-list a[data-search]').forEach((item) => {
            const show = query === '' || item.dataset.search.includes(query);
            item.classList.toggle('d-none', !show);
            if (show) visibleGroups.add(item.dataset.group);
        });
        document.querySelectorAll('#unit-list .unit-group-header').forEach((header) => {
            header.classList.toggle('d-none', !visibleGroups.has(header.dataset.group));
        });
        document.getElementById('no-unit-results').hidden = visibleGroups.size > 0;
    });
})();
</script>
