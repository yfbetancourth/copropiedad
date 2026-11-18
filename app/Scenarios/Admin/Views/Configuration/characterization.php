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
$hasTowers = in_array($housingType, ['torres_apartamento', 'mixto'], true);

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Configuracion</h1>
        <p class="text-secondary mb-0">Caracterizacion · define el tipo de vivienda de la copropiedad.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Tipo de vivienda</h2></div>
                <form id="characterization-form" method="post" action="<?= $escape($basePath) ?>/configuracion/caracterizacion/guardar" data-has-composition="<?= $hasComposition ? 'true' : 'false' ?>" data-can-write="<?= $canWrite ? 'true' : 'false' ?>">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="confirm_housing_change" value="0">
                        <div class="mb-3">
                            <label for="housing_type" class="form-label">Selecciona el tipo de vivienda</label>
                            <select id="housing_type" name="housing_type" class="form-select" required <?= $canWrite ? '' : 'disabled' ?>>
                                <option value="">Selecciona una opcion</option>
                                <option value="casa" <?= $housingType === 'casa' ? 'selected' : '' ?>>Casa</option>
                                <option value="torres_apartamento" <?= $housingType === 'torres_apartamento' ? 'selected' : '' ?>>Torres de apartamentos</option>
                                <option value="mixto" <?= $housingType === 'mixto' ? 'selected' : '' ?>>Mixto</option>
                            </select>
                            <?php if ($housingType !== ''): ?>
                                <div class="form-text">Si cambias esta caracterizacion despues de registrar inmuebles, te pediremos confirmar antes de reiniciar esa estructura.</div>
                            <?php endif; ?>
                            <?php if (!$canWrite && $housingType !== ''): ?>
                                <input type="hidden" name="housing_type" value="<?= $escape($housingType) ?>">
                            <?php endif; ?>
                        </div>
                        <div id="tower-nomenclature-group" class="mb-3" <?= $hasTowers ? '' : 'hidden' ?>>
                            <label for="tower_nomenclature" class="form-label">Nomenclatura de las torres de apartamentos</label>
                            <select id="tower_nomenclature" name="tower_nomenclature" class="form-select" <?= $canWrite && $hasTowers ? 'required' : 'disabled' ?>>
                                <option value="numeric" <?= $towerNomenclature === 'numeric' ? 'selected' : '' ?>>Numérica (Torre 1, Torre 2…)</option>
                                <option value="alphabetic" <?= $towerNomenclature === 'alphabetic' ? 'selected' : '' ?>>Alfabética (Torre A, Torre B… Torre Z, Torre AA…)</option>
                            </select>
                            <div class="form-text">Los apartamentos usarán siempre números de dos dígitos, desde 01; 00 no es válido.</div>
                        </div>
                        <?php if ($canWrite): ?>
                            <button class="btn btn-primary" type="submit">Guardar caracterizacion</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?php if ($canWrite): ?>
<script>
document.getElementById('characterization-form')?.addEventListener('submit', function (event) {
    const form = event.currentTarget;
    const previous = <?= json_encode($housingType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const previousNomenclature = <?= json_encode($towerNomenclature, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const selected = form.querySelector('[name="housing_type"]').value;
    const newNomenclature = form.querySelector('[name="tower_nomenclature"]').value;
    const nomenclatureChanged = ['torres_apartamento', 'mixto'].includes(selected)
        && newNomenclature !== previousNomenclature;
    if (form.dataset.hasComposition === 'true' && (selected !== previous || nomenclatureChanged)) {
        if (!window.confirm('La estructura actual puede dejar de corresponder a la nueva caracterizacion. Si confirmas, se eliminaran las respuestas y los inmuebles registrados para empezar de nuevo. ¿Deseas continuar?')) {
            event.preventDefault();
            return;
        }
        form.querySelector('[name="confirm_housing_change"]').value = '1';
    }
});
document.getElementById('housing_type')?.addEventListener('change', function (event) {
    const form = document.getElementById('characterization-form');
    const group = document.getElementById('tower-nomenclature-group');
    const nomenclature = document.getElementById('tower_nomenclature');
    const applicable = ['torres_apartamento', 'mixto'].includes(event.currentTarget.value);
    group.hidden = !applicable;
    nomenclature.disabled = form.dataset.canWrite !== 'true' || !applicable;
    nomenclature.required = form.dataset.canWrite === 'true' && applicable;
});
</script>
<?php endif; ?>
