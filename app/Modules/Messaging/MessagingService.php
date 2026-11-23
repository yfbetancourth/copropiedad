<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Database;
use App\Core\Tenant;
use RuntimeException;
use Throwable;

final class MessagingService
{
    /** Residentes activos: los comunicados se leen en el portal, que es solo para residentes. */
    public function activeUsers(): array
    {
        $statement = Database::connection()->prepare(
            "SELECT users.id, users.username, users.email, profiles.full_name
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id AND roles.kind = 'resident'
             LEFT JOIN resident_profiles AS profiles ON profiles.user_id = users.id
             WHERE users.tenant_id = ? AND users.is_active = 1
             ORDER BY COALESCE(profiles.full_name, users.username)"
        );
        $statement->execute([Tenant::id()]);

        return $statement->fetchAll();
    }

    public function send(int $senderId, string $subject, string $body, ?int $recipientId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            if ($recipientId === null) {
                $all = $pdo->prepare(
                    "SELECT users.id FROM admin_users AS users
                     JOIN admin_roles AS roles ON roles.id = users.role_id AND roles.kind = 'resident'
                     WHERE users.tenant_id = ? AND users.is_active = 1 ORDER BY users.id"
                );
                $all->execute([Tenant::id()]);
                $recipients = $all->fetchAll(\PDO::FETCH_COLUMN);
            } else {
                $recipient = $pdo->prepare(
                    "SELECT users.id FROM admin_users AS users
                     JOIN admin_roles AS roles ON roles.id = users.role_id AND roles.kind = 'resident'
                     WHERE users.id = ? AND users.tenant_id = ? AND users.is_active = 1 LIMIT 1"
                );
                $recipient->execute([$recipientId, Tenant::id()]);
                $recipients = $recipient->fetchAll(\PDO::FETCH_COLUMN);
            }

            if ($recipients === []) {
                throw new RuntimeException('Selecciona al menos un residente activo como destinatario.');
            }

            $message = $pdo->prepare(
                'INSERT INTO admin_messages (tenant_id, sender_id, subject, body) VALUES (?, ?, ?, ?)'
            );
            $message->execute([Tenant::id(), $senderId, $subject, $body]);
            $messageId = (int) $pdo->lastInsertId();

            $insertRecipient = $pdo->prepare(
                'INSERT INTO admin_message_recipients (message_id, user_id) VALUES (?, ?)'
            );
            foreach ($recipients as $userId) {
                $insertRecipient->execute([$messageId, (int) $userId]);
            }

            $pdo->commit();

            return count($recipients);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function sentBy(int $senderId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT messages.id, messages.subject, messages.created_at,
                    COUNT(recipients.user_id) AS recipient_count,
                    SUM(recipients.read_at IS NOT NULL) AS read_count
             FROM admin_messages AS messages
             LEFT JOIN admin_message_recipients AS recipients ON recipients.message_id = messages.id
             WHERE messages.tenant_id = ? AND messages.sender_id = ?
             GROUP BY messages.id
             ORDER BY messages.created_at DESC, messages.id DESC
             LIMIT 50'
        );
        $statement->execute([Tenant::id(), $senderId]);

        return $statement->fetchAll();
    }
}
