<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\Tenant;
use RuntimeException;

final class PortalNotificationService
{
    public function unreadForUser(int $userId): array
    {
        $pdo = Database::connection();
        $messages = $pdo->prepare(
            'SELECT messages.id, messages.subject, messages.created_at, users.username AS sender_name
             FROM admin_message_recipients AS recipients
             JOIN admin_messages AS messages ON messages.id = recipients.message_id
             JOIN admin_users AS users ON users.id = messages.sender_id
             WHERE recipients.user_id = ? AND recipients.read_at IS NULL
             ORDER BY messages.created_at DESC, messages.id DESC
             LIMIT 5'
        );
        $messages->execute([$userId]);

        $messageCount = $pdo->prepare(
            'SELECT COUNT(*)
             FROM admin_message_recipients
             WHERE user_id = ? AND read_at IS NULL'
        );
        $messageCount->execute([$userId]);

        $billing = $pdo->prepare(
            'SELECT statements.id, statements.period, statements.unit_name,
                    statements.administration_fee, statements.fine, statements.created_at
             FROM billing_statements AS statements
             JOIN property_unit_assignments AS assignments
               ON assignments.tenant_id = statements.tenant_id AND assignments.unit_id = statements.unit_id AND assignments.user_id = ?
             LEFT JOIN billing_statement_user_reads AS notice_reads
               ON notice_reads.statement_id = statements.id AND notice_reads.user_id = assignments.user_id
             WHERE notice_reads.statement_id IS NULL
               AND (statements.administration_fee > 0 OR statements.fine > 0)
             ORDER BY statements.created_at DESC, statements.id DESC
             LIMIT 5'
        );
        $billing->execute([$userId]);

        $billingCount = $pdo->prepare(
            'SELECT COUNT(*)
             FROM billing_statements AS statements
             JOIN property_unit_assignments AS assignments
               ON assignments.tenant_id = statements.tenant_id AND assignments.unit_id = statements.unit_id AND assignments.user_id = ?
             LEFT JOIN billing_statement_user_reads AS notice_reads
               ON notice_reads.statement_id = statements.id AND notice_reads.user_id = assignments.user_id
             WHERE notice_reads.statement_id IS NULL
               AND (statements.administration_fee > 0 OR statements.fine > 0)'
        );
        $billingCount->execute([$userId]);

        [$notices, $noticeCount] = $this->unreadNotices($userId);

        return [
            'messages' => $messages->fetchAll(),
            'message_count' => (int) $messageCount->fetchColumn(),
            'billing' => $billing->fetchAll(),
            'billing_count' => (int) $billingCount->fetchColumn(),
            'notices' => $notices,
            'notice_count' => $noticeCount,
        ];
    }

    /**
     * Avisos de pagos (recibido, reporte aprobado o rechazado).
     *
     * @return array{0: list<array>, 1: int}
     */
    private function unreadNotices(int $userId): array
    {
        try {
            $pdo = Database::connection();
            $list = $pdo->prepare(
                'SELECT id, kind, title, body, created_at FROM user_notices
                 WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL
                 ORDER BY created_at DESC, id DESC LIMIT 5'
            );
            $list->execute([Tenant::id(), $userId]);
            $count = $pdo->prepare('SELECT COUNT(*) FROM user_notices WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL');
            $count->execute([Tenant::id(), $userId]);

            return [$list->fetchAll(), (int) $count->fetchColumn()];
        } catch (\PDOException) {
            return [[], 0]; // Sin la migración 025 no hay avisos de pagos.
        }
    }

    public function messagesForUser(int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT messages.id, messages.subject, messages.body, messages.created_at,
                    messages.sender_id, users.username AS sender_name, recipients.read_at
             FROM admin_message_recipients AS recipients
             JOIN admin_messages AS messages ON messages.id = recipients.message_id
             JOIN admin_users AS users ON users.id = messages.sender_id
             WHERE recipients.user_id = ?
             ORDER BY messages.created_at DESC, messages.id DESC'
        );
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    public function markBillingRead(int $statementId, int $userId): void
    {
        $pdo = Database::connection();
        $ownership = $pdo->prepare(
            'SELECT 1
             FROM billing_statements AS statements
             JOIN property_unit_assignments AS assignments
               ON assignments.tenant_id = statements.tenant_id AND assignments.unit_id = statements.unit_id
             WHERE statements.id = ? AND assignments.user_id = ?
             LIMIT 1'
        );
        $ownership->execute([$statementId, $userId]);
        if ($ownership->fetchColumn() === false) {
            throw new RuntimeException('La notificación no existe o no corresponde a tus inmuebles asignados.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO billing_statement_user_reads (statement_id, user_id)
             SELECT statements.id, assignments.user_id
             FROM billing_statements AS statements
             JOIN property_unit_assignments AS assignments
               ON assignments.tenant_id = statements.tenant_id AND assignments.unit_id = statements.unit_id AND assignments.user_id = ?
             WHERE statements.id = ?
             ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([$userId, $statementId]);
    }

    /** Marca como leído un aviso (pagos, reservas…) y devuelve su destino dentro del portal (null si no es del usuario). */
    public function openNotice(int $noticeId, int $userId): ?string
    {
        $pdo = Database::connection();
        $query = $pdo->prepare('SELECT link FROM user_notices WHERE id = ? AND tenant_id = ? AND user_id = ?');
        $query->execute([$noticeId, Tenant::id(), $userId]);
        $link = $query->fetchColumn();
        if ($link === false) {
            return null;
        }
        $pdo->prepare(
            'UPDATE user_notices SET read_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ? AND user_id = ? AND read_at IS NULL'
        )->execute([$noticeId, Tenant::id(), $userId]);

        return str_starts_with((string) $link, '/usuario') ? (string) $link : '/usuario';
    }

    public function markMessageRead(int $messageId, int $userId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE admin_message_recipients
             SET read_at = CURRENT_TIMESTAMP
             WHERE message_id = ? AND user_id = ? AND read_at IS NULL'
        );
        $statement->execute([$messageId, $userId]);

        if ($statement->rowCount() === 0) {
            $exists = Database::connection()->prepare(
                'SELECT 1 FROM admin_message_recipients WHERE message_id = ? AND user_id = ? LIMIT 1'
            );
            $exists->execute([$messageId, $userId]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('El mensaje no existe en tu bandeja.');
            }
        }
    }
}
