<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$canWrite = (bool) ($actor['is_system'] ?? false);

?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1">Configuracion</h1>
        <p class="text-secondary mb-0">Parametrizacion · datos generales y bancarios de la copropiedad.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$canWrite): ?>
        <div class="alert alert-info" role="status">Solo el usuario Superadmin puede modificar estos datos.</div>
    <?php endif; ?>

    <form method="post" action="<?= $escape($basePath) ?>/configuracion/parametrizacion/guardar">
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h2 class="card-title">Informacion general</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="property_name" class="form-label">Nombre de la copropiedad</label>
                            <input id="property_name" name="property_name" class="form-control" maxlength="160" required value="<?= $escape($settings['property_name']) ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="address" class="form-label">Direccion</label>
                            <input id="address" name="address" class="form-control" maxlength="255" value="<?= $escape($settings['address']) ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="tax_id" class="form-label">NIT</label>
                            <input id="tax_id" name="tax_id" class="form-control" maxlength="32" value="<?= $escape($settings['tax_id']) ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h2 class="card-title">Informacion bancaria</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="account_holder" class="form-label">Titular de la cuenta</label>
                            <input id="account_holder" name="account_holder" class="form-control" maxlength="160" value="<?= $escape($settings['account_holder']) ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="bank_name" class="form-label">Nombre del banco</label>
                            <input id="bank_name" name="bank_name" class="form-control" maxlength="120" value="<?= $escape($settings['bank_name']) ?>" <?= $canWrite ? '' : 'readonly' ?>>
                        </div>
                        <div class="mb-3">
                            <label for="account_type" class="form-label">Tipo de cuenta</label>
                            <select id="account_type" name="account_type" class="form-select" <?= $canWrite ? '' : 'disabled' ?>>
                                <option value="">Sin configurar</option>
                                <option value="corriente" <?= $settings['account_type'] === 'corriente' ? 'selected' : '' ?>>Corriente</option>
                                <option value="ahorros" <?= $settings['account_type'] === 'ahorros' ? 'selected' : '' ?>>Ahorros</option>
                            </select>
                            <?php if (!$canWrite): ?>
                                <input type="hidden" name="account_type" value="<?= $escape($settings['account_type']) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="account_number" class="form-label">Numero de cuenta bancaria</label>
                            <input id="account_number" name="account_number" type="text" inputmode="numeric" pattern="[0-9]{1,34}" maxlength="34" class="form-control" value="<?= $escape($settings['account_number']) ?>" <?= $canWrite ? '' : 'readonly' ?>>
                            <small class="text-secondary">Ingresa solo numeros, sin espacios ni guiones.</small>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($canWrite): ?>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Guardar parametrizacion</button>
                </div>
            <?php endif; ?>
        </div>
    </form>
</section>
