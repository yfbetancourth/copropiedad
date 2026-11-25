<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use App\Core\SecretBox;
use PDOException;
use RuntimeException;

/**
 * Cuenta SMTP personal de cada administrador (por ejemplo, su correo certificado).
 *
 * Reglas:
 * - Solo se usa para enviar si está activa y verificada con una prueba exitosa.
 * - Cambiar servidor, usuario, contraseña o remitente la desactiva hasta volver a probarla.
 * - La contraseña se cifra ligada al usuario: no sirve si se copia a la fila de otro.
 */
final class PersonalMailService
{
    /** @return array{configured: bool, host: string, port: string, username: string, encryption: string, from_address: string, from_name: string, password_configured: bool, active: bool, verified_at: ?string}|null */
    public function current(int $userId): ?array
    {
        $row = $this->row($userId);
        if ($row === null) {
            return null;
        }

        return [
            'configured' => true,
            'host' => (string) $row['host'],
            'port' => (string) $row['port'],
            'username' => (string) $row['username'],
            'encryption' => (string) $row['encryption'],
            'from_address' => (string) $row['from_address'],
            'from_name' => (string) $row['from_name'],
            'password_configured' => (string) $row['password_ciphertext'] !== '',
            'active' => (bool) $row['is_active'] && $row['verified_at'] !== null,
            'verified_at' => $row['verified_at'] === null ? null : (string) $row['verified_at'],
        ];
    }

    /**
     * Credenciales completas, sin importar si está activa (para la prueba de envío).
     *
     * @return array{host: string, port: int, username: string, password: string, encryption: string, from_address: string, from_name: string}|null
     */
    public function credentials(int $userId): ?array
    {
        $row = $this->row($userId);
        if ($row === null) {
            return null;
        }

        return [
            'host' => (string) $row['host'],
            'port' => (int) $row['port'],
            'username' => (string) $row['username'],
            'password' => SecretBox::decrypt((string) $row['password_ciphertext'], self::context($userId)),
            'encryption' => (string) $row['encryption'],
            'from_address' => (string) $row['from_address'],
            'from_name' => (string) $row['from_name'],
        ];
    }

    /** Cuenta personal conectada con Google o Microsoft (tiene prioridad sobre el SMTP personal), o null. */
    public function connectedAccount(int $userId): ?array
    {
        return \App\Core\Tenant::scopeId() === null ? null : (new MailOAuthService())->connection('personal', $userId);
    }

    /**
     * Credenciales listas para enviar: la cuenta conectada, o el SMTP personal si está activo y verificado.
     *
     * @return array<string, mixed>|null
     */
    public function activeCredentials(int $userId): ?array
    {
        if (\App\Core\Tenant::scopeId() !== null) {
            $connected = (new MailOAuthService())->sendingSettings('personal', $userId, '');
            if ($connected !== null) {
                return $connected;
            }
        }
        $current = $this->current($userId);

        return $current !== null && $current['active'] ? $this->credentials($userId) : null;
    }

    public function save(int $userId, string $host, int $port, string $username, string $password, string $encryption, string $fromAddress, string $fromName): void
    {
        $existing = $this->row($userId);
        if ($password === '') {
            if ($existing === null || (string) $existing['password_ciphertext'] === '') {
                throw new RuntimeException('Ingresa la contraseña SMTP de tu cuenta.');
            }
            $password = SecretBox::decrypt((string) $existing['password_ciphertext'], self::context($userId));
        }

        // Si cambió algo que afecta el envío, la cuenta debe volver a probarse antes de usarse.
        $unchanged = $existing !== null
            && (string) $existing['host'] === $host && (int) $existing['port'] === $port
            && (string) $existing['username'] === $username && (string) $existing['encryption'] === $encryption
            && (string) $existing['from_address'] === $fromAddress
            && SecretBox::decrypt((string) $existing['password_ciphertext'], self::context($userId)) === $password;

        Database::connection()->prepare(
            'INSERT INTO admin_user_mail_settings
                (user_id, host, port, username, password_ciphertext, encryption, from_address, from_name, is_active, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE
                host = VALUES(host), port = VALUES(port), username = VALUES(username),
                password_ciphertext = VALUES(password_ciphertext), encryption = VALUES(encryption),
                from_address = VALUES(from_address), from_name = VALUES(from_name),
                is_active = IF(?, is_active, 0), verified_at = IF(?, verified_at, NULL)'
        )->execute([
            $userId, $host, $port, $username, SecretBox::encrypt($password, self::context($userId)),
            $encryption, $fromAddress, $fromName, $unchanged ? 1 : 0, $unchanged ? 1 : 0,
        ]);
    }

    /** Se llama tras una prueba exitosa: deja la cuenta verificada y activa. */
    public function markVerified(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE admin_user_mail_settings SET is_active = 1, verified_at = CURRENT_TIMESTAMP WHERE user_id = ?'
        )->execute([$userId]);
    }

    public function setActive(int $userId, bool $active): void
    {
        $current = $this->current($userId);
        if ($current === null) {
            throw new RuntimeException('Primero configura tu cuenta de correo.');
        }
        if ($active && $current['verified_at'] === null) {
            throw new RuntimeException('Envía un correo de prueba exitoso antes de activar la cuenta.');
        }
        Database::connection()->prepare(
            'UPDATE admin_user_mail_settings SET is_active = ? WHERE user_id = ?'
        )->execute([$active ? 1 : 0, $userId]);
    }

    public function delete(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM admin_user_mail_settings WHERE user_id = ?')->execute([$userId]);
    }

    private function row(int $userId): ?array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT host, port, username, password_ciphertext, encryption, from_address, from_name, is_active, verified_at
                 FROM admin_user_mail_settings WHERE user_id = ?'
            );
            $statement->execute([$userId]);
        } catch (PDOException) {
            return null; // La migración 021 aún no se aplicó: no hay cuentas personales.
        }
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private static function context(int $userId): string
    {
        return 'copropiedad-user-mail-v1:' . $userId;
    }
}
