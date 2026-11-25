<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Tenant;
use DateTimeImmutable;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Importación de extractos bancarios en dos pasos.
 *   1. upload(): lee el archivo, descarta lo ya importado y propone una acción por ingreso:
 *        apply        = trae la referencia de pago de un inmueble -> se registra como pago de su nota;
 *        report       = coincide con un único pago reportado por un residente -> se aprueba ese reporte;
 *        unidentified = sin pistas seguras -> va a Consignaciones por identificar (con sus sugerencias por valor);
 *        omit         = ya importado, ya registrado o dispersión de una pasarela de pago.
 *   2. confirm(): el administrador revisa y confirma; cada ingreso pasa por Consignaciones por identificar para que
 *      quede un solo rastro (y la identificación reutiliza el pago, el comprobante y los avisos de siempre).
 */
final class BankImportService
{
    public const ACTION_LABELS = [
        'apply' => 'Registrar como pago',
        'report' => 'Aprobar el pago reportado',
        'unidentified' => 'Enviar a por identificar',
        'omit' => 'Omitir',
    ];
    public const STATUS_LABELS = [
        'pending' => 'Pendiente',
        'applied' => 'Registrado como pago',
        'unidentified' => 'Por identificar',
        'omitted' => 'Omitido',
        'failed' => 'No se pudo aplicar',
    ];
    /** Abonos de pasarelas: su pago ya lo registró la pasarela; importarlos lo duplicaría. */
    private const GATEWAY_PATTERN = '/\b(BOLD|WOMPI|PAYU|EPAYCO|MERCADO\s?PAGO|PLACE\s?TO\s?PAY)\b/i';
    private const PAYOUT_PATTERN = '/\bDISPERSI\w*\b/i';
    private const REPORT_DAYS = 5;
    private const PAYMENT_DAYS = 2;

    public function __construct(
        private readonly BankStatementParser $parser = new BankStatementParser(),
        private readonly PaymentReferenceService $references = new PaymentReferenceService(),
    ) {
    }

