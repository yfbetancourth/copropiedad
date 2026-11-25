<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Cifrado simétrico de secretos guardados en la base (contraseñas SMTP, llaves de API).
 * AES-256-GCM con APP_KEY; el «contexto» se usa como dato asociado, de modo que un secreto
 * cifrado para un propósito (o para un usuario) no se puede reutilizar en otro.
 * Formato: v1.<nonce>.<tag>.<texto cifrado>, todo en base64.
 */
final class SecretBox
{
    public static function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if (!is_string($ciphertext)) {
            throw new RuntimeException('No se pudo proteger la credencial.');
        }

        return implode('.', ['v1', base64_encode($nonce), base64_encode($tag), base64_encode($ciphertext)]);
    }

    public static function decrypt(string $payload, string $context): string
    {
        $parts = explode('.', $payload);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new RuntimeException('Las credenciales guardadas tienen un formato inválido.');
        }
        $nonce = base64_decode($parts[1], true);
        $tag = base64_decode($parts[2], true);
        $ciphertext = base64_decode($parts[3], true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
            throw new RuntimeException('No se pudieron leer las credenciales guardadas.');
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if (!is_string($plaintext)) {
            throw new RuntimeException('No se pudieron descifrar las credenciales. Verifica APP_KEY.');
        }

        return $plaintext;
    }

    /**
     * Firma HMAC-SHA256 (hex) de $data para un propósito concreto, derivada de APP_KEY. El propósito separa
     * los usos (p. ej. «contact-form» o «ip-hash») para que una firma no sirva en otro contexto.
     */
    public static function mac(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $data, hash_hmac('sha256', $purpose, self::key(), true));
    }

    private static function key(): string
    {
        $setting = Env::get('APP_KEY');
        if (!str_starts_with($setting, 'base64:')) {
            throw new RuntimeException('Configura APP_KEY con una clave base64 de 32 bytes para proteger las credenciales.');
        }
        $key = base64_decode(substr($setting, 7), true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY debe contener exactamente 32 bytes codificados en base64.');
        }

        return $key;
    }
}
