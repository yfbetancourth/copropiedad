<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Auth\UserNotifier;
use PDOException;
use RuntimeException;

/**
 * Atención a residentes: conversaciones que el residente abre desde su portal y el equipo de la administración
 * atiende desde el panel. Cada lado ve qué tiene sin leer; el residente recibe aviso y correo con cada respuesta, y la
 * administración un correo con cada solicitud o mensaje nuevo.
 */
final class ConversationService
{
    public const CATEGORIES = [
        'consulta' => 'Consulta',
        'peticion' => 'Petición',
        'queja' => 'Queja',
        'reclamo' => 'Reclamo',
        'sugerencia' => 'Sugerencia',
    ];
    public const STATUS_LABELS = [
        'open' => 'Esperando respuesta de la administración',
        'answered' => 'Respondida',
        'closed' => 'Cerrada',
    ];
    private const MAX_BODY = 5000;

    public function __construct(private readonly UserNotifier $notifier = new UserNotifier())
    {
    }

    /* ------------------------------------ Residente ------------------------------------ */

    public function open(int $residentId, string $category, string $subject, string $body): int
    {
        $subject = trim($subject);
        if (!isset(self::CATEGORIES[$category])) {
            throw new RuntimeException('Elige el tipo de solicitud.');
        }
        if (mb_strlen($subject, 'UTF-8') < 3 || mb_strlen($subject, 'UTF-8') > 160) {
            throw new RuntimeException('El asunto debe tener entre 3 y 160 caracteres.');
        }
        $body = $this->validBody($body);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO resident_conversations (tenant_id, resident_id, category, subject) VALUES (?, ?, ?, ?)'
            )->execute([Tenant::id(), $residentId, $category, $subject]);
            $conversationId = (int) $pdo->lastInsertId();
            $this->insertMessage($conversationId, $residentId, 'resident', $body);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $this->tellAdministration($conversationId, 'Nueva ' . mb_strtolower(self::CATEGORIES[$category], 'UTF-8'), $body);

