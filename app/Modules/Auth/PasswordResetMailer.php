<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Env;
use App\Core\Tenant;
use App\Modules\Configuration\MailSettingsService;
use RuntimeException;

final class PasswordResetMailer
{
    /**
     * Envía el enlace para crear la contraseña: recuperación (vence en 1 hora) o invitación a una cuenta nueva
     * creada por la administración (vence en $validHours horas).
     */
    public function send(string $email, string $username, string $token, bool $invitation = false, int $validHours = 1): void
    {
        $settings = (new MailSettingsService())->credentials();
        // El enlace lleva al subdominio del conjunto de la cuenta (o al dominio principal para el Superadmin).
        $appUrl = Env::get('APP_URL') === '' ? '' : rtrim(Tenant::absoluteUrl('/'), '/');

        if ($settings['host'] === '' || $settings['from_address'] === '' || $appUrl === ''
            || $settings['username'] === '' || $settings['password'] === ''
        ) {
            throw new RuntimeException('Configura SMTP autenticado, correo remitente y APP_URL para enviar recuperaciones.');
        }

        $urlParts = parse_url($appUrl);
        if (!is_array($urlParts) || !in_array($urlParts['scheme'] ?? '', ['https', 'http'], true)
            || !isset($urlParts['host'])
            || (($urlParts['scheme'] ?? '') !== 'https'
                && !in_array($urlParts['host'], ['localhost', '127.0.0.1'], true)
                && (Env::get('APP_ENV') ?: 'production') === 'production')
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
        $validity = $validHours === 1 ? 'una hora' : $validHours . ' horas';
        if ($invitation) {
            $property = htmlspecialchars((new \App\Modules\Configuration\SettingsService())->propertyName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subject = 'Tu acceso al portal de ' . html_entity_decode($property, ENT_QUOTES, 'UTF-8');
            $htmlBody = sprintf(
                '<p>Hola %s,</p><p>La administración de <strong>%s</strong> creó tu cuenta en el portal de residentes. '
                . 'Allí podrás consultar tus cobros, pagar en línea, reservar zonas comunes y recibir comunicados.</p>'
                . '<p><a href="%s">Crear mi contraseña</a></p>'
                . '<p>Tu usuario es <strong>%s</strong>. El enlace vence en %s; si vence, usa «¿Olvidaste tu contraseña?» en la página de acceso.</p>',
                $escapedUsername, $property, $escapedLink, $escapedUsername, $validity
            );
            $textBody = sprintf(
                "Hola %s,\n\nLa administración de %s creó tu cuenta en el portal de residentes.\n"
                . "Crea tu contraseña en este enlace (vence en %s):\n%s\n\nTu usuario es %s.",
                $username, html_entity_decode($property, ENT_QUOTES, 'UTF-8'), $validity, $link, $username
            );
        } else {
            $subject = 'Recuperacion de acceso a Copropiedad';
            $htmlBody = sprintf(
                '<p>Hola %s,</p><p>Recibimos una solicitud para cambiar la contrasena de tu cuenta.</p>'
                . '<p><a href="%s">Crear una nueva contrasena</a></p>'
                . '<p>El enlace vence en %s. Si no solicitaste este cambio, ignora este mensaje.</p>',
                $escapedUsername, $escapedLink, $validity
            );
            $textBody = sprintf(
                "Hola %s,\n\nCambia tu contrasena en este enlace (vence en %s):\n%s\n\n"
                . 'Si no solicitaste este cambio, ignora este mensaje.',
                $username, $validity, $link
            );
        }

        (new SmtpMailer())->send(
            $settings,
            $email,
            $username,
            $subject,
            $htmlBody,
            $textBody
        );
    }

}
