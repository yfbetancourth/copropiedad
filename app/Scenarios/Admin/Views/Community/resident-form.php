<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$editing = $resident !== null;
$value = static function (string $field) use ($old, $resident): string {
    if (is_array($old) && array_key_exists($field, $old)) {
        return is_string($old[$field]) ? $old[$field] : '';
    }

    return is_array($resident) ? (string) ($resident[$field] ?? '') : '';
};
?>
<section class="content">
    <a class="small text-decoration-none d-inline-block mb-2" href="<?= $escape($basePath) ?>/comunidad/residentes"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Residentes</a>
    <h1 class="h3 mb-3"><?= $editing ? 'Editar residente' : 'Nuevo residente' ?></h1>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="alert"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $escape($basePath) ?>/comunidad/residentes/guardar" autocomplete="off">
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="user_id" value="<?= $editing ? (int) $resident['id'] : 0 ?>">
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header"><h2 class="card-title">Datos de la persona</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="full-name">Nombre completo</label>
                            <input class="form-control" id="full-name" name="full_name" minlength="3" maxlength="160" required value="<?= $escape($value('full_name')) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="resident-type">Tipo de residente</label>
                            <select class="form-select" id="resident-type" name="resident_type_id">
                                <option value="">Sin clasificar</option>
                                <?php foreach ($residentTypes as $type): ?>
                                    <?php $selected = $value('resident_type_id') === (string) $type['id']; ?>
                                    <?php if ($type['is_active'] || $selected): ?>
                                        <option value="<?= (int) $type['id'] ?>" data-requires-owner="<?= $type['requires_unit_owner'] ? '1' : '0' ?>"<?= $selected ? ' selected' : '' ?>><?= $escape($type['name']) ?><?= $type['is_active'] ? '' : ' (inactivo)' ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">El catálogo se administra en <a href="<?= $escape($basePath) ?>/comunidad/tipos-residente">Tipos de residente</a>.</div>
                        </div>
                        <div class="mb-3" id="tenancy-unit-field" hidden>
                            <label class="form-label" for="tenancy-unit">Inmueble que arrienda</label>
                            <?php if ($hasTenancy): ?>
                                <div class="small text-success mb-1"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Ya está vinculado como inquilino. Elige otro inmueble solo si arrienda uno más.</div>
                            <?php endif; ?>
                            <select class="form-select" id="tenancy-unit" name="unit_id" aria-describedby="tenancy-unit-help">
                                <option value=""><?= $hasTenancy ? 'Conservar sus inmuebles actuales' : 'Elige el inmueble…' ?></option>
                                <?php foreach ($tenancyUnits as $unitId => $unit): ?>
                                    <option value="<?= $escape((string) $unitId) ?>"<?= $unit['owners'] === [] ? ' disabled' : '' ?><?= $value('unit_id') === (string) $unitId ? ' selected' : '' ?>>
                                        <?= $escape($unit['name']) ?> · <?= $unit['owners'] === [] ? 'sin propietario' : 'Propietario: ' . $escape(implode(', ', $unit['owners'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (array_filter($tenancyUnits, static fn (array $unit): bool => $unit['owners'] !== []) === []): ?>
                                <div class="alert alert-warning small py-2 mt-2 mb-0" role="status">
                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                    <?= $tenancyUnits === [] ? 'Aún no hay inmuebles en la conformación.' : 'Ningún inmueble tiene propietario todavía.' ?>
                                    Un inquilino solo se puede registrar cuando su inmueble ya tiene propietario: vincula primero a los propietarios en Asignación de inmuebles.
                                </div>
                            <?php endif; ?>
                            <div class="form-text" id="tenancy-unit-help">Solo se puede registrar en un inmueble que ya tiene propietario; los demás aparecen deshabilitados. Vincula primero al propietario en Asignación de inmuebles.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-5">
                                <label class="form-label" for="document-type">Tipo de documento</label>
                                <select class="form-select" id="document-type" name="document_type">
                                    <option value="">Sin registrar</option>
                                    <?php foreach ($documentTypes as $code => $label): ?>
                                        <option value="<?= $escape($code) ?>"<?= $value('document_type') === $code ? ' selected' : '' ?>><?= $escape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" for="document-number">Número de documento</label>
                                <input class="form-control" id="document-number" name="document_number" maxlength="30" value="<?= $escape($value('document_number')) ?>">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-7">
                                <label class="form-label" for="email">Correo electrónico</label>
                                <input class="form-control" id="email" name="email" type="email" maxlength="254" required value="<?= $escape($value('email')) ?>">
                                <div class="form-text">Allí recibe su acceso, sus notas de cobro y los avisos.</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="phone">Teléfono</label>
                                <input class="form-control" id="phone" name="phone" type="tel" maxlength="30" value="<?= $escape($value('phone')) ?>">
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="notes">Notas internas</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="500" placeholder="Solo las ve la administración"><?= $escape($value('notes')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header"><h2 class="card-title">Acceso al portal</h2></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="username">Usuario</label>
                            <input class="form-control" id="username" name="username" maxlength="64" value="<?= $escape($value('username')) ?>" placeholder="<?= $editing ? '' : 'Se genera a partir del correo' ?>" <?= $editing ? 'required' : '' ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password"><?= $editing ? 'Nueva contraseña' : 'Contraseña inicial' ?> (opcional)</label>
                            <input class="form-control" id="password" name="password" type="password" minlength="12" autocomplete="new-password"
                                   placeholder="<?= $editing ? 'Deja vacío para conservarla' : 'Vacío: se envía la invitación' ?>">
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="send-invitation" name="send_invitation" value="1" <?= $editing ? '' : 'checked' ?>>
                            <label class="form-check-label" for="send-invitation">Enviar por correo el enlace para crear su contraseña (vence en 72 horas)</label>
                        </div>
                        <?php if (!$editing): ?>
                            <div class="form-text">Si no escribes una contraseña, la invitación se envía siempre.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($editing): ?>
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">Inmuebles</h2></div>
                        <div class="card-body small">
                            <?php if ($resident['units'] === []): ?>
                                <p class="text-warning mb-2">Aún no tiene inmuebles vinculados.</p>
                            <?php else: ?>
                                <ul class="mb-2 ps-3">
                                    <?php foreach ($resident['units'] as $unit): ?>
                                        <li><?= $escape($unit['name']) ?> · <?= $escape($unit['relation']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($canAssign): ?>
                                <a href="<?= $escape($basePath) ?>/inmuebles/asignaciones?usuario=<?= (int) $resident['id'] ?>"><i class="bi bi-house-door me-1" aria-hidden="true"></i>Vincular o cambiar inmuebles</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Guardar cambios' : 'Crear residente' ?></button>
            <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/comunidad/residentes">Cancelar</a>
        </div>
    </form>
</section>
<script>
(() => {
    // El inmueble que arrienda se pide solo para tipos que exigen propietario (p. ej. Inquilino).
    const type = document.getElementById('resident-type');
    const field = document.getElementById('tenancy-unit-field');
    const unit = document.getElementById('tenancy-unit');
    const hasTenancy = <?= $hasTenancy ? 'true' : 'false' ?>;
    const sync = () => {
        const requires = type.selectedOptions[0]?.dataset.requiresOwner === '1';
        field.hidden = !requires;
        unit.required = requires && !hasTenancy;
        unit.disabled = !requires;
    };
    type.addEventListener('change', sync);
    sync();
})();
</script>
