<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.pagos', 2);
$methodLabels = [
    'CREDIT_CARD' => 'Tarjeta de crédito o débito',
    'PSE' => 'PSE',
    'BOTON_BANCOLOMBIA' => 'Botón Bancolombia',
    'NEQUI' => 'Nequi',
];
$methodsToShow = $availableMethods !== []
    ? $availableMethods
    : array_fill_keys(array_keys($methodLabels), []);

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Metodos de pago</h1>
        <p class="text-secondary mb-0">Configura Bold para recibir pagos en línea desde las notas de cobro del portal de usuario.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (is_string($providerError)): ?>
        <div class="alert alert-warning" role="alert"><?= $escape($providerError) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title">Integración Bold · API Link de pagos</h2>
                    <span class="badge text-bg-<?= $settings['api_key_configured'] && $availableMethods !== [] ? 'success' : 'warning' ?>">
                        <?= $settings['api_key_configured'] && $availableMethods !== [] ? 'Conexión verificada' : 'Configuración incompleta' ?>
                    </span>
                </div>
                <form method="post" action="<?= $escape($basePath) ?>/configuracion/metodos-pago/guardar" autocomplete="off">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <div class="mb-3">
                            <label for="bold-environment" class="form-label">Ambiente</label>
                            <select id="bold-environment" name="environment" class="form-select" <?= $canWrite ? '' : 'disabled' ?>>
                                <option value="sandbox" <?= $settings['environment'] === 'sandbox' ? 'selected' : '' ?>>Pruebas (sandbox)</option>
                                <option value="production" <?= $settings['environment'] === 'production' ? 'selected' : '' ?>>Producción</option>
                            </select>
                            <?php if (!$canWrite): ?>
                                <input type="hidden" name="environment" value="<?= $escape($settings['environment']) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="bold-api-key" class="form-label">Llave de identidad (API key) de Botón de pagos</label>
                            <input id="bold-api-key" name="api_key" type="password" class="form-control" maxlength="512" autocomplete="new-password" <?= $canWrite && !$settings['api_key_configured'] ? 'required' : '' ?> <?= $canWrite ? '' : 'readonly' ?> placeholder="<?= $settings['api_key_configured'] ? 'Guardada; deja vacío para conservarla' : 'Ingresa la llave del ambiente seleccionado' ?>">
                            <small class="text-secondary">La llave se guarda cifrada y nunca vuelve a mostrarse. No ingreses la llave secreta ni credenciales de API Integrations para datáfonos.</small>
                        </div>
                        <fieldset class="mb-3">
                            <legend class="form-label">Métodos habilitados para las notas</legend>
                            <?php if ($availableMethods === []): ?>
                                <p class="small text-secondary">Al guardar, se verificará con Bold cuáles de estos métodos están habilitados para el comercio.</p>
                            <?php endif; ?>
                            <?php foreach ($methodsToShow as $code => $method): ?>
                                <?php
                                $label = $method['label'] ?? $methodLabels[$code] ?? $code;
                                $selected = in_array($code, $settings['payment_methods'], true)
                                    || (!$settings['api_key_configured'] && $settings['payment_methods'] === []);
                                ?>
                                <div class="form-check">
                                    <input id="method-<?= $escape($code) ?>" name="payment_methods[]" type="checkbox" class="form-check-input" value="<?= $escape($code) ?>" <?= $selected ? 'checked' : '' ?> <?= $canWrite ? '' : 'disabled' ?>>
                                    <label for="method-<?= $escape($code) ?>" class="form-check-label">
                                        <?= $escape($label) ?>
                                        <?php if (isset($method['min'], $method['max'])): ?>
                                            <small class="text-secondary">(<?= $escape(number_format((int) $method['min'], 0, ',', '.')) ?>–<?= $escape(number_format((int) $method['max'], 0, ',', '.')) ?> COP)</small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>
                        <div class="alert alert-info mb-0">
                            Los pagos solo se registran en cartera cuando Bold confirma la transacción como aprobada. El retorno del navegador no confirma el pago.
                        </div>
                    </div>
                    <?php if ($canWrite): ?>
                        <div class="card-footer">
                            <button class="btn btn-primary" type="submit">Guardar y verificar conexión</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card card-info card-outline">
                <div class="card-header"><h2 class="card-title">Configurar webhook de Bold</h2></div>
                <div class="card-body">
                    <p>Registra esta URL HTTPS en el Panel de Comercios de Bold, en Integraciones / Webhooks, para recibir las ventas aprobadas:</p>
                    <code class="user-select-all"><?= $escape($webhookUrl !== '' ? $webhookUrl : 'Configura APP_URL con un dominio público HTTPS.') ?></code>
                    <hr>
                    <p class="small text-secondary mb-0">La aplicación consulta el estado del link autenticándose con Bold antes de registrar cada pago, y verifica que el monto coincida con la nota.</p>
                </div>
            </div>
        </div>
    </div>
</section>