        return $conversationId;
    }

    public function forResident(int $residentId): array
    {
        $query = Database::connection()->prepare(
            'SELECT conversations.*, (SELECT COUNT(*) FROM resident_conversation_messages AS messages
                                       WHERE messages.conversation_id = conversations.id) AS message_count
             FROM resident_conversations AS conversations
             WHERE conversations.tenant_id = ? AND conversations.resident_id = ?
             ORDER BY conversations.last_message_at DESC'
        );
        $query->execute([Tenant::id(), $residentId]);

        return $query->fetchAll();
    }

    /** Conversaciones con respuestas que el residente no ha leído (para el menú del portal). */
    public function unreadForResident(int $residentId): int
    {
        try {
            return $this->count(
                'SELECT COUNT(*) FROM resident_conversations WHERE tenant_id = ? AND resident_id = ? AND resident_unread = 1',
                [Tenant::id(), $residentId]
            );
        } catch (PDOException) {
            return 0; // Sin la migración 030 no hay conversaciones.
        }
    }

    /* ---------------------------------- Equipo de la administración ---------------------------------- */

    /** @param 'pending'|'answered'|'closed'|'all' $filter */
    public function inbox(string $filter, string $search = ''): array
    {
        $where = match ($filter) {
            'answered' => "AND conversations.status = 'answered'",
            'closed' => "AND conversations.status = 'closed'",
            'all' => '',
            default => "AND conversations.status = 'open'",
        };
        $parameters = [Tenant::id()];
        $searchSql = '';
        $search = trim($search);
        if ($search !== '') {
            $searchSql = 'AND (conversations.subject LIKE ? OR users.username LIKE ? OR profiles.full_name LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($parameters, $like, $like, $like);
        }
        $query = Database::connection()->prepare(
            "SELECT conversations.*, users.username, users.email, profiles.full_name,
                    (SELECT COUNT(*) FROM resident_conversation_messages AS messages
                     WHERE messages.conversation_id = conversations.id) AS message_count
             FROM resident_conversations AS conversations
             JOIN admin_users AS users ON users.id = conversations.resident_id
             LEFT JOIN resident_profiles AS profiles ON profiles.user_id = users.id
             WHERE conversations.tenant_id = ? {$where} {$searchSql}
             ORDER BY " . ($filter === 'pending' ? 'conversations.last_message_at ASC' : 'conversations.last_message_at DESC') . '
             LIMIT 200'
        );
        $query->execute($parameters);

        return $query->fetchAll();
    }

    /** Conversaciones que esperan respuesta de la administración (contador del menú del panel). */
    public function pendingForStaff(): int
    {
        try {
            return $this->count("SELECT COUNT(*) FROM resident_conversations WHERE tenant_id = ? AND status = 'open'", [Tenant::id()]);
        } catch (PDOException) {
            return 0; // Sin la migración 030 no hay conversaciones.
        }
    }

    /* ---------------------------------- Ambos lados ---------------------------------- */

    /**
     * Conversación con sus mensajes. Con $residentId solo la entrega a su autor. Al abrirla se marca como leída
     * para el lado que la consulta.
     */
    public function thread(int $conversationId, ?int $residentId = null): array
    {
        $query = Database::connection()->prepare(
            'SELECT conversations.*, users.username, users.email, profiles.full_name, profiles.phone, closers.username AS closed_by_name
             FROM resident_conversations AS conversations
             JOIN admin_users AS users ON users.id = conversations.resident_id
             LEFT JOIN resident_profiles AS profiles ON profiles.user_id = users.id
             LEFT JOIN admin_users AS closers ON closers.id = conversations.closed_by
             WHERE conversations.id = ? AND conversations.tenant_id = ?'
        );
        $query->execute([$conversationId, Tenant::id()]);
        $conversation = $query->fetch();
        if (!is_array($conversation) || ($residentId !== null && (int) $conversation['resident_id'] !== $residentId)) {
            throw new RuntimeException('La conversación no existe.');
        }

        $messages = Database::connection()->prepare(
            'SELECT messages.*, authors.username AS author_name
             FROM resident_conversation_messages AS messages
             LEFT JOIN admin_users AS authors ON authors.id = messages.author_id
             WHERE messages.conversation_id = ?
             ORDER BY messages.id'
        );
        $messages->execute([$conversationId]);
        $conversation['messages'] = $messages->fetchAll();

        $column = $residentId !== null ? 'resident_unread' : 'staff_unread';
        Database::connection()->prepare("UPDATE resident_conversations SET {$column} = 0 WHERE id = ? AND tenant_id = ?")
            ->execute([$conversationId, Tenant::id()]);

        return $conversation;
    }

    /**
     * Agrega un mensaje. El residente solo responde en sus conversaciones abiertas o respondidas (la deja esperando
     * a la administración); la respuesta del equipo la marca como respondida y avisa al residente.
     */
    public function reply(int $conversationId, int $authorId, bool $fromResident, string $body): void
    {
        $body = $this->validBody($body);
        $conversation = $this->thread($conversationId, $fromResident ? $authorId : null);
        if ($conversation['status'] === 'closed') {
            throw new RuntimeException($fromResident
                ? 'La conversación está cerrada. Si necesitas algo más, abre una nueva solicitud.'
                : 'La conversación está cerrada: reábrela para responder.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->insertMessage($conversationId, $authorId, $fromResident ? 'resident' : 'staff', $body);
            $pdo->prepare(
                'UPDATE resident_conversations
                 SET status = ?, last_message_at = CURRENT_TIMESTAMP, resident_unread = ?, staff_unread = ?
                 WHERE id = ? AND tenant_id = ?'
            )->execute([$fromResident ? 'open' : 'answered', $fromResident ? 0 : 1, $fromResident ? 1 : 0, $conversationId, Tenant::id()]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        if ($fromResident) {
            $this->tellAdministration($conversationId, 'Nuevo mensaje', $body);
        } else {
            $this->tellResident($conversation, 'La administración respondió: ' . $conversation['subject'], $body);
        }
    }

    /** Solo el equipo de la administración cierra o reabre. */
    public function setClosed(int $conversationId, bool $closed, int $actorId): void
    {
        $conversation = $this->thread($conversationId);
        if (($conversation['status'] === 'closed') === $closed) {
            return;
        }
        Database::connection()->prepare(
            'UPDATE resident_conversations SET status = ?, closed_at = ?, closed_by = ?, resident_unread = ?
             WHERE id = ? AND tenant_id = ?'
        )->execute([
            $closed ? 'closed' : 'answered',
            $closed ? date('Y-m-d H:i:s') : null,
            $closed ? $actorId : null,
            $closed ? 1 : (int) $conversation['resident_unread'],
            $conversationId,
            Tenant::id(),
        ]);
        if ($closed) {
            $this->tellResident($conversation, 'Solicitud cerrada: ' . $conversation['subject'], 'La administración dio por atendida tu solicitud. Si necesitas algo más, abre una nueva desde tu portal.');
        }
    }

    /* ------------------------------------ Apoyo ------------------------------------ */

    private function insertMessage(int $conversationId, int $authorId, string $side, string $body): void
    {
        Database::connection()->prepare(
            'INSERT INTO resident_conversation_messages (conversation_id, author_id, author_side, body) VALUES (?, ?, ?, ?)'
        )->execute([$conversationId, $authorId, $side, $body]);
    }

    private function validBody(string $body): string
    {
        $body = trim($body);
        $length = mb_strlen($body, 'UTF-8');
        if ($length < 2 || $length > self::MAX_BODY) {
            throw new RuntimeException('Escribe tu mensaje (hasta ' . number_format(self::MAX_BODY, 0, ',', '.') . ' caracteres).');
        }

        return $body;
    }

    private function tellAdministration(int $conversationId, string $what, string $body): void
    {
        UserNotifier::afterResponse(function () use ($conversationId, $what, $body): void {
            $query = Database::connection()->prepare(
                'SELECT conversations.subject, conversations.category, users.username, profiles.full_name
                 FROM resident_conversations AS conversations
                 JOIN admin_users AS users ON users.id = conversations.resident_id
                 LEFT JOIN resident_profiles AS profiles ON profiles.user_id = users.id
                 WHERE conversations.id = ?'
            );
            $query->execute([$conversationId]);
            $conversation = $query->fetch();
            if (!is_array($conversation)) {
                return;
            }
            $who = $conversation['full_name'] ?: $conversation['username'];
            $this->notifier->mailAdministration(
                $what . ' de ' . $who . ' · ' . $conversation['subject'],
                sprintf(
                    "%s escribió (%s):\n\n%s\n\nRespóndele en el panel: Comunicación > Atención a residentes%s.",
                    $who,
                    self::CATEGORIES[$conversation['category']] ?? $conversation['category'],
                    $body,
                    ($url = Tenant::absoluteUrl('/admin/comunicacion/atencion/ver?id=' . $conversationId)) !== '' ? ' (' . $url . ')' : ''
                )
            );
        });
    }

    private function tellResident(array $conversation, string $title, string $body): void
    {
        $residentId = (int) $conversation['resident_id'];
        $conversationId = (int) $conversation['id'];
        UserNotifier::afterResponse(function () use ($residentId, $conversationId, $title, $body): void {
            $this->notifier->notice($residentId, 'conversation_reply', $title, mb_substr($body, 0, 300, 'UTF-8'), '/usuario/comunicacion/ver?id=' . $conversationId);
            $account = UserNotifier::account($residentId);
            if ($account !== null) {
                $this->notifier->mail((string) $account['email'], (string) $account['username'], $title, $title . "\n\n" . $body . "\n\nPuedes continuar la conversación desde tu portal de residente.");
            }
        });
    }

    private function count(string $sql, array $parameters): int
    {
        $query = Database::connection()->prepare($sql);
        $query->execute($parameters);

        return (int) $query->fetchColumn();
    }
}
