<?php

declare(strict_types=1);

/**
 * Agenda de zonas comunes por día, compartida por el panel administrativo y el portal.
 *
 * Espera:
 * - $escape         callable(string): string
 * - $agenda         array<string, list<array>>  fecha Y-m-d => reservas pendientes y aprobadas
 * - $agendaViewer   ?int  null en el panel (muestra quién reservó); id del residente en el portal (solo ve sus nombres)
 * - $agendaShowArea bool  mostrar el nombre del espacio (agenda de todos los espacios)
 */
$agendaWeekdays = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$agendaToday = (new DateTimeImmutable('today'))->format('Y-m-d');
?>
<div class="list-group list-group-flush">
    <?php foreach ($agenda as $agendaDay => $agendaBookings): ?>
        <?php $agendaDate = new DateTimeImmutable($agendaDay); ?>
        <div class="list-group-item d-flex flex-wrap gap-3<?= $agendaDay === $agendaToday ? ' bg-primary-subtle' : '' ?>">
            <div class="fw-semibold" style="min-width: 9rem">
                <?= $escape($agendaWeekdays[(int) $agendaDate->format('w')]) ?>
                <span class="d-block small text-secondary fw-normal"><?= $escape($agendaDate->format('d/m/Y')) ?><?= $agendaDay === $agendaToday ? ' · hoy' : '' ?></span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                <?php if ($agendaBookings === []): ?>
                    <span class="small text-success"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Libre</span>
                <?php endif; ?>
                <?php foreach ($agendaBookings as $agendaBooking): ?>
                    <?php
                    $agendaApproved = $agendaBooking['status'] === 'approved';
                    $agendaMine = $agendaViewer !== null && (int) $agendaBooking['user_id'] === $agendaViewer;
                    $agendaWho = $agendaViewer === null ? (string) $agendaBooking['username'] : ($agendaMine ? 'Tu reserva' : ($agendaApproved ? 'Reservado' : 'Solicitado'));
                    ?>
                    <span class="badge rounded-pill text-bg-<?= $agendaApproved ? ($agendaMine ? 'primary' : 'danger') : 'warning' ?> fw-normal fs-6"
                          title="<?= $agendaApproved ? 'Aprobada' : 'Pendiente de aprobación' ?>">
                        <i class="bi <?= $agendaApproved ? 'bi-lock-fill' : 'bi-hourglass-split' ?> me-1" aria-hidden="true"></i>
                        <?= $escape(substr((string) $agendaBooking['starts_at'], 11, 5) . '–' . substr((string) $agendaBooking['ends_at'], 11, 5)) ?>
                        · <?= $escape($agendaWho) ?><?= $agendaShowArea ? ' · ' . $escape((string) $agendaBooking['area_name']) : '' ?>
                        <span class="visually-hidden">(<?= $agendaApproved ? 'aprobada' : 'pendiente' ?>)</span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<p class="small text-secondary px-3 py-2 mb-0">
    <span class="badge text-bg-danger">&nbsp;</span> Reservado ·
    <span class="badge text-bg-warning">&nbsp;</span> Solicitud pendiente (puede haber varias; la administración aprueba solo una por horario)<?= $agendaViewer !== null ? ' · <span class="badge text-bg-primary">&nbsp;</span> Tu reserva' : '' ?>
</p>
