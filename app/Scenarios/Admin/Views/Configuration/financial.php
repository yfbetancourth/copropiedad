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
$feeBasis = array_key_exists($settings['fee_basis'] ?? '', \App\Modules\Configuration\SettingsService::FEE_BASES) ? $settings['fee_basis'] : 'base';
// Rótulo y texto sugerido del valor según la opción elegida.
$feeInputLabels = ['base' => 'Base mensual por cada 100 % de coeficiente', 'budget' => 'Presupuesto mensual de administración'];
$feePlaceholders = ['base' => 'Ej. 1000000 → un inmueble de 1 % paga 10.000', 'budget' => 'Ej. 25000000 (presupuesto anual ÷ 12)'];
$unitCount = (int) ($unitCount ?? 0);
$coefficientSum = (float) ($coefficientSum ?? 0);

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
                        <fieldset class="border rounded-3 p-3 mb-3 bg-body-tertiary">
                            <legend class="float-none w-auto fs-6 fw-semibold px-2 mb-0">Cuota de administración</legend>
                            <p class="small text-secondary mb-2">Elige cómo se liquida y escribe el valor mensual.</p>
                            <?php foreach (\App\Modules\Configuration\SettingsService::FEE_BASES as $basisKey => $basisLabel): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="fee_basis" id="fee-basis-<?= $escape($basisKey) ?>" value="<?= $escape($basisKey) ?>"
                                           <?= $feeBasis === $basisKey ? 'checked' : '' ?> <?= $canWrite ? '' : 'disabled' ?>>
                                    <label class="form-check-label" for="fee-basis-<?= $escape($basisKey) ?>">
                                        <?= $escape($basisLabel) ?>
                                        <span class="d-block small text-secondary"><?= $basisKey === 'base'
                                            ? 'Cada inmueble paga este valor × su coeficiente.'
                                            : 'Total a recaudar: se reparte según el coeficiente de cada inmueble.' ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$canWrite): ?>
                                <input type="hidden" name="fee_basis" value="<?= $escape($feeBasis) ?>">
                            <?php endif; ?>
                            <label for="administration_fee" class="form-label small fw-semibold mt-2 mb-1" id="administration-fee-label"><?= $escape($feeInputLabels[$feeBasis]) ?></label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input id="administration_fee" name="administration_fee" type="number" class="form-control text-end" min="0" max="9999999999999.99" step="0.01" required
                                       value="<?= $escape($settings['administration_fee'] ?? '') ?>" placeholder="<?= $escape($feePlaceholders[$feeBasis]) ?>" <?= $canWrite ? '' : 'readonly' ?>
                                       aria-describedby="administration-fee-help">
                                <span class="input-group-text">COP</span>
                            </div>
                            <div class="form-text mb-0" id="administration-fee-help" aria-live="polite"></div>
                        </fieldset>
                        <div class="mb-3">
                            <label for="effective_date" class="form-label">Fecha desde que aplica</label>
                            <input id="effective_date" name="effective_date" type="date" class="form-control" required value="<?= $escape($settings['effective_date'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="early_payment_discount" class="form-label">Descuento por pronto pago (COP)</label>
                            <input id="early_payment_discount" name="early_payment_discount" type="number" class="form-control" min="0" max="9999999999999.99" step="0.01" required aria-describedby="early_payment_discount_help" value="<?= $escape($settings['early_payment_discount'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                            <small id="early_payment_discount_help" class="text-secondary">0 = no aplica descuento por pronto pago (igual que el día límite 0 en Parametrización).</small>
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
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h2 class="card-title">Cuota extraordinaria</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="extraordinary_fee" class="form-label">Valor de la cuota extraordinaria (COP)</label>
                            <input id="extraordinary_fee" name="extraordinary_fee" type="number" class="form-control" min="0" max="9999999999999.99" step="0.01" value="<?= $escape($settings['extraordinary_fee'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="extraordinary_fee_start_date" class="form-label">Fecha inicial de vigencia</label>
                            <input id="extraordinary_fee_start_date" name="extraordinary_fee_start_date" type="date" class="form-control" value="<?= $escape($settings['extraordinary_fee_start_date'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="extraordinary_fee_end_date" class="form-label">Fecha final de vigencia</label>
                            <input id="extraordinary_fee_end_date" name="extraordinary_fee_end_date" type="date" class="form-control" value="<?= $escape($settings['extraordinary_fee_end_date'] ?? '') ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <small class="text-secondary">Completa los tres campos para configurar la cuota; déjalos vacíos si no aplica.</small>
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
<script nonce="<?= \App\Core\Csp::nonce() ?>">
(() => {
    // Texto de ayuda según la opción: cuánto se recauda al mes con la conformación actual.
    const input = document.getElementById('administration_fee');
    const label = document.getElementById('administration-fee-label');
    const help = document.getElementById('administration-fee-help');
    const radios = [...document.querySelectorAll('input[name="fee_basis"]')];
    const labels = <?= json_encode($feeInputLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const placeholders = <?= json_encode($feePlaceholders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const units = <?= $unitCount ?>;
    const coefficientSum = <?= json_encode($coefficientSum) ?>;
    const money = (value) => '$ ' + value.toLocaleString('es-CO', { maximumFractionDigits: 0 });
    const percent = (value) => value.toLocaleString('es-CO', { maximumFractionDigits: 4 }) + ' %';
    const sync = () => {
        const basis = radios.find((radio) => radio.checked)?.value || 'base';
        label.textContent = labels[basis];
        input.placeholder = placeholders[basis];
        const value = Number(input.value) || 0;
        if (units === 0 || coefficientSum <= 0) {
            help.textContent = 'Completa la conformación (inmuebles y coeficientes) para ver cuánto se recauda al mes.';
            return;
        }
        if (value <= 0) {
            help.textContent = basis === 'base'
                ? `Escribe la base: cada inmueble paga base × su coeficiente (${units} inmueble(s), coeficientes que suman ${percent(coefficientSum)}).`
                : `Escribe el total mensual: se reparte entre ${units} inmueble(s) según su coeficiente.`;
            return;
        }
        if (basis === 'base') {
            help.textContent = `Un inmueble de 1 % paga ${money(value / 100)}. Con ${units} inmueble(s) (coeficientes: ${percent(coefficientSum)}) se recaudan ${money(value * coefficientSum / 100)} al mes.`;
        } else {
            help.textContent = `Un inmueble de 1 % paga ${money(value / coefficientSum)}. Entre ${units} inmueble(s) las cuotas suman exactamente ${money(value)} al mes.`;
        }
    };
    radios.forEach((radio) => radio.addEventListener('change', sync));
    input.addEventListener('input', sync);
    sync();
})();
</script>
