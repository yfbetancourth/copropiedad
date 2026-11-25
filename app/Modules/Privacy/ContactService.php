<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Database;
use App\Core\SecretBox;
use App\Core\Tenant;
use App\Modules\Auth\UserNotifier;
use RuntimeException;
use Throwable;

/**
 * Formulario de contacto de la página pública. Cada mensaje exige la autorización de tratamiento de datos:
 * se guarda junto con la prueba (versiones aceptadas, fecha, IP y navegador) en data_consents.
 *
 * Protección sin cookies (la portada no crea sesión): token firmado con la hora de carga del formulario
 * (mínimo unos segundos, máximo unas horas), campo trampa para bots y límite de mensajes por IP.
 */
final class ContactService
{
    private const TOKEN_MIN_SECONDS = 3;
    private const TOKEN_MAX_SECONDS = 4 * 3600;
    private const MAX_MESSAGES_PER_HOUR = 5;
    public const HONEYPOT_FIELD = 'website';

    public function __construct(private readonly PrivacyService $privacy = new PrivacyService())
    {
    }

    /** Token del formulario: hora de carga firmada para este conjunto. */
    public static function formToken(): string
    {
        $issuedAt = (string) time();

        return $issuedAt . '.' . SecretBox::mac('contact-form', Tenant::id() . '|' . $issuedAt);
    }

