<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Storage;
use App\Core\Tenant;
use DateTimeImmutable;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Consignaciones por identificar: dinero que llegó a la cuenta de la copropiedad sin saber de qué inmueble es
 * (por ejemplo, el aviso del banco en el correo de la administración). Se registran aparte de la cartera y, al
 * identificarlas, se convierten en un pago normal de la nota del inmueble, con su comprobante y su aviso.
 *
 * Para identificarlas se sugieren, en orden de confianza:
 *   1. una referencia de pago válida escrita en la referencia o descripción del banco;
 *   2. un pago reportado por un residente con el mismo valor y fecha cercana;
 *   3. notas con saldo igual al valor (o al valor con descuento de pronto pago).
 */
final class UnidentifiedDepositService
{
    public const CHANNEL_LABELS = [
        'consignacion' => 'Consignación',
        'transferencia' => 'Transferencia',
        'otro' => 'Otro',
    ];

    public const STATUS_LABELS = [
        'pending' => 'Por identificar',
        'processing' => 'Por identificar',
        'identified' => 'Identificada',
        'discarded' => 'Descartada',
    ];

    private const REPORT_DATE_TOLERANCE_DAYS = 5;

    private ?array $portfolio = null;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $openReports = null;

    public function __construct(
        private readonly PaymentReferenceService $references = new PaymentReferenceService(),
    ) {
    }

    /**
     * @param array<string, string> $data
     * @param array<string, mixed>|null $file entrada de $_FILES (opcional)
     */
    public function create(array $data, ?array $file, int $actorId): int
    {
        $amountCents = BillingService::moneyToCents($data['amount'] ?? '');
        $depositedOn = trim((string) ($data['deposited_on'] ?? ''));
        $channel = (string) ($data['channel'] ?? '');
        $bankReference = trim((string) ($data['bank_reference'] ?? ''));
        $payerDetail = trim(preg_replace('/\s+/u', ' ', (string) ($data['payer_detail'] ?? '')) ?? '');
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($amountCents < 1 || $amountCents > 999999999999999) {
            throw new RuntimeException('Ingresa el valor de la consignación (mayor que cero, sin separador de miles).');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $depositedOn);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $depositedOn) {
            throw new RuntimeException('Ingresa la fecha de la consignación.');
        }
        if ($depositedOn > date('Y-m-d')) {
            throw new RuntimeException('La fecha de la consignación no puede ser futura.');
        }
        if (!isset(self::CHANNEL_LABELS[$channel])) {
            throw new RuntimeException('Selecciona el medio: consignación, transferencia u otro.');
        }
        if (mb_strlen($bankReference, 'UTF-8') > 80 || mb_strlen($payerDetail, 'UTF-8') > 160 || mb_strlen($notes, 'UTF-8') > 255) {
            throw new RuntimeException('La referencia admite 80 caracteres, el detalle del banco 160 y las notas 255.');
        }

