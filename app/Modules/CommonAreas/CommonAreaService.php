<?php

declare(strict_types=1);

namespace App\Modules\CommonAreas;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Auth\UserNotifier;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Zonas comunes y su agenda de reservas. El residente solicita un horario; la administración lo aprueba solo si no
 * se cruza con otra reserva aprobada del mismo espacio. Al aprobar, las demás solicitudes pendientes que se cruzan
 * se rechazan automáticamente y se avisa a cada residente.
 */
final class CommonAreaService
{
    public const STATUS_LABELS = [
        'pending' => 'Pendiente de aprobación',
        'approved' => 'Aprobada',
        'rejected' => 'Rechazada',
        'cancelled' => 'Cancelada',
    ];

    public function __construct(private readonly UserNotifier $notifier = new UserNotifier())
    {
    }

    /* ---------------------------------- Espacios ---------------------------------- */

    public function areas(bool $onlyActive = false): array
    {
        $query = Database::connection()->prepare(
            'SELECT areas.*,
                    (SELECT COUNT(*) FROM common_area_bookings AS bookings WHERE bookings.area_id = areas.id) AS booking_count
             FROM common_areas AS areas
             WHERE areas.tenant_id = ?' . ($onlyActive ? ' AND areas.is_active = 1' : '') . '
             ORDER BY areas.is_active DESC, areas.name'
        );
        $query->execute([Tenant::id()]);

        return $query->fetchAll();
    }

    public function area(int $areaId, bool $onlyActive = false): array
    {
        $query = Database::connection()->prepare(
            'SELECT * FROM common_areas WHERE id = ? AND tenant_id = ?' . ($onlyActive ? ' AND is_active = 1' : '')
        );
        $query->execute([$areaId, Tenant::id()]);
        $area = $query->fetch();
        if (!is_array($area)) {
            throw new RuntimeException('La zona común no existe o no está disponible.');
        }

        return $area;
    }

