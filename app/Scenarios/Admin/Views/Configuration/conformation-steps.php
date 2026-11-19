<?php

declare(strict_types=1);

/**
 * Guía de tres pasos compartida por Caracterización, Conformación y Asignación de inmuebles.
 *
 * Espera: $escape, $basePath, $conformation (CompositionService::progress()) y $currentStep (1, 2 o 3).
 */
$stepCharacterized = $conformation['housing_type'] !== '';
$stepStructureDone = $conformation['completed'] && $conformation['missing_coefficients'] === 0;
$stepAssignmentsDone = $conformation['unit_count'] > 0 && $conformation['assigned_units'] === $conformation['unit_count'];
$conformationSteps = [
    1 => [
        'label' => 'Caracterización',
        'href' => '/configuracion/caracterizacion',
        'state' => $stepCharacterized ? 'done' : 'todo',
        'detail' => $stepCharacterized
            ? ['casa' => 'Casas', 'torres_apartamento' => 'Torres de apartamentos', 'mixto' => 'Mixto'][$conformation['housing_type']] ?? ''
            : 'Elige el tipo de vivienda',
    ],
    2 => [
        'label' => 'Conformación',
        'href' => '/configuracion/conformacion',
        'state' => !$stepCharacterized ? 'locked' : ($stepStructureDone ? 'done' : ($conformation['unit_count'] > 0 ? 'partial' : 'todo')),
        'detail' => $conformation['unit_count'] === 0
            ? 'Define cuántos inmuebles hay'
            : ($conformation['missing_coefficients'] > 0
                ? $conformation['missing_coefficients'] . ' de ' . $conformation['unit_count'] . ' sin coeficiente'
                : $conformation['unit_count'] . ' inmuebles · ' . rtrim(rtrim($conformation['coefficient_total'], '0'), '.') . ' %'),
    ],
    3 => [
        'label' => 'Asignación',
        'href' => '/inmuebles/asignaciones',
        'state' => !$conformation['completed'] ? 'locked' : ($stepAssignmentsDone ? 'done' : ($conformation['assigned_units'] > 0 ? 'partial' : 'todo')),
        'detail' => $conformation['completed']
            ? $conformation['assigned_units'] . ' de ' . $conformation['unit_count'] . ' con usuario'
            : 'Disponible al guardar la estructura',
    ],
];
$stepVisuals = [
    'done' => ['bi-check-lg', 'text-bg-success'],
    'partial' => ['bi-hourglass-split', 'text-bg-warning'],
    'todo' => [null, 'text-bg-primary'],
    'locked' => ['bi-lock', 'text-bg-secondary'],
];
?>
<nav class="card border-0 shadow-sm mb-4" aria-label="Pasos de la conformación">
    <ol class="list-unstyled row g-0 mb-0">
        <?php foreach ($conformationSteps as $number => $step): ?>
            <?php
            [$stepIcon, $stepBadge] = $stepVisuals[$step['state']];
            $isCurrent = $number === $currentStep;
            $isLink = !$isCurrent && $step['state'] !== 'locked';
            ?>
            <li class="col-md-4<?= $number > 1 ? ' border-top border-top-md-0 border-start-md' : '' ?>">
                <<?= $isLink ? 'a href="' . $escape($basePath . $step['href']) . '"' : 'div' ?>
                    class="d-flex align-items-center gap-3 p-3 h-100 text-decoration-none <?= $isCurrent ? 'bg-primary-subtle' : 'text-body' ?> <?= $step['state'] === 'locked' ? 'opacity-50' : '' ?>"
                    <?= $isCurrent ? 'aria-current="step"' : '' ?>>
                    <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0 <?= $stepBadge ?>" style="width: 2.25rem; height: 2.25rem" aria-hidden="true">
                        <?php if ($stepIcon !== null): ?><i class="bi <?= $stepIcon ?>"></i><?php else: ?><?= $number ?><?php endif; ?>
                    </span>
                    <span style="min-width: 0">
                        <span class="d-block fw-semibold"><?= $number ?>. <?= $escape($step['label']) ?></span>
                        <span class="d-block small text-secondary text-truncate"><?= $escape($step['detail']) ?></span>
                        <span class="visually-hidden">
                            <?= ['done' => 'Completo', 'partial' => 'En progreso', 'todo' => 'Pendiente', 'locked' => 'Bloqueado hasta completar el paso anterior'][$step['state']] ?>
                        </span>
                    </span>
                </<?= $isLink ? 'a' : 'div' ?>>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
<style>
@media (min-width: 768px) {
    .border-top-md-0 { border-top: 0 !important; }
    .border-start-md { border-left: var(--bs-border-width) solid var(--bs-border-color) !important; }
}
</style>
