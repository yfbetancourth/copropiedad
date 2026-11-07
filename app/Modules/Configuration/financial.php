<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.financiera', 2);
$lateFeeType = in_array($settings['late_fee_type'] ?? null, ['percentage', 'fixed'], true)
    ? $settings['late_fee_type']
    : 'percentage';

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Configuracion</h1>
        <p class="text-secondary mb-0">Financiera · parametros para la gestion de cartera; guardar no genera cobros automaticamente.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $escape($basePath) ?>/configuracion/financiera/guardar">
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h2 class="card-title">Administracion y descuento</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="administration_fee" class="form-label">Valor de la administracion (COP)</label>
                            <input id="administration_fee" name="administration_fee" type="number" class="form-control" min="0" max="9999999999999.99" step="0.01" required value="<?= $escape($settings['administration_fee'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="effective_date" class="form-label">Fecha desde que aplica</label>
                            <input id="effective_date" name="effective_date" type="date" class="form-control" required value="<?= $escape($settings['effective_date'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="early_payment_discount" class="form-label">Descuento por pronto pago (COP)</label>
                            <input id="early_payment_discount" name="early_payment_discount" type="number" class="form-control" min="0" max="9999999999999.99" step="0.01" required value="<?= $escape($settings['early_payment_discount'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h2 class="card-title">Mora y multas</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="late_fee_days" class="form-label">Dias despues del vencimiento para aplicar mora</label>
                            <input id="late_fee_days" name="late_fee_days" type="number" class="form-control" min="0" max="365" step="1" required value="<?= $escape($settings['late_fee_days'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="late_fee_type" class="form-label">Tipo de mora</label>
                            <select id="late_fee_type" name="late_fee_type" class="form-select" <?= $canWrite ? '' : 'disabled' ?>>
                                <option value="percentage" <?= $lateFeeType === 'percentage' ? 'selected' : '' ?>>Porcentaje (%)</option>
                                <option value="fixed" <?= $lateFeeType === 'fixed' ? 'selected' : '' ?>>Valor fijo (COP)</option>
                            </select>
                            <?php if (!$canWrite): ?>
                                <input type="hidden" name="late_fee_type" value="<?= $escape($lateFeeType) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="late_fee_value" class="form-label">Valor o porcentaje de mora</label>
                            <input id="late_fee_value" name="late_fee_value" type="number" class="form-control" min="0" max="<?= $lateFeeType === 'percentage' ? '100' : '9999999999999.99' ?>" step="0.01" required value="<?= $escape($settings['late_fee_value'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                            <small class="text-secondary">Si es porcentaje, se calcula sobre el valor de administracion.</small>
                        </div>
                        <div class="mb-3">
                            <label for="fine_amount" class="form-label">Valor base por multas (COP)</label>
                            <input id="fine_amount" name="fine_amount" type="number" class="form-control" min="0" max="9999999999999.99" step="0.01" required value="<?= $escape($settings['fine_amount'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($canWrite): ?>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Guardar parametros financieros</button>
                </div>
            <?php endif; ?>
        </div>
    </form>
</section>
