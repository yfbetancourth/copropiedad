<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.caracterizacion', 2);
$housingType = in_array($settings['housing_type'] ?? null, ['casa', 'torres_apartamento'], true)
    ? $settings['housing_type']
    : '';

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
                <form method="post" action="<?= $escape($basePath) ?>/configuracion/caracterizacion/guardar">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <div class="mb-3">
                            <label for="housing_type" class="form-label">Selecciona el tipo de vivienda</label>
                            <select id="housing_type" name="housing_type" class="form-select" required <?= $canWrite ? '' : 'disabled' ?>>
                                <option value="">Selecciona una opcion</option>
                                <option value="casa" <?= $housingType === 'casa' ? 'selected' : '' ?>>Casa</option>
                                <option value="torres_apartamento" <?= $housingType === 'torres_apartamento' ? 'selected' : '' ?>>Torres de apartamentos</option>
                                <option value="mixto" <?= $housingType === 'mixto' ? 'selected' : '' ?>>Mixto</option>
                            </select>
                            <?php if (!$canWrite && $housingType !== ''): ?>
                                <input type="hidden" name="housing_type" value="<?= $escape($housingType) ?>">
                            <?php endif; ?>
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
