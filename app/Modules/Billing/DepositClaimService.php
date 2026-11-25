<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Storage;
use App\Core\Tenant;
use App\Modules\Auth\UserNotifier;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * «Es mía»: el residente reclama desde el portal una consignación por identificar, con su soporte y la nota a la
 * que corresponde. La administración aprueba (la consignación se identifica con esa nota y se registra el pago) o
 * rechaza con motivo. En el portal solo se publican fecha, valor y medio de cada consignación.
 */
final class DepositClaimService
{
    public const STATUS_LABELS = ['pending' => 'En revisión', 'approved' => 'Aprobado', 'rejected' => 'Rechazado'];
    private const PUBLIC_DAYS = 120;
    private const MAX_PENDING_PER_USER = 3;

    public function __construct(private readonly UserNotifier $notifier = new UserNotifier())
    {
    }

    /**
     * Consignaciones visibles para los residentes: por identificar, publicadas y recientes. Sin datos personales.
     *
     * @return list<array{id: int, deposited_on: string, amount_cents: int, channel: string}>
     */
    public function publicDeposits(): array
    {
        try {
            $statement = Database::connection()->prepare(
                "SELECT id, deposited_on, amount, channel FROM billing_unidentified_deposits
                 WHERE tenant_id = ? AND status = 'pending' AND published_to_residents = 1
                   AND deposited_on >= DATE_SUB(CURDATE(), INTERVAL " . self::PUBLIC_DAYS . " DAY)
                 ORDER BY deposited_on DESC, id DESC"
            );
            $statement->execute([Tenant::id()]);
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['42S02', '42S22'], true)) {
                return [];
            }
            throw $exception;
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'deposited_on' => (string) $row['deposited_on'],
            'amount_cents' => BillingService::moneyToCents($row['amount']),
            'channel' => (string) $row['channel'],
        ], $statement->fetchAll());
    }

    /** @return list<array<string, mixed>> reclamos del residente con su estado */
    public function forUser(int $userId): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT claims.id, claims.deposit_id, claims.status, claims.review_note, claims.created_at,
                        deposits.amount, deposits.deposited_on, deposits.payment_id, statements.unit_name
                 FROM billing_deposit_claims AS claims
                 JOIN billing_unidentified_deposits AS deposits ON deposits.id = claims.deposit_id
                 JOIN billing_statements AS statements ON statements.id = claims.statement_id
                 WHERE claims.tenant_id = ? AND claims.user_id = ?
                 ORDER BY claims.created_at DESC LIMIT 20'
            );
            $statement->execute([Tenant::id(), $userId]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '42S02') {
                return [];
            }
            throw $exception;
        }

        return array_map(static function (array $row): array {
            $row['amount_cents'] = BillingService::moneyToCents($row['amount']);

            return $row;
        }, $statement->fetchAll());
    }

    /** @param array<string, mixed> $file entrada de $_FILES */
    public function create(int $userId, int $depositId, int $statementId, string $notes, array $file): int
    {
        $notes = trim(preg_replace('/\s+/u', ' ', $notes) ?? '');
        if (mb_strlen($notes, 'UTF-8') > 255) {
            throw new RuntimeException('La nota admite hasta 255 caracteres.');
        }
        $deposit = Database::connection()->prepare(
            "SELECT id FROM billing_unidentified_deposits
             WHERE id = ? AND tenant_id = ? AND status = 'pending' AND published_to_residents = 1"
        );
        $deposit->execute([$depositId, Tenant::id()]);
        if ($deposit->fetchColumn() === false) {
            throw new RuntimeException('Esa consignación ya fue identificada o no está disponible.');
        }
        try {
            $statement = (new BillingService())->statement($statementId);
        } catch (RuntimeException) {
            throw new RuntimeException('Selecciona una de tus notas de cobro.');
        }
        if (!BillingService::isRecipient($statement, $userId)) {
            throw new RuntimeException('Selecciona una de tus notas de cobro.');
        }
        $pending = Database::connection()->prepare(
            "SELECT COUNT(*) FROM billing_deposit_claims WHERE tenant_id = ? AND user_id = ? AND status = 'pending'"
        );
        $pending->execute([Tenant::id(), $userId]);
        if ((int) $pending->fetchColumn() >= self::MAX_PENDING_PER_USER) {
            throw new RuntimeException('Ya tienes varios reclamos en revisión. Espera la respuesta de la administración.');
        }

        $proof = PaymentReportService::validateProof($file);
        $storageName = bin2hex(random_bytes(16)) . '.' . $proof['extension'];
        $destination = Storage::directory('deposit-claims') . DIRECTORY_SEPARATOR . $storageName;
        if (!move_uploaded_file($proof['temporary_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar el soporte. Intenta nuevamente.');
        }

        try {
            Database::connection()->prepare(
                'INSERT INTO billing_deposit_claims
                    (tenant_id, deposit_id, user_id, statement_id, notes, proof_storage_name, proof_mime_type, proof_byte_size)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([Tenant::id(), $depositId, $userId, $statementId, $notes, $storageName, $proof['mime_type'], $proof['byte_size']]);
            $claimId = (int) Database::connection()->lastInsertId();
        } catch (PDOException $exception) {
            @unlink($destination);
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Ya reclamaste esta consignación. La administración está revisando tu soporte.');
            }
            throw $exception;
        }

        $this->notifier->mailAdministration(
            'Un residente reclamó una consignación por identificar · ' . $statement['unit_name'],
            sprintf(
                "Un residente de %s indicó que la consignación #%d es suya y adjuntó su soporte.\n\nRevísala en Cartera > Consignaciones por identificar%s.",
                $statement['unit_name'],
                $depositId,
                ($url = Tenant::absoluteUrl('/admin/cartera/consignaciones')) !== '' ? ': ' . $url : ''
            )
        );

        return $claimId;
    }

    /**
     * Reclamos pendientes agrupados por consignación (para el panel).
     *
     * @param list<int> $depositIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function pendingForDeposits(array $depositIds): array
    {
        if ($depositIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($depositIds), '?'));
        $statement = Database::connection()->prepare(
            "SELECT claims.id, claims.deposit_id, claims.statement_id, claims.notes, claims.created_at,
                    users.username, users.email, statements.unit_name, statements.period
             FROM billing_deposit_claims AS claims
             JOIN admin_users AS users ON users.id = claims.user_id
             JOIN billing_statements AS statements ON statements.id = claims.statement_id
             WHERE claims.tenant_id = ? AND claims.status = 'pending' AND claims.deposit_id IN ($placeholders)
             ORDER BY claims.created_at"
        );
        $statement->execute([Tenant::id(), ...$depositIds]);
        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $grouped[(int) $row['deposit_id']][] = $row;
        }

        return $grouped;
    }

    public function pendingCount(): int
    {
        try {
            $statement = Database::connection()->prepare(
                "SELECT COUNT(*) FROM billing_deposit_claims WHERE tenant_id = ? AND status = 'pending'"
            );
            $statement->execute([Tenant::id()]);

            return (int) $statement->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * Aprueba el reclamo: identifica la consignación con la nota del residente (pago, comprobante y aviso) y rechaza
     * los demás reclamos de la misma consignación. Devuelve el id del pago.
     */
    public function approve(int $claimId, int $actorId): int
    {
        $claim = $this->find($claimId);
        // Se marca primero como aprobado: así, al identificar la consignación, solo se cierran los demás reclamos.
        $mark = Database::connection()->prepare(
            "UPDATE billing_deposit_claims SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $mark->execute([$actorId, $claimId, Tenant::id()]);
        if ($mark->rowCount() !== 1) {
            throw new RuntimeException('El reclamo ya fue revisado.');
        }

        try {
            return (new UnidentifiedDepositService())->identify(
                (int) $claim['deposit_id'],
                (int) $claim['statement_id'],
                null,
                $actorId,
                'Reclamada por ' . $claim['username'] . ' desde el portal'
            );
        } catch (Throwable $exception) {
            Database::connection()->prepare(
                "UPDATE billing_deposit_claims SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL
                 WHERE id = ? AND tenant_id = ? AND status = 'approved'"
            )->execute([$claimId, Tenant::id()]);
            throw $exception;
        }
    }

    /**
     * Cierra los reclamos que siguen en revisión cuando la consignación ya se resolvió (identificada con otro
     * inmueble o descartada) y avisa a cada residente. No interrumpe la resolución si falla un aviso.
     */
    public function closePending(int $depositId, string $reason, int $actorId): void
    {
        $reason = mb_substr($reason, 0, 255, 'UTF-8');
        try {
            $pending = Database::connection()->prepare(
                "SELECT id FROM billing_deposit_claims WHERE tenant_id = ? AND deposit_id = ? AND status = 'pending'"
            );
            $pending->execute([Tenant::id(), $depositId]);
            $claimIds = $pending->fetchAll(\PDO::FETCH_COLUMN);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '42S02') {
                return;
            }
            throw $exception;
        }
        foreach ($claimIds as $claimId) {
            try {
                $this->reject((int) $claimId, $reason, $actorId);
            } catch (RuntimeException $exception) {
                error_log('No se pudo cerrar el reclamo de consignación ' . $claimId . ': ' . $exception->getMessage());
            }
        }
    }

    public function reject(int $claimId, string $reason, int $actorId): void
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 255) {
            throw new RuntimeException('Escribe el motivo para el residente (entre 5 y 255 caracteres).');
        }
        $claim = $this->find($claimId);
        $statement = Database::connection()->prepare(
            "UPDATE billing_deposit_claims SET status = 'rejected', review_note = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND tenant_id = ? AND status = 'pending'"
        );
        $statement->execute([$reason, $actorId, $claimId, Tenant::id()]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('El reclamo ya fue revisado.');
        }

        $amount = BillingService::money(BillingService::moneyToCents($claim['amount']));
        $title = 'Tu reclamo de la consignación de ' . $amount . ' no fue aprobado';
        try {
            $this->notifier->notice((int) $claim['user_id'], 'deposit_claim_rejected', $title, 'Motivo: ' . $reason, '/usuario/pagos#consignaciones');
            $this->notifier->mail(
                (string) $claim['email'],
                (string) $claim['username'],
                $title,
                sprintf(
                    "Hola %s,\n\nLa administración revisó tu reclamo de la consignación del %s por %s y no pudo aprobarlo.\n\nMotivo: %s\n\nSi tienes dudas, comunícate con la administración.",
                    $claim['username'],
                    date('d/m/Y', strtotime((string) $claim['deposited_on']) ?: 0),
                    $amount,
                    $reason
                )
            );
        } catch (Throwable $exception) {
            error_log('No se pudo avisar el rechazo de un reclamo de consignación: ' . $exception->getMessage());
        }
    }

    /** @return array{path: string, mime_type: string, byte_size: int, download_name: string}|null */
    public function proof(int $claimId, ?int $userId = null): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT proof_storage_name, proof_mime_type, proof_byte_size FROM billing_deposit_claims
             WHERE id = ? AND tenant_id = ?' . ($userId === null ? '' : ' AND user_id = ?')
        );
        $statement->execute($userId === null ? [$claimId, Tenant::id()] : [$claimId, Tenant::id(), $userId]);
        $row = $statement->fetch();
        if (!is_array($row) || preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$/D', (string) $row['proof_storage_name']) !== 1) {
            return null;
        }
        $path = Storage::directory('deposit-claims') . DIRECTORY_SEPARATOR . $row['proof_storage_name'];

        return is_file($path) ? [
            'path' => $path,
            'mime_type' => (string) $row['proof_mime_type'],
            'byte_size' => (int) $row['proof_byte_size'],
            'download_name' => 'reclamo-' . $claimId . '.' . pathinfo((string) $row['proof_storage_name'], PATHINFO_EXTENSION),
        ] : null;
    }

    private function find(int $claimId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT claims.*, users.username, users.email, deposits.amount, deposits.deposited_on
             FROM billing_deposit_claims AS claims
             JOIN admin_users AS users ON users.id = claims.user_id
             JOIN billing_unidentified_deposits AS deposits ON deposits.id = claims.deposit_id
             WHERE claims.id = ? AND claims.tenant_id = ?'
        );
        $statement->execute([$claimId, Tenant::id()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('El reclamo no existe.');
        }

        return $row;
    }
}