    /**
     * Valida y guarda un mensaje con su autorización. Devuelve el id del mensaje.
     *
     * @param array<string, mixed> $input
     * @return array{errors: array<string, string>, id: ?int}
     */
    public function submit(array $input, string $ipAddress, string $userAgent): array
    {
        if (!$this->privacy->contactFormAvailable()) {
            throw new RuntimeException('El formulario de contacto no está disponible en este momento.');
        }
        // Un bot que llena el campo oculto o envía demasiado rápido recibe la misma respuesta que un envío correcto.
        if (trim((string) ($input[self::HONEYPOT_FIELD] ?? '')) !== '' || !$this->validToken((string) ($input['token'] ?? ''))) {
            return ['errors' => [], 'id' => null];
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')) ?? '');
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $message = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['message'] ?? '')));
        $authorized = ($input['authorization'] ?? '') === '1';

        $errors = [];
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) {
            $errors['name'] = 'Escribe tu nombre (entre 2 y 100 caracteres).';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $errors['email'] = 'Escribe un correo electrónico válido para poder responderte.';
        }
        if ($phone !== '' && preg_match('/^[0-9+() .-]{7,30}$/D', $phone) !== 1) {
            $errors['phone'] = 'El teléfono solo puede tener números, espacios y los signos + ( ) - .';
        }
        if (mb_strlen($message, 'UTF-8') < 10 || mb_strlen($message, 'UTF-8') > 2000) {
            $errors['message'] = 'Escribe tu mensaje (entre 10 y 2.000 caracteres).';
        }
        if (!$authorized) {
            $errors['authorization'] = 'Para enviar el mensaje debes autorizar el tratamiento de tus datos.';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'id' => null];
        }

        $ipHash = SecretBox::mac('contact-ip', Tenant::id() . '|' . $ipAddress);
        if ($this->recentFromIp($ipHash) >= self::MAX_MESSAGES_PER_HOUR) {
            return ['errors' => ['form' => 'Recibimos varios mensajes desde tu conexión. Intenta de nuevo en una hora.'], 'id' => null];
        }

        $policy = $this->privacy->current('data_policy');
        $authorization = $this->privacy->current('authorization');
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO data_consents (tenant_id, source, subject_name, subject_email, subject_phone,
                     data_policy_version_id, authorization_version_id, ip_address, user_agent)
                 VALUES (?, 'contact_form', ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                Tenant::id(), $name, $email, $phone === '' ? null : $phone,
                $policy['id'], $authorization['id'], substr($ipAddress, 0, 45),
                $userAgent === '' ? null : mb_substr($userAgent, 0, 255, 'UTF-8'),
            ]);
            $consentId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO contact_messages (tenant_id, consent_id, name, email, phone, message, ip_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([Tenant::id(), $consentId, $name, $email, $phone === '' ? null : $phone, $message, $ipHash]);
            $messageId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $this->notifyAdministration($name, $email, $phone, $message);

        return ['errors' => [], 'id' => $messageId];
    }

    /** @return list<array<string, mixed>> */
    public function messages(?string $status): array
    {
        $sql = 'SELECT messages.id, messages.name, messages.email, messages.phone, messages.message, messages.status,
                       messages.created_at, messages.read_at, readers.username AS read_by_name,
                       messages.consent_id, consents.revoked_at
                FROM contact_messages AS messages
                JOIN data_consents AS consents ON consents.id = messages.consent_id
                LEFT JOIN admin_users AS readers ON readers.id = messages.read_by
                WHERE messages.tenant_id = ?' . ($status === null ? '' : ' AND messages.status = ?') . '
                ORDER BY messages.created_at DESC, messages.id DESC
                LIMIT 300';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($status === null ? [Tenant::id()] : [Tenant::id(), $status]);

        return $statement->fetchAll();
    }

    /** @return array{new: int, read: int} */
    public function counts(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT status, COUNT(*) AS total FROM contact_messages WHERE tenant_id = ? GROUP BY status'
        );
        $statement->execute([Tenant::id()]);
        $counts = ['new' => 0, 'read' => 0];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function newCount(): int
    {
        try {
            return $this->counts()['new'];
        } catch (Throwable) {
            return 0;
        }
    }

    public function markRead(int $messageId, int $actorId): void
    {
        $statement = Database::connection()->prepare(
            "UPDATE contact_messages SET status = 'read', read_at = NOW(), read_by = ?
             WHERE id = ? AND tenant_id = ? AND status = 'new'"
        );
        $statement->execute([$actorId, $messageId, Tenant::id()]);
        if ($statement->rowCount() === 0 && !$this->exists($messageId)) {
            throw new RuntimeException('El mensaje seleccionado no existe.');
        }
    }

    /**
     * Elimina el mensaje (supresión). La autorización se conserva en el registro como prueba; si el titular
     * revocó, la revocación se registra en Privacidad / Autorizaciones.
     */
    public function delete(int $messageId): void
    {
        $statement = Database::connection()->prepare('DELETE FROM contact_messages WHERE id = ? AND tenant_id = ?');
        $statement->execute([$messageId, Tenant::id()]);
        if ($statement->rowCount() === 0) {
            throw new RuntimeException('El mensaje seleccionado no existe.');
        }
    }

    private function exists(int $messageId): bool
    {
        $statement = Database::connection()->prepare('SELECT 1 FROM contact_messages WHERE id = ? AND tenant_id = ?');
        $statement->execute([$messageId, Tenant::id()]);

        return $statement->fetchColumn() !== false;
    }

    private function validToken(string $token): bool
    {
        if (preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/D', $token, $parts) !== 1) {
            return false;
        }
        $age = time() - (int) $parts[1];
        if ($age < self::TOKEN_MIN_SECONDS || $age > self::TOKEN_MAX_SECONDS) {
            return false;
        }

        return hash_equals(SecretBox::mac('contact-form', Tenant::id() . '|' . $parts[1]), $parts[2]);
    }

    private function recentFromIp(string $ipHash): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM contact_messages
             WHERE tenant_id = ? AND ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $statement->execute([Tenant::id(), $ipHash]);

        return (int) $statement->fetchColumn();
    }

    private function notifyAdministration(string $name, string $email, string $phone, string $message): void
    {
        try {
            (new UserNotifier())->mailAdministration(
                'Nuevo mensaje desde el sitio web · ' . $name,
                sprintf(
                    "%s escribió desde el formulario de contacto de la página pública y autorizó el tratamiento de sus datos.\n\nCorreo: %s\nTeléfono: %s\n\nMensaje:\n%s\n\nRevísalo en Comunicación > Contacto del sitio web%s.",
                    $name,
                    $email,
                    $phone !== '' ? $phone : '—',
                    $message,
                    ($url = Tenant::absoluteUrl('/admin/comunicacion/contacto')) !== '' ? ': ' . $url : ''
                )
            );
        } catch (Throwable $exception) {
            error_log('No se pudo avisar del mensaje de contacto: ' . $exception->getMessage());
        }
    }
}
