<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use RuntimeException;
use Throwable;

/**
 * Enlace de un solo uso para crear la contraseña: «¿Olvidaste tu contraseña?» (vence en 1 hora) o la invitación que
 * la administración envía a una cuenta nueva (vence en 72 horas). Si el correo no sale, el enlace se invalida.
 */
final class AccessLinkService
{
    public const INVITATION_HOURS = 72;

    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly PasswordResetMailer $mailer = new PasswordResetMailer()
    ) {
    }

    /** @param array{id: int|string, email: string, username: string} $account */
    public function send(array $account, bool $invitation): void
    {
        $hours = $invitation ? self::INVITATION_HOURS : 1;
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $this->auth->createResetToken((int) $account['id'], $tokenHash, $hours);

        try {
            $this->mailer->send((string) $account['email'], (string) $account['username'], $token, $invitation, $hours);
        } catch (Throwable $exception) {
            $this->auth->invalidateResetToken($tokenHash);
            error_log('No se pudo enviar el enlace de acceso: ' . $exception->getMessage());
            throw new RuntimeException('No se pudo enviar el correo: revisa la configuración de correo (SMTP) y APP_URL.');
        }
    }
}
