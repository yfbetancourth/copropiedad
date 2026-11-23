<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Http;
use App\Core\Authorization;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use RuntimeException;

/** Enlaces de pago temporales y su QR, generados por la administración desde una nota de cobro. */
final class PaymentLinkController
{
    private const FLASH = 'billing_flash';
    private const PERMISSION = 'cartera.cobros';

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $statementId = Http::id($_GET['id'] ?? null);
        try {
            $statement = (new BillingService())->statement($statementId);
        } catch (RuntimeException) {
            http_response_code(404);
            echo 'La nota de cobro no existe.';
            return;
        }

        Session::start();
        $flash = Http::pullFlash(self::FLASH);

        View::renderAdmin('Billing/payment-links', [
            'title' => 'Enlace de pago · ' . $statement['number'],
            'statement' => $statement,
            'recipients' => $this->recipientAccounts($statement),
            'links' => (new PaymentLinkService())->forStatement($statementId),
            'durations' => PaymentLinkService::DURATIONS,
            'onlineReady' => PaymentGateways::anyReady(),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
            'highlight' => Http::id($_GET['enlace'] ?? null),
            'csrfToken' => Session::csrfToken(),
            'flash' => $flash,
        ]);
    }

    public function create(): void
    {
        $actor = $this->requireWrite();
        $statementId = Http::id($_POST['statement_id'] ?? null);
        $linkId = 0;
        try {
            if (!PaymentGateways::anyReady()) {
                throw new RuntimeException('Configura Bold o Wompi en Métodos de pago (y habilita su módulo) antes de generar enlaces.');
            }
            $service = new PaymentLinkService();
            $link = $service->create(
                $statementId,
                Http::id($_POST['user_id'] ?? null),
                Http::id($_POST['hours'] ?? null),
                (int) $actor['id'],
                'admin'
            );
            $linkId = $link['id'];
            $message = 'Enlace generado. Cópialo, compártelo o muestra el QR.';
            if (($_POST['send'] ?? null) === '1') {
                try {
                    $message = 'Enlace generado y enviado a ' . $service->send($linkId, (int) $actor['id']) . '.';
                } catch (RuntimeException $exception) {
                    $message = 'Enlace generado, pero no se envió el correo: ' . $exception->getMessage();
                }
            }
            Http::flash(self::FLASH, 'success', $message);
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->back($statementId, $linkId);
    }

    public function send(): void
    {
        $actor = $this->requireWrite();
        $statementId = Http::id($_POST['statement_id'] ?? null);
        $linkId = Http::id($_POST['link_id'] ?? null);
        try {
            $email = (new PaymentLinkService())->send($linkId, (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'Enlace enviado a ' . $email . '.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->back($statementId, $linkId);
    }

    public function revoke(): void
    {
        $actor = $this->requireWrite();
        $statementId = Http::id($_POST['statement_id'] ?? null);
        try {
            $statementId = (new PaymentLinkService())->revoke(Http::id($_POST['link_id'] ?? null), (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'El enlace quedó revocado; ya no permite pagar.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->back($statementId, 0);
    }

    /** Cuentas a las que puede atribuirse el pago: las destinatarias de la nota. */
    private function recipientAccounts(array $statement): array
    {
        $accounts = [];
        foreach ($statement['recipients'] as $recipient) {
            $accounts[(int) $recipient['user_id']] = [
                'id' => (int) $recipient['user_id'],
                'name' => (string) $recipient['recipient_name'],
                'email' => (string) $recipient['recipient_email'],
            ];
        }

        return array_values($accounts);
    }

    private function requireWrite(): array
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            exit;
        }
        Http::requireCsrf();

        return $actor;
    }

    private function back(int $statementId, int $linkId): never
    {
        header('Location: ' . Url::to('/cartera/cobros/enlaces?id=' . $statementId . ($linkId > 0 ? '&enlace=' . $linkId . '#enlace-' . $linkId : '')), true, 303);
        exit;
    }
}
