<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use RuntimeException;

final class MailSettingsService
{
    /**
     * @return array{host: string, port: string, username: string, encryption: string, from_address: string, from_name: string, password_configured: bool}
     */
    public function current(): array
    {
        $row = Database::connection()->query(
            'SELECT host, port, username, encryption, from_address, from_name, password_ciphertext
             FROM copropiedad_mail_settings
             WHERE id = 1'
        )->fetch();

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
            'host' => self::environmentSetting('MAIL_HOST'),
            'port' => self::environmentSetting('MAIL_PORT') ?: '587',
            'username' => self::environmentSetting('MAIL_USERNAME'),
            'encryption' => strtolower(self::environmentSetting('MAIL_ENCRYPTION')),
            'from_address' => self::environmentSetting('MAIL_FROM_ADDRESS'),
            'from_name' => self::environmentSetting('MAIL_FROM_NAME'),
            'password_configured' => self::environmentSetting('MAIL_PASSWORD') !== '',
        ];
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, encryption: string, from_address: string, from_name: string}
     */
    public function credentials(): array
    {
        $row = Database::connection()->query(
            'SELECT host, port, username, password_ciphertext, encryption, from_address, from_name
             FROM copropiedad_mail_settings
             WHERE id = 1'
        )->fetch();

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

        $port = filter_var(self::environmentSetting('MAIL_PORT'), FILTER_VALIDATE_INT);

        return [
            'host' => self::environmentSetting('MAIL_HOST'),
            'port' => is_int($port) ? $port : 0,
            'username' => self::environmentSetting('MAIL_USERNAME'),
            'password' => self::environmentSetting('MAIL_PASSWORD'),
            'encryption' => strtolower(self::environmentSetting('MAIL_ENCRYPTION')),
            'from_address' => self::environmentSetting('MAIL_FROM_ADDRESS'),
            'from_name' => self::environmentSetting('MAIL_FROM_NAME'),
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
                (id, host, port, username, password_ciphertext, encryption, from_address, from_name)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                host = VALUES(host),
                port = VALUES(port),
                username = VALUES(username),
                password_ciphertext = VALUES(password_ciphertext),
                encryption = VALUES(encryption),
                from_address = VALUES(from_address),
                from_name = VALUES(from_name)'
        )->execute([$host, $port, $username, $ciphertext, $encryption, $fromAddress, $fromName]);
    }

    private function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'copropiedad-mail-settings-v1'
        );
        if (!is_string($ciphertext)) {
            throw new RuntimeException('No se pudo proteger la contrasena del proveedor de correo.');
        }

        return implode('.', [
            'v1',
            base64_encode($nonce),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    private function decrypt(string $payload): string
    {
        $parts = explode('.', $payload);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new RuntimeException('Las credenciales SMTP guardadas tienen un formato invalido.');
        }

        $nonce = base64_decode($parts[1], true);
        $tag = base64_decode($parts[2], true);
        $ciphertext = base64_decode($parts[3], true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16
            || !is_string($ciphertext)
        ) {
            throw new RuntimeException('No se pudieron leer las credenciales SMTP guardadas.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'copropiedad-mail-settings-v1'
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('No se pudieron descifrar las credenciales SMTP. Verifica APP_KEY.');
        }

        return $plaintext;
    }

    private function encryptionKey(): string
    {
        $setting = self::environmentSetting('APP_KEY');
        if (!str_starts_with($setting, 'base64:')) {
            throw new RuntimeException('Configura APP_KEY con una clave base64 de 32 bytes para proteger las credenciales SMTP.');
        }

        $key = base64_decode(substr($setting, 7), true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY debe contener exactamente 32 bytes codificados en base64.');
        }

        return $key;
    }

    private static function environmentSetting(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
