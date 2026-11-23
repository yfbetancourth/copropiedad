<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$form = $editing ?? [
    'id' => 0, 'name' => '', 'description' => '', 'rules' => '', 'capacity' => null,
    'opens_at' => '08:00', 'closes_at' => '22:00', 'max_hours' => 4, 'max_days_ahead' => 60,
];
$formId = (int) $form['id'];
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-building me-2" aria-hidden="true"></i>Espacios comunes</h1>
            <p class="text-secondary mb-0">Define qué espacios se pueden reservar, en qué horario, cuánto puede durar cada reserva y con cuánta anticipación.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/zonas-comunes"><i class="bi bi-calendar2-check me-1" aria-hidden="true"></i>Solicitudes y agenda</a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Espacios</h2></div>
                <?php if ($areas === []): ?>
                    <div class="card-body text-secondary">Aún no hay espacios registrados.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($areas as $area): ?>
                            <?php $active = (bool) $area['is_active']; $id = (int) $area['id']; ?>
                            <li class="list-group-item">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <strong><?= $escape((string) $area['name']) ?></strong>
                                        <?php if (!$active): ?><span class="badge text-bg-secondary ms-1">Inactivo</span><?php endif; ?>
                                        <div class="small text-secondary">
                                            <?= $escape(substr((string) $area['opens_at'], 0, 5) . '–' . substr((string) $area['closes_at'], 0, 5)) ?>
                                            · máx. <?= (int) $area['max_hours'] ?> h por reserva
                                            · hasta <?= (int) $area['max_days_ahead'] ?> días antes
                                            <?= $area['capacity'] !== null ? '· aforo ' . (int) $area['capacity'] : '' ?>
                                            · <?= (int) $area['booking_count'] ?> reserva(s)
                                        </div>
                                    </div>
                                    <?php if ($canWrite): ?>
                                        <div class="d-flex flex-wrap gap-1 align-items-start">
                                            <a class="btn btn-sm btn-outline-primary" href="<?= $escape($basePath) ?>/zonas-comunes/espacios?editar=<?= $id ?>">Editar</a>
                                            <form method="post" action="<?= $escape($basePath) ?>/zonas-comunes/espacios/estado">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="area_id" value="<?= $id ?>">
                                                <input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>">
                                                <button class="btn btn-sm btn-outline-<?= $active ? 'secondary' : 'success' ?>" type="submit"><?= $active ? 'Desactivar' : 'Activar' ?></button>
                                            </form>
                                            <?php if ((int) $area['booking_count'] === 0): ?>
                                                <form method="post" action="<?= $escape($basePath) ?>/zonas-comunes/espacios/eliminar" onsubmit="return confirm('¿Eliminar este espacio?');">
                                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                    <input type="hidden" name="area_id" value="<?= $id ?>">
                                                    <button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($canWrite): ?>
            <div class="col-xl-5">
                <div class="card card-primary card-outline">
                    <div class="card-header"><h2 class="card-title"><?= $formId > 0 ? 'Editar «' . $escape((string) $form['name']) . '»' : 'Nuevo espacio' ?></h2></div>
                    <form method="post" action="<?= $escape($basePath) ?>/zonas-comunes/espacios/guardar">
                        <div class="card-body">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="area_id" value="<?= $formId ?>">
                            <div class="mb-3">
                                <label class="form-label" for="area-name">Nombre</label>
                                <input class="form-control" id="area-name" name="name" minlength="3" maxlength="120" required value="<?= $escape((string) $form['name']) ?>" placeholder="Salón social, BBQ, piscina…">
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <label class="form-label" for="area-opens">Abre</label>
                                    <input class="form-control" id="area-opens" name="opens_at" type="time" required value="<?= $escape(substr((string) $form['opens_at'], 0, 5)) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="area-closes">Cierra</label>
                                    <input class="form-control" id="area-closes" name="closes_at" type="time" required value="<?= $escape(substr((string) $form['closes_at'], 0, 5)) ?>">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="area-max-hours">Máx. horas</label>
                                    <input class="form-control" id="area-max-hours" name="max_hours" type="number" min="1" max="24" required value="<?= (int) $form['max_hours'] ?>">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="area-max-days">Días antes</label>
                                    <input class="form-control" id="area-max-days" name="max_days_ahead" type="number" min="1" max="365" required value="<?= (int) $form['max_days_ahead'] ?>">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="area-capacity">Aforo</label>
                                    <input class="form-control" id="area-capacity" name="capacity" type="number" min="1" max="10000" value="<?= $form['capacity'] !== null ? (int) $form['capacity'] : '' ?>" placeholder="Opcional">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="area-description">Descripción</label>
                                <textarea class="form-control" id="area-description" name="description" rows="2" maxlength="1000"><?= $escape((string) $form['description']) ?></textarea>
                            </div>
                            <div>
                                <label class="form-label" for="area-rules">Reglamento de uso</label>
                                <textarea class="form-control" id="area-rules" name="rules" rows="4" maxlength="2000" placeholder="Depósito, aseo, horario de música, mascotas…"><?= $escape((string) $form['rules']) ?></textarea>
                                <div class="form-text">El residente lo ve antes de solicitar.</div>
                            </div>
                        </div>
                        <div class="card-footer d-flex gap-2">
                            <button class="btn btn-primary" type="submit"><?= $formId > 0 ? 'Guardar cambios' : 'Crear espacio' ?></button>
                            <?php if ($formId > 0): ?><a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/zonas-comunes/espacios">Cancelar</a><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
