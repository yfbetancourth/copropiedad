<?php

declare(strict_types=1);

use App\Modules\CommonAreas\CommonAreaService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$agendaViewer = null;
$agendaShowArea = $areaId === 0;
$previous = $from->modify('-' . $agendaDays . ' days')->format('Y-m-d');
$next = $from->modify('+' . $agendaDays . ' days')->format('Y-m-d');
$areaQuery = $areaId > 0 ? '&zona=' . $areaId : '';
?>
<section class="content">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-calendar2-check me-2" aria-hidden="true"></i>Zonas comunes</h1>
            <p class="text-secondary mb-0">Aprueba las solicitudes de los residentes. Solo se puede aprobar una reserva que no se cruce con otra ya aprobada del mismo espacio; al aprobarla, las solicitudes pendientes que se cruzan se rechazan y se avisa a cada residente.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/zonas-comunes/espacios"><i class="bi bi-building me-1" aria-hidden="true"></i>Administrar espacios</a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($areas === []): ?>
        <div class="alert alert-light border">Aún no hay espacios. <a href="<?= $escape($basePath) ?>/zonas-comunes/espacios">Crea el primero</a> (salón social, BBQ, piscina…) para que los residentes puedan solicitarlo.</div>
    <?php endif; ?>

    <h2 class="h5 mb-3">Solicitudes por decidir <span class="badge text-bg-warning align-middle"><?= count($requests) ?></span></h2>
    <?php if ($requests === []): ?>
        <p class="text-secondary">No hay solicitudes pendientes.</p>
    <?php endif; ?>
    <div class="row g-3 mb-4">
        <?php foreach ($requests as $request): ?>
            <?php $blocked = $request['blocking'] !== []; $requestId = (int) $request['id']; ?>
            <div class="col-lg-6">
                <div class="card h-100 <?= $blocked ? 'border-danger' : 'card-warning card-outline' ?>">
                    <div class="card-header">
                        <h3 class="card-title h6 mb-0"><?= $escape((string) $request['area_name']) ?> · <?= $escape(CommonAreaService::range($request)) ?></h3>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong><?= $escape((string) $request['username']) ?></strong> <span class="text-secondary small"><?= $escape((string) $request['email']) ?></span></p>
                        <p class="small mb-2">
                            <?= $request['guests'] !== null ? (int) $request['guests'] . ' asistente(s)' . ($request['capacity'] !== null ? ' de un aforo de ' . (int) $request['capacity'] : '') . ' · ' : '' ?>
                            Solicitada el <?= $escape(substr((string) $request['created_at'], 0, 16)) ?>
                        </p>
                        <?php if ((string) $request['notes'] !== ''): ?>
                            <p class="small fst-italic mb-2">«<?= $escape((string) $request['notes']) ?>»</p>
                        <?php endif; ?>
                        <?php if ($blocked): ?>
                            <div class="alert alert-danger py-2 small mb-2"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Se cruza con la reserva aprobada de
                                <?= $escape(implode(', ', array_map(static fn (array $b): string => $b['username'] . ' (' . substr((string) $b['starts_at'], 11, 5) . '–' . substr((string) $b['ends_at'], 11, 5) . ')', $request['blocking']))) ?>. No se puede aprobar.</div>
                        <?php elseif ($request['competing'] !== []): ?>
                            <div class="alert alert-warning py-2 small mb-2"><i class="bi bi-people me-1" aria-hidden="true"></i>Compite con otras solicitudes por ese horario:
                                <?= $escape(implode(', ', array_map(static fn (array $b): string => $b['username'] . ' (' . substr((string) $b['starts_at'], 11, 5) . '–' . substr((string) $b['ends_at'], 11, 5) . ')', $request['competing']))) ?>. Si apruebas esta, esas se rechazan.</div>
                        <?php else: ?>
                            <div class="small text-success mb-2"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Sin cruces: el horario está libre.</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canWrite): ?>
                        <div class="card-footer">
                            <div class="row g-2">
                                <form class="col-md-6" method="post" action="<?= $escape($basePath) ?>/zonas-comunes/aprobar">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="booking_id" value="<?= $requestId ?>">
                                    <label class="visually-hidden" for="approve-notes-<?= $requestId ?>">Indicaciones para el residente</label>
                                    <input class="form-control form-control-sm mb-2" id="approve-notes-<?= $requestId ?>" name="notes" maxlength="255" placeholder="Indicaciones (opcional)" <?= $blocked ? 'disabled' : '' ?>>
                                    <button class="btn btn-success btn-sm w-100" type="submit" <?= $blocked ? 'disabled' : '' ?>><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Aprobar</button>
                                </form>
                                <form class="col-md-6" method="post" action="<?= $escape($basePath) ?>/zonas-comunes/rechazar">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="booking_id" value="<?= $requestId ?>">
                                    <label class="visually-hidden" for="reject-reason-<?= $requestId ?>">Motivo del rechazo</label>
                                    <input class="form-control form-control-sm mb-2" id="reject-reason-<?= $requestId ?>" name="reason" minlength="5" maxlength="255" required
                                           placeholder="Motivo del rechazo" value="<?= $blocked ? 'El horario ya está reservado.' : '' ?>">
                                    <button class="btn btn-outline-danger btn-sm w-100" type="submit"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rechazar</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card" id="agenda">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h2 class="card-title h6 mb-0 me-auto">Agenda · <?= $escape($from->format('d/m/Y')) ?> a <?= $escape($from->modify('+' . ($agendaDays - 1) . ' days')->format('d/m/Y')) ?></h2>
            <form method="get" action="<?= $escape($basePath) ?>/zonas-comunes#agenda" class="d-flex gap-2">
                <input type="hidden" name="desde" value="<?= $escape($from->format('Y-m-d')) ?>">
                <label class="visually-hidden" for="agenda-area">Espacio</label>
                <select class="form-select form-select-sm" id="agenda-area" name="zona" data-autosubmit>
                    <option value="0">Todos los espacios</option>
                    <?php foreach ($areas as $area): ?>
                        <option value="<?= (int) $area['id'] ?>"<?= (int) $area['id'] === $areaId ? ' selected' : '' ?>><?= $escape((string) $area['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/zonas-comunes?desde=<?= $escape($previous . $areaQuery) ?>#agenda" aria-label="Semanas anteriores"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/zonas-comunes?desde=<?= $escape($next . $areaQuery) ?>#agenda" aria-label="Semanas siguientes"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
        <?php require dirname(__DIR__, 3) . '/Shared/common-area-agenda.php'; ?>
    </div>
</section>
