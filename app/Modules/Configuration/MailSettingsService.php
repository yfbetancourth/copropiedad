<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Env;
use App\Core\Database;
use App\Core\Tenant;
use App\Core\SecretBox;
use RuntimeException;

final class MailSettingsService
{
    /**
     * @return array{host: string, port: string, username: string, encryption: string, from_address: string, from_name: string, password_configured: bool, using_platform: bool}
     *         password_configured = el conjunto tiene su propia contraseña guardada; using_platform = aún envía con
     *         la cuenta de la plataforma (.env), cuya contraseña nunca se le entrega.
     */
    public function current(): array
    {
        $row = $this->storedRow('host, port, username, encryption, from_address, from_name, password_ciphertext');

        if (is_array($row)) {
            return [
                'host' => (string) $row['host'],
                'port' => (string) $row['port'],
                'username' => (string) $row['username'],
                'encryption' => (string) $row['encryption'],
                'from_address' => (string) $row['from_address'],
                'from_name' => (string) $row['from_name'],
                'password_configured' => (string) $row['password_ciphertext'] !== '',
                'using_platform' => false,
            ];
        }

        return [
            'host' => Env::get('MAIL_HOST'),
            'port' => Env::get('MAIL_PORT') ?: '587',
            'username' => Env::get('MAIL_USERNAME'),
            'encryption' => strtolower(Env::get('MAIL_ENCRYPTION')),
            'from_address' => Env::get('MAIL_FROM_ADDRESS'),
            'from_name' => Env::get('MAIL_FROM_NAME'),
            // La contraseña de la plataforma (.env) nunca se ofrece como «guardada» al conjunto: al guardar su propia
            // configuración debe escribir la suya. Si no, quien cambiara el servidor recibiría la de la plataforma.
            'password_configured' => false,
            'using_platform' => Env::get('MAIL_PASSWORD') !== '',
        ];
    }

    /** Cuenta del conjunto conectada con Google o Microsoft (tiene prioridad sobre el SMTP), o null. */
    public function connectedAccount(): ?array
    {
        return Tenant::scopeId() === null ? null : (new MailOAuthService())->connection('tenant');
    }

    /** El conjunto puede enviar correo: cuenta conectada, SMTP propio completo o la cuenta de la plataforma. */
    public function isReady(): bool
    {
        if ($this->connectedAccount() !== null) {
            return true;
        }
        $settings = $this->current();

        return trim($settings['host']) !== ''
            && (int) $settings['port'] >= 1 && (int) $settings['port'] <= 65535
            && trim($settings['username']) !== ''
            && ($settings['password_configured'] || $settings['using_platform'])
            && in_array($settings['encryption'], ['tls', 'ssl'], true)
            && filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Configuración de envío del conjunto: la cuenta conectada si la hay; si no, el SMTP propio o el de la plataforma.
     *
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        if (Tenant::scopeId() !== null) {
            $connected = (new MailOAuthService())->sendingSettings('tenant', null, $this->senderName());
            if ($connected !== null) {
                return $connected;
            }
        }
        $row = $this->storedRow('host, port, username, password_ciphertext, encryption, from_address, from_name');

        if (is_array($row)) {
            return [
                'host' => (string) $row['host'],
                'port' => (int) $row['port'],
                'username' => (string) $row['username'],
                'password' => $this->decrypt((string) $row['password_ciphertext']),
                'encryption' => (string) $row['encryption'],
                'from_address' => (string) $row['from_address'],
                'from_name' => (string) $row['from_name'],
            ];
        }

        $port = filter_var(Env::get('MAIL_PORT'), FILTER_VALIDATE_INT);

        return [
            'host' => Env::get('MAIL_HOST'),
            'port' => is_int($port) ? $port : 0,
            'username' => Env::get('MAIL_USERNAME'),
            'password' => Env::get('MAIL_PASSWORD'),
            'encryption' => strtolower(Env::get('MAIL_ENCRYPTION')),
            'from_address' => Env::get('MAIL_FROM_ADDRESS'),
            'from_name' => Env::get('MAIL_FROM_NAME'),
        ];
    }

    public function save(
        string $host,
        int $port,
        string $username,
        string $password,
        string $encryption,
        string $fromAddress,
        string $fromName
    ): void {
        if ($password === '') {
            // Solo se conserva la contraseña guardada del propio conjunto y para el mismo servidor, puerto y usuario:
            // si cambiaran, la contraseña viajaría a un servidor que no es el suyo (p. ej. uno de un atacante).
            $stored = $this->storedRow('host, port, username, password_ciphertext');
            if (!is_array($stored) || (string) $stored['password_ciphertext'] === '') {
                throw new RuntimeException('Ingresa la contrasena SMTP del proveedor.');
            }
            if (strcasecmp((string) $stored['host'], $host) !== 0 || (int) $stored['port'] !== $port || (string) $stored['username'] !== $username) {
                throw new RuntimeException('Cambiaste el servidor, el puerto o el usuario: escribe de nuevo la contrasena SMTP.');
            }

            $password = $this->decrypt((string) $stored['password_ciphertext']);
        }
        if ($password === '') {
            throw new RuntimeException('Ingresa la contrasena SMTP del proveedor.');
        }

        $ciphertext = $this->encrypt($password);
        Database::connection()->prepare(
            'INSERT INTO copropiedad_mail_settings
                (tenant_id, host, port, username, password_ciphertext, encryption, from_address, from_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                host = VALUES(host),
                port = VALUES(port),
                username = VALUES(username),
                password_ciphertext = VALUES(password_ciphertext),
                encryption = VALUES(encryption),
                from_address = VALUES(from_address),
                from_name = VALUES(from_name)'
        )->execute([Tenant::id(), $host, $port, $username, $ciphertext, $encryption, $fromAddress, $fromName]);
    }

    /**
     * Configuración SMTP propia del conjunto. Sin ella (o en la plataforma) se usa la cuenta de .env.
     *
     * @return array<string, mixed>|false
     */
    /** Nombre del remitente con la cuenta conectada: el configurado en el SMTP o, si no hay, el del conjunto. */
    private function senderName(): string
    {
        $row = $this->storedRow('from_name');
        $name = is_array($row) ? trim((string) $row['from_name']) : '';

        return $name !== '' ? $name : (new SettingsService())->propertyName();
    }

    private function storedRow(string $columns): array|false
    {
        $tenantId = Tenant::scopeId();
        if ($tenantId === null) {
            return false;
        }
        $statement = Database::connection()->prepare(
            'SELECT ' . $columns . ' FROM copropiedad_mail_settings WHERE tenant_id = ?'
        );
        $statement->execute([$tenantId]);

        return $statement->fetch();
    }

    private const SECRET_CONTEXT = 'copropiedad-mail-settings-v1';

    private function encrypt(string $plaintext): string
    {
        return SecretBox::encrypt($plaintext, self::SECRET_CONTEXT);
    }

    private function decrypt(string $payload): string
    {
        return SecretBox::decrypt($payload, self::SECRET_CONTEXT);
    }

}
