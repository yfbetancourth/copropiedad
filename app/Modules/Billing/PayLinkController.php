<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Http;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

/** Página pública de un enlace de pago temporal (/pagar?t=…): resumen de la nota y paso al checkout de Bold. */
final class PayLinkController
{
    public function show(): void
    {
        $token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
        $service = new PaymentLinkService();
        $link = $this->available() ? $service->findActiveByToken($token) : null;
        $statement = null;
        $error = null;
        if ($link !== null) {
            try {
                $statement = (new BillingService())->payableStatement((int) $link['statement_id']);
                $service->markOpened((int) $link['id']);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
        if ($link === null) {
            http_response_code(404);
        }

        $this->render([
            'title' => 'Pagar nota de cobro',
            'link' => $link,
            'statement' => $statement,
            'token' => $token,
            'error' => $error ?? $this->takeFlash(),
            'gateways' => PaymentGateways::ready(),
        ]);
    }

    public function start(): void
    {
        Http::requireCsrf();
        $token = is_string($_POST['t'] ?? null) ? $_POST['t'] : '';
        $link = $this->available() ? (new PaymentLinkService())->findActiveByToken($token) : null;
        if ($link === null) {
            Http::redirect('/pagar?t=' . rawurlencode($token));
        }

        try {
            $gateways = PaymentGateways::ready();
            $gateway = Http::string($_POST['gateway'] ?? null) ?: ($gateways[0] ?? '');
            $checkoutUrl = PaymentGateways::checkoutUrl($gateway, (int) $link['statement_id'], (int) $link['user_id']);
            header('Location: ' . $checkoutUrl, true, 303);
            exit;
        } catch (RuntimeException $exception) {
            Http::flash('pay_link_flash', 'error', $exception->getMessage());
            Http::redirect('/pagar?t=' . rawurlencode($token));
        }
    }

    public function result(): void
    {
        $status = is_string($_GET['estado'] ?? null) && in_array($_GET['estado'], ['approved', 'pending', 'rejected'], true)
            ? $_GET['estado']
            : 'pending';
        $this->render([
            'title' => 'Resultado del pago',
            'result' => $status,
            'link' => null,
            'statement' => null,
            'token' => '',
            'error' => null,
            'gateways' => [],
        ]);
    }

    private function available(): bool
    {
        return PaymentGateways::anyReady();
    }

    private function render(array $data): void
    {
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        Session::start();
        $settings = (new \App\Modules\Configuration\SettingsService())->current();
        View::renderAuth('Billing/pay-link', $data + [
            'result' => null,
            'csrfToken' => Session::csrfToken(),
            'address' => (string) ($settings['address'] ?? ''),
        ]);
    }

    private function takeFlash(): ?string
    {
        $flash = Http::pullFlash('pay_link_flash');

        return is_array($flash) ? (string) $flash['message'] : null;
    }
}
