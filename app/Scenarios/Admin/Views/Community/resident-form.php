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
