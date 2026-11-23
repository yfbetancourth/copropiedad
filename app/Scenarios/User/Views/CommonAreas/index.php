<?php

declare(strict_types=1);

use App\Modules\CommonAreas\CommonAreaService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$agendaViewer = (int) $user['id'];
$agendaShowArea = false;
$today = new DateTimeImmutable('today');
$statusBadges = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-calendar2-week me-2" aria-hidden="true"></i>Zonas comunes</h1>
        <p class="text-secondary mb-0">Consulta la agenda y solicita un espacio. La administración aprueba cada solicitud si el horario no se cruza con otra reserva; te avisaremos aquí y por correo.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($areas === []): ?>
        <div class="alert alert-light border">La administración aún no ha habilitado espacios para reservar.</div>
    <?php else: ?>
        <ul class="nav nav-pills mb-3">
            <?php foreach ($areas as $area): ?>
                <?php $isSelected = (int) $area['id'] === (int) $selected['id']; ?>
                <li class="nav-item">
                    <a class="nav-link<?= $isSelected ? ' active' : '' ?>"<?= $isSelected ? ' aria-current="page"' : '' ?>
                       href="<?= $escape($basePath) ?>/usuario/zonas-comunes?zona=<?= (int) $area['id'] ?>"><?= $escape((string) $area['name']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card" id="agenda">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <h2 class="card-title h6 mb-0 me-auto">Agenda de <?= $escape((string) $selected['name']) ?></h2>
                        <?php if ($from > $today): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/usuario/zonas-comunes?zona=<?= (int) $selected['id'] ?>&desde=<?= $escape($from->modify('-' . $agendaDays . ' days')->format('Y-m-d')) ?>#agenda" aria-label="Semanas anteriores"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($basePath) ?>/usuario/zonas-comunes?zona=<?= (int) $selected['id'] ?>&desde=<?= $escape($from->modify('+' . $agendaDays . ' days')->format('Y-m-d')) ?>#agenda" aria-label="Semanas siguientes"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                    </div>
                    <?php require dirname(__DIR__, 3) . '/Shared/common-area-agenda.php'; ?>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header"><h2 class="card-title">Solicitar <?= $escape((string) $selected['name']) ?></h2></div>
                    <form method="post" action="<?= $escape($basePath) ?>/usuario/zonas-comunes/solicitar">
                        <div class="card-body">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="area_id" value="<?= (int) $selected['id'] ?>">
                            <p class="small text-secondary">
                                Horario <?= $escape(substr((string) $selected['opens_at'], 0, 5) . '–' . substr((string) $selected['closes_at'], 0, 5)) ?>
                                · máximo <?= (int) $selected['max_hours'] ?> h
                                · hasta <?= (int) $selected['max_days_ahead'] ?> días antes
                                <?= $selected['capacity'] !== null ? '· aforo ' . (int) $selected['capacity'] . ' personas' : '' ?>
                            </p>
                            <div class="mb-2">
                                <label class="form-label" for="booking-date">Fecha</label>
                                <input class="form-control" id="booking-date" name="date" type="date" required
                                       min="<?= $escape($today->format('Y-m-d')) ?>"
                                       max="<?= $escape($today->modify('+' . (int) $selected['max_days_ahead'] . ' days')->format('Y-m-d')) ?>"
                                       value="<?= $escape($from->format('Y-m-d')) ?>">
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label" for="booking-start">Desde</label>
                                    <input class="form-control" id="booking-start" name="start" type="time" required step="900"
                                           min="<?= $escape(substr((string) $selected['opens_at'], 0, 5)) ?>" max="<?= $escape(substr((string) $selected['closes_at'], 0, 5)) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="booking-end">Hasta</label>
                                    <input class="form-control" id="booking-end" name="end" type="time" required step="900"
                                           min="<?= $escape(substr((string) $selected['opens_at'], 0, 5)) ?>" max="<?= $escape(substr((string) $selected['closes_at'], 0, 5)) ?>">
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label" for="booking-guests">Asistentes (opcional)</label>
                                <input class="form-control" id="booking-guests" name="guests" type="number" min="1" <?= $selected['capacity'] !== null ? 'max="' . (int) $selected['capacity'] . '"' : '' ?>>
                            </div>
                            <div>
                                <label class="form-label" for="booking-notes">Motivo u observaciones (opcional)</label>
                                <input class="form-control" id="booking-notes" name="notes" maxlength="255" placeholder="Cumpleaños, reunión familiar…">
                            </div>
                            <?php if (trim((string) $selected['rules']) !== ''): ?>
                                <details class="mt-3 small">
                                    <summary>Reglamento de uso</summary>
                                    <p class="mt-2 mb-0"><?= nl2br($escape((string) $selected['rules'])) ?></p>
                                </details>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar solicitud</button>
                        </div>
                    </form>
                </div>
                <?php if (trim((string) $selected['description']) !== ''): ?>
                    <p class="small text-secondary"><?= nl2br($escape((string) $selected['description'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card mt-3" id="mis-reservas">
        <div class="card-header">
            <h2 class="card-title">Mis reservas</h2>
            <span class="badge text-bg-secondary"><?= count($bookings) ?></span>
        </div>
        <?php if ($bookings === []): ?>
            <div class="card-body text-secondary">Aún no has solicitado zonas comunes.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Espacio</th><th>Horario</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <?php $cancellable = in_array($booking['status'], ['pending', 'approved'], true) && $booking['starts_at'] > $now; ?>
                        <tr>
                            <td><?= $escape((string) $booking['area_name']) ?></td>
                            <td><?= $escape(CommonAreaService::range($booking)) ?></td>
                            <td>
                                <span class="badge text-bg-<?= $statusBadges[$booking['status']] ?? 'secondary' ?>"><?= $escape(CommonAreaService::STATUS_LABELS[$booking['status']] ?? (string) $booking['status']) ?></span>
                                <?php if ((string) ($booking['decision_notes'] ?? '') !== ''): ?>
                                    <div class="small text-secondary mt-1"><?= $escape((string) $booking['decision_notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($cancellable): ?>
                                    <form method="post" action="<?= $escape($basePath) ?>/usuario/zonas-comunes/cancelar" onsubmit="return confirm('¿Cancelar esta reserva?');">
                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Cancelar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
