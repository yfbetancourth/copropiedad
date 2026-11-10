<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Database;
use RuntimeException;
use Throwable;

final class MessagingService
{
    public function activeUsers(): array
    {
        return Database::connection()->query(
            'SELECT id, username, email
             FROM admin_users
             WHERE is_active = 1
             ORDER BY username'
        )->fetchAll();
    }

    public function send(int $senderId, string $subject, string $body, ?int $recipientId): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            if ($recipientId === null) {
                $recipients = $pdo->query(
                    'SELECT id FROM admin_users WHERE is_active = 1 ORDER BY id'
                )->fetchAll(\PDO::FETCH_COLUMN);
            } else {
                $recipient = $pdo->prepare(
                    'SELECT id FROM admin_users WHERE id = ? AND is_active = 1 LIMIT 1'
                );
                $recipient->execute([$recipientId]);
                $recipients = $recipient->fetchAll(\PDO::FETCH_COLUMN);
            }

            if ($recipients === []) {
                throw new RuntimeException('Selecciona al menos un usuario activo como destinatario.');
            }

            $message = $pdo->prepare(
                'INSERT INTO admin_messages (sender_id, subject, body) VALUES (?, ?, ?)'
            );
            $message->execute([$senderId, $subject, $body]);
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
             WHERE messages.sender_id = ?
             GROUP BY messages.id
             ORDER BY messages.created_at DESC, messages.id DESC
             LIMIT 50'
        );
        $statement->execute([$senderId]);

        return $statement->fetchAll();
    }
}
