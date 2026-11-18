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
$terminalMethodsToShow = $availableTerminalMethods !== []
    ? $availableTerminalMethods
    : array_map(static fn (string $name): array => ['name' => $name], ['POS', 'NEQUI', 'PAY_BY_QR_BOLD', 'DAVIPLATA', 'MANUAL']);

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
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="card-title mb-0">Bold · API Integrations para datáfono</h2>
            <span class="badge text-bg-<?= $terminalSettings['configured'] ? 'success' : 'warning' ?>"><?= $terminalSettings['configured'] ? 'Datáfono listo' : 'Configuración pendiente' ?></span>
        </div>
        <?php if (is_string($terminalProviderError)): ?><div class="alert alert-warning m-3 mb-0" role="alert"><?= $escape($terminalProviderError) ?></div><?php endif; ?>
        <form method="post" action="<?= $escape($basePath) ?>/configuracion/metodos-pago/bold-terminal/guardar" autocomplete="off" aria-labelledby="bold-terminal-heading">
            <div class="card-body">
                <h3 id="bold-terminal-heading" class="visually-hidden">Configurar pagos en datáfono Bold</h3>
                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                <p class="small text-secondary">Esta llave es independiente de la llave de Botón de pagos web. Bold API Integrations requiere un datáfono Smart o Smart Pro vinculado y habilitado desde la app Bold.</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="terminal-environment" class="form-label">Ambiente API Datáfono</label>
                        <select id="terminal-environment" name="terminal_environment" class="form-select" <?= $canWrite ? '' : 'disabled' ?> required>
                            <option value="sandbox" <?= $terminalSettings['environment'] === 'sandbox' ? 'selected' : '' ?>>Pruebas</option>
                            <option value="production" <?= $terminalSettings['environment'] === 'production' ? 'selected' : '' ?>>Producción</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label for="terminal-api-key" class="form-label">Llave de identidad API Datáfono</label>
                        <input id="terminal-api-key" name="terminal_api_key" type="password" class="form-control" maxlength="512" autocomplete="new-password" <?= $canWrite && !$terminalSettings['api_key_configured'] ? 'required' : '' ?> <?= $canWrite ? '' : 'readonly' ?> placeholder="<?= $terminalSettings['api_key_configured'] ? 'Guardada; deja vacío para conservarla' : 'Ingresa la llave del ambiente seleccionado' ?>">
                    </div>
                    <div class="col-md-8">
                        <label for="terminal-webhook-secret" class="form-label">Llave secreta para firma del webhook</label>
                        <input id="terminal-webhook-secret" name="terminal_webhook_secret" type="password" class="form-control" maxlength="512" autocomplete="new-password" <?= $canWrite ? '' : 'readonly' ?> placeholder="<?= $terminalSettings['webhook_secret_configured'] ? 'Guardada; deja vacío para conservarla' : 'Requerida para producción' ?>">
                        <small class="text-secondary">En sandbox Bold firma con llave vacía. En producción usa la llave secreta API Datáfono. Se guarda cifrada.</small>
                    </div>
                    <div class="col-md-4">
                        <label for="terminal-payment-method" class="form-label">Método de pago</label>
                        <select id="terminal-payment-method" name="terminal_payment_method" class="form-select" <?= $canWrite ? '' : 'disabled' ?>>
                            <?php if ($availableTerminalMethods === []): ?><option value="" <?= !$terminalSettings['api_key_configured'] ? 'selected' : '' ?>><?= $terminalSettings['api_key_configured'] ? 'Método guardado · consulta Bold para actualizar' : 'Bold elegirá un método activo; podrás cambiarlo después' ?></option><?php endif; ?>
                            <?php if ($availableTerminalMethods !== []): ?><option value="MANUAL" <?= $terminalSettings['payment_method'] === 'MANUAL' ? 'selected' : '' ?>>Selección manual en el datáfono</option><?php endif; ?>
                            <?php foreach ($terminalMethodsToShow as $method): ?>
                                <?php if ($method['name'] !== 'MANUAL'): ?><option value="<?= $escape($method['name']) ?>" <?= $availableTerminalMethods !== [] && $terminalSettings['payment_method'] === $method['name'] ? 'selected' : '' ?>><?= $escape($method['name']) ?></option><?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label for="terminal-device" class="form-label">Datáfono habilitado</label>
                        <select id="terminal-device" name="terminal" class="form-select" <?= $canWrite ? '' : 'disabled' ?>>
                            <option value="">Validar llave primero y selecciona un terminal</option>
                            <?php foreach ($availableTerminals as $terminal): ?>
                                <option value="<?= $escape($terminal['terminal_model'] . '|' . $terminal['terminal_serial']) ?>" <?= $terminalSettings['terminal_model'] === $terminal['terminal_model'] && $terminalSettings['terminal_serial'] === $terminal['terminal_serial'] ? 'selected' : '' ?>><?= $escape(($terminal['name'] ?? $terminal['terminal_model']) . ' · ' . $terminal['terminal_model'] . ' · ' . $terminal['terminal_serial']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-secondary">Si es la primera vez, guarda primero la llave y el método; luego recarga para listar terminales y guarda nuevamente.</small>
                    </div>
                </div>
                <div class="alert alert-info mt-3 mb-0">El residente iniciará el cobro en este datáfono. El saldo de la nota solo se aplicará después de validar la firma del webhook, la referencia y el monto reportado por Bold.</div>
            </div>
            <?php if ($canWrite): ?><div class="card-footer"><button class="btn btn-primary" type="submit">Guardar y validar API Datáfono</button></div><?php endif; ?>
        </form>
    </div>
</section>
