<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Configuration\MailSettingsService;
use Closure;
use PDOException;
use Throwable;

/**
 * Avisos a las personas, compartidos por todos los módulos: aviso en la campana del portal, correo a una cuenta y
 * correo a la administración. Nunca interrumpen la operación que los origina: cualquier falla queda en el log.
 */
final class UserNotifier
{
    /** @var list<Closure> */
    private static array $queue = [];
    private static bool $shutdownRegistered = false;

    /**
     * Ejecuta el trabajo después de responder al navegador (o al webhook), para no hacerlo esperar por el SMTP.
     * El conjunto de la petición sigue resuelto, así que el trabajo puede consultar sus datos.
     */
    public static function afterResponse(Closure $job): void
    {
        self::$queue[] = $job;
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            while (($next = array_shift(self::$queue)) !== null) {
                try {
                    $next();
                } catch (Throwable $exception) {
                    error_log('No se pudo enviar un aviso: ' . $exception->getMessage());
                }
            }
        });
    }

    /** Aviso en la campana del portal; $link es una ruta del portal (/usuario/...). */
    public function notice(int $userId, string $kind, string $title, string $body, string $link): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO user_notices (tenant_id, user_id, kind, title, body, link) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                Tenant::id(),
                $userId,
                $kind,
                mb_substr($title, 0, 160, 'UTF-8'),
                mb_substr($body, 0, 500, 'UTF-8'),
                $link,
            ]);
        } catch (PDOException $exception) {
            error_log('No se pudo crear el aviso del portal (¿faltan las migraciones 025/028?): ' . $exception->getMessage());
        }
    }

    /** Correo con la cuenta general de la copropiedad. Si no se da $html, se arma desde el texto. */
    public function mail(string $recipient, string $name, string $subject, string $text, ?string $html = null): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            (new SmtpMailer())->send(
                (new MailSettingsService())->credentials(),
                $recipient,
                $name,
                $subject,
                $html ?? '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
                $text
            );
        } catch (Throwable $exception) {
            error_log('No se pudo enviar un correo de aviso: ' . $exception->getMessage());
        }
    }

    /** La administración recibe los avisos en la dirección remitente de la cuenta general de correo. */
    public function mailAdministration(string $subject, string $text): void
    {
        try {
            $address = (new MailSettingsService())->credentials()['from_address'];
        } catch (Throwable $exception) {
            error_log('No se pudo leer el correo de la administración: ' . $exception->getMessage());
            return;
        }
        $this->mail($address, 'Administración', $subject, $text);
    }

    /** Usuario y correo de una cuenta activa del conjunto, o null. */
    public static function account(int $userId): ?array
    {
        $query = Database::connection()->prepare(
            'SELECT id, username, email FROM admin_users WHERE id = ? AND tenant_id = ? AND is_active = 1'
        );
        $query->execute([$userId, Tenant::id()]);
        $account = $query->fetch();

        return is_array($account) ? $account : null;
    }
}