    /**
     * @param array<string, mixed> $file entrada de $_FILES
     * @return array{import_id: int, warnings: list<string>, skipped: int}
     */
    public function upload(array $file, int $actorId): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Selecciona el archivo del extracto.');
        }
        $temporary = $file['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($temporary) || !is_uploaded_file($temporary)) {
            throw new RuntimeException('El archivo no se cargó correctamente. El tamaño máximo es 3 MB.');
        }

        return $this->importFile($temporary, (string) ($file['name'] ?? 'extracto'), $actorId);
    }

    /**
     * Lee un archivo ya validado y crea el borrador de importación (también lo usan las pruebas).
     *
     * @return array{import_id: int, warnings: list<string>, skipped: int}
     */
    public function importFile(string $path, string $originalName, int $actorId): array
    {
        $parsed = $this->parser->parse($path, $originalName);
        if ($parsed['movements'] === []) {
            throw new RuntimeException(implode(' ', $parsed['warnings']) ?: 'El archivo no trae ingresos.');
        }
        $fileName = mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', basename($originalName)) ?? 'extracto', 0, 160, 'UTF-8');

        $pdo = Database::connection();
        // Los borradores abandonados no sirven: se descartan los de más de un día.
        $pdo->prepare("DELETE FROM billing_bank_imports WHERE tenant_id = ? AND status = 'draft' AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")
            ->execute([Tenant::id()]);

        $movements = $this->withFingerprints($parsed['movements']);
        $proposals = $this->propose($movements);
        $total = array_sum(array_column($movements, 'amount_cents'));

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO billing_bank_imports (tenant_id, file_name, format, movements_count, total_amount, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([Tenant::id(), $fileName, $parsed['format'], count($movements), self::decimal($total), $actorId]);
            $importId = (int) $pdo->lastInsertId();
            $insert = $pdo->prepare(
                'INSERT INTO billing_bank_movements
                    (tenant_id, import_id, line_number, movement_date, amount, description, reference, document, fingerprint,
                     proposed_action, proposed_statement_id, proposed_report_id, proposal_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($movements as $index => $movement) {
                $proposal = $proposals[$index];
                $insert->execute([
                    Tenant::id(), $importId, $movement['line'], $movement['date'], self::decimal($movement['amount_cents']),
                    $movement['description'], $movement['reference'], $movement['document'], $movement['fingerprint'],
                    $proposal['action'], $proposal['statement_id'], $proposal['report_id'], mb_substr($proposal['note'], 0, 255, 'UTF-8'),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return ['import_id' => $importId, 'warnings' => $parsed['warnings'], 'skipped' => $parsed['skipped']];
    }

    /** @return array{import: array<string, mixed>, movements: list<array<string, mixed>>} */
    public function review(int $importId): array
    {
        $import = Database::connection()->prepare(
            'SELECT imports.*, uploaders.username AS uploaded_by_name, confirmers.username AS confirmed_by_name
             FROM billing_bank_imports AS imports
             LEFT JOIN admin_users AS uploaders ON uploaders.id = imports.uploaded_by
             LEFT JOIN admin_users AS confirmers ON confirmers.id = imports.confirmed_by
             WHERE imports.id = ? AND imports.tenant_id = ?'
        );
        $import->execute([$importId, Tenant::id()]);
        $row = $import->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('La importación no existe.');
        }
        $movements = Database::connection()->prepare(
            'SELECT movements.*, statements.unit_name, users.username AS report_username
             FROM billing_bank_movements AS movements
             LEFT JOIN billing_statements AS statements ON statements.id = movements.proposed_statement_id
             LEFT JOIN billing_payment_reports AS reports ON reports.id = movements.proposed_report_id
             LEFT JOIN admin_users AS users ON users.id = reports.user_id
             WHERE movements.import_id = ? AND movements.tenant_id = ?
             ORDER BY movements.line_number'
        );
        $movements->execute([$importId, Tenant::id()]);

        return [
            'import' => $row,
            'movements' => array_map(static function (array $movement): array {
                $movement['id'] = (int) $movement['id'];
                $movement['amount_cents'] = BillingService::moneyToCents($movement['amount']);

                return $movement;
            }, $movements->fetchAll()),
        ];
    }

    /**
     * Ejecuta las acciones elegidas. $actions: id del movimiento => acción. Una acción que no corresponde a la
     * propuesta (p. ej. «apply» sin inmueble) se trata como «unidentified».
     *
     * @param array<int|string, string> $actions
     * @return array<string, int> conteo por resultado
     */
    public function confirm(int $importId, array $actions, int $actorId): array
    {
        $pdo = Database::connection();
        $claim = $pdo->prepare(
            "UPDATE billing_bank_imports SET status = 'confirmed', confirmed_by = ?, confirmed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tenant_id = ? AND status = 'draft'"
        );
        $claim->execute([$actorId, $importId, Tenant::id()]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException('Esta importación ya se confirmó o no existe.');
        }

        $deposits = new UnidentifiedDepositService();
        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($this->review($importId)['movements'] as $movement) {
            $action = (string) ($actions[$movement['id']] ?? $movement['proposed_action']);
            if (!isset(self::ACTION_LABELS[$action])
                || ($action === 'apply' && $movement['proposed_statement_id'] === null)
                || ($action === 'report' && $movement['proposed_report_id'] === null)
            ) {
                $action = 'unidentified';
            }
            [$status, $note, $depositId, $paymentId] = $this->execute($movement, $action, $importId, $deposits, $actorId);
            try {
                $pdo->prepare(
                    'UPDATE billing_bank_movements
                     SET status = ?, result_note = ?, deposit_id = ?, payment_id = ?, fingerprint_final = ?
                     WHERE id = ? AND tenant_id = ?'
                )->execute([$status, $note, $depositId, $paymentId, $status === 'failed' ? null : $movement['fingerprint'], $movement['id'], Tenant::id()]);
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
                // Otra importación simultánea ya registró este mismo movimiento.
                $status = 'failed';
                $pdo->prepare("UPDATE billing_bank_movements SET status = 'failed', result_note = ? WHERE id = ? AND tenant_id = ?")
                    ->execute(['Este movimiento ya se importó en otro extracto.', $movement['id'], Tenant::id()]);
            }
            $counts[$status]++;
        }

        return $counts;
    }

    public function discard(int $importId): void
    {
        $statement = Database::connection()->prepare(
            "DELETE FROM billing_bank_imports WHERE id = ? AND tenant_id = ? AND status = 'draft'"
        );
        $statement->execute([$importId, Tenant::id()]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('La importación ya se confirmó o no existe.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function recentImports(): array
    {
        $statement = Database::connection()->prepare(
            "SELECT imports.*, uploaders.username AS uploaded_by_name,
                    SUM(movements.status = 'applied') AS applied, SUM(movements.status = 'unidentified') AS unidentified,
                    SUM(movements.status = 'omitted') AS omitted, SUM(movements.status = 'failed') AS failed
             FROM billing_bank_imports AS imports
             LEFT JOIN admin_users AS uploaders ON uploaders.id = imports.uploaded_by
             LEFT JOIN billing_bank_movements AS movements ON movements.import_id = imports.id
             WHERE imports.tenant_id = ?
             GROUP BY imports.id
             ORDER BY imports.created_at DESC, imports.id DESC
             LIMIT 20"
        );
        $statement->execute([Tenant::id()]);

        return $statement->fetchAll();
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?int, 3: ?int} [estado, nota, deposit_id, payment_id]
     */
    private function execute(array $movement, string $action, int $importId, UnidentifiedDepositService $deposits, int $actorId): array
    {
        if ($action === 'omit') {
            return ['omitted', $movement['proposal_note'] !== '' ? $movement['proposal_note'] : 'Omitido por la administración.', null, null];
        }
        try {
            $depositId = $deposits->create([
                'amount' => self::decimal($movement['amount_cents']),
                'deposited_on' => (string) $movement['movement_date'],
                'channel' => self::channel($movement['description'] . ' ' . $movement['reference']),
                'bank_reference' => mb_substr(trim($movement['reference'] !== '' ? $movement['reference'] : $movement['document']), 0, 80, 'UTF-8'),
                'payer_detail' => mb_substr((string) $movement['description'], 0, 160, 'UTF-8'),
                'notes' => 'Extracto #' . $importId . ', línea ' . $movement['line_number'],
            ], null, $actorId);
        } catch (PDOException $exception) {
            error_log('Importación de extracto #' . $importId . ': no se registró la línea ' . $movement['line_number'] . ': ' . $exception->getMessage());

            return ['failed', 'Error al guardar el movimiento; vuelve a importarlo o regístralo a mano.', null, null];
        } catch (RuntimeException $exception) {
            return ['failed', mb_substr($exception->getMessage(), 0, 255, 'UTF-8'), null, null];
        }
        if ($action === 'unidentified') {
            return ['unidentified', null, $depositId, null];
        }
        try {
            $paymentId = $deposits->identify(
                $depositId,
                (int) $movement['proposed_statement_id'],
                $action === 'report' ? (int) $movement['proposed_report_id'] : null,
                $actorId,
                $action === 'apply' ? 'Identificada por la referencia de pago en el extracto #' . $importId : null
            );

            return ['applied', null, $depositId, $paymentId];
        } catch (PDOException $exception) {
            error_log('Importación de extracto #' . $importId . ': no se aplicó la línea ' . $movement['line_number'] . ': ' . $exception->getMessage());

            return ['unidentified', 'No se aplicó automáticamente por un error interno; aplícala a mano en Consignaciones por identificar.', $depositId, null];
        } catch (RuntimeException $exception) {
            // Quedó registrada como consignación por identificar: se puede aplicar a mano desde allí.
            return ['unidentified', mb_substr('No se aplicó automáticamente: ' . $exception->getMessage(), 0, 255, 'UTF-8'), $depositId, null];
        }
    }

    /**
     * Huella de cada movimiento para no importarlo dos veces. Dos movimientos idénticos en el mismo archivo (mismo día,
     * valor y descripción) se distinguen por su orden de aparición.
     *
     * @param list<array<string, mixed>> $movements
     * @return list<array<string, mixed>>
     */
    private function withFingerprints(array $movements): array
    {
        $seen = [];
        foreach ($movements as &$movement) {
            $base = implode('|', [
                Tenant::id(), $movement['date'], $movement['amount_cents'],
                mb_strtolower(preg_replace('/\s+/', ' ', $movement['description']) ?? '', 'UTF-8'),
                $movement['reference'], $movement['document'],
            ]);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $movement['fingerprint'] = hash('sha256', $base . '|' . $seen[$base]);
        }

        return $movements;
    }

    /**
     * @param list<array<string, mixed>> $movements
     * @return list<array{action: string, statement_id: ?int, report_id: ?int, note: string}>
     */
    private function propose(array $movements): array
    {
        $imported = $this->alreadyImported(array_column($movements, 'fingerprint'));
        $latestByUnit = [];
        foreach ((new ReportService())->portfolio() as $row) {
            $latestByUnit[$row['unit_id']] = $row;
        }
        $reports = $this->pendingReports();
        $proposals = [];
        foreach ($movements as $movement) {
            $proposals[] = $this->proposeOne($movement, $imported, $latestByUnit, $reports);
        }

        return $proposals;
    }

    private function proposeOne(array $movement, array $imported, array $latestByUnit, array $reports): array
    {
        $result = static fn (string $action, string $note, ?int $statementId = null, ?int $reportId = null): array => [
            'action' => $action, 'statement_id' => $statementId, 'report_id' => $reportId, 'note' => $note,
        ];
        if (isset($imported[$movement['fingerprint']])) {
            return $result('omit', 'Ya se importó en un extracto anterior.');
        }
        $text = $movement['description'] . ' ' . $movement['reference'] . ' ' . $movement['document'];
        if (preg_match(self::GATEWAY_PATTERN, $text, $gateway) === 1) {
            return $result('omit', 'Parece un abono de la pasarela ' . strtoupper($gateway[1]) . ': esos pagos ya se registraron al pagar en línea.');
        }
        if (preg_match(self::PAYOUT_PATTERN, $text) === 1) {
            return $result('omit', 'Parece la dispersión de una pasarela de pago: esos pagos ya se registraron al pagar en línea.');
        }

        $units = array_values(array_unique($this->references->findInText($text)));
        if (count($units) > 1) {
            return $result('unidentified', 'Trae referencias de varios inmuebles; revísalo a mano.');
        }
        if (count($units) === 1) {
            $unit = $latestByUnit[$units[0]] ?? null;
            if ($unit === null) {
                return $result('unidentified', 'La referencia corresponde a un inmueble sin notas de cobro.');
            }
            $duplicate = $this->existingPayment($units[0], (int) $movement['amount_cents'], (string) $movement['date']);
            if ($duplicate !== null) {
                return $result('omit', 'Ya hay un pago registrado de este inmueble por el mismo valor (' . BillingService::receiptNumber($duplicate) . ').');
            }

            return $result('apply', 'Referencia de pago de ' . $unit['unit_name'] . '.', (int) $unit['statement_id']);
        }

        $matches = array_values(array_filter($reports, function (array $report) use ($movement): bool {
            return $report['amount_cents'] === (int) $movement['amount_cents']
                && abs((int) (new DateTimeImmutable($report['paid_on']))->diff(new DateTimeImmutable($movement['date']))->format('%r%a')) <= self::REPORT_DAYS;
        }));
        if (count($matches) === 1) {
            return $result('report', $matches[0]['username'] . ' reportó un pago por el mismo valor (' . $matches[0]['unit_name'] . ').', (int) $matches[0]['statement_id'], (int) $matches[0]['id']);
        }

        return $result('unidentified', count($matches) > 1 ? 'Coincide con varios pagos reportados; revísalo a mano.' : 'Sin referencia de pago: queda por identificar.');
    }

    /** @return array<string, true> */
    private function alreadyImported(array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($fingerprints), '?'));
        $statement = Database::connection()->prepare(
            "SELECT fingerprint_final FROM billing_bank_movements WHERE tenant_id = ? AND fingerprint_final IN ($placeholders)"
        );
        $statement->execute([Tenant::id(), ...$fingerprints]);

        return array_fill_keys($statement->fetchAll(\PDO::FETCH_COLUMN), true);
    }

    /** Pago vigente del inmueble por el mismo valor en fechas cercanas (p. ej. registrado a mano desde el correo). */
    private function existingPayment(string $unitId, int $amountCents, string $date): ?int
    {
        $statement = Database::connection()->prepare(
            'SELECT payments.id FROM billing_payments AS payments
             JOIN billing_statements AS statements ON statements.id = payments.statement_id
             WHERE payments.tenant_id = ? AND statements.unit_id = ? AND payments.voided_at IS NULL
               AND payments.amount = ? AND payments.received_on BETWEEN DATE_SUB(?, INTERVAL ' . self::PAYMENT_DAYS . ' DAY) AND DATE_ADD(?, INTERVAL ' . self::PAYMENT_DAYS . ' DAY)
             LIMIT 1'
        );
        $statement->execute([Tenant::id(), $unitId, self::decimal($amountCents), $date, $date]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function pendingReports(): array
    {
        try {
            $statement = Database::connection()->prepare(
                "SELECT reports.id, reports.statement_id, reports.amount, reports.paid_on, users.username, statements.unit_name
                 FROM billing_payment_reports AS reports
                 JOIN admin_users AS users ON users.id = reports.user_id
                 JOIN billing_statements AS statements ON statements.id = reports.statement_id
                 WHERE reports.tenant_id = ? AND reports.status = 'pending'"
            );
            $statement->execute([Tenant::id()]);
        } catch (PDOException) {
            return [];
        }

        return array_map(static function (array $row): array {
            $row['amount_cents'] = BillingService::moneyToCents($row['amount']);

            return $row;
        }, $statement->fetchAll());
    }

    private static function channel(string $text): string
    {
        return match (true) {
            preg_match('/CONSIG|DEPOSITO|DEPÓSITO|EFECTIVO|CORRESPONSAL/iu', $text) === 1 => 'consignacion',
            preg_match('/TRANSF|PSE|ACH|TRASLADO|ABONO|NEQUI|DAVIPLATA|BRE-?B|LLAVE/iu', $text) === 1 => 'transferencia',
            default => 'otro',
        };
    }

    private static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
