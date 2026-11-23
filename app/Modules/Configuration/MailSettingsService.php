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
     * @return array{host: string, port: string, username: string, encryption: string, from_address: string, from_name: string, password_configured: bool}
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
            ];
        }

        return [
            'host' => Env::get('MAIL_HOST'),
            'port' => Env::get('MAIL_PORT') ?: '587',
            'username' => Env::get('MAIL_USERNAME'),
            'encryption' => strtolower(Env::get('MAIL_ENCRYPTION')),
            'from_address' => Env::get('MAIL_FROM_ADDRESS'),
            'from_name' => Env::get('MAIL_FROM_NAME'),
            'password_configured' => Env::get('MAIL_PASSWORD') !== '',
        ];
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, encryption: string, from_address: string, from_name: string}
     */
    public function credentials(): array
    {
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
            $current = $this->current();
            if (!$current['password_configured']) {
                throw new RuntimeException('Ingresa la contrasena SMTP del proveedor.');
            }

            $password = $this->credentials()['password'];
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
