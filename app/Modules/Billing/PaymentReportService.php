<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Storage;
use App\Core\Tenant;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Pagos que el residente informa (transferencia, consignación…) con su soporte. No mueven la cartera hasta que
 * la administración los aprueba: al aprobar se registra un pago normal en la nota vigente del inmueble.
 */
final class PaymentReportService
{
    public const METHOD_LABELS = [
        'transferencia' => 'Transferencia bancaria',
        'consignacion' => 'Consignación',
        'efectivo' => 'Efectivo en la administración',
        'otro' => 'Otro medio',
    ];

    public const STATUS_LABELS = [
        'pending' => 'En revisión',
        'processing' => 'En revisión',
        'approved' => 'Aprobado',
        'rejected' => 'Rechazado',
    ];

    private const MAX_PROOF_BYTES = 5 * 1024 * 1024;
    private const MAX_PENDING_PER_STATEMENT = 3;
    private const PROOF_FORMATS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** @param array<string, mixed> $file entrada de $_FILES */
    public function create(int $userId, array $data, array $file): int
    {
        $statementId = filter_var($data['statement_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = trim(is_string($data['amount'] ?? null) ? $data['amount'] : '');
        $paidOn = trim(is_string($data['paid_on'] ?? null) ? $data['paid_on'] : '');
        $method = is_string($data['method'] ?? null) ? $data['method'] : '';
        $reference = trim(is_string($data['reference'] ?? null) ? $data['reference'] : '');
        $notes = trim(is_string($data['notes'] ?? null) ? $data['notes'] : '');

        if (!is_int($statementId) || $statementId < 1) {
            throw new RuntimeException('Selecciona la nota de cobro que pagaste.');
        }
        $amountCents = BillingService::moneyToCents($amount);
        if ($amountCents < 1 || $amountCents > 99999999999999) {
            throw new RuntimeException('Ingresa el valor pagado (mayor que cero, hasta dos decimales).');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $paidOn
            || $paidOn > (new DateTimeImmutable('today'))->format('Y-m-d')
            || $paidOn < (new DateTimeImmutable('today'))->modify('-1 year')->format('Y-m-d')
        ) {
            throw new RuntimeException('Indica la fecha del pago: no puede ser futura ni de hace más de un año.');
        }
        if (!isset(self::METHOD_LABELS[$method])) {
            throw new RuntimeException('Selecciona el medio de pago.');
        }
        if (mb_strlen($reference, 'UTF-8') > 80 || mb_strlen($notes, 'UTF-8') > 255) {
            throw new RuntimeException('La referencia admite hasta 80 caracteres y las observaciones hasta 255.');
        }

        $statement = (new BillingService())->payableStatement($statementId);
        if (!BillingService::isRecipient($statement, $userId)) {
            throw new RuntimeException('La nota de cobro no corresponde a tus inmuebles.');
        }

        $pending = Database::connection()->prepare(
            "SELECT COUNT(*) FROM billing_payment_reports
             WHERE tenant_id = ? AND statement_id = ? AND user_id = ? AND status IN ('pending', 'processing')"
        );
        $pending->execute([Tenant::id(), $statementId, $userId]);
        if ((int) $pending->fetchColumn() >= self::MAX_PENDING_PER_STATEMENT) {
            throw new RuntimeException('Ya tienes pagos en revisión para esta nota. Espera la respuesta de la administración.');
        }

        $proof = $this->validateProof($file);
        $storageName = bin2hex(random_bytes(16)) . '.' . $proof['extension'];
        $destination = Storage::directory('payment-proofs') . DIRECTORY_SEPARATOR . $storageName;
        if (!move_uploaded_file($proof['temporary_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar el soporte. Intenta nuevamente.');
        }

        try {
            Database::connection()->prepare(
                'INSERT INTO billing_payment_reports
                    (tenant_id, statement_id, user_id, amount, paid_on, method, reference, notes,
                     proof_storage_name, proof_mime_type, proof_byte_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                Tenant::id(),
                $statementId,
                $userId,
                sprintf('%d.%02d', intdiv($amountCents, 100), $amountCents % 100),
                $paidOn,
                $method,
                $reference,
                $notes,
                $storageName,
                $proof['mime_type'],
                $proof['byte_size'],
            ]);
            $reportId = (int) Database::connection()->lastInsertId();
        } catch (Throwable $exception) {
            if (is_file($destination)) {
                @unlink($destination);
            }
            throw $exception;
        }

        PaymentNotificationService::defer('report_created', ['report_id' => $reportId]);

        return $reportId;
    }

    public function forUser(int $userId): array
    {
        $query = Database::connection()->prepare(
            'SELECT reports.id, reports.statement_id, reports.amount, reports.paid_on, reports.method, reports.reference,
                    reports.status, reports.review_notes, reports.payment_id, reports.created_at,
                    statements.unit_name, statements.period
             FROM billing_payment_reports AS reports
             JOIN billing_statements AS statements ON statements.id = reports.statement_id
             WHERE reports.tenant_id = ? AND reports.user_id = ?
             ORDER BY reports.created_at DESC, reports.id DESC
             LIMIT 50'
        );
        $query->execute([Tenant::id(), $userId]);

        return $query->fetchAll();
    }

    /** @param 'open'|'approved'|'rejected'|'reviewed'|'all' $filter */
    public function forReview(string $filter = 'open'): array
    {
        $where = match ($filter) {
            'approved' => "reports.status = 'approved'",
            'rejected' => "reports.status = 'rejected'",
            'reviewed' => "reports.status IN ('approved', 'rejected')",
            'all' => '1 = 1',
            default => "reports.status IN ('pending', 'processing')",
        };
        $query = Database::connection()->prepare(
            "SELECT reports.*, users.username, users.email, statements.unit_name, statements.period,
                    reviewers.username AS reviewer_name
             FROM billing_payment_reports AS reports
             JOIN admin_users AS users ON users.id = reports.user_id
             JOIN billing_statements AS statements ON statements.id = reports.statement_id
             LEFT JOIN admin_users AS reviewers ON reviewers.id = reports.reviewed_by
             WHERE reports.tenant_id = ? AND {$where}
             ORDER BY reports.created_at " . ($filter === 'open' ? 'ASC' : 'DESC') . ', reports.id
             LIMIT ' . ($filter === 'reviewed' ? 20 : 200)
        );
        $query->execute([Tenant::id()]);

        return $query->fetchAll();
    }

    public function pendingCount(): int
    {
        try {
            $query = Database::connection()->prepare(
                "SELECT COUNT(*) FROM billing_payment_reports WHERE tenant_id = ? AND status IN ('pending', 'processing')"
            );
            $query->execute([Tenant::id()]);

            return (int) $query->fetchColumn();
        } catch (\PDOException) {
            return 0; // Sin la migración 025 no hay pagos reportados.
        }
    }

    /**
     * Aprueba el reporte registrando el pago en la nota vigente del inmueble (si el saldo ya se trasladó a una
     * nota posterior, el pago va a esa). El valor y la fecha pueden corregirse contra el extracto bancario.
     */
    public function approve(int $reportId, string $amount, string $receivedOn, int $actorId): int
    {
        $pdo = Database::connection();
        $claim = $pdo->prepare(
            "UPDATE billing_payment_reports SET status = 'processing', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $claim->execute([$actorId, $reportId, Tenant::id()]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException('El pago reportado ya fue revisado o no existe.');
        }

        try {
            $report = $this->find($reportId);
            $targetStatementId = $this->currentStatementFor((int) $report['statement_id']);
            $reference = mb_substr(trim('Reporte #' . $reportId . ' ' . (string) $report['reference']), 0, 80, 'UTF-8');
            $notes = mb_substr(
                'Pago reportado por ' . $report['username'] . ' · ' . (self::METHOD_LABELS[$report['method']] ?? $report['method']),
                0,
                255,
                'UTF-8'
            );
            $paymentId = (new BillingService())->addPayment(
                $targetStatementId,
                $amount,
                $receivedOn,
                $reference,
                $notes,
                $actorId,
                'report'
            );
            $pdo->prepare(
                "UPDATE billing_payment_reports SET status = 'approved', payment_id = ? WHERE id = ? AND tenant_id = ?"
            )->execute([$paymentId, $reportId, Tenant::id()]);

            return $paymentId;
        } catch (Throwable $exception) {
            $pdo->prepare(
                "UPDATE billing_payment_reports SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL
                 WHERE id = ? AND tenant_id = ? AND status = 'processing'"
            )->execute([$reportId, Tenant::id()]);
            throw $exception;
        }
    }

    public function reject(int $reportId, string $reason, int $actorId): void
    {
        $length = mb_strlen($reason, 'UTF-8');
        if ($length < 5 || $length > 255) {
            throw new RuntimeException('Explica al residente por qué no se aprueba (entre 5 y 255 caracteres).');
        }
        $update = Database::connection()->prepare(
            "UPDATE billing_payment_reports
             SET status = 'rejected', review_notes = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $update->execute([$reason, $actorId, $reportId, Tenant::id()]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('El pago reportado ya fue revisado o no existe.');
        }
        PaymentNotificationService::defer('report_rejected', ['report_id' => $reportId]);
    }

    /**
     * Archivo de soporte para mostrarlo. Con $userId solo lo entrega a quien lo reportó.
     *
     * @return array{path: string, mime_type: string, byte_size: int, download_name: string}|null
     */
    public function proof(int $reportId, ?int $userId = null): ?array
    {
        $query = Database::connection()->prepare(
            'SELECT id, user_id, proof_storage_name, proof_mime_type, proof_byte_size
             FROM billing_payment_reports WHERE id = ? AND tenant_id = ?'
        );
        $query->execute([$reportId, Tenant::id()]);
        $row = $query->fetch();
        if (!is_array($row) || ($userId !== null && (int) $row['user_id'] !== $userId)) {
            return null;
        }
        $name = (string) $row['proof_storage_name'];
        if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$/D', $name)
            || !isset(self::PROOF_FORMATS[(string) $row['proof_mime_type']])
        ) {
            return null;
        }
        $path = Storage::directory('payment-proofs') . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'mime_type' => (string) $row['proof_mime_type'],
            'byte_size' => (int) $row['proof_byte_size'],
            'download_name' => 'soporte-pago-' . (int) $row['id'] . '.' . pathinfo($name, PATHINFO_EXTENSION),
        ];
    }

    private function find(int $reportId): array
    {
        $query = Database::connection()->prepare(
            'SELECT reports.*, users.username
             FROM billing_payment_reports AS reports
             JOIN admin_users AS users ON users.id = reports.user_id
             WHERE reports.id = ? AND reports.tenant_id = ?'
        );
        $query->execute([$reportId, Tenant::id()]);
        $report = $query->fetch();
        if (!is_array($report)) {
            throw new RuntimeException('El pago reportado no existe.');
        }

        return $report;
    }

    /** La nota más reciente del mismo inmueble: allí vive hoy el saldo. */
    private function currentStatementFor(int $statementId): int
    {
        $query = Database::connection()->prepare(
            'SELECT latest.id
             FROM billing_statements AS original
             JOIN billing_statements AS latest
               ON latest.tenant_id = original.tenant_id AND latest.unit_id = original.unit_id
             WHERE original.id = ? AND original.tenant_id = ?
             ORDER BY latest.period DESC
             LIMIT 1'
        );
        $query->execute([$statementId, Tenant::id()]);
        $latest = $query->fetchColumn();
        if ($latest === false) {
            throw new RuntimeException('La nota de cobro del pago reportado ya no existe.');
        }

        return (int) $latest;
    }

    /**
     * Valida un soporte subido (imagen o PDF de máximo 5 MB, comprobando el contenido real). También lo usan las
     * consignaciones por identificar.
     *
     * @return array{temporary_name: string, mime_type: string, extension: string, byte_size: int}
     */
    public static function validateProof(array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Adjunta el soporte del pago (imagen o PDF).');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('El soporte no se cargó correctamente. El tamaño máximo es 5 MB.');
        }
        $temporaryName = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        if (!is_string($temporaryName) || !is_uploaded_file($temporaryName)
            || !is_int($size) || $size < 1 || $size > self::MAX_PROOF_BYTES
        ) {
            throw new RuntimeException('El soporte debe pesar máximo 5 MB.');
        }
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryName);
        if (!is_string($mimeType) || !isset(self::PROOF_FORMATS[$mimeType])) {
            throw new RuntimeException('El soporte debe ser una imagen JPEG, PNG, WebP o un PDF.');
        }
        if ($mimeType !== 'application/pdf' && !is_array(@getimagesize($temporaryName))) {
            throw new RuntimeException('La imagen del soporte no es válida.');
        }
        if ($mimeType === 'application/pdf') {
            $handle = fopen($temporaryName, 'rb');
            $header = $handle !== false ? (string) fread($handle, 5) : '';
            if ($handle !== false) {
                fclose($handle);
            }
            if ($header !== '%PDF-') {
                throw new RuntimeException('El PDF del soporte no es válido.');
            }
        }

        return [
            'temporary_name' => $temporaryName,
            'mime_type' => $mimeType,
            'extension' => self::PROOF_FORMATS[$mimeType],
            'byte_size' => $size,
        ];
    }

}
