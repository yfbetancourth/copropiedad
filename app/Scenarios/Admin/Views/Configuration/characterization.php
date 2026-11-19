<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.caracterizacion', 2);
$housingType = in_array($settings['housing_type'] ?? null, ['casa', 'torres_apartamento', 'mixto'], true)
    ? $settings['housing_type']
    : '';
$towerNomenclature = in_array($settings['tower_nomenclature'] ?? null, ['numeric', 'alphabetic'], true)
    ? $settings['tower_nomenclature']
    : 'numeric';
$locked = $billingLocked && $hasComposition;
// Con una estructura ya creada, cambiar la caracterización la reinicia: solo el Superadmin puede hacerlo.
$superadminOnly = $hasComposition && !$locked && !\App\Core\Authorization::isSuperadmin($actor);
$editable = $canWrite && !$locked && !$superadminOnly;
$housingOptions = [
    'casa' => ['bi-house', 'Casas', 'La copropiedad está formada solo por casas.', 'Casa 1, Casa 2, Casa 3…'],
    'torres_apartamento' => ['bi-building', 'Torres de apartamentos', 'Uno o varios edificios con apartamentos por piso.', 'Torre 1 - Piso 3 - Apartamento 02'],
    'mixto' => ['bi-buildings', 'Mixto', 'Torres de apartamentos y además locales u oficinas.', 'Torre 1 - Piso 3 - Apartamento 02 · Inmueble comercial 1'],
];
$currentStep = 1;
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Caracterización</h1>
        <p class="text-secondary mb-0">Paso 1 de 3 · Define qué tipo de inmuebles tiene la copropiedad. Con esto se arma la estructura del paso 2.</p>
    </div>

    <?php require __DIR__ . '/conformation-steps.php'; ?>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($locked): ?>
        <div class="alert alert-info d-flex gap-2" role="status">
            <i class="bi bi-lock-fill mt-1" aria-hidden="true"></i>
            <div>
                <strong>La caracterización está bloqueada.</strong> Ya hay notas de cobro emitidas para los inmuebles actuales;
                cambiar el tipo de vivienda borraría la estructura y desconectaría la cartera.
                Para corregir nombres, coeficientes o agregar inmuebles, usa
                <a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/conformacion">Conformación de la copropiedad</a>.
            </div>
        </div>
    <?php elseif ($superadminOnly && $canWrite): ?>
        <div class="alert alert-info d-flex gap-2" role="status">
            <i class="bi bi-shield-lock-fill mt-1" aria-hidden="true"></i>
            <div>
                <strong>Solo el perfil Superadmin puede cambiar la caracterización.</strong> Ya existe una estructura de inmuebles y este cambio
                reiniciaría todo el proceso de conformación. Si necesitas cambiarla, solicítalo a un Superadmin.
                Para corregir nombres, coeficientes o agregar inmuebles, usa
                <a class="alert-link" href="<?= $escape($basePath) ?>/configuracion/conformacion">Conformación de la copropiedad</a>.
            </div>
        </div>
    <?php endif; ?>

    <form id="characterization-form" method="post" action="<?= $escape($basePath) ?>/configuracion/caracterizacion/guardar"
          data-has-composition="<?= $hasComposition ? 'true' : 'false' ?>"
          data-saved-type="<?= $escape($housingType) ?>" data-saved-nomenclature="<?= $escape($towerNomenclature) ?>">
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0">¿Qué tipo de vivienda tiene la copropiedad?</h2></div>
            <div class="card-body">
                <fieldset class="border-0 p-0 m-0" <?= $editable ? '' : 'disabled' ?>>
                    <legend class="visually-hidden">Tipo de vivienda</legend>
                    <div class="row g-3">
                        <?php foreach ($housingOptions as $value => [$icon, $label, $description, $example]): ?>
                            <div class="col-md-4">
                                <input class="btn-check" type="radio" name="housing_type" id="housing-<?= $escape($value) ?>" value="<?= $escape($value) ?>" <?= $housingType === $value ? 'checked' : '' ?> required>
                                <label class="btn btn-outline-primary w-100 h-100 text-start p-3 d-flex flex-column gap-2" for="housing-<?= $escape($value) ?>">
                                    <span class="d-flex align-items-center gap-2 fw-semibold"><i class="bi <?= $icon ?> fs-4" aria-hidden="true"></i><?= $escape($label) ?></span>
                                    <span class="small opacity-75"><?= $escape($description) ?></span>
                                    <span class="small mt-auto"><span class="opacity-75">Ejemplo:</span> <code class="text-reset"><?= $escape($example) ?></code></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>
        </div>

        <div id="tower-nomenclature-group" class="card border-0 shadow-sm mb-3" <?= in_array($housingType, ['torres_apartamento', 'mixto'], true) ? '' : 'hidden' ?>>
            <div class="card-header bg-transparent"><h2 class="card-title h6 mb-0">¿Cómo se identifican las torres?</h2></div>
            <div class="card-body">
                <fieldset class="border-0 p-0 m-0" <?= $editable ? '' : 'disabled' ?>>
                    <legend class="visually-hidden">Nomenclatura de las torres</legend>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <input class="btn-check" type="radio" name="tower_nomenclature" id="nomenclature-numeric" value="numeric" <?= $towerNomenclature === 'numeric' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="nomenclature-numeric">Con números · Torre 1, Torre 2…</label>
                        <input class="btn-check" type="radio" name="tower_nomenclature" id="nomenclature-alphabetic" value="alphabetic" <?= $towerNomenclature === 'alphabetic' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="nomenclature-alphabetic">Con letras · Torre A, Torre B…</label>
                    </div>
                </fieldset>
                <div class="bg-body-tertiary rounded p-3">
                    <div class="small text-secondary mb-1">Así se nombrarán los apartamentos:</div>
                    <code id="nomenclature-preview" class="fs-6"><?= $towerNomenclature === 'alphabetic' ? 'Torre A' : 'Torre 1' ?> - Piso 1 - Apartamento 01</code>
                    <div class="small text-secondary mt-1">El número del apartamento siempre tiene dos dígitos, de 01 a 99.</div>
                </div>
            </div>
        </div>

        <?php if ($hasComposition && $editable): ?>
            <div id="reset-impact" class="card border-danger mb-3" role="alert">
                <div class="card-body">
                    <h2 class="h6 text-danger"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Este cambio reinicia la estructura de inmuebles</h2>
                    <p class="mb-2">La estructura actual dejaría de corresponder al nuevo tipo de vivienda. Al guardar se eliminará:</p>
                    <ul class="mb-3">
                        <?php if ($impact['units'] > 0): ?><li><strong><?= (int) $impact['units'] ?></strong> inmuebles con sus nombres y coeficientes.</li><?php endif; ?>
                        <?php if ($impact['answers'] > 0): ?><li>Las cantidades registradas en el asistente.</li><?php endif; ?>
                        <?php if ($impact['assignments'] > 0): ?><li><strong><?= (int) $impact['assignments'] ?></strong> asignaciones de inmuebles a usuarios.</li><?php endif; ?>
                    </ul>
                    <label for="confirm-text" class="form-label">Para confirmar, escribe <strong>REINICIAR</strong></label>
                    <input id="confirm-text" name="confirm_text" class="form-control" style="max-width: 16rem" autocomplete="off" spellcheck="false">
                </div>
            </div>
        <?php endif; ?>

        <?php if ($editable): ?>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <button id="characterization-submit" class="btn btn-primary" type="submit">Guardar caracterización</button>
                <span id="characterization-status" class="small text-secondary" aria-live="polite"></span>
            </div>
        <?php elseif (!$canWrite): ?>
            <p class="text-secondary small mb-0">Tu rol solo puede consultar esta información.</p>
        <?php endif; ?>
    </form>
