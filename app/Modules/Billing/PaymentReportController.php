<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Http;
use App\Core\Authorization;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

/** Revisión de pagos reportados por los residentes y comprobantes de pago, en el panel de cartera. */
final class PaymentReportController
{
    private const FLASH = 'billing_flash';
    private const PERMISSION = 'cartera.cobros';
    private const FILTERS = ['open', 'approved', 'rejected', 'all'];

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $filter = is_string($_GET['estado'] ?? null) && in_array($_GET['estado'], self::FILTERS, true)
            ? $_GET['estado']
            : 'open';

        Session::start();
        $flash = Http::pullFlash(self::FLASH);
        $service = new PaymentReportService();
        View::renderAdmin('Billing/payment-reports', [
            'title' => 'Pagos reportados',
            'filter' => $filter,
            'reports' => $service->forReview($filter),
            // En «Por revisar», debajo se muestran los últimos ya revisados para tener contexto.
            'recent' => $filter === 'open' ? $service->forReview('reviewed') : [],
            'pendingCount' => $service->pendingCount(),
            'canReview' => Authorization::hasPermission($actor, self::PERMISSION, 2),
            'csrfToken' => Session::csrfToken(),
            'flash' => $flash,
        ]);
    }

    public function proof(): void
    {
        if (Authorization::requirePermission(self::PERMISSION, 'read') === null) {
            return;
        }
        self::streamProof((new PaymentReportService())->proof(Http::id($_GET['id'] ?? null)));
    }

    public function approve(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        try {
            $paymentId = (new PaymentReportService())->approve(
                Http::id($_POST['report_id'] ?? null),
                trim(is_string($_POST['amount'] ?? null) ? $_POST['amount'] : ''),
                trim(is_string($_POST['received_on'] ?? null) ? $_POST['received_on'] : ''),
                (int) $actor['id']
            );
            Http::flash(self::FLASH, 'success', 'Pago aprobado y registrado en cartera (comprobante ' . BillingService::receiptNumber($paymentId) . '). Se avisó al residente.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/pagos-reportados');
    }

    public function reject(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        try {
            (new PaymentReportService())->reject(
                Http::id($_POST['report_id'] ?? null),
                trim(is_string($_POST['reason'] ?? null) ? $_POST['reason'] : ''),
                (int) $actor['id']
            );
            Http::flash(self::FLASH, 'success', 'El pago reportado quedó rechazado y se avisó al residente con el motivo.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/pagos-reportados');
    }

    public function receipt(): void
    {
        if (Authorization::requirePermission(self::PERMISSION, 'read') === null) {
            return;
        }
        self::renderReceipt(Http::id($_GET['id'] ?? null), null);
    }

    public static function renderReceipt(int $paymentId, ?int $userId): void
    {
        $service = new PaymentNotificationService();
        try {
            $receipt = $service->receipt($paymentId, $userId);
        } catch (RuntimeException) {
            http_response_code(404);
            echo 'El comprobante no existe.';
            return;
        }
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private');
        echo \App\Core\Csp::printable($service->receiptHtml($receipt));
    }

    /** @param array{path: string, mime_type: string, byte_size: int, download_name: string}|null $proof */
    public static function streamProof(?array $proof): void
    {
        if ($proof === null) {
            http_response_code(404);
            echo 'El soporte no existe.';
            return;
        }
        header('Content-Type: ' . $proof['mime_type']);
        header('Content-Length: ' . (string) filesize($proof['path']));
        header('Content-Disposition: inline; filename="' . $proof['download_name'] . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        readfile($proof['path']);
    }

}
