<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\View;
use PDOException;
use RuntimeException;

/**
 * Cartera / Documentos: estado de cuenta y paz y salvo por inmueble, reenvío de comprobantes y la verificación
 * pública de un paz y salvo por su código.
 */
final class AccountDocumentController
{
    private const FLASH = 'documents_flash';
    private const PERMISSION = 'cartera.cobros';

    public function __construct(private readonly AccountDocumentService $documents = new AccountDocumentService())
    {
    }

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $portfolio = (new ReportService())->portfolio();
        $references = (new PaymentReferenceService())->forUnits(array_column($portfolio, 'unit_id'));

        Session::start();
        View::renderAdmin('Billing/documents', [
            'title' => 'Cartera · Documentos',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'units' => $portfolio,
            'references' => $references,
            'clearances' => $this->documents->recentClearances(),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function account(): void
    {
        if (Authorization::requirePermission(self::PERMISSION, 'read') === null) {
            return;
        }
        $unitId = Http::string($_GET['inmueble'] ?? null);
        $this->send(fn (): string => $this->documents->accountHtml($this->documents->account($this->validUnit($unitId))));
    }

    public function clearance(): void
    {
        if (Authorization::requirePermission(self::PERMISSION, 'read') === null) {
            return;
        }
        $this->send(fn (): string => $this->documents->clearanceHtml($this->documents->clearance(Http::id($_GET['id'] ?? null))));
    }

    public function issueClearance(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        try {
            $clearance = $this->documents->issueClearance($this->validUnit(Http::string($_POST['unit_id'] ?? null)), (int) $actor['id'], null);
            Http::flash(self::FLASH, 'success', 'Paz y salvo expedido para ' . $clearance['unit_name'] . ' con el código ' . AccountDocumentService::formatCode($clearance['code']) . '.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/documentos');
    }

    /** Vuelve a enviar por correo el comprobante a las cuentas del inmueble (sin crear avisos nuevos). */
    public function resendReceipt(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        $period = Http::string($_POST['period'] ?? null);
        try {
            $sent = (new PaymentNotificationService())->resendReceipt(Http::id($_POST['payment_id'] ?? null));
            Http::flash('billing_flash', $sent > 0 ? 'success' : 'error', $sent > 0
                ? 'Comprobante reenviado a ' . $sent . ' cuenta(s) del inmueble.'
                : 'El inmueble no tiene cuentas activas con correo para reenviar el comprobante.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash('billing_flash', 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/cobros' . (BillingService::validPeriod($period) ? '?periodo=' . $period . '#pagos' : ''));
    }

    /** Página pública: cualquiera verifica un paz y salvo con su código. No muestra datos personales. */
    public function verify(): void
    {
        if (!ModuleCatalog::enabled('cartera') || !Tenant::isActive()) {
            http_response_code(404);
            echo 'Página no encontrada.';
            return;
        }
        $code = trim(Http::string($_GET['codigo'] ?? null));
        $clearance = null;
        if ($code !== '') {
            try {
                $clearance = $this->documents->clearanceByCode($code);
            } catch (PDOException) {
                $clearance = null;
            }
        }
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        View::renderPublic('Billing/clearance-verify', [
            'title' => 'Verificar paz y salvo',
            'metaDescription' => 'Verificación de certificados de paz y salvo.',
            'canonicalUrl' => '',
            'code' => mb_substr($code, 0, 20, 'UTF-8'),
            'clearance' => $clearance,
        ]);
    }

    private function validUnit(string $unitId): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $unitId) !== 1) {
            throw new RuntimeException('Selecciona un inmueble válido.');
        }

        return $unitId;
    }

    /** @param callable(): string $render */
    private function send(callable $render): void
    {
        try {
            $html = $render();
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            http_response_code(404);
            echo htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return;
        }
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private');
        echo $html;
    }
}
