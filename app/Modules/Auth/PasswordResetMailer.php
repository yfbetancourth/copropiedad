<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class PasswordResetMailer
{
    public function send(string $email, string $username, string $token): void
    {
        $host = self::setting('MAIL_HOST');
        $fromAddress = self::setting('MAIL_FROM_ADDRESS');
        $appUrl = rtrim(self::setting('APP_URL'), '/');

        if ($host === '' || $fromAddress === '' || $appUrl === ''
            || self::setting('MAIL_USERNAME') === ''
            || self::setting('MAIL_PASSWORD') === ''
        ) {
            throw new RuntimeException('Configura SMTP autenticado, MAIL_FROM_ADDRESS y APP_URL para enviar recuperaciones.');
        }

        $urlParts = parse_url($appUrl);
        if (!is_array($urlParts) || !in_array($urlParts['scheme'] ?? '', ['https', 'http'], true)
            || !isset($urlParts['host'])
            || (($urlParts['scheme'] ?? '') !== 'https' && !in_array($urlParts['host'], ['localhost', '127.0.0.1'], true))
        ) {
            throw new RuntimeException('APP_URL debe usar HTTPS fuera de localhost.');
        }

        $port = filter_var(self::setting('MAIL_PORT'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($port === false || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('La configuracion SMTP no es valida.');
        }

        $link = $appUrl . '/reset-password?token=' . rawurlencode($token);
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->Port = $port;
        $mailer->SMTPAuth = true;
        $mailer->Username = self::setting('MAIL_USERNAME');
        $mailer->Password = self::setting('MAIL_PASSWORD');
        $mailer->Timeout = 10;

        $encryption = strtolower(self::setting('MAIL_ENCRYPTION'));
        if ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            throw new RuntimeException('MAIL_ENCRYPTION debe ser tls o ssl.');
        }

        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($fromAddress, self::setting('MAIL_FROM_NAME') ?: 'Copropiedad');
        $mailer->addAddress($email, $username);
        $mailer->isHTML(true);
        $mailer->Subject = 'Recuperacion de acceso a Copropiedad';
        $escapedUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mailer->Body = sprintf(
            '<p>Hola %s,</p><p>Recibimos una solicitud para cambiar la contrasena de tu cuenta.</p>'
            . '<p><a href="%s">Crear una nueva contrasena</a></p>'
            . '<p>El enlace vence en una hora. Si no solicitaste este cambio, ignora este mensaje.</p>',
            $escapedUsername,
            $escapedLink
        );
        $mailer->AltBody = sprintf(
            "Hola %s,\n\nCambia tu contrasena en este enlace (vence en una hora):\n%s\n\n"
            . 'Si no solicitaste el cambio, ignora este mensaje.',
            $username,
            $link
        );
        $mailer->send();
    }

    private static function setting(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
