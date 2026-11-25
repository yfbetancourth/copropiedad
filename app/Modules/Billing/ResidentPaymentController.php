<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\View;
use App\Modules\Auth\PortalNotificationService;
use App\Modules\Configuration\SettingsService;
use PDOException;
use RuntimeException;

/**
 * Todo lo que el residente hace con sus pagos desde el portal: ver sus notas, pagar en línea o en datáfono,
 * generar el QR, reportar un pago con soporte, ver comprobantes y abrir los avisos de pagos.
 */
final class ResidentPaymentController
{
    private const FLASH = 'user_profile_flash';
    private const QR_SESSION = 'payment_qr';
    private const RESULT_MESSAGES = [
        'approved' => ['success', 'Bold confirmó el pago y se actualizó el saldo de la nota.'],
        'pending' => ['error', 'El pago aún está pendiente de confirmación de Bold. Actualiza la sección en unos minutos.'],
        'rejected' => ['error', 'Bold no aprobó el pago. Puedes intentarlo nuevamente desde la nota.'],
    ];

    public function index(): void
    {
        $user = $this->requireResident();
        $result = Http::string($_GET['pago'] ?? null);
        if (isset(self::RESULT_MESSAGES[$result])) {
            Http::flash(self::FLASH, ...self::RESULT_MESSAGES[$result]);
        }

        $online = new BoldPaymentService();
        $onlineReady = $online->isReady();
        Session::start();
        $paymentQr = $_SESSION[self::QR_SESSION] ?? null;
        unset($_SESSION[self::QR_SESSION]);

        $billingDocuments = $online->payableStatements((int) $user['id']);
        View::renderPortal('Portal/payments', [
            'title' => 'Pagos',
            'user' => $user,
            'billingDocuments' => $billingDocuments,
            'paymentReferences' => (new PaymentReferenceService())->forUnits(
                array_map(static fn (array $document): string => (string) $document['unit_id'], $billingDocuments)
            ),
            'paymentMethods' => $onlineReady ? $online->configuredMethods() : [],
            'onlinePaymentsReady' => $onlineReady,
            'wompiReady' => PaymentGateways::isReady('wompi'),
            'terminalPaymentsReady' => (new BoldTerminalService())->isReady(),
            'notifications' => (new PortalNotificationService())->unreadForUser((int) $user['id']),
            'paymentReports' => $this->paymentReportsFor((int) $user['id']),
            'publicDeposits' => (new DepositClaimService())->publicDeposits(),
            'depositClaims' => (new DepositClaimService())->forUser((int) $user['id']),
            'paymentQr' => $paymentQr,
            'bankDetails' => (new SettingsService())->current(),
            'flash' => Http::pullFlash(self::FLASH),
            'csrfToken' => Session::csrfToken(),
        ]);
    }