</section>
<?php if ($editable): ?>
<script>
(() => {
    const form = document.getElementById('characterization-form');
    const group = document.getElementById('tower-nomenclature-group');
    const preview = document.getElementById('nomenclature-preview');
    const impact = document.getElementById('reset-impact');
    const confirmInput = document.getElementById('confirm-text');
    const submit = document.getElementById('characterization-submit');
    const status = document.getElementById('characterization-status');
    const value = (name) => form.querySelector(`[name="${name}"]:checked`)?.value ?? '';

    const refresh = () => {
        const type = value('housing_type');
        const nomenclature = value('tower_nomenclature');
        const hasTowers = ['torres_apartamento', 'mixto'].includes(type);
        group.hidden = !hasTowers;
        preview.textContent = `${nomenclature === 'alphabetic' ? 'Torre A' : 'Torre 1'} - Piso 1 - Apartamento 01`;

        const changed = type !== form.dataset.savedType
            || (hasTowers && nomenclature !== form.dataset.savedNomenclature);
        const resets = changed && form.dataset.hasComposition === 'true';
        if (impact) {
            impact.hidden = !resets;
            confirmInput.required = resets;
        }
        const confirmed = !resets || confirmInput.value.trim().toUpperCase() === 'REINICIAR';
        submit.disabled = type === '' || !changed || !confirmed;
        submit.classList.toggle('btn-danger', resets);
        submit.classList.toggle('btn-primary', !resets);
        submit.textContent = resets ? 'Reiniciar y guardar' : 'Guardar caracterización';
        status.textContent = type === '' ? 'Elige un tipo de vivienda.'
            : !changed ? 'No hay cambios por guardar.'
            : !confirmed ? 'Escribe REINICIAR para habilitar el botón.' : '';
    };
    form.addEventListener('change', refresh);
    confirmInput?.addEventListener('input', refresh);
    refresh();
})();
</script>
<?php endif; ?>