    /** Crea ($areaId = 0) o actualiza un espacio. */
    public function saveArea(int $areaId, array $input): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $rules = trim((string) ($input['rules'] ?? ''));
        $capacity = trim((string) ($input['capacity'] ?? ''));
        $opens = (string) ($input['opens_at'] ?? '');
        $closes = (string) ($input['closes_at'] ?? '');
        $maxHours = filter_var($input['max_hours'] ?? null, FILTER_VALIDATE_INT);
        $maxDays = filter_var($input['max_days_ahead'] ?? null, FILTER_VALIDATE_INT);

        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 120) {
            throw new RuntimeException('El nombre del espacio debe tener entre 3 y 120 caracteres.');
        }
        if (mb_strlen($description, 'UTF-8') > 1000 || mb_strlen($rules, 'UTF-8') > 2000) {
            throw new RuntimeException('La descripción admite 1.000 caracteres y el reglamento 2.000.');
        }
        if ($capacity !== '' && (!ctype_digit($capacity) || (int) $capacity < 1 || (int) $capacity > 10000)) {
            throw new RuntimeException('El aforo debe ser un número entre 1 y 10.000, o dejarse vacío.');
        }
        if (!self::validTime($opens) || !self::validTime($closes) || $opens >= $closes) {
            throw new RuntimeException('Indica el horario de uso: la hora de apertura debe ser anterior a la de cierre.');
        }
        if (!is_int($maxHours) || $maxHours < 1 || $maxHours > 24) {
            throw new RuntimeException('La duración máxima de una reserva debe estar entre 1 y 24 horas.');
        }
        if (!is_int($maxDays) || $maxDays < 1 || $maxDays > 365) {
            throw new RuntimeException('La anticipación máxima debe estar entre 1 y 365 días.');
        }

        $values = [$name, $description, $rules, $capacity === '' ? null : (int) $capacity, $opens . ':00', $closes . ':00', $maxHours, $maxDays];
        $pdo = Database::connection();
        try {
            if ($areaId > 0) {
                $this->area($areaId);
                $pdo->prepare(
                    'UPDATE common_areas SET name = ?, description = ?, rules = ?, capacity = ?, opens_at = ?, closes_at = ?,
                            max_hours = ?, max_days_ahead = ?
                     WHERE id = ? AND tenant_id = ?'
                )->execute([...$values, $areaId, Tenant::id()]);

                return $areaId;
            }
            $pdo->prepare(
                'INSERT INTO common_areas (tenant_id, name, description, rules, capacity, opens_at, closes_at, max_hours, max_days_ahead)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([Tenant::id(), ...$values]);

            return (int) $pdo->lastInsertId();
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Ya existe un espacio con ese nombre.');
            }
            throw $exception;
        }
    }

    /** Desactivar oculta el espacio para nuevas solicitudes; las reservas ya hechas se conservan. */
    public function setAreaActive(int $areaId, bool $active): void
    {
        $this->area($areaId);
        Database::connection()->prepare('UPDATE common_areas SET is_active = ? WHERE id = ? AND tenant_id = ?')
            ->execute([$active ? 1 : 0, $areaId, Tenant::id()]);
    }

    /** Solo se elimina un espacio sin reservas; si tiene historial, se desactiva. */
    public function deleteArea(int $areaId): void
    {
        $area = $this->area($areaId);
        if ((int) $this->count('SELECT COUNT(*) FROM common_area_bookings WHERE area_id = ?', [$areaId]) > 0) {
            throw new RuntimeException('«' . $area['name'] . '» tiene reservas registradas: desactívalo en lugar de eliminarlo.');
        }
        Database::connection()->prepare('DELETE FROM common_areas WHERE id = ? AND tenant_id = ?')->execute([$areaId, Tenant::id()]);
    }

    /* ----------------------------------- Agenda ----------------------------------- */

    /**
     * Reservas pendientes y aprobadas entre dos fechas (incluidas), de un espacio o de todos, agrupadas por día.
     *
     * @return array<string, list<array<string, mixed>>> fecha Y-m-d => reservas ordenadas por hora
     */
    public function agenda(?int $areaId, DateTimeImmutable $from, int $days): array
    {
        $to = $from->modify('+' . $days . ' days');
        $query = Database::connection()->prepare(
            "SELECT bookings.*, areas.name AS area_name, users.username
             FROM common_area_bookings AS bookings
             JOIN common_areas AS areas ON areas.id = bookings.area_id
             JOIN admin_users AS users ON users.id = bookings.user_id
             WHERE bookings.tenant_id = ? AND bookings.status IN ('pending', 'approved')
               AND bookings.starts_at < ? AND bookings.ends_at > ?" . ($areaId !== null ? ' AND bookings.area_id = ?' : '') . '
             ORDER BY bookings.starts_at, bookings.status'
        );
        $parameters = [Tenant::id(), $to->format('Y-m-d 00:00:00'), $from->format('Y-m-d 00:00:00')];
        if ($areaId !== null) {
            $parameters[] = $areaId;
        }
        $query->execute($parameters);

        $byDay = [];
        for ($day = 0; $day < $days; $day++) {
            $byDay[$from->modify('+' . $day . ' days')->format('Y-m-d')] = [];
        }
        foreach ($query->fetchAll() as $booking) {
            $day = substr((string) $booking['starts_at'], 0, 10);
            if (isset($byDay[$day])) {
                $byDay[$day][] = $booking;
            }
        }

        return $byDay;
    }

    /* ------------------------------ Solicitudes del residente ------------------------------ */

    public function request(int $userId, int $areaId, string $date, string $start, string $end, string $guests, string $notes): int
    {
        $area = $this->area($areaId, true);
        [$startsAt, $endsAt] = $this->validateSlot($area, $date, $start, $end);
        $notes = trim($notes);
        if (mb_strlen($notes, 'UTF-8') > 255) {
            throw new RuntimeException('Las observaciones admiten hasta 255 caracteres.');
        }
        $guestCount = null;
        if (trim($guests) !== '') {
            $guestCount = filter_var($guests, FILTER_VALIDATE_INT);
            if (!is_int($guestCount) || $guestCount < 1 || $guestCount > 10000) {
                throw new RuntimeException('Indica un número de asistentes válido.');
            }
            if ($area['capacity'] !== null && $guestCount > (int) $area['capacity']) {
                throw new RuntimeException('El aforo de «' . $area['name'] . '» es de ' . (int) $area['capacity'] . ' personas.');
            }
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->lockArea($pdo, $areaId);
            $approved = $this->overlapping($pdo, $areaId, $startsAt, $endsAt, ['approved']);
            if ($approved !== []) {
                throw new RuntimeException('Ese horario ya está reservado (' . self::range($approved[0]) . '). Elige otro en la agenda.');
            }
            $own = $pdo->prepare(
                "SELECT COUNT(*) FROM common_area_bookings
                 WHERE tenant_id = ? AND area_id = ? AND user_id = ? AND status = 'pending' AND starts_at < ? AND ends_at > ?"
            );
            $own->execute([Tenant::id(), $areaId, $userId, $endsAt, $startsAt]);
            if ((int) $own->fetchColumn() > 0) {
                throw new RuntimeException('Ya tienes una solicitud pendiente que se cruza con ese horario.');
            }
            $pdo->prepare(
                'INSERT INTO common_area_bookings (tenant_id, area_id, user_id, starts_at, ends_at, guests, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([Tenant::id(), $areaId, $userId, $startsAt, $endsAt, $guestCount, $notes]);
            $bookingId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $account = UserNotifier::account($userId);
        $summary = sprintf('%s · %s', $area['name'], self::range(['starts_at' => $startsAt, 'ends_at' => $endsAt]));
        UserNotifier::afterResponse(fn () => $this->notifier->mailAdministration(
            'Solicitud de zona común por aprobar · ' . $summary,
            sprintf(
                "%s solicitó %s.%s\n\nApruébala o recházala en Zonas comunes > Solicitudes del panel administrativo.",
                $account['username'] ?? 'Un residente',
                $summary,
                $notes !== '' ? "\nObservaciones: " . $notes : ''
            )
        ));

        return $bookingId;
    }

    /** El residente cancela una solicitud pendiente o una reserva aprobada que aún no empieza. */
    public function cancel(int $bookingId, int $userId): void
    {
        $booking = $this->booking($bookingId);
        if ((int) $booking['user_id'] !== $userId) {
            throw new RuntimeException('La reserva no existe.');
        }
        if (!in_array($booking['status'], ['pending', 'approved'], true) || $booking['starts_at'] <= self::now()) {
            throw new RuntimeException('Solo puedes cancelar solicitudes o reservas que aún no han comenzado.');
        }
        Database::connection()->prepare(
            "UPDATE common_area_bookings SET status = 'cancelled' WHERE id = ? AND tenant_id = ? AND status IN ('pending', 'approved')"
        )->execute([$bookingId, Tenant::id()]);

        if ($booking['status'] === 'approved') {
            UserNotifier::afterResponse(fn () => $this->notifier->mailAdministration(
                'Reserva cancelada · ' . $booking['area_name'],
                sprintf('%s canceló su reserva aprobada de %s (%s). El horario quedó libre.', $booking['username'], $booking['area_name'], self::range($booking))
            ));
        }
    }

    public function forUser(int $userId): array
    {
        $query = Database::connection()->prepare(
            'SELECT bookings.*, areas.name AS area_name
             FROM common_area_bookings AS bookings
             JOIN common_areas AS areas ON areas.id = bookings.area_id
             WHERE bookings.tenant_id = ? AND bookings.user_id = ?
             ORDER BY bookings.starts_at DESC
             LIMIT 50'
        );
        $query->execute([Tenant::id(), $userId]);

        return $query->fetchAll();
    }

    /* ------------------------------ Decisión de la administración ------------------------------ */

    /**
     * Solicitudes pendientes que aún no empiezan, con lo que se cruza: «blocking» (aprobadas: impiden aprobar) y
     * «competing» (otras pendientes: se rechazarán si se aprueba esta).
     */
    public function pendingRequests(): array
    {
        $query = Database::connection()->prepare(
            "SELECT bookings.*, areas.name AS area_name, areas.capacity, users.username, users.email
             FROM common_area_bookings AS bookings
             JOIN common_areas AS areas ON areas.id = bookings.area_id
             JOIN admin_users AS users ON users.id = bookings.user_id
             WHERE bookings.tenant_id = ? AND bookings.status = 'pending' AND bookings.starts_at > ?
             ORDER BY bookings.starts_at, bookings.created_at"
        );
        $query->execute([Tenant::id(), self::now()]);
        $pdo = Database::connection();
        $requests = [];
        foreach ($query->fetchAll() as $booking) {
            $booking['blocking'] = $this->overlapping($pdo, (int) $booking['area_id'], $booking['starts_at'], $booking['ends_at'], ['approved']);
            $booking['competing'] = $this->overlapping($pdo, (int) $booking['area_id'], $booking['starts_at'], $booking['ends_at'], ['pending'], (int) $booking['id']);
            $requests[] = $booking;
        }

        return $requests;
    }

    public function pendingCount(): int
    {
        try {
            return (int) $this->count(
                "SELECT COUNT(*) FROM common_area_bookings WHERE tenant_id = ? AND status = 'pending' AND starts_at > ?",
                [Tenant::id(), self::now()]
            );
        } catch (PDOException) {
            return 0; // Sin la migración 028 no hay reservas.
        }
    }

    /** @return int solicitudes rechazadas automáticamente por cruzarse con la aprobada */
    public function approve(int $bookingId, string $notes, int $actorId): int
    {
        $notes = trim($notes);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $booking = $this->booking($bookingId);
            $this->lockArea($pdo, (int) $booking['area_id']);
            $booking = $this->booking($bookingId, true);
            if ($booking['status'] !== 'pending') {
                throw new RuntimeException('La solicitud ya fue decidida o cancelada.');
            }
            if ($booking['starts_at'] <= self::now()) {
                throw new RuntimeException('La solicitud es para un horario que ya pasó.');
            }
            $blocking = $this->overlapping($pdo, (int) $booking['area_id'], $booking['starts_at'], $booking['ends_at'], ['approved']);
            if ($blocking !== []) {
                throw new RuntimeException(sprintf(
                    'No se puede aprobar: se cruza con la reserva aprobada de %s (%s).',
                    $blocking[0]['username'],
                    self::range($blocking[0])
                ));
            }
            $decide = $pdo->prepare(
                'UPDATE common_area_bookings SET status = ?, decision_notes = ?, decided_by = ?, decided_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND tenant_id = ?'
            );
            $decide->execute(['approved', $notes !== '' ? mb_substr($notes, 0, 255, 'UTF-8') : null, $actorId, $bookingId, Tenant::id()]);
            $competing = $this->overlapping($pdo, (int) $booking['area_id'], $booking['starts_at'], $booking['ends_at'], ['pending'], $bookingId);
            $autoNote = 'Se aprobó otra solicitud para ese horario.';
            foreach ($competing as $other) {
                $decide->execute(['rejected', $autoNote, $actorId, (int) $other['id'], Tenant::id()]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $this->notifyDecision($booking, 'approved', $notes);
        foreach ($competing as $other) {
            $this->notifyDecision($other + ['area_name' => $booking['area_name']], 'rejected', $autoNote);
        }

        return count($competing);
    }

    public function reject(int $bookingId, string $reason, int $actorId): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 255) {
            throw new RuntimeException('Explica al residente el motivo del rechazo (entre 5 y 255 caracteres).');
        }
        $booking = $this->booking($bookingId);
        $update = Database::connection()->prepare(
            "UPDATE common_area_bookings SET status = 'rejected', decision_notes = ?, decided_by = ?, decided_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $update->execute([$reason, $actorId, $bookingId, Tenant::id()]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('La solicitud ya fue decidida o cancelada.');
        }
        $this->notifyDecision($booking, 'rejected', $reason);
    }

    /* ---------------------------------- Apoyo ---------------------------------- */

    /** Aviso en el portal y correo al residente con la decisión. */
    private function notifyDecision(array $booking, string $status, string $notes): void
    {
        $approved = $status === 'approved';
        $title = ($approved ? 'Reserva aprobada: ' : 'Reserva no aprobada: ') . $booking['area_name'];
        $body = self::range($booking) . ($notes !== '' ? ' · ' . $notes : '');
        $userId = (int) $booking['user_id'];
        UserNotifier::afterResponse(function () use ($userId, $approved, $title, $body): void {
            $this->notifier->notice($userId, $approved ? 'booking_approved' : 'booking_rejected', $title, $body, '/usuario/zonas-comunes#mis-reservas');
            $account = UserNotifier::account($userId);
            if ($account !== null) {
                $this->notifier->mail((string) $account['email'], (string) $account['username'], $title, $title . "\n\n" . $body);
            }
        });
    }

    /**
     * Fecha y horas de una solicitud, dentro del horario del espacio, la duración máxima y la anticipación permitida.
     *
     * @return array{0: string, 1: string} inicio y fin (Y-m-d H:i:s)
     */
    private function validateSlot(array $area, string $date, string $start, string $end): array
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $date || !self::validTime($start) || !self::validTime($end)) {
            throw new RuntimeException('Indica la fecha y las horas de inicio y fin.');
        }
        if ($start >= $end) {
            throw new RuntimeException('La hora de fin debe ser posterior a la de inicio.');
        }
        $opens = substr((string) $area['opens_at'], 0, 5);
        $closes = substr((string) $area['closes_at'], 0, 5);
        if ($start < $opens || $end > $closes) {
            throw new RuntimeException('«' . $area['name'] . '» se puede usar de ' . $opens . ' a ' . $closes . '.');
        }
        $startsAt = $date . ' ' . $start . ':00';
        $endsAt = $date . ' ' . $end . ':00';
        $minutes = (strtotime($endsAt) - strtotime($startsAt)) / 60;
        if ($minutes > (int) $area['max_hours'] * 60) {
            throw new RuntimeException('Cada reserva puede durar máximo ' . (int) $area['max_hours'] . ' hora(s).');
        }
        if ($startsAt <= self::now()) {
            throw new RuntimeException('Elige un horario que aún no haya pasado.');
        }
        $limit = (new DateTimeImmutable('today'))->modify('+' . (int) $area['max_days_ahead'] . ' days')->format('Y-m-d');
        if ($date > $limit) {
            throw new RuntimeException('Se puede reservar con máximo ' . (int) $area['max_days_ahead'] . ' días de anticipación.');
        }

        return [$startsAt, $endsAt];
    }

    /** Reservas del espacio en los estados dados que se cruzan con el intervalo [inicio, fin). */
    private function overlapping(PDO $pdo, int $areaId, string $startsAt, string $endsAt, array $statuses, int $excludeId = 0): array
    {
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
        $query = $pdo->prepare(
            "SELECT bookings.id, bookings.user_id, bookings.starts_at, bookings.ends_at, bookings.status, users.username
             FROM common_area_bookings AS bookings
             JOIN admin_users AS users ON users.id = bookings.user_id
             WHERE bookings.tenant_id = ? AND bookings.area_id = ? AND bookings.id <> ?
               AND bookings.status IN ({$placeholders}) AND bookings.starts_at < ? AND bookings.ends_at > ?
             ORDER BY bookings.starts_at"
        );
        $query->execute([Tenant::id(), $areaId, $excludeId, ...$statuses, $endsAt, $startsAt]);

        return $query->fetchAll();
    }

    /** Serializa solicitudes y aprobaciones del mismo espacio para que dos decisiones simultáneas no se crucen. */
    private function lockArea(PDO $pdo, int $areaId): void
    {
        $pdo->prepare('SELECT id FROM common_areas WHERE id = ? AND tenant_id = ? FOR UPDATE')->execute([$areaId, Tenant::id()]);
    }

    private function booking(int $bookingId, bool $forUpdate = false): array
    {
        $query = Database::connection()->prepare(
            'SELECT bookings.*, areas.name AS area_name, users.username
             FROM common_area_bookings AS bookings
             JOIN common_areas AS areas ON areas.id = bookings.area_id
             JOIN admin_users AS users ON users.id = bookings.user_id
             WHERE bookings.id = ? AND bookings.tenant_id = ?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $query->execute([$bookingId, Tenant::id()]);
        $booking = $query->fetch();
        if (!is_array($booking)) {
            throw new RuntimeException('La reserva no existe.');
        }

        return $booking;
    }

    private function count(string $sql, array $parameters): int
    {
        $query = Database::connection()->prepare($sql);
        $query->execute($parameters);

        return (int) $query->fetchColumn();
    }

    /** «sáb 29/11/2026 · 14:00–18:00» */
    public static function range(array $booking): string
    {
        $start = new DateTimeImmutable((string) $booking['starts_at']);
        $end = new DateTimeImmutable((string) $booking['ends_at']);
        $days = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];

        return $days[(int) $start->format('w')] . ' ' . $start->format('d/m/Y') . ' · ' . $start->format('H:i') . '–' . $end->format('H:i');
    }

    private static function validTime(string $time): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time) === 1;
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }
}