    public function startOnline(): void
    {
        $user = $this->requireResident();
        Http::requireCsrf();
        try {
            $gateway = Http::string($_POST['gateway'] ?? null) ?: 'bold';
            $checkoutUrl = PaymentGateways::checkoutUrl($gateway, $this->statementId(), (int) $user['id']);
            header('Location: ' . $checkoutUrl, true, 303);
            exit;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/usuario/pagos');
    }

    public function startTerminal(): void
    {
        $user = $this->requireResident();
        ModuleCatalog::requireEnabled('pagos_en_linea');
        Http::requireCsrf();
        try {
            (new BoldTerminalService())->startPayment($this->statementId(), (int) $user['id'], (string) ($user['email'] ?? ''));
            Http::flash(self::FLASH, 'success', 'Solicitud enviada al datáfono Bold. Completa el pago en el terminal; el saldo se actualizará cuando Bold confirme la transacción.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/usuario/pagos');
    }

    /**
     * QR para pagar la nota desde otro dispositivo (o que la pague otra persona): enlace temporal de 24 horas
     * a nombre del residente.
     */
    public function qr(): void
    {
        $user = $this->requireResident();
        Http::requireCsrf();
        try {
            if (!PaymentGateways::anyReady()) {
                throw new RuntimeException('La administración aún no habilita los pagos en línea.');
            }
            $statementId = $this->statementId();
            $link = (new PaymentLinkService())->portalLink($statementId, (int) $user['id']);
            $statement = (new BillingService())->statement($statementId);
            Session::start();
            $_SESSION[self::QR_SESSION] = [
                'url' => $link['url'],
                'expires_at' => $link['expires_at'],
                'number' => (string) $statement['number'],
                'unit_name' => (string) $statement['unit_name'],
                'balance' => (int) $statement['payable_balance'],
            ];
        } catch (PDOException $exception) {
            error_log('No se pudo generar el QR de pago: ' . $exception->getMessage());
            Http::flash(self::FLASH, 'error', 'No fue posible generar el QR en este momento.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/usuario/pagos#qr');
    }

    public function report(): void
    {
        $user = $this->requireResident();
        Http::requireCsrf();
        try {
            (new PaymentReportService())->create((int) $user['id'], $_POST, $_FILES['proof'] ?? []);
            Http::flash(self::FLASH, 'success', 'Recibimos tu reporte de pago. La administración lo revisará y te avisaremos cuando quede aplicado.');
        } catch (PDOException $exception) {
            error_log('No se pudo guardar un pago reportado: ' . $exception->getMessage());
            Http::flash(self::FLASH, 'error', 'No fue posible guardar el reporte en este momento. Intenta más tarde.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/usuario/pagos#reportes');
    }

    public function proof(): void
    {
        $user = $this->requireResident();
        PaymentReportController::streamProof(
            (new PaymentReportService())->proof(Http::id($_GET['id'] ?? null), (int) $user['id'])
        );
    }

    public function receipt(): void
    {
        $user = $this->requireResident();
        PaymentReportController::renderReceipt(Http::id($_GET['id'] ?? null), (int) $user['id']);
    }

    /** «Es mía»: reclama una consignación por identificar con su soporte. */
    public function claimDeposit(): void
    {
        $user = $this->requireResident();
        Http::requireCsrf();
        try {
            (new DepositClaimService())->create(
                (int) $user['id'],
                Http::id($_POST['deposit_id'] ?? null),
                Http::id($_POST['statement_id'] ?? null),
                Http::string($_POST['notes'] ?? null),
                $_FILES['proof'] ?? []
            );
            Http::flash(self::FLASH, 'success', 'Recibimos tu reclamo. La administración revisará tu soporte y te avisaremos cuando quede aplicado.');
        } catch (PDOException $exception) {
            error_log('No se pudo guardar un reclamo de consignación: ' . $exception->getMessage());
            Http::flash(self::FLASH, 'error', 'No fue posible guardar el reclamo en este momento. Intenta más tarde.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/usuario/pagos#consignaciones');
    }

    public function claimProof(): void
    {
        $user = $this->requireResident();
        PaymentReportController::streamProof(
            (new DepositClaimService())->proof(Http::id($_GET['id'] ?? null), (int) $user['id'])
        );
    }


    /** Pagos que el residente reportó; null si aún no se aplicó la migración 025. */
    private function paymentReportsFor(int $userId): ?array
    {
        try {
            return (new PaymentReportService())->forUser($userId);
        } catch (PDOException $exception) {
            error_log('No se pudieron consultar los pagos reportados: ' . $exception->getMessage());

            return null;
        }
    }

    private function statementId(): int
    {
        $statementId = Http::id($_POST['statement_id'] ?? null);
        if ($statementId === 0) {
            Http::flash(self::FLASH, 'error', 'Selecciona una nota de cobro válida.');
            Http::redirect('/usuario/pagos');
        }

        return $statementId;
    }

    private function requireResident(): array
    {
        $user = Authorization::requireResident();
        ModuleCatalog::requireEnabled('cartera');

        return $user;
    }
}