        $proof = null;
        $destination = null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $proof = PaymentReportService::validateProof($file);
            $proof['storage_name'] = bin2hex(random_bytes(16)) . '.' . $proof['extension'];
            $destination = Storage::directory('deposit-proofs') . DIRECTORY_SEPARATOR . $proof['storage_name'];
            if (!move_uploaded_file($proof['temporary_name'], $destination)) {
                throw new RuntimeException('No se pudo guardar el soporte. Intenta nuevamente.');
            }
        }

        try {
            Database::connection()->prepare(
                'INSERT INTO billing_unidentified_deposits
                    (tenant_id, amount, deposited_on, channel, bank_reference, payer_detail, notes,
                     proof_storage_name, proof_mime_type, proof_byte_size, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                Tenant::id(), self::decimal($amountCents), $depositedOn, $channel, $bankReference, $payerDetail, $notes,
                $proof['storage_name'] ?? null, $proof['mime_type'] ?? null, $proof['byte_size'] ?? null, $actorId,
            ]);

            return (int) Database::connection()->lastInsertId();
        } catch (Throwable $exception) {
            if ($destination !== null && is_file($destination)) {
                @unlink($destination);
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(string $filter): array
    {
        $statuses = match ($filter) {
            'identified' => ['identified'],
            'discarded' => ['discarded'],
            default => ['pending', 'processing'],
        };
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $statement = Database::connection()->prepare(
            "SELECT deposits.*, creators.username AS created_by_name, resolvers.username AS resolved_by_name,
                    statements.unit_name, statements.period
             FROM billing_unidentified_deposits AS deposits
             LEFT JOIN admin_users AS creators ON creators.id = deposits.created_by
             LEFT JOIN admin_users AS resolvers ON resolvers.id = deposits.resolved_by
             LEFT JOIN billing_statements AS statements ON statements.id = deposits.statement_id
             WHERE deposits.tenant_id = ? AND deposits.status IN ($placeholders)
             ORDER BY deposits.deposited_on DESC, deposits.id DESC
             LIMIT 300"
        );
        $statement->execute([Tenant::id(), ...$statuses]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['amount_cents'] = BillingService::moneyToCents($row['amount']);

            return $row;
        }, $statement->fetchAll());
    }

    /** @return array{pending: int, identified: int, discarded: int, pending_amount: int} */
    public function counts(): array
    {
        $statement = Database::connection()->prepare(
            "SELECT SUM(status IN ('pending', 'processing')) AS pending, SUM(status = 'identified') AS identified,
                    SUM(status = 'discarded') AS discarded,
                    COALESCE(SUM(CASE WHEN status IN ('pending', 'processing') THEN amount END), 0) AS pending_amount
             FROM billing_unidentified_deposits WHERE tenant_id = ?"
        );
        $statement->execute([Tenant::id()]);
        $row = $statement->fetch() ?: [];

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'identified' => (int) ($row['identified'] ?? 0),
            'discarded' => (int) ($row['discarded'] ?? 0),
            'pending_amount' => BillingService::moneyToCents($row['pending_amount'] ?? '0'),
        ];
    }

    public function pendingCount(): int
    {
        try {
            return $this->counts()['pending'];
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * Sugerencias de a qué nota corresponde una consignación, de mayor a menor confianza.
     *
     * @param array<string, mixed> $deposit fila de list()
     * @return list<array{kind: string, reason: string, statement_id: int, unit_name: string, reference: ?string, balance: int, report_id?: int}>
     */
    public function suggestions(array $deposit): array
    {
        $amount = (int) $deposit['amount_cents'];
        $depositedOn = (string) $deposit['deposited_on'];
        $byUnit = [];
        foreach ($this->openPortfolio() as $row) {
            $byUnit[$row['unit_id']] = $row;
        }
        $references = $this->references->forUnits(array_keys($byUnit));
        $suggestions = [];
        $seen = [];
        $add = function (string $kind, string $reason, array $row, ?int $reportId = null) use (&$suggestions, &$seen, $references): void {
            $key = $kind . ':' . $row['statement_id'] . ':' . ($reportId ?? 0);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $suggestion = [
                'kind' => $kind,
                'reason' => $reason,
                'statement_id' => (int) $row['statement_id'],
                'unit_name' => (string) $row['unit_name'],
                'reference' => $references[$row['unit_id']] ?? null,
                'balance' => (int) $row['balance'],
            ];
            if ($reportId !== null) {
                $suggestion['report_id'] = $reportId;
            }
            $suggestions[] = $suggestion;
        };

        // 1. Referencia de pago escrita en los datos del banco.
        $text = $deposit['bank_reference'] . ' ' . $deposit['payer_detail'] . ' ' . $deposit['notes'];
        foreach ($this->references->findInText($text) as $reference => $unitId) {
            if (isset($byUnit[$unitId])) {
                $add('reference', 'La referencia de pago ' . $reference . ' corresponde a este inmueble.', $byUnit[$unitId]);
            }
        }

        // 2. Pago reportado por un residente con el mismo valor y fecha cercana.
        foreach ($this->openReports() as $report) {
            $days = abs((int) (new DateTimeImmutable($report['paid_on']))->diff(new DateTimeImmutable($depositedOn))->format('%r%a'));
            if ($report['amount_cents'] === $amount && $days <= self::REPORT_DATE_TOLERANCE_DAYS) {
                $row = $byUnit[$report['unit_id']] ?? [
                    'statement_id' => $report['statement_id'], 'unit_id' => $report['unit_id'],
                    'unit_name' => $report['unit_name'], 'balance' => 0,
                ];
                $add('report', sprintf(
                    '%s reportó un pago por el mismo valor el %s%s.',
                    $report['username'],
                    date('d/m/Y', strtotime($report['paid_on']) ?: 0),
                    $report['reference'] !== '' ? ' (ref. ' . $report['reference'] . ')' : ''
                ), $row, (int) $report['id']);
            }
        }

        // 3. Saldo pendiente igual al valor, o igual al valor con el descuento de pronto pago.
        foreach ($byUnit as $row) {
            $earlyAmount = $row['discount'] === 0 && $row['early_discount'] > 0 && $row['early_payment_date'] !== null
                && $depositedOn <= $row['early_payment_date']
                ? $row['balance'] - $row['early_discount']
                : null;
            if ($row['balance'] === $amount) {
                $add('amount', 'El saldo pendiente de la nota es exactamente este valor.', $row);
            } elseif ($earlyAmount !== null && $earlyAmount === $amount) {
                $add('amount', 'Coincide con el saldo menos el descuento de pronto pago.', $row);
            }
        }

        return $suggestions;
    }

    /**
     * Inmuebles con su nota más reciente, para asignar a mano.
     *
     * @return list<array{statement_id: int, unit_name: string, period: string, balance: int, reference: ?string}>
     */
    public function assignableStatements(): array
    {
        $rows = (new ReportService())->portfolio();
        $references = $this->references->forUnits(array_column($rows, 'unit_id'));

        return array_map(static fn (array $row): array => [
            'statement_id' => $row['statement_id'],
            'unit_name' => $row['unit_name'],
            'period' => $row['period'],
            'balance' => $row['balance'],
            'reference' => $references[$row['unit_id']] ?? null,
        ], $rows);
    }

    /**
     * Identifica la consignación: registra el pago en la nota (la más reciente del inmueble) y, si viene de un pago
     * reportado por el residente, lo aprueba en lugar de duplicarlo. Devuelve el id del pago.
     */
    public function identify(int $depositId, int $statementId, ?int $reportId, int $actorId, ?string $resolutionNote = null): int
    {
        $pdo = Database::connection();
        $claim = $pdo->prepare(
            "UPDATE billing_unidentified_deposits SET status = 'processing' WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $claim->execute([$depositId, Tenant::id()]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException('La consignación ya fue identificada, descartada o no existe.');
        }

        try {
            $deposit = $this->find($depositId);
            $amount = self::decimal((int) $deposit['amount_cents']);
            if ($reportId !== null) {
                $paymentId = (new PaymentReportService())->approve($reportId, $amount, (string) $deposit['deposited_on'], $actorId);
            } else {
                $target = (new BillingService())->statement($statementId);
                if ($target['superseded_by'] !== null) {
                    $statementId = (int) $target['superseded_by']['id'];
                }
                $paymentId = (new BillingService())->addPayment(
                    $statementId,
                    $amount,
                    (string) $deposit['deposited_on'],
                    mb_substr(trim('Consignación #' . $depositId . ' ' . $deposit['bank_reference']), 0, 80, 'UTF-8'),
                    mb_substr(trim(self::CHANNEL_LABELS[$deposit['channel']] . ' por identificar. ' . $deposit['payer_detail']), 0, 255, 'UTF-8'),
                    $actorId
                );
            }
            $paymentStatement = $pdo->prepare('SELECT statement_id FROM billing_payments WHERE id = ? AND tenant_id = ?');
            $paymentStatement->execute([$paymentId, Tenant::id()]);
            $pdo->prepare(
                "UPDATE billing_unidentified_deposits
                 SET status = 'identified', statement_id = ?, payment_id = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP,
                     resolution_note = ?
                 WHERE id = ? AND tenant_id = ?"
            )->execute([
                (int) $paymentStatement->fetchColumn(), $paymentId, $actorId,
                $reportId !== null ? 'Coincide con el pago reportado #' . $reportId : $resolutionNote,
                $depositId, Tenant::id(),
            ]);
        } catch (Throwable $exception) {
            $pdo->prepare(
                "UPDATE billing_unidentified_deposits SET status = 'pending' WHERE id = ? AND tenant_id = ? AND status = 'processing'"
            )->execute([$depositId, Tenant::id()]);
            throw $exception;
        }

        // El pago ya quedó registrado: los reclamos que sigan en revisión eran de otro inmueble.
        (new DepositClaimService())->closePending($depositId, 'La consignación se identificó con otro inmueble.', $actorId);

        return $paymentId;
    }

    /** Muestra u oculta la consignación en el portal de residentes (allí solo se ven fecha, valor y medio). */
    public function setPublished(int $depositId, bool $published): void
    {
        $statement = Database::connection()->prepare(
            "UPDATE billing_unidentified_deposits SET published_to_residents = ?
             WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $statement->execute([$published ? 1 : 0, $depositId, Tenant::id()]);
        if ($statement->rowCount() === 0 && !$this->isPending($depositId)) {
            throw new RuntimeException('La consignación ya fue identificada, descartada o no existe.');
        }
    }

    private function isPending(int $depositId): bool
    {
        $statement = Database::connection()->prepare(
            "SELECT 1 FROM billing_unidentified_deposits WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $statement->execute([$depositId, Tenant::id()]);

        return $statement->fetchColumn() !== false;
    }

    /** Descarta una consignación registrada por error, duplicada o devuelta por el banco. Queda con el motivo. */
    public function discard(int $depositId, string $reason, int $actorId): void
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 255) {
            throw new RuntimeException('Escribe el motivo para descartarla (entre 5 y 255 caracteres).');
        }
        $statement = Database::connection()->prepare(
            "UPDATE billing_unidentified_deposits
             SET status = 'discarded', resolution_note = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $statement->execute([$reason, $actorId, $depositId, Tenant::id()]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('La consignación ya fue identificada, descartada o no existe.');
        }
        (new DepositClaimService())->closePending($depositId, 'La administración descartó esta consignación: ' . $reason, $actorId);
    }

    /** @return array{path: string, mime_type: string, byte_size: int, download_name: string}|null */
    public function proof(int $depositId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT proof_storage_name, proof_mime_type, proof_byte_size FROM billing_unidentified_deposits
             WHERE id = ? AND tenant_id = ? AND proof_storage_name IS NOT NULL'
        );
        $statement->execute([$depositId, Tenant::id()]);
        $row = $statement->fetch();
        if (!is_array($row) || preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$/D', (string) $row['proof_storage_name']) !== 1) {
            return null;
        }
        $path = Storage::directory('deposit-proofs') . DIRECTORY_SEPARATOR . $row['proof_storage_name'];
        if (!is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'mime_type' => (string) $row['proof_mime_type'],
            'byte_size' => (int) $row['proof_byte_size'],
            'download_name' => 'consignacion-' . $depositId . '.' . pathinfo((string) $row['proof_storage_name'], PATHINFO_EXTENSION),
        ];
    }

    private function find(int $depositId): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM billing_unidentified_deposits WHERE id = ? AND tenant_id = ?');
        $statement->execute([$depositId, Tenant::id()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('La consignación no existe.');
        }
        $row['amount_cents'] = BillingService::moneyToCents($row['amount']);

        return $row;
    }

    /** Nota más reciente de cada inmueble con saldo pendiente (una sola consulta para todas las sugerencias). */
    private function openPortfolio(): array
    {
        return $this->portfolio ??= array_values(array_filter(
            (new ReportService())->portfolio(),
            static fn (array $row): bool => $row['balance'] > 0
        ));
    }

    /** Pagos reportados por residentes que siguen en revisión. */
    private function openReports(): array
    {
        if ($this->openReports !== null) {
            return $this->openReports;
        }
        try {
            $statement = Database::connection()->prepare(
                "SELECT reports.id, reports.statement_id, reports.amount, reports.paid_on, reports.reference,
                        users.username, statements.unit_id, statements.unit_name
                 FROM billing_payment_reports AS reports
                 JOIN admin_users AS users ON users.id = reports.user_id
                 JOIN billing_statements AS statements ON statements.id = reports.statement_id
                 WHERE reports.tenant_id = ? AND reports.status = 'pending'"
            );
            $statement->execute([Tenant::id()]);
            $rows = $statement->fetchAll();
        } catch (PDOException) {
            $rows = [];
        }

        return $this->openReports = array_map(static function (array $row): array {
            $row['amount_cents'] = BillingService::moneyToCents($row['amount']);
            $row['reference'] = (string) $row['reference'];

            return $row;
        }, $rows);
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
