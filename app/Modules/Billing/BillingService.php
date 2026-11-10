<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Modules\Auth\SmtpMailer;
use App\Modules\Configuration\CompositionService;
use App\Modules\Configuration\MailSettingsService;
use App\Modules\Configuration\SettingsService;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class BillingService
{
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

    public function preview(string $period): array
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
        $mailSettingsReady = trim($mailSettings['host']) !== ''
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
            $previousBalance = $prior === null
                ? $openingAdjustment
                : $previous['balance'];
            $openingBalance = $previousBalance + $openingAdjustment;
            $effectiveDate = (string) ($propertySettings['effective_date'] ?? '');
            $feeApplies = $feeReady && $effectiveDate <= $periodStart && !$feeNotYetEffective;
            $currentFee = $feeApplies ? $fee : 0;
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
        );
        $blockingReasons = [];
        if (!$dateSettingsReady) {
            $blockingReasons[] = 'Configura los días de pronto pago y vencimiento en Parametrización.';
        }
        if (!$bankSettingsReady) {
            $blockingReasons[] = 'Completa los datos bancarios en Parametrización.';
        }
        if (!$mailSettingsReady) {
            $blockingReasons[] = 'Completa y prueba la configuración SMTP en Configuración / Correo.';
        }
        if (!$feeReady) {
            $blockingReasons[] = 'Configura la cuota y su fecha de vigencia en Configuración / Financiera.';
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

        return [
            'period' => $period,
            'previous_period' => $previousPeriod,
            'rows' => $rows,
            'settings' => $propertySettings,
            'discount_day' => $discountDay,
            'due_day' => $dueDay,
            'ready' => $blockingReasons === [],
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
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
                'INSERT INTO billing_monthly_items (unit_id, period, category, description, amount, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$unitId, $period, $category, $description, self::decimal($amountCents), $actorId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function addPayment(int $statementId, string $amount, string $receivedOn, string $reference, string $notes, int $actorId): void
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
            'SELECT id FROM billing_statements WHERE id = ?'
        );
        $statement->execute([$statementId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('La nota de cobro seleccionada no existe.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM billing_statements WHERE id = ? FOR UPDATE');
            $lock->execute([$statementId]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('La nota de cobro seleccionada no existe.');
            }
            $pdo->prepare(
                'INSERT INTO billing_payments (statement_id, amount, received_on, reference, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$statementId, self::decimal($amountCents), $receivedOn, $reference, $notes, $actorId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function removeItem(int $itemId, string $period): void
    {
        self::periodDate($period);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->lockPeriodItems($pdo, $period);
            $query = $pdo->prepare(
                'SELECT unit_id FROM billing_monthly_items WHERE id = ? AND period = ?'
            );
            $query->execute([$itemId, $period]);
            $unitId = $query->fetchColumn();
            if ($unitId === false) {
                throw new RuntimeException('El concepto no existe o ya fue eliminado.');
            }
            if (isset($this->statementMap($period)[(string) $unitId])) {
                throw new RuntimeException('Los conceptos de un inmueble con nota emitida no se pueden eliminar.');
            }
            $delete = $pdo->prepare(
                'DELETE FROM billing_monthly_items WHERE id = ? AND period = ?'
            );
            $delete->execute([$itemId, $period]);
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
                'SELECT id FROM billing_statements WHERE period = ? FOR UPDATE'
            );
            $lockPrevious->execute([$previousPeriod]);
            $lockPrevious->fetchAll();
            $preview = $this->preview($period);
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

        $delivery = $this->sendPeriod($period);

        return [
            'issued' => count($statementIds),
            'sent' => $delivery['sent'],
            'failed' => $delivery['failed'],
            'pending' => $delivery['pending'],
        ];
    }

    public function sendPeriod(string $period): array
    {
        self::periodDate($period);
        $settings = (new MailSettingsService())->credentials();
        $query = Database::connection()->prepare(
            "SELECT recipients.id AS recipient_id, recipients.recipient_email, recipients.recipient_name,
                    statements.id AS statement_id, statements.period
             FROM billing_statement_recipients AS recipients
             JOIN billing_statements AS statements ON statements.id = recipients.statement_id
             WHERE statements.period = ?
               AND (
                    recipients.delivery_status IN ('pending', 'failed')
                    OR (recipients.delivery_status = 'sending'
                        AND recipients.delivery_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE))
               )
             ORDER BY statements.id, recipients.id"
        );
        $query->execute([$period]);
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
                    sprintf('Nota de cobro %s · %s', $this->number($statement), $statement['period']),
                    $this->statementHtml($statement),
                    $this->statementText($statement)
                );
                Database::connection()->prepare(
                    "UPDATE billing_statement_recipients
                     SET delivery_status = 'sent', sent_at = CURRENT_TIMESTAMP, delivery_started_at = NULL
                     WHERE id = ?"
                )->execute([(int) $recipient['recipient_id']]);
                $sent++;
            } catch (\PHPMailer\PHPMailer\Exception|RuntimeException $exception) {
                error_log('Fallo al notificar una nota de cobro: ' . $exception->getMessage());
                Database::connection()->prepare(
                    "UPDATE billing_statement_recipients
                     SET delivery_status = 'failed', delivery_started_at = NULL,
                         last_error = 'No se pudo enviar. Revisa SMTP y reintenta.'
                     WHERE id = ?"
                )->execute([(int) $recipient['recipient_id']]);
            }
        }

        $remaining = Database::connection()->prepare(
            "SELECT delivery_status, COUNT(*) AS status_count
             FROM billing_statement_recipients AS recipients
             JOIN billing_statements AS statements ON statements.id = recipients.statement_id
             WHERE statements.period = ?
               AND recipients.delivery_status IN ('pending', 'failed', 'sending')
             GROUP BY delivery_status"
        );
        $remaining->execute([$period]);
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

    public function statements(string $period): array
    {
        $query = Database::connection()->prepare(
            'SELECT statements.*,
                    (SELECT COUNT(*) FROM billing_statement_recipients r WHERE r.statement_id = statements.id) AS recipient_count,
                    (SELECT COUNT(*) FROM billing_statement_recipients r WHERE r.statement_id = statements.id AND r.delivery_status = "sent") AS sent_count,
                    (SELECT COUNT(*) FROM billing_statement_recipients r WHERE r.statement_id = statements.id AND r.delivery_status = "failed") AS failed_count,
                    (SELECT COUNT(*) FROM billing_statement_recipients r WHERE r.statement_id = statements.id AND r.delivery_status IN ("pending", "sending")) AS pending_count,
                    (SELECT COALESCE(SUM(payments.amount), 0) FROM billing_payments payments WHERE payments.statement_id = statements.id) AS payments_total
             FROM billing_statements statements
             WHERE statements.period = ?
             ORDER BY statements.unit_name'
        );
        $query->execute([$period]);

        return $query->fetchAll();
    }

    public function statement(int $statementId): array
    {
        $query = Database::connection()->prepare(
            'SELECT * FROM billing_statements WHERE id = ?'
        );
        $query->execute([$statementId]);
        $statement = $query->fetch();
        if (!is_array($statement)) {
            throw new RuntimeException('La nota de cobro no existe.');
        }
        $statement['lines'] = $this->statementLines($statementId);
        $statement['recipients'] = $this->statementRecipients($statementId);
        $statement['payments'] = $this->statementPayments($statementId);
        $statement['payments_total'] = array_sum(array_map(
            static fn (array $payment): int => self::cents($payment['amount']),
            $statement['payments']
        ));
        $earlyPayments = array_sum(array_map(
            static fn (array $payment): int => $payment['received_on'] <= $statement['early_payment_date']
                ? self::cents($payment['amount'])
                : 0,
            $statement['payments']
        ));
        $earlyThreshold = max(0, self::cents($statement['total_due']) - self::cents($statement['early_discount']));
        $statement['discount_applied'] = self::cents($statement['total_due']) > 0
            && $earlyPayments >= $earlyThreshold
            && $earlyPayments > 0
            ? self::cents($statement['early_discount'])
            : 0;
        $statement['balance'] = self::cents($statement['total_due'])
            - $statement['payments_total']
            - $statement['discount_applied'];
        $statement['number'] = $this->number($statement);

        return $statement;
    }

    public function statementHtml(array $statement): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars(
            is_scalar($value) ? (string) $value : '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $currency = static fn (mixed $cents): string => self::money((int) $cents);
        $rows = static function (array $items) use ($escape, $currency): string {
            $html = '';
            foreach ($items as [$label, $amount]) {
                $html .= '<tr><td>' . $escape($label) . '</td><td style="text-align:right">' . $currency($amount) . '</td></tr>';
            }

            return $html;
        };
        $previousPeriod = $statement['previous_period'] ?: 'Sin período anterior emitido';
        $previousRows = [
            ['Saldo mes anterior', self::cents($statement['previous_opening_balance'])],
            ['Cuota Administración', self::cents($statement['previous_fee'])],
            ['Interés moratorio', self::cents($statement['previous_interest'])],
            ['Cobro por proceso jurídico', self::cents($statement['previous_legal'])],
            ['Multas', self::cents($statement['previous_fine'])],
            ['Retroactivo', self::cents($statement['previous_retroactive'])],
            ['Cuota extra', self::cents($statement['previous_extra'])],
            ['Otros cobros', self::cents($statement['previous_other'])],
            ['Total a pagar ' . $previousPeriod, max(0, self::cents($statement['previous_total']))],
            ['Menos pagos realizados', -self::cents($statement['previous_payments'])],
            ['Descuentos pronto pago', -self::cents($statement['previous_discount'])],
            ['Saldo ' . $previousPeriod, self::cents($statement['previous_balance'])],
        ];
        if (self::cents($statement['previous_balance']) < 0) {
            $previousRows[] = ['Saldo a favor', -self::cents($statement['previous_balance'])];
        }
        $currentRows = [
            ['Saldo mes anterior', self::cents($statement['opening_balance'])],
            ['Cuota Administración', self::cents($statement['administration_fee'])],
            ['Interés moratorio', self::cents($statement['interest'])],
            ['Cobro por proceso jurídico', self::cents($statement['legal'])],
            ['Multas', self::cents($statement['fine'])],
            ['Retroactivo', self::cents($statement['retroactive'])],
            ['Cuota extra', self::cents($statement['extra'])],
            ['Otros cobros', self::cents($statement['other'])],
            ['Total a pagar ' . $statement['period'], max(0, self::cents($statement['total_due']))],
            ['A pagar antes del ' . $this->formatDate($statement['early_payment_date']) . ' (pronto pago)', max(
                0,
                self::cents($statement['total_due']) - self::cents($statement['early_discount'])
            )],
        ];
        if (self::cents($statement['total_due']) < 0) {
            $currentRows[] = ['Saldo a favor', -self::cents($statement['total_due'])];
        }
        $recipients = implode(', ', array_map(
            static fn (array $recipient): string => $escape($recipient['recipient_name']),
            $statement['recipients']
        ));
        $lines = '';
        foreach ($statement['lines'] as $line) {
            $lines .= '<li>' . $escape($line['description']) . ': ' . $currency(self::cents($line['amount'])) . '</li>';
        }
        $bank = trim((string) $statement['bank_name']) . ' · '
            . ($statement['account_type'] === 'corriente' ? 'Cuenta corriente' : 'Cuenta de ahorros')
            . ' · ' . trim((string) $statement['account_number']);

        return '<!doctype html><html lang="es"><meta charset="utf-8"><title>Nota de cobro '
            . $escape($statement['number']) . '</title><body style="font:15px Arial,sans-serif;color:#202633;max-width:800px;margin:24px auto;padding:16px">'
            . '<header style="border-bottom:2px solid #315ea8;padding-bottom:12px"><h1 style="margin:0">'
            . $escape($statement['property_name']) . '</h1><p>' . $escape($statement['property_address'])
            . ' · NIT ' . $escape($statement['property_tax_id']) . '</p><h2>Nota de cobro '
            . $escape($statement['number']) . '</h2></header>'
            . '<p><strong>Inmueble:</strong> #' . $escape($statement['unit_name']) . '<br><strong>Propietario o residente / usuario asignado:</strong> '
            . ($recipients !== '' ? $recipients : 'Usuario asignado') . '<br><strong>Fecha de emisión:</strong> '
            . $escape($this->formatDate($statement['issue_date'])) . '</p>'
            . '<h3>Movimiento ' . $escape($previousPeriod) . '</h3><table style="width:100%;border-collapse:collapse" border="1" cellpadding="8">'
            . $rows($previousRows) . '</table><h3>Movimiento ' . $escape($statement['period']) . '</h3>'
            . '<table style="width:100%;border-collapse:collapse" border="1" cellpadding="8">' . $rows($currentRows) . '</table>'
            . '<p>Fecha límite de pronto pago: <strong>' . $escape($this->formatDate($statement['early_payment_date']))
            . '</strong><br>Vencimiento ordinario: <strong>' . $escape($this->formatDate($statement['payment_due_date'])) . '</strong></p>'
            . ($lines !== '' ? '<h3>Detalle de conceptos</h3><ul>' . $lines . '</ul>' : '')
            . '<h3>Datos para el pago</h3><p>' . $escape($bank) . '<br>Titular: ' . $escape($statement['account_holder'])
            . '<br>Correo de la copropiedad: ' . $escape($statement['property_email']) . '</p>'
            . '<p style="font-size:12px;color:#687386">Nota de cobro para información y recaudo de la copropiedad. No reemplaza una factura electrónica.</p>'
            . '<button type="button" onclick="window.print()" style="padding:10px 16px;margin:12px 0">Imprimir o guardar como PDF</button>'
            . '<style>@media print{button{display:none}body{margin:0 auto}}</style></body></html>';
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
        $currentRows = [
            'Saldo mes anterior' => $statement['opening_balance'],
            'Cuota Administración' => $statement['administration_fee'],
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
            'unit_id' => $row['id'],
            'period' => $preview['period'],
            'unit_name' => $row['name'],
            'unit_category' => $row['category'],
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
        $earlyPayments = $payments['early'] ?? 0;
        $earlyThreshold = max(
            0,
            self::cents($statement['total_due']) - self::cents($statement['early_discount'])
        );
        $discount = self::cents($statement['total_due']) > 0
            && $earlyPayments >= $earlyThreshold
            && $earlyPayments > 0
            ? self::cents($statement['early_discount'])
            : 0;

        return [
            'statement_id' => (int) $statement['id'],
            'opening_balance' => self::cents($statement['opening_balance']),
            'fee' => self::cents($statement['administration_fee']),
            'items' => $items,
            'total' => self::cents($statement['total_due']),
            'payments' => $paymentsTotal,
            'discount' => $discount,
            'balance' => self::cents($statement['total_due']) - $paymentsTotal - $discount,
        ];
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
                    'recipients' => [],
                ];
            }
        }
        if ($unitMap === []) {
            return [];
        }
        $assignments = Database::connection()->query(
            'SELECT assignments.unit_id, users.id, users.username, users.email
             FROM property_unit_assignments AS assignments
             JOIN admin_users AS users ON users.id = assignments.user_id
             WHERE users.is_active = 1
             ORDER BY assignments.unit_id, users.username'
        )->fetchAll();
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
            'SELECT id FROM billing_monthly_items WHERE period = ? FOR UPDATE'
        );
        $query->execute([$period]);
        $query->fetchAll();
    }

    private function statementMap(string $period): array
    {
        $query = Database::connection()->prepare(
            'SELECT statements.id, statements.unit_id,
                    GROUP_CONCAT(recipients.delivery_status ORDER BY recipients.id) AS delivery_status
             FROM billing_statements statements
             LEFT JOIN billing_statement_recipients recipients ON recipients.statement_id = statements.id
             WHERE statements.period = ?
             GROUP BY statements.id'
        );
        $query->execute([$period]);
        $map = [];
        foreach ($query->fetchAll() as $row) {
            $map[(string) $row['unit_id']] = [
                'id' => (int) $row['id'],
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
             WHERE period = ?
             ORDER BY id'
        );
        $query->execute([$period]);
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
                 WHERE period < ?
                 GROUP BY unit_id
             ) AS latest
               ON latest.unit_id = statements.unit_id AND latest.latest_period = statements.period'
        );
        $query->execute([$period]);
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
             GROUP BY payments.statement_id"
        );
        $query->execute($ids);
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
            'SELECT recipient_name, recipient_email, delivery_status, delivery_attempts, sent_at
             FROM billing_statement_recipients WHERE statement_id = ? ORDER BY recipient_name'
        );
        $query->execute([$statementId]);

        return $query->fetchAll();
    }

    private function statementPayments(int $statementId): array
    {
        $query = Database::connection()->prepare(
            'SELECT amount, received_on, reference, notes
             FROM billing_payments WHERE statement_id = ? ORDER BY received_on, id'
        );
        $query->execute([$statementId]);

        return $query->fetchAll();
    }

    private function number(array $statement): string
    {
        $period = str_replace('-', '', (string) $statement['period']);

        return sprintf('NC-%s-%06d', $period, (int) $statement['id']);
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

    private static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
