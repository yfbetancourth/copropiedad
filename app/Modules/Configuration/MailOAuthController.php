<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\Session;
use App\Core\Tenant;
use PDOException;
use RuntimeException;

/**
 * Conectar o desconectar una cuenta de Google o Microsoft para enviar correo: la del conjunto (Configuración / Correo)
 * o la personal del administrador (Mi correo). El retorno del proveedor lo atiende public/correo-oauth.php.
 */
final class MailOAuthController
{
    private const FLASH = 'configuration_flash';
    private const TENANT_PERMISSION = 'configuracion.correo';
    private const PERSONAL_PERMISSION = 'cartera.cobros';

    public function connectTenant(): void
    {
        $actor = $this->require(self::TENANT_PERMISSION);
        $this->connect('tenant', $actor, '/admin/configuracion/correo');
    }

    public function connectPersonal(): void
    {
        $actor = $this->require(self::PERSONAL_PERMISSION);
        $this->connect('personal', $actor, '/admin/configuracion/mi-correo');
    }

    public function disconnectTenant(): void
    {
        $this->require(self::TENANT_PERMISSION);
        $this->disconnect('tenant', null, '/configuracion/correo');
    }

    public function disconnectPersonal(): void
    {
        $actor = $this->require(self::PERSONAL_PERMISSION);
        $this->disconnect('personal', (int) $actor['id'], '/configuracion/mi-correo');
    }

    /**
     * Mensaje del resultado de la conexión: la página de retorno (en el dominio de la plataforma) no comparte sesión
     * con el panel del conjunto, así que vuelve con un código fijo en ?correo=.
     *
     * @return array{type: string, message: string}|null
     */
    public static function resultFlash(): ?array
    {
        $code = Http::string($_GET['correo'] ?? null);
        $message = MailOAuthService::RESULT_MESSAGES[$code] ?? null;

        return $message === null ? null : ['type' => $code === 'conectado' ? 'success' : 'error', 'message' => $message];
    }

    private function connect(string $target, array $actor, string $returnPath): void
    {
        $provider = Http::string($_POST['provider'] ?? null);
        try {
            $url = (new MailOAuthService())->start(
                $provider,
                $target,
                (int) $actor['id'],
                Tenant::absoluteUrl($returnPath),
                $target === 'personal' ? (string) ($actor['email'] ?? '') : ''
            );
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect(substr($returnPath, strlen('/admin')));
        }
        header('Location: ' . $url, true, 303);
        exit;
    }

    private function disconnect(string $target, ?int $userId, string $returnPath): void
    {
        try {
            (new MailOAuthService())->disconnect($target, $userId);
            Http::flash(self::FLASH, 'success', $target === 'tenant'
                ? 'Cuenta desconectada. El conjunto vuelve a enviar con la configuración SMTP (o la de la plataforma).'
                : 'Cuenta desconectada. Tus envíos vuelven a salir por tu SMTP personal, si lo tienes activo, o por la cuenta del conjunto.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect($returnPath);
    }

    private function require(string $permission): array
    {
        $actor = Authorization::requirePermission($permission, 'write');
        if ($actor === null) {
            exit;
        }
        Http::requireCsrf();
        Session::start();

        return $actor;
    }
}
