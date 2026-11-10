<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Configuration\MailSettingsService;
use RuntimeException;

final class PasswordResetMailer
{
    public function send(string $email, string $username, string $token): void
    {
        $settings = (new MailSettingsService())->credentials();
        $appUrl = rtrim(self::environmentSetting('APP_URL'), '/');

        if ($settings['host'] === '' || $settings['from_address'] === '' || $appUrl === ''
            || $settings['username'] === '' || $settings['password'] === ''
        ) {
            throw new RuntimeException('Configura SMTP autenticado, correo remitente y APP_URL para enviar recuperaciones.');
        }

        $urlParts = parse_url($appUrl);
        if (!is_array($urlParts) || !in_array($urlParts['scheme'] ?? '', ['https', 'http'], true)
            || !isset($urlParts['host'])
            || (($urlParts['scheme'] ?? '') !== 'https' && !in_array($urlParts['host'], ['localhost', '127.0.0.1'], true))
        ) {
            throw new RuntimeException('APP_URL debe usar HTTPS fuera de localhost.');
        }
        if ($settings['port'] < 1 || $settings['port'] > 65535
            || !filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException('La configuracion SMTP no es valida.');
        }

        $link = $appUrl . '/reset-password?token=' . rawurlencode($token);
        $escapedUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $htmlBody = sprintf(
            '<p>Hola %s,</p><p>Recibimos una solicitud para cambiar la contrasena de tu cuenta.</p>'
            . '<p><a href="%s">Crear una nueva contrasena</a></p>'
            . '<p>El enlace vence en una hora. Si no solicitaste este cambio, ignora este mensaje.</p>',
            $escapedUsername,
            $escapedLink
        );
        $textBody = sprintf(
            "Hola %s,\n\nCambia tu contrasena en este enlace (vence en una hora):\n%s\n\n"
            . 'Si no solicitaste este cambio, ignora este mensaje.',
            $username,
            $link
        );

        (new SmtpMailer())->send(
            $settings,
            $email,
            $username,
            'Recuperacion de acceso a Copropiedad',
            $htmlBody,
            $textBody
        );
    }

    private static function environmentSetting(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
