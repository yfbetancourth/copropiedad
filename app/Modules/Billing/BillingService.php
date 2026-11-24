<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Auth\SmtpMailer;
use App\Modules\Configuration\CompositionService;
use App\Modules\Configuration\MailSettingsService;
use App\Modules\Configuration\SettingsService;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class BillingService
{
    private const PROVIDER_PAYMENT_TABLES = ['billing_online_payments', 'billing_bold_terminal_payments', 'billing_wompi_payments'];

    private const ITEM_COLUMNS = [
        'interest' => 'interest',
        'legal' => 'legal',
        'fine' => 'fine',
        'retroactive' => 'retroactive',
        'extra' => 'extra',
        'other' => 'other',
    ];

    private const ITEM_LABELS = [
        'opening_balance' => 'Saldo inicial / ajuste de saldo anterior',
        'interest' => 'Interés moratorio',
        'legal' => 'Cobro por proceso jurídico',
        'fine' => 'Multas',
        'retroactive' => 'Retroactivo',
        'extra' => 'Cuota extra',
        'other' => 'Otros cobros',
    ];

    public function preview(string $period, ?int $actorId = null): array
    {
        $periodDate = self::periodDate($period);
        $currentMonth = new DateTimeImmutable('first day of this month');
        if ($periodDate > $currentMonth) {
            throw new RuntimeException('Solo se pueden generar cobros del mes actual o de meses anteriores.');
        }

        $propertySettings = (new SettingsService())->current();
        $mailSettings = (new MailSettingsService())->current();
        $units = $this->assignedUnits();
        $existing = $this->statementMap($period);
        $items = $this->itemMap($period);
        $previousPeriod = $periodDate->modify('-1 month')->format('Y-m');
        $priorStatements = $this->latestStatementsBefore($period);
        $previousPayments = $this->paymentMap(array_values($priorStatements));
        $fee = self::cents($propertySettings['administration_fee'] ?? '0');
        $configuredDiscount = self::cents($propertySettings['early_payment_discount'] ?? '0');
        $discountDay = (int) ($propertySettings['early_payment_day'] ?? 0);
        $dueDay = (int) ($propertySettings['payment_due_day'] ?? 0);
        $dateSettingsReady = $discountDay >= 1 && $discountDay <= 28
            && $dueDay >= $discountDay && $dueDay <= 28;
        $bankSettingsReady = trim((string) ($propertySettings['account_holder'] ?? '')) !== ''
            && trim((string) ($propertySettings['bank_name'] ?? '')) !== ''
            && trim((string) ($propertySettings['account_type'] ?? '')) !== ''
            && trim((string) ($propertySettings['account_number'] ?? '')) !== '';
        $personalMail = $actorId !== null ? (new \App\Modules\Configuration\PersonalMailService())->current($actorId) : null;
        $mailSettingsReady = ($personalMail['active'] ?? false) || trim($mailSettings['host']) !== ''
            && (int) $mailSettings['port'] >= 1 && (int) $mailSettings['port'] <= 65535
            && trim($mailSettings['username']) !== ''
            && $mailSettings['password_configured']
            && in_array($mailSettings['encryption'], ['tls', 'ssl'], true)
            && filter_var($mailSettings['from_address'], FILTER_VALIDATE_EMAIL) !== false;
        $feeReady = ($propertySettings['administration_fee'] ?? '') !== ''
            && ($propertySettings['effective_date'] ?? '') !== '';
        $periodStart = $periodDate->format('Y-m-d');
        $periodEnd = $periodDate->modify('last day of this month')->format('Y-m-d');
        $feeStartsMidPeriod = $feeReady
            && $propertySettings['effective_date'] > $periodStart
            && $propertySettings['effective_date'] <= $periodEnd;
        $feeNotYetEffective = $feeReady && $propertySettings['effective_date'] > $periodEnd;
        // Valor mensual del presupuesto: se reparte entre todos los inmuebles de la conformación (tengan o no
        // residentes asignados), así la suma de las cuotas es el presupuesto.
        $budgetShares = ($propertySettings['fee_basis'] ?? 'base') === 'budget'
            ? self::budgetShares($fee, (new CompositionService())->current()['units'] ?? [])
            : null;

        $rows = [];
        $cycleError = false;
        foreach ($units as $unit) {
            $unitId = $unit['id'];
            $recipients = $unit['recipients'];
            $prior = $priorStatements[$unitId] ?? null;
            $existingStatement = $existing[$unitId] ?? null;
            $unitItems = $items[$unitId] ?? [];
            $itemAmounts = array_fill_keys(array_keys(self::ITEM_COLUMNS), 0);
            $openingAdjustment = 0;
            foreach ($unitItems as $item) {
                if ($item['category'] === 'opening_balance') {
                    $openingAdjustment += self::cents($item['amount']);
                } elseif (isset($itemAmounts[$item['category']])) {
                    $itemAmounts[$item['category']] += self::cents($item['amount']);
                }
            }

            $priorPeriodInvalid = $prior !== null && $prior['period'] !== $previousPeriod;
            $cycleError = $cycleError || $priorPeriodInvalid;
            $previous = $this->previousMovement($prior, $previousPayments[$prior['id'] ?? 0] ?? null);
            if ($prior === null && $openingAdjustment > 0) {
                $previous['opening_balance'] = $openingAdjustment;
                $previous['total'] = $openingAdjustment;
                $previous['balance'] = $openingAdjustment;
            }
            // Sin nota anterior, el ajuste es el saldo inicial: sumarlo otra vez lo duplicaba.
            $openingBalance = $prior === null
                ? $openingAdjustment
                : $previous['balance'] + $openingAdjustment;
            $effectiveDate = (string) ($propertySettings['effective_date'] ?? '');
            $feeApplies = $feeReady && $effectiveDate <= $periodStart && !$feeNotYetEffective;
            $coefficient = $existingStatement !== null
                ? self::normalizedCoefficient($existingStatement['coefficient'] ?? null)
                : self::normalizedCoefficient($unit['coefficient']);
            $coefficientMissing = $coefficient === null && $existingStatement === null;
            $currentFee = $existingStatement !== null
                ? self::cents($existingStatement['administration_fee'])
                : ($feeApplies && $coefficient !== null
                    ? ($budgetShares !== null ? ($budgetShares[$unitId] ?? 0) : self::allocatedFeeCents($fee, $coefficient))
                    : 0);
            $total = $openingBalance + $currentFee + array_sum($itemAmounts);
            $discount = min($configuredDiscount, $currentFee);
            $earlyPayable = max(0, $total - $discount);
            $invalidRecipient = $recipients === [];
            foreach ($recipients as $recipient) {
                if (filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) === false) {
                    $invalidRecipient = true;
                }
            }

            $rows[] = [
                'id' => $unitId,
                'name' => $unit['name'],
                'category' => $unit['category'],
                'coefficient' => $coefficient,
                'coefficient_missing' => $coefficientMissing,
                'recipients' => $recipients,
                'already_issued' => $existingStatement !== null,
                'statement_id' => $existingStatement['id'] ?? null,
                'delivery_status' => $existingStatement['delivery_status'] ?? null,
                'prior_period_invalid' => $priorPeriodInvalid,
                'invalid_recipient' => $invalidRecipient,
                'previous' => $previous,
                'opening_adjustment' => $openingAdjustment,
                'opening_balance' => $openingBalance,
                'fee' => $currentFee,
                'items' => $itemAmounts,
                'item_details' => $unitItems,
                'total' => $total,
                'due' => max(0, $total),
                'credit' => max(0, -$total),
                'early_discount' => $discount,
                'early_payable' => $earlyPayable,
            ];
        }

        $eligibleRows = array_filter(
            $rows,
            static fn (array $row): bool => !$row['already_issued']
                && !$row['prior_period_invalid']
                && !$row['invalid_recipient']
                && !$row['coefficient_missing']
        );
        $blockingReasons = [];
        if (!$dateSettingsReady) {
            $blockingReasons[] = 'Configura los días de pronto pago y vencimiento en Parametrización.';
        }
        if (!$bankSettingsReady) {
            $blockingReasons[] = 'Completa los datos bancarios en Parametrización.';
        }
        if (!$mailSettingsReady) {
            $blockingReasons[] = 'Completa y prueba la configuración SMTP en Configuración / Correo, o activa tu correo personal de envío.';
        }
        if (!$feeReady) {
            $blockingReasons[] = 'Configura la cuota y su fecha de vigencia en Configuración / Financiera.';
        }
        if (array_filter($rows, static fn (array $row): bool => $row['coefficient_missing']) !== []) {
            $blockingReasons[] = 'Completa el coeficiente (%) de cada inmueble en Configuración / Conformación antes de emitir.';
        }
        if ($feeStartsMidPeriod) {
            $blockingReasons[] = 'La cuota empieza a mitad del período. Define el tratamiento de prorrata antes de emitir; el sistema no la calcula automáticamente.';
        } elseif ($feeNotYetEffective) {
            $blockingReasons[] = 'La cuota aún no está vigente en el período seleccionado.';
        }
        if ($cycleError) {
            $blockingReasons[] = 'Emite los meses anteriores en orden antes de continuar.';
        }
        if ($rows === []) {
            $blockingReasons[] = 'No hay inmuebles con usuarios activos asignados.';
        }
        if (count($eligibleRows) === 0 && $rows !== [] && !$cycleError) {
            $blockingReasons[] = 'No hay notas nuevas para emitir en este período.';
        }
        foreach ($rows as $row) {
            if ($row['invalid_recipient'] && !$row['already_issued']) {
                $blockingReasons[] = 'Corrige o asigna usuarios activos con correo válido a todos los inmuebles antes de emitir.';
                break;
            }
        }
        $warnings = [];
        $pendingOnline = $this->pendingOnlinePaymentsBefore($period);
        if ($pendingOnline > 0) {
            $warnings[] = sprintf(
                'Hay %d pago(s) en línea o en datáfono pendientes de confirmación sobre notas anteriores. Si se aprueban después de emitir, quedarán en la nota anterior y deberás registrar un ajuste en este período.',
                $pendingOnline
            );
        }

        return [
            'period' => $period,
            'previous_period' => $previousPeriod,
            'rows' => $rows,
            'settings' => $propertySettings,
            'discount_day' => $discountDay,
            'due_day' => $dueDay,
            'ready' => $blockingReasons === [],
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'warnings' => $warnings,
            'new_count' => count($eligibleRows),
            'issued_count' => count(array_filter($rows, static fn (array $row): bool => $row['already_issued'])),
        ];
    }

    public function addItem(string $unitId, string $period, string $category, string $description, string $amount, int $actorId): void
    {
        $periodDate = self::periodDate($period);
        if ($periodDate > new DateTimeImmutable('first day of this month')) {
            throw new RuntimeException('No se pueden registrar conceptos en un período futuro.');
        }
        if (!isset(self::ITEM_LABELS[$category])) {
            throw new RuntimeException('Selecciona un concepto válido.');
        }
        if ($description === '' || strlen($description) > 160) {
            throw new RuntimeException('La descripción es obligatoria y admite hasta 160 caracteres.');
        }
        $amountCents = self::cents($amount);
        if ($amountCents < 1 || $amountCents > 999999999999999) {
            throw new RuntimeException('El valor debe ser mayor que cero y no superar $ 9.999.999.999.999,99.');
        }

        $pdo = Database::connection();
        if (!isset($this->unitMap()[$unitId])) {
            throw new RuntimeException('El inmueble seleccionado no pertenece a la estructura actual.');
        }
        $pdo->beginTransaction();
        try {
            $this->lockPeriodItems($pdo, $period);
            if (isset($this->statementMap($period)[$unitId])) {
                throw new RuntimeException('El período ya fue emitido para este inmueble; el documento cerrado no se puede modificar.');
            }
            $pdo->prepare(
                'INSERT INTO billing_monthly_items (tenant_id, unit_id, period, category, description, amount, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([Tenant::id(), $unitId, $period, $category, $description, self::decimal($amountCents), $actorId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Registra un pago y programa su aviso al residente y a la administración.
     *
     * @param string $origin manual (personal de cartera) o report (pago reportado y aprobado)
     */
    public function addPayment(int $statementId, string $amount, string $receivedOn, string $reference, string $notes, int $actorId, string $origin = 'manual'): int
    {
        $amountCents = self::cents($amount);
        if ($amountCents < 1 || $amountCents > 999999999999999) {
            throw new RuntimeException('Ingresa un pago mayor que cero y dentro del límite permitido.');
        }
        self::validateDate($receivedOn);
        if ($receivedOn > (new DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new RuntimeException('La fecha de recepción no puede ser futura.');
        }
        if (strlen($reference) > 80 || strlen($notes) > 255) {
            throw new RuntimeException('La referencia admite hasta 80 caracteres y las notas hasta 255.');
        }

        $statement = Database::connection()->prepare(
            'SELECT id FROM billing_statements WHERE id = ? AND tenant_id = ?'
        );
        $statement->execute([$statementId, Tenant::id()]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('La nota de cobro seleccionada no existe.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id, unit_id, period FROM billing_statements WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $lock->execute([$statementId, Tenant::id()]);
            $locked = $lock->fetch();
            if (!is_array($locked)) {
                throw new RuntimeException('La nota de cobro seleccionada no existe.');
            }
            $newer = $this->newerStatement((string) $locked['unit_id'], (string) $locked['period']);
            if ($newer !== null) {
                throw new RuntimeException(sprintf(
                    'El saldo de esta nota ya se trasladó a la nota del período %s. Registra el pago en la nota más reciente del inmueble.',
                    $newer['period']
                ));
            }
            $pdo->prepare(
                'INSERT INTO billing_payments (tenant_id, statement_id, amount, received_on, reference, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([Tenant::id(), $statementId, self::decimal($amountCents), $receivedOn, $reference, $notes, $actorId]);
            $paymentId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        PaymentNotificationService::defer('payment', ['payment_id' => $paymentId, 'origin' => $origin]);

        return $paymentId;
    }

    /**
     * Anula un pago manual registrado por error. El registro se conserva con quién, cuándo y por qué
     * se anuló; nunca se borra. Solo aplica mientras el saldo de la nota no se haya trasladado.
     */
    public function voidPayment(int $paymentId, string $reason, int $actorId): void
    {
        $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
        if ($reasonLength < 5 || strlen($reason) > 255) {
            throw new RuntimeException('Indica el motivo de la anulación (entre 5 y 255 caracteres).');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare(
                'SELECT payments.id, payments.statement_id, payments.reference, payments.voided_at,
                        statements.unit_id, statements.period
                 FROM billing_payments AS payments
                 JOIN billing_statements AS statements ON statements.id = payments.statement_id
                 WHERE payments.id = ? AND payments.tenant_id = ?
                 FOR UPDATE'
            );
            $query->execute([$paymentId, Tenant::id()]);
            $payment = $query->fetch();
            if (!is_array($payment)) {
                throw new RuntimeException('El pago seleccionado no existe.');
            }
            if ($payment['voided_at'] !== null) {
                throw new RuntimeException('El pago ya estaba anulado.');
            }
            $newer = $this->newerStatement((string) $payment['unit_id'], (string) $payment['period']);
            if ($newer !== null) {
                throw new RuntimeException(sprintf(
                    'El saldo de esta nota ya se trasladó a la nota del período %s. Corrige la diferencia con un concepto de ajuste en el período vigente.',
                    $newer['period']
                ));
            }
            if ($this->isProviderPayment((int) $payment['statement_id'], (string) $payment['reference'])) {
                throw new RuntimeException('Los pagos confirmados por Bold no se anulan desde aquí: gestiona la reversión con el proveedor y registra un ajuste.');
            }
            $pdo->prepare(
                'UPDATE billing_payments
                 SET voided_at = CURRENT_TIMESTAMP, voided_by = ?, void_reason = ?
                 WHERE id = ? AND tenant_id = ? AND voided_at IS NULL'
            )->execute([$actorId, $reason, $paymentId, Tenant::id()]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Pagos (incluidos los anulados) de las notas de un período, para consulta y anulación. */
    public function periodPayments(string $period): array
    {
        self::periodDate($period);
        $query = Database::connection()->prepare(
            'SELECT payments.id, payments.statement_id, payments.amount, payments.received_on,
                    payments.reference, payments.notes, payments.created_at,
                    payments.voided_at, payments.void_reason,
                    statements.unit_name, statements.period,
                    creators.username AS created_by_name, voiders.username AS voided_by_name
             FROM billing_payments AS payments
             JOIN billing_statements AS statements ON statements.id = payments.statement_id
             LEFT JOIN admin_users AS creators ON creators.id = payments.created_by
             LEFT JOIN admin_users AS voiders ON voiders.id = payments.voided_by
             WHERE statements.tenant_id = ? AND statements.period = ?
             ORDER BY payments.received_on DESC, payments.id DESC'
        );
        $query->execute([Tenant::id(), $period]);

        return $query->fetchAll();
    }

    public function removeItem(int $itemId, string $period): void
    {
        self::periodDate($period);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->lockPeriodItems($pdo, $period);
            $query = $pdo->prepare(
                'SELECT unit_id FROM billing_monthly_items WHERE id = ? AND tenant_id = ? AND period = ?'
            );
            $query->execute([$itemId, Tenant::id(), $period]);
            $unitId = $query->fetchColumn();
            if ($unitId === false) {
                throw new RuntimeException('El concepto no existe o ya fue eliminado.');
            }
            if (isset($this->statementMap($period)[(string) $unitId])) {
                throw new RuntimeException('Los conceptos de un inmueble con nota emitida no se pueden eliminar.');
            }
            $delete = $pdo->prepare(
                'DELETE FROM billing_monthly_items WHERE id = ? AND tenant_id = ? AND period = ?'
            );
            $delete->execute([$itemId, Tenant::id(), $period]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('El concepto no existe o ya fue eliminado.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function issue(string $period, int $actorId): array
    {
        $periodDate = self::periodDate($period);
        $this->assignedUnits();
        $previousPeriod = $periodDate->modify('-1 month')->format('Y-m');
        (new MailSettingsService())->credentials();

        $pdo = Database::connection();
        $statementIds = [];
        $pdo->beginTransaction();
        try {
            $this->lockPeriodItems($pdo, $period);
            $lockPrevious = $pdo->prepare(
                'SELECT id FROM billing_statements WHERE tenant_id = ? AND period = ? FOR UPDATE'
            );
            $lockPrevious->execute([Tenant::id(), $previousPeriod]);
            $lockPrevious->fetchAll();
            $preview = $this->preview($period, $actorId);
            if (!$preview['ready']) {
                throw new RuntimeException(implode(' ', $preview['blocking_reasons']));
            }
            foreach ($preview['rows'] as $row) {
                if ($row['already_issued']) {
                    continue;
                }
                $data = $this->statementData($row, $preview);
                $fields = array_keys($data);
                $insert = $pdo->prepare(
                    'INSERT INTO billing_statements (' . implode(', ', $fields) . ', issued_by)
                     VALUES (' . implode(', ', array_fill(0, count($fields) + 1, '?')) . ')'
                );
                $insert->execute([...array_values($data), $actorId]);
                $statementId = (int) $pdo->lastInsertId();
                $statementIds[] = $statementId;
                $recipientInsert = $pdo->prepare(
                    'INSERT INTO billing_statement_recipients (statement_id, user_id, recipient_email, recipient_name)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($row['recipients'] as $recipient) {
                    $recipientInsert->execute([
                        $statementId,
                        $recipient['id'],
                        $recipient['email'],
                        $recipient['username'],
                    ]);
                }

                $lineInsert = $pdo->prepare(
                    'INSERT INTO billing_statement_lines (statement_id, category, description, amount)
                     VALUES (?, ?, ?, ?)'
                );
                if ($row['fee'] > 0) {
                    $lineInsert->execute([
                        $statementId,
                        'administration',
                        'Cuota de administración ' . $preview['period'],
                        self::decimal($row['fee']),
                    ]);
                }
                foreach ($row['item_details'] as $item) {
                    if ($item['category'] !== 'opening_balance') {
                        $lineInsert->execute([
                            $statementId,
                            $item['category'],
                            $item['description'],
                            $item['amount'],
                        ]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $delivery = $this->sendPeriod($period, $actorId);

        return [
            'issued' => count($statementIds),
            'sent' => $delivery['sent'],
            'failed' => $delivery['failed'],
            'pending' => $delivery['pending'],
        ];
    }

    /**
     * Cuenta desde la que salen los correos que dispara un administrador: su correo personal
     * (p. ej. certificado) si lo tiene activo y verificado; si no, la cuenta general.
     *
     * @return array{settings: array, address: string, name: string, personal: bool}
     */
    public function senderFor(?int $actorId): array
    {
        $personal = $actorId !== null ? (new \App\Modules\Configuration\PersonalMailService())->activeCredentials($actorId) : null;
        $settings = $personal ?? (new MailSettingsService())->credentials();

        return [
            'settings' => $settings,
            'address' => (string) $settings['from_address'],
            'name' => (string) $settings['from_name'],
            'personal' => $personal !== null,
        ];
    }

    public function sendPeriod(string $period, ?int $actorId = null): array
    {
        self::periodDate($period);
        $sender = $this->senderFor($actorId);
        $settings = $sender['settings'];
        $query = Database::connection()->prepare(
            "SELECT recipients.id AS recipient_id, recipients.recipient_email, recipients.recipient_name,
                    statements.id AS statement_id, statements.period
             FROM billing_statement_recipients AS recipients
             JOIN billing_statements AS statements ON statements.id = recipients.statement_id
             WHERE statements.tenant_id = ? AND statements.period = ?
               AND (
                    recipients.delivery_status IN ('pending', 'failed')
                    OR (recipients.delivery_status = 'sending'
                        AND recipients.delivery_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE))
               )
             ORDER BY statements.id, recipients.id"
        );
        $query->execute([Tenant::id(), $period]);
        $recipients = array_slice($query->fetchAll(), 0, 50);
        $sent = 0;
        foreach ($recipients as $recipient) {
            $claim = Database::connection()->prepare(
                "UPDATE billing_statement_recipients
                 SET delivery_status = 'sending', delivery_attempts = delivery_attempts + 1,
                     delivery_started_at = CURRENT_TIMESTAMP, last_error = NULL
                 WHERE id = ? AND (
                    delivery_status IN ('pending', 'failed')
                    OR (delivery_status = 'sending'
                        AND delivery_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE))
                 )"
            );
            $claim->execute([(int) $recipient['recipient_id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }

            try {
                $statement = $this->statement((int) $recipient['statement_id']);
                (new SmtpMailer())->send(
                    $settings,
                    (string) $recipient['recipient_email'],
                    (string) $recipient['recipient_name'],
                    sprintf('Nota de cobro %s · %s', self::statementNumber((string) $statement['period'], (int) $statement['id']), $statement['period']),
                    $this->statementHtml($statement),
                    $this->statementText($statement)
                );
                $this->markRecipientSent((int) $recipient['recipient_id'], $sender['address'], $actorId);
                $sent++;
            } catch (\PHPMailer\PHPMailer\Exception|RuntimeException $exception) {
                error_log('Fallo al notificar una nota de cobro: ' . $exception->getMessage());
                Database::connection()->prepare(
                    "UPDATE billing_statement_recipients
                     SET delivery_status = 'failed', delivery_started_at = NULL, last_error = ?
                     WHERE id = ?"
                )->execute([
                    mb_substr('No se pudo enviar desde ' . $sender['address'] . '. Revisa la cuenta SMTP y reintenta.', 0, 255, 'UTF-8'),
                    (int) $recipient['recipient_id'],
                ]);
            }
        }

        $remaining = Database::connection()->prepare(
            "SELECT delivery_status, COUNT(*) AS status_count
             FROM billing_statement_recipients AS recipients
             JOIN billing_statements AS statements ON statements.id = recipients.statement_id
             WHERE statements.tenant_id = ? AND statements.period = ?
               AND recipients.delivery_status IN ('pending', 'failed', 'sending')
             GROUP BY delivery_status"
        );
        $remaining->execute([Tenant::id(), $period]);
        $remainingCounts = ['pending' => 0, 'failed' => 0];
        foreach ($remaining->fetchAll() as $row) {
            $remainingCounts[$row['delivery_status']] = (int) $row['status_count'];
        }

        return [
            'sent' => $sent,
            'failed' => $remainingCounts['failed'],
            'pending' => $remainingCounts['pending'],
        ];
    }

    /** Marca el envío como hecho y deja constancia de la cuenta remitente y de quién lo envió. */
    private function markRecipientSent(int $recipientId, string $fromAddress, ?int $actorId): void
    {
        try {
            Database::connection()->prepare(
                "UPDATE billing_statement_recipients
                 SET delivery_status = 'sent', sent_at = CURRENT_TIMESTAMP, delivery_started_at = NULL,
                     sent_from = ?, sent_by = ?
                 WHERE id = ?"
            )->execute([$fromAddress, $actorId, $recipientId]);
        } catch (\PDOException) {
            // Sin la migración 021 no existen las columnas de trazabilidad; el envío igual se registra.
            Database::connection()->prepare(
                "UPDATE billing_statement_recipients
                 SET delivery_status = 'sent', sent_at = CURRENT_TIMESTAMP, delivery_started_at = NULL
                 WHERE id = ?"
            )->execute([$recipientId]);
        }
    }

    public function statements(string $period): array
    {
        $query = Database::connection()->prepare(
            'SELECT statements.*,
                    (SELECT COUNT(*) FROM billing_statement_recipients r WHERE r.statement_id = statements.id) AS recipient_count,
                    (SELECT COUNT(*) FROM billing_statement_recipients r WHERE r.statement_id = statements.id AND r.delivery_status = "sent") AS sent_count,
                    (SELECT COUNT(*) FROM billing_statement_recipients r WHERE r.statement_id = statements.id AND r.delivery_status = "failed") AS failed_count,
                    (SELECT COUNT(*) FROM billing_statement_recipients r WHERE r.statement_id = statements.id AND r.delivery_status IN ("pending", "sending")) AS pending_count,
                    (SELECT COALESCE(SUM(payments.amount), 0) FROM billing_payments payments WHERE payments.statement_id = statements.id AND payments.voided_at IS NULL) AS payments_total,
                    (SELECT COALESCE(SUM(payments.amount), 0) FROM billing_payments payments WHERE payments.statement_id = statements.id AND payments.voided_at IS NULL AND payments.received_on <= statements.early_payment_date) AS early_payments_total,
                    (SELECT MIN(newer.period) FROM billing_statements newer WHERE newer.tenant_id = statements.tenant_id AND newer.unit_id = statements.unit_id AND newer.period > statements.period) AS superseded_by_period
             FROM billing_statements statements
             WHERE statements.tenant_id = ? AND statements.period = ?
             ORDER BY statements.unit_name'
        );
        $query->execute([Tenant::id(), $period]);

        return $query->fetchAll();
    }

    public function statement(int $statementId): array
    {
        $query = Database::connection()->prepare(
            'SELECT * FROM billing_statements WHERE id = ? AND tenant_id = ?'
        );
        $query->execute([$statementId, Tenant::id()]);
        $statement = $query->fetch();
        if (!is_array($statement)) {
            throw new RuntimeException('La nota de cobro no existe.');
        }
        $statement['lines'] = $this->statementLines($statementId);
        $statement['recipients'] = $this->statementRecipients($statementId);
        $statement['payments'] = $this->statementPayments($statementId);
        $validPayments = array_filter(
            $statement['payments'],
            static fn (array $payment): bool => $payment['voided_at'] === null
        );
        $statement['payments_total'] = array_sum(array_map(
            static fn (array $payment): int => self::cents($payment['amount']),
            $validPayments
        ));
        $earlyPayments = array_sum(array_map(
            static fn (array $payment): int => $payment['received_on'] <= $statement['early_payment_date']
                ? self::cents($payment['amount'])
                : 0,
            $validPayments
        ));
        $settlement = self::settle(
            self::cents($statement['total_due']),
            self::cents($statement['early_discount']),
            $statement['payments_total'],
            $earlyPayments
        );
        $statement['discount_applied'] = $settlement['discount'];
        $statement['balance'] = $settlement['balance'];
        $statement['number'] = self::statementNumber((string) $statement['period'], (int) $statement['id']);
        // Una nota con una nota posterior del mismo inmueble ya trasladó su saldo: no se paga ni se ajusta.
        $statement['superseded_by'] = $this->newerStatement((string) $statement['unit_id'], (string) $statement['period']);
        $statement['payable_balance'] = $statement['superseded_by'] === null ? max(0, $settlement['balance']) : 0;

        return $statement;
    }

    /**
     * Nota de un inmueble tal como quedaría al emitir el período, sin guardar nada: los mismos datos que usa
     * issue() (statementData, líneas y destinatarios). El número definitivo se asigna al emitir.
     *
     * @return array{statement: array, subject: string, recipients: list<array{name: string, email: string}>}
     */
    public function draftStatement(string $period, string $unitId, ?int $actorId = null): array
    {
        $preview = $this->preview($period, $actorId);
        $row = null;
        foreach ($preview['rows'] as $candidate) {
            if ($candidate['id'] === $unitId) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            throw new RuntimeException('El inmueble no tiene residentes activos asignados en este período.');
        }
        if ($row['already_issued']) {
            throw new RuntimeException('La nota de este inmueble ya fue emitida: ábrela desde Notas emitidas.');
        }

        $statement = $this->statementData($row, $preview);
        $statement['id'] = 0;
        $statement['number'] = sprintf('NC-%s-(se asigna al emitir)', str_replace('-', '', $period));
        $statement['lines'] = [];
        if ($row['fee'] > 0) {
            $statement['lines'][] = ['category' => 'administration', 'description' => 'Cuota de administración ' . $period, 'amount' => self::decimal($row['fee'])];
        }
        foreach ($row['item_details'] as $item) {
            if ($item['category'] !== 'opening_balance') {
                $statement['lines'][] = ['category' => $item['category'], 'description' => $item['description'], 'amount' => $item['amount']];
            }
        }
        $statement['recipients'] = array_map(
            static fn (array $recipient): array => ['recipient_name' => $recipient['username'], 'recipient_email' => $recipient['email']],
            $row['recipients']
        );

        return [
            'statement' => $statement,
            'subject' => sprintf('Nota de cobro %s · %s', $statement['number'], $period),
            'recipients' => array_map(
                static fn (array $recipient): array => ['name' => (string) $recipient['username'], 'email' => (string) $recipient['email']],
                $row['recipients']
            ),
        ];
    }

    public function statementHtml(array $statement): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars(
            is_scalar($value) ? (string) $value : '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $currency = static fn (mixed $cents): string => self::money((int) $cents);
        $cell = 'padding:1px 6px;border-bottom:1px solid #e3e7ee;line-height:1.35';
        // Filas: [etiqueta, centavos, estilo]. Estilo: '' normal, 'opt' (se omite en cero), 'total', 'key' (destacado).
        $rows = static function (array $items) use ($escape, $currency, $cell): string {
            $html = '';
            foreach ($items as [$label, $amount, $style]) {
                if ($style === 'opt' && $amount === 0) {
                    continue; // los conceptos sin valor no ocupan espacio en la media carta
                }
                $rowStyle = match ($style) {
                    'total' => 'font-weight:bold;background:#f1f4f9',
                    'key' => 'font-weight:bold;background:#315ea8;color:#ffffff',
                    default => '',
                };
                $html .= '<tr style="' . $rowStyle . '"><td style="' . $cell . '">' . $escape($label)
                    . '</td><td style="' . $cell . ';text-align:right;white-space:nowrap">' . $currency($amount) . '</td></tr>';
            }

            return $html;
        };
        $table = static fn (string $title, string $body, string $extra = ''): string => '<div style="font-size:9.5px;font-weight:bold;text-transform:uppercase;letter-spacing:.04em;color:#315ea8;margin:0 0 2px">'
            . $title . '</div><table role="presentation" style="width:100%;border-collapse:collapse;font-size:10.5px' . $extra . '">' . $body . '</table>';
        $previousPeriod = $statement['previous_period'] ?: 'Sin período anterior emitido';
        $previousRows = [
            ['Saldo mes anterior', self::cents($statement['previous_opening_balance']), ''],
            ['Cuota Administración', self::cents($statement['previous_fee']), ''],
            ['Interés moratorio', self::cents($statement['previous_interest']), 'opt'],
            ['Cobro por proceso jurídico', self::cents($statement['previous_legal']), 'opt'],
            ['Multas', self::cents($statement['previous_fine']), 'opt'],
            ['Retroactivo', self::cents($statement['previous_retroactive']), 'opt'],
            ['Cuota extra', self::cents($statement['previous_extra']), 'opt'],
            ['Otros cobros', self::cents($statement['previous_other']), 'opt'],
            ['Total a pagar ' . $previousPeriod, max(0, self::cents($statement['previous_total'])), 'total'],
            ['Menos pagos realizados', -self::cents($statement['previous_payments']), ''],
            ['Descuentos pronto pago', -self::cents($statement['previous_discount']), ''],
            ['Saldo ' . $previousPeriod, self::cents($statement['previous_balance']), 'total'],
        ];
        if (self::cents($statement['previous_balance']) < 0) {
            $previousRows[] = ['Saldo a favor', -self::cents($statement['previous_balance']), ''];
        }
        $feeLabel = empty($statement['coefficient'])
            ? 'Cuota Administración'
            : 'Cuota Administración (coef. ' . number_format((float) $statement['coefficient'], 4, ',', '.') . '%)';
        $earlyPayable = max(0, self::cents($statement['total_due']) - self::cents($statement['early_discount']));
        $currentRows = [
            ['Saldo mes anterior', self::cents($statement['opening_balance']), ''],
            [$feeLabel, self::cents($statement['administration_fee']), ''],
            ['Interés moratorio', self::cents($statement['interest']), 'opt'],
            ['Cobro por proceso jurídico', self::cents($statement['legal']), 'opt'],
            ['Multas', self::cents($statement['fine']), 'opt'],
            ['Retroactivo', self::cents($statement['retroactive']), 'opt'],
            ['Cuota extra', self::cents($statement['extra']), 'opt'],
            ['Otros cobros', self::cents($statement['other']), 'opt'],
            ['Total a pagar ' . $statement['period'], max(0, self::cents($statement['total_due'])), 'key'],
            ['Pagando antes del ' . $this->formatDate($statement['early_payment_date']) . ' (pronto pago)', $earlyPayable, 'total'],
        ];
        if (self::cents($statement['total_due']) < 0) {
            $currentRows[] = ['Saldo a favor', -self::cents($statement['total_due']), 'total'];
        }
        $recipients = implode(', ', array_map(
            static fn (array $recipient): string => $escape($recipient['recipient_name']),
            $statement['recipients']
        ));
        $lines = '';
        foreach ($statement['lines'] as $line) {
            $lines .= '<tr><td style="' . $cell . '">' . $escape($line['description']) . '</td><td style="' . $cell . ';width:9.5em;text-align:right;white-space:nowrap;vertical-align:top">'
                . $currency(self::cents($line['amount'])) . '</td></tr>';
        }
        $bank = trim((string) $statement['bank_name']) . ' · '
            . ($statement['account_type'] === 'corriente' ? 'Cuenta corriente' : 'Cuenta de ahorros')
            . ' · ' . trim((string) $statement['account_number']);
        $label = static fn (string $text): string => '<span style="display:block;font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#687386">' . $text . '</span>';
        $column = 'width:50%;vertical-align:top';

        // Media carta horizontal (8,5 × 5,5 in) a dos columnas: movimiento anterior | movimiento del período.
        // Maquetado con tablas y estilos en línea para que se vea igual en los clientes de correo.
        return '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Nota de cobro '
            . $escape($statement['number']) . '</title>'
            . '<style>@page{size:8.5in 5.5in;margin:0}@media print{body{background:#ffffff!important;padding:0!important}.sheet{margin:0!important;box-shadow:none!important;border:0!important;width:8.5in!important;max-width:none!important}.no-print{display:none!important}}</style>'
            . '<body style="margin:0;padding:16px 8px;background:#eef1f5;font-family:Arial,Helvetica,sans-serif;color:#202633">'
            . '<div class="sheet" style="box-sizing:border-box;width:100%;max-width:8.5in;min-height:5.5in;margin:0 auto;padding:.3in .35in;background:#ffffff;border:1px solid #dfe3ea;box-shadow:0 2px 8px rgba(0,0,0,.08)">'
            // Encabezado: copropiedad | número de la nota.
            . '<table role="presentation" style="width:100%;border-collapse:collapse;border-bottom:2px solid #315ea8"><tr>'
            . '<td style="vertical-align:bottom;padding-bottom:6px"><div style="font-size:17px;font-weight:bold">' . $escape($statement['property_name']) . '</div>'
            . '<div style="font-size:10px;color:#687386">' . $escape($statement['property_address']) . ' · NIT ' . $escape($statement['property_tax_id'])
            . ' · ' . $escape($statement['property_email']) . '</div></td>'
            . '<td style="vertical-align:bottom;text-align:right;padding-bottom:6px;white-space:nowrap"><div style="font-size:10px;font-weight:bold;letter-spacing:.06em;color:#315ea8">NOTA DE COBRO</div>'
            . '<div style="font-size:13px;font-weight:bold">' . $escape($statement['number']) . '</div></td></tr></table>'
            // Datos del inmueble y fechas.
            . '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:11px;margin:6px 0 8px"><tr>'
            . '<td style="padding:3px 6px 3px 0;vertical-align:top">' . $label('Inmueble') . '<strong>#' . $escape($statement['unit_name']) . '</strong></td>'
            . '<td style="padding:3px 6px;vertical-align:top">' . $label('Propietario o residente') . ($recipients !== '' ? $recipients : 'Usuario asignado') . '</td>'
            . '<td style="padding:3px 6px;vertical-align:top;white-space:nowrap">' . $label('Emisión') . $escape($this->formatDate($statement['issue_date'])) . '</td>'
            . '<td style="padding:3px 6px;vertical-align:top;white-space:nowrap">' . $label('Pronto pago hasta') . '<strong>' . $escape($this->formatDate($statement['early_payment_date'])) . '</strong></td>'
            . '<td style="padding:3px 0 3px 6px;vertical-align:top;white-space:nowrap">' . $label('Vence') . '<strong>' . $escape($this->formatDate($statement['payment_due_date'])) . '</strong></td>'
            . '</tr></table>'
            // Dos columnas. Izquierda: movimiento anterior y datos para el pago. Derecha: movimiento del período y
            // su detalle de conceptos (así las dos columnas quedan de alto parecido).
            . '<table role="presentation" style="width:100%;border-collapse:collapse"><tr>'
            . '<td style="' . $column . ';padding-right:10px">'
            . $table('Movimiento ' . $escape($previousPeriod), $rows($previousRows))
            . '<div style="height:8px"></div>'
            . $table('Datos para el pago', '<tr><td style="padding:1px 6px;line-height:1.45">' . $escape($bank) . '<br>Titular: ' . $escape($statement['account_holder'])
                . '<br>Correo: ' . $escape($statement['property_email']) . '</td></tr>')
            . '</td><td style="' . $column . ';padding-left:10px">'
            . $table('Movimiento ' . $escape($statement['period']), $rows($currentRows))
            . '<div style="height:8px"></div>'
            . $table('Detalle de conceptos', $lines !== '' ? $lines : '<tr><td style="' . $cell . ';color:#687386">Sin conceptos adicionales.</td></tr>', ';table-layout:fixed')
            . '</td></tr></table>'
            . '<p style="font-size:8.5px;color:#687386;margin:8px 0 0;border-top:1px solid #e3e7ee;padding-top:3px">Nota de cobro para información y recaudo de la copropiedad. No reemplaza una factura electrónica.</p>'
            . '</div>'
            . '<p class="no-print" style="text-align:center;margin:12px 0 0"><button type="button" onclick="window.print()" style="padding:8px 16px">Imprimir o guardar como PDF (media carta)</button></p>'
            . '</body></html>';
    }

    public function statementText(array $statement): string
    {
        $amount = static fn (mixed $value): string => self::decimal(self::cents($value));
        $previousPeriod = $statement['previous_period'] ?: 'Sin período anterior emitido';
        $previousRows = [
            'Saldo mes anterior' => $statement['previous_opening_balance'],
            'Cuota Administración' => $statement['previous_fee'],
            'Interés moratorio' => $statement['previous_interest'],
            'Cobro por proceso jurídico' => $statement['previous_legal'],
            'Multas' => $statement['previous_fine'],
            'Retroactivo' => $statement['previous_retroactive'],
            'Cuota extra' => $statement['previous_extra'],
            'Otros cobros' => $statement['previous_other'],
            'Total a pagar ' . $previousPeriod => self::decimal(max(0, self::cents($statement['previous_total']))),
            'Menos pagos realizados' => self::decimal(-self::cents($statement['previous_payments'])),
            'Descuentos pronto pago' => self::decimal(-self::cents($statement['previous_discount'])),
            'Saldo ' . $previousPeriod => $statement['previous_balance'],
        ];
        if (self::cents($statement['previous_balance']) < 0) {
            $previousRows['Saldo a favor'] = self::decimal(-self::cents($statement['previous_balance']));
        }
        $feeLabel = empty($statement['coefficient'])
            ? 'Cuota Administración'
            : 'Cuota Administración (coef. ' . number_format((float) $statement['coefficient'], 4, ',', '.') . '%)';
        $currentRows = [
            'Saldo mes anterior' => $statement['opening_balance'],
            $feeLabel => $statement['administration_fee'],
            'Interés moratorio' => $statement['interest'],
            'Cobro por proceso jurídico' => $statement['legal'],
            'Multas' => $statement['fine'],
            'Retroactivo' => $statement['retroactive'],
            'Cuota extra' => $statement['extra'],
            'Otros cobros' => $statement['other'],
            'Total a pagar ' . $statement['period'] => self::decimal(max(0, self::cents($statement['total_due']))),
            'A pagar antes del ' . $this->formatDate($statement['early_payment_date']) . ' (pronto pago)' => max(
                0,
                self::cents($statement['total_due']) - self::cents($statement['early_discount'])
            ),
        ];
        if (self::cents($statement['total_due']) < 0) {
            $currentRows['Saldo a favor'] = self::decimal(-self::cents($statement['total_due']));
        }
        $previousText = implode("\n", array_map(
            static fn (string $label, mixed $value): string => $label . ': $ ' . $amount($value) . ' COP',
            array_keys($previousRows),
            array_values($previousRows)
        ));
        $currentText = implode("\n", array_map(
            static fn (string $label, mixed $value): string => $label . ': $ ' . $amount($value) . ' COP',
            array_keys($currentRows),
            array_values($currentRows)
        ));
        $lineText = implode("\n", array_map(
            static fn (array $line): string => '- ' . $line['description'] . ': $ ' . $amount($line['amount']) . ' COP',
            $statement['lines']
        ));
        $recipientNames = implode(', ', array_column($statement['recipients'], 'recipient_name'));

        return "Nota de cobro {$statement['number']}\n"
            . "Inmueble: #{$statement['unit_name']}\nPeríodo: {$statement['period']}\n"
            . 'Propietario o residente / usuario asignado: ' . ($recipientNames !== '' ? $recipientNames : 'Usuario asignado') . "\n"
            . 'Fecha de emisión: ' . $this->formatDate($statement['issue_date']) . "\n\n"
            . 'Movimiento ' . $previousPeriod . ":\n" . $previousText . "\n\n"
            . "Movimiento {$statement['period']}:\n" . $currentText . "\n\n"
            . "Detalle de conceptos:\n{$lineText}\n\n"
            . "Fecha límite de pronto pago: " . $this->formatDate($statement['early_payment_date']) . "\n"
            . 'Vencimiento ordinario: ' . $this->formatDate($statement['payment_due_date']) . "\n"
            . "Datos bancarios: {$statement['bank_name']}, {$statement['account_type']}, "
            . "{$statement['account_number']}, titular {$statement['account_holder']}\n"
            . "Correo de la copropiedad: {$statement['property_email']}";
    }

    public static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return '$ ' . $sign . number_format(intdiv($absolute, 100), 0, ',', '.') . ','
            . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT) . ' COP';
    }

    public static function moneyToCents(mixed $value): int
    {
        return self::cents($value);
    }

    public static function validPeriod(string $period): bool
    {
        try {
            self::periodDate($period);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function statementData(array $row, array $preview): array
    {
        $previous = $row['previous'];
        $settings = $preview['settings'];
        $periodDate = self::periodDate($preview['period']);
        $discountDate = $periodDate->setDate(
            (int) $periodDate->format('Y'),
            (int) $periodDate->format('m'),
            $preview['discount_day']
        );
        $dueDate = $periodDate->setDate(
            (int) $periodDate->format('Y'),
            (int) $periodDate->format('m'),
            $preview['due_day']
        );
        $mail = (new MailSettingsService())->current();

        return [
            'tenant_id' => Tenant::id(),
            'unit_id' => $row['id'],
            'period' => $preview['period'],
            'unit_name' => $row['name'],
            'unit_category' => $row['category'],
            'coefficient' => $row['coefficient'],
            'issued_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'issue_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'previous_period' => $preview['previous_period'],
            'previous_statement_id' => $previous['statement_id'],
            'previous_opening_balance' => self::decimal($previous['opening_balance']),
            'previous_fee' => self::decimal($previous['fee']),
            'previous_interest' => self::decimal($previous['items']['interest']),
            'previous_legal' => self::decimal($previous['items']['legal']),
            'previous_fine' => self::decimal($previous['items']['fine']),
            'previous_retroactive' => self::decimal($previous['items']['retroactive']),
            'previous_extra' => self::decimal($previous['items']['extra']),
            'previous_other' => self::decimal($previous['items']['other']),
            'previous_total' => self::decimal($previous['total']),
            'previous_payments' => self::decimal($previous['payments']),
            'previous_discount' => self::decimal($previous['discount']),
            'previous_balance' => self::decimal($previous['balance']),
            'opening_balance' => self::decimal($row['opening_balance']),
            'administration_fee' => self::decimal($row['fee']),
            'interest' => self::decimal($row['items']['interest']),
            'legal' => self::decimal($row['items']['legal']),
            'fine' => self::decimal($row['items']['fine']),
            'retroactive' => self::decimal($row['items']['retroactive']),
            'extra' => self::decimal($row['items']['extra']),
            'other' => self::decimal($row['items']['other']),
            'total_due' => self::decimal($row['total']),
            'early_discount' => self::decimal($row['early_discount']),
            'early_payment_date' => $discountDate->format('Y-m-d'),
            'payment_due_date' => $dueDate->format('Y-m-d'),
            'property_name' => (string) $settings['property_name'],
            'property_address' => (string) $settings['address'],
            'property_tax_id' => (string) $settings['tax_id'],
            'account_holder' => (string) $settings['account_holder'],
            'bank_name' => (string) $settings['bank_name'],
            'account_type' => $settings['account_type'],
            'account_number' => (string) $settings['account_number'],
            'property_email' => $mail['from_address'],
        ];
    }

    private function previousMovement(?array $statement, ?array $payments): array
    {
        $items = array_fill_keys(array_keys(self::ITEM_COLUMNS), 0);
        if ($statement === null) {
            return [
                'statement_id' => null,
                'opening_balance' => 0,
                'fee' => 0,
                'items' => $items,
                'total' => 0,
                'payments' => 0,
                'discount' => 0,
                'balance' => 0,
            ];
        }
        foreach (self::ITEM_COLUMNS as $key => $_column) {
            $items[$key] = self::cents($statement[$key]);
        }
        $paymentsTotal = $payments['total'] ?? 0;
        $settlement = self::settle(
            self::cents($statement['total_due']),
            self::cents($statement['early_discount']),
            $paymentsTotal,
            $payments['early'] ?? 0
        );

        return [
            'statement_id' => (int) $statement['id'],
            'opening_balance' => self::cents($statement['opening_balance']),
            'fee' => self::cents($statement['administration_fee']),
            'items' => $items,
            'total' => self::cents($statement['total_due']),
            'payments' => $paymentsTotal,
            'discount' => $settlement['discount'],
            'balance' => $settlement['balance'],
        ];
    }

    /**
     * Regla única de saldo: el descuento de pronto pago se reconoce cuando los pagos recibidos
     * hasta la fecha límite cubren el total menos el descuento.
     *
     * @return array{discount: int, balance: int}
     */
    public static function settle(int $totalDue, int $earlyDiscount, int $paymentsTotal, int $earlyPayments): array
    {
        $threshold = max(0, $totalDue - $earlyDiscount);
        $discount = $totalDue > 0 && $earlyPayments > 0 && $earlyPayments >= $threshold ? $earlyDiscount : 0;

        return ['discount' => $discount, 'balance' => $totalDue - $paymentsTotal - $discount];
    }

    /** Nota inmediatamente posterior del mismo inmueble, si existe. */
    public function newerStatement(string $unitId, string $period): ?array
    {
        $query = Database::connection()->prepare(
            'SELECT id, period FROM billing_statements
             WHERE tenant_id = ? AND unit_id = ? AND period > ?
             ORDER BY period LIMIT 1'
        );
        $query->execute([Tenant::id(), $unitId, $period]);
        $row = $query->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'period' => (string) $row['period']] : null;
    }

    private function isProviderPayment(int $statementId, string $reference): bool
    {
        if ($reference === '') {
            return false;
        }
        foreach (self::PROVIDER_PAYMENT_TABLES as $table) {
            try {
                $query = Database::connection()->prepare(
                    "SELECT 1 FROM {$table} WHERE statement_id = ? AND transaction_id = ? AND status = 'approved' LIMIT 1"
                );
                $query->execute([$statementId, $reference]);
            } catch (PDOException $exception) {
                if (self::isMissingTable($exception)) {
                    continue;
                }
                throw $exception;
            }
            if ($query->fetchColumn() !== false) {
                return true;
            }
        }

        return false;
    }

    /** Una integración Bold cuya migración no se aplicó simplemente no tiene pagos. */
    private static function isMissingTable(PDOException $exception): bool
    {
        return (string) $exception->getCode() === '42S02';
    }

    private function pendingOnlinePaymentsBefore(string $period): int
    {
        $count = 0;
        foreach (self::PROVIDER_PAYMENT_TABLES as $table) {
            try {
                $query = Database::connection()->prepare(
                    "SELECT COUNT(*) FROM {$table} AS pending
                     JOIN billing_statements AS statements ON statements.id = pending.statement_id
                     WHERE statements.tenant_id = ? AND statements.period < ? AND pending.status = 'pending'"
                );
                $query->execute([Tenant::id(), $period]);
                $count += (int) $query->fetchColumn();
            } catch (PDOException $exception) {
                if (!self::isMissingTable($exception)) {
                    throw $exception;
                }
            }
        }

        return $count;
    }

    private function assignedUnits(): array
    {
        $composition = (new CompositionService())->current();
        if (!is_array($composition) || !$composition['completed'] || !is_array($composition['units'])) {
            throw new RuntimeException('Finaliza primero la estructura de inmuebles en Configuración / Conformación.');
        }
        $composition = (new CompositionService())->ensureUnitIds();
        $unitMap = [];
        foreach ($composition['units'] as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null)) {
                $unitMap[$unit['id']] = [
                    'id' => $unit['id'],
                    'name' => (string) $unit['name'],
                    'category' => (string) $unit['category'],
                    'coefficient' => (string) ($unit['coefficient'] ?? ''),
                    'recipients' => [],
                ];
            }
        }
        if ($unitMap === []) {
            return [];
        }
        $assignmentsStatement = Database::connection()->prepare(
            'SELECT assignments.unit_id, users.id, users.username, users.email
             FROM property_unit_assignments AS assignments
             JOIN admin_users AS users ON users.id = assignments.user_id
             WHERE assignments.tenant_id = ? AND users.is_active = 1
             ORDER BY assignments.unit_id, users.username'
        );
        $assignmentsStatement->execute([Tenant::id()]);
        $assignments = $assignmentsStatement->fetchAll();
        foreach ($assignments as $assignment) {
            $unitId = (string) $assignment['unit_id'];
            if (isset($unitMap[$unitId])) {
                $unitMap[$unitId]['recipients'][] = [
                    'id' => (int) $assignment['id'],
                    'username' => (string) $assignment['username'],
                    'email' => (string) $assignment['email'],
                ];
            }
        }

        return array_values(array_filter(
            $unitMap,
            static fn (array $unit): bool => $unit['recipients'] !== []
        ));
    }

    private function unitMap(): array
    {
        $map = [];
        foreach ($this->assignedUnits() as $unit) {
            $map[$unit['id']] = $unit;
        }

        return $map;
    }

    private function lockPeriodItems(PDO $pdo, string $period): void
    {
        $query = $pdo->prepare(
            'SELECT id FROM billing_monthly_items WHERE tenant_id = ? AND period = ? FOR UPDATE'
        );
        $query->execute([Tenant::id(), $period]);
        $query->fetchAll();
    }

    private function statementMap(string $period): array
    {
        $query = Database::connection()->prepare(
            'SELECT statements.id, statements.unit_id, statements.coefficient, statements.administration_fee,
                    GROUP_CONCAT(recipients.delivery_status ORDER BY recipients.id) AS delivery_status
             FROM billing_statements statements
             LEFT JOIN billing_statement_recipients recipients ON recipients.statement_id = statements.id
             WHERE statements.tenant_id = ? AND statements.period = ?
             GROUP BY statements.id'
        );
        $query->execute([Tenant::id(), $period]);
        $map = [];
        foreach ($query->fetchAll() as $row) {
            $map[(string) $row['unit_id']] = [
                'id' => (int) $row['id'],
                'coefficient' => $row['coefficient'] === null ? null : (string) $row['coefficient'],
                'administration_fee' => (string) $row['administration_fee'],
                'delivery_status' => (string) ($row['delivery_status'] ?? ''),
            ];
        }

        return $map;
    }

    private function itemMap(string $period): array
    {
        $query = Database::connection()->prepare(
            'SELECT id, unit_id, category, description, amount
             FROM billing_monthly_items
             WHERE tenant_id = ? AND period = ?
             ORDER BY id'
        );
        $query->execute([Tenant::id(), $period]);
        $map = [];
        foreach ($query->fetchAll() as $row) {
            $map[(string) $row['unit_id']][] = [
                'id' => (int) $row['id'],
                'category' => (string) $row['category'],
                'description' => (string) $row['description'],
                'amount' => (string) $row['amount'],
            ];
        }

        return $map;
    }

    private function latestStatementsBefore(string $period): array
    {
        $query = Database::connection()->prepare(
            'SELECT statements.*
             FROM billing_statements AS statements
             JOIN (
                 SELECT unit_id, MAX(period) AS latest_period
                 FROM billing_statements
                 WHERE tenant_id = ? AND period < ?
                 GROUP BY unit_id
             ) AS latest
               ON latest.unit_id = statements.unit_id AND latest.latest_period = statements.period
             WHERE statements.tenant_id = ?'
        );
        $query->execute([Tenant::id(), $period, Tenant::id()]);
        $map = [];
        foreach ($query->fetchAll() as $row) {
            $map[(string) $row['unit_id']] = $row;
        }

        return $map;
    }

    private function paymentMap(array $statements): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $statement): int => (int) $statement['id'],
            $statements
        )));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = Database::connection()->prepare(
            "SELECT payments.statement_id,
                    COALESCE(SUM(amount), 0) AS total,
                    COALESCE(SUM(CASE WHEN payments.received_on <= statements.early_payment_date
                        THEN payments.amount ELSE 0 END), 0) AS early
             FROM billing_payments AS payments
             JOIN billing_statements AS statements ON statements.id = payments.statement_id
             WHERE payments.statement_id IN ($placeholders)
               AND payments.tenant_id = ?
               AND payments.voided_at IS NULL
             GROUP BY payments.statement_id"
        );
        $query->execute([...$ids, Tenant::id()]);
        $map = [];
        foreach ($query->fetchAll() as $row) {
            $map[(int) $row['statement_id']] = [
                'total' => self::cents($row['total']),
                'early' => self::cents($row['early']),
            ];
        }

        return $map;
    }

    private function statementLines(int $statementId): array
    {
        $query = Database::connection()->prepare(
            'SELECT category, description, amount
             FROM billing_statement_lines WHERE statement_id = ? ORDER BY id'
        );
        $query->execute([$statementId]);

        return $query->fetchAll();
    }

    private function statementRecipients(int $statementId): array
    {
        $query = Database::connection()->prepare(
            'SELECT user_id, recipient_name, recipient_email, delivery_status, delivery_attempts, sent_at
             FROM billing_statement_recipients WHERE statement_id = ? ORDER BY recipient_name'
        );
        $query->execute([$statementId]);

        return $query->fetchAll();
    }

    private function statementPayments(int $statementId): array
    {
        $query = Database::connection()->prepare(
            'SELECT id, amount, received_on, reference, notes, voided_at, void_reason
             FROM billing_payments WHERE statement_id = ? ORDER BY received_on, id'
        );
        $query->execute([$statementId]);

        return $query->fetchAll();
    }

    /**
     * Nota que todavía se puede pagar: su saldo no pasó a una nota posterior y tiene saldo pendiente.
     *
     * @throws RuntimeException con el motivo para mostrarlo al usuario
     */
    public function payableStatement(int $statementId): array
    {
        $statement = $this->statement($statementId);
        if ($statement['superseded_by'] !== null) {
            throw new RuntimeException(sprintf(
                'El saldo de esta nota ya se incluyó en la nota del período %s. Usa la nota más reciente.',
                $statement['superseded_by']['period']
            ));
        }
        if ((int) $statement['payable_balance'] <= 0) {
            throw new RuntimeException('Esta nota no tiene saldo pendiente.');
        }

        return $statement;
    }

    /** La cuenta recibió la nota (es destinataria de su envío). $statement es el resultado de statement(). */
    public static function isRecipient(array $statement, int $userId): bool
    {
        foreach ($statement['recipients'] as $recipient) {
            if ((int) ($recipient['user_id'] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
    }

    /** Consecutivo visible de una nota de cobro: NC-AAAAMM-000123. */
    public static function statementNumber(string $period, int $statementId): string
    {
        return sprintf('NC-%s-%06d', str_replace('-', '', $period), $statementId);
    }

    /** Consecutivo visible de un comprobante de pago: RC-000123. */
    public static function receiptNumber(int $paymentId): string
    {
        return sprintf('RC-%06d', $paymentId);
    }

    private function formatDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof DateTimeImmutable ? $parsed->format('d/m/Y') : $date;
    }

    private static function periodDate(string $period): DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})$/D', $period, $matches) !== 1
            || !checkdate((int) $matches[2], 1, (int) $matches[1])
        ) {
            throw new RuntimeException('Selecciona un período mensual válido.');
        }

        return new DateTimeImmutable($period . '-01');
    }

    private static function validateDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException('Ingresa una fecha válida.');
        }
    }

    private static function cents(mixed $value): int
    {
        $value = trim((string) $value);
        if (!preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/D', $value, $matches)) {
            return 0;
        }
        $cents = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    private static function normalizedCoefficient(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/D', $value, $matches)) {
            return null;
        }
        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 4, '0');
        $scaled = $whole * 10000 + $fraction;
        if ($scaled < 1 || $scaled > 1000000) {
            return null;
        }
        return $whole . '.' . str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Reparte un presupuesto mensual (centavos) en proporción al coeficiente de cada inmueble. Usa el método del
     * mayor residuo: cada inmueble recibe su parte redondeada hacia abajo y los centavos sobrantes van a quienes
     * tienen la mayor fracción, de modo que la suma es exactamente el presupuesto aunque los coeficientes no sumen
     * 100 %. Solo aritmética entera.
     *
     * @param list<array{id?: string, coefficient?: mixed}> $units
     * @return array<string, int> centavos por id de inmueble (los inmuebles sin coeficiente no reciben parte)
     */
    public static function budgetShares(int $budgetCents, array $units): array
    {
        $scaled = [];
        foreach ($units as $unit) {
            $coefficient = is_array($unit) && is_string($unit['id'] ?? null) ? self::normalizedCoefficient($unit['coefficient'] ?? null) : null;
            if ($coefficient !== null) {
                [$whole, $fraction] = explode('.', $coefficient, 2);
                $value = ((int) $whole * 10000) + (int) $fraction;
                if ($value > 0) {
                    $scaled[$unit['id']] = $value;
                }
            }
        }
        $total = array_sum($scaled);
        if ($total === 0 || $budgetCents <= 0) {
            return array_map(static fn (): int => 0, $scaled);
        }

        $shares = [];
        $remainders = [];
        $quotient = intdiv($budgetCents, $total);
        $rest = $budgetCents % $total;
        foreach ($scaled as $id => $value) {
            // budget × value / total sin desbordar: (q·total + r) × value / total = q·value + r·value / total
            $shares[$id] = $quotient * $value + intdiv($rest * $value, $total);
            $remainders[$id] = ($rest * $value) % $total;
        }
        $leftover = $budgetCents - array_sum($shares);
        // Mayor fracción primero; en empate, el orden de la conformación (resultado reproducible).
        $order = array_keys($scaled);
        $position = array_flip($order);
        usort($order, static fn (string $a, string $b): int => [$remainders[$b], $position[$a]] <=> [$remainders[$a], $position[$b]]);
        foreach (array_slice($order, 0, $leftover) as $id) {
            $shares[$id]++;
        }

        return $shares;
    }

    private static function allocatedFeeCents(int $baseCents, string $coefficient): int
    {
        [$whole, $fraction] = explode('.', $coefficient, 2);
        $scaledCoefficient = ((int) $whole * 10000) + (int) $fraction;
        $denominator = 1000000;
        $wholePart = intdiv($baseCents, $denominator) * $scaledCoefficient;
        $remainder = ($baseCents % $denominator) * $scaledCoefficient;
        return $wholePart + intdiv($remainder + intdiv($denominator, 2), $denominator);
    }

    private static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
