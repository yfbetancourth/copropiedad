<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\Session;
use App\Core\View;
use PDOException;
use RuntimeException;

/** Cartera / Consignaciones por identificar: registro, sugerencias de cruce, identificación y descarte. */
final class UnidentifiedDepositController
{
    private const FLASH = 'deposits_flash';
    private const OLD_INPUT = 'deposits_old';
    private const PERMISSION = 'cartera.cobros';
    private const FILTERS = ['pending' => 'Por identificar', 'identified' => 'Identificadas', 'discarded' => 'Descartadas'];

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $filter = Http::string($_GET['estado'] ?? null);
        if (!isset(self::FILTERS[$filter])) {
            $filter = 'pending';
        }

        Session::start();
        $old = $_SESSION[self::OLD_INPUT] ?? [];
        unset($_SESSION[self::OLD_INPUT]);
        $service = new UnidentifiedDepositService();
        $deposits = $service->list($filter);
        if ($filter === 'pending') {
            foreach ($deposits as &$deposit) {
                $deposit['suggestions'] = $service->suggestions($deposit);
            }
            unset($deposit);
        }
        $canWrite = Authorization::hasPermission($actor, self::PERMISSION, 2);

        View::renderAdmin('Billing/deposits', [
            'title' => 'Cartera · Consignaciones por identificar',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => $service->counts(),
            'deposits' => $deposits,
            'statements' => $canWrite && $filter === 'pending' ? $service->assignableStatements() : [],
            'old' => is_array($old) ? $old : [],
            'canWrite' => $canWrite,
        ]);
    }

    public function store(): void
    {
        $actor = $this->requireWrite();
        $data = [];
        foreach (['amount', 'deposited_on', 'channel', 'bank_reference', 'payer_detail', 'notes'] as $field) {
            $data[$field] = trim(Http::string($_POST[$field] ?? null));
        }
        try {
            $file = $_FILES['proof'] ?? null;
            $id = (new UnidentifiedDepositService())->create($data, is_array($file) ? $file : null, (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'Consignación #' . $id . ' registrada. Revisa las sugerencias para identificarla.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Session::start();
            $_SESSION[self::OLD_INPUT] = $data;
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/consignaciones');
    }

    public function identify(): void
    {
        $actor = $this->requireWrite();
        $reportId = Http::integer($_POST['report_id'] ?? null);
        try {
            $paymentId = (new UnidentifiedDepositService())->identify(
                Http::id($_POST['deposit_id'] ?? null),
                Http::id($_POST['statement_id'] ?? null),
                is_int($reportId) && $reportId > 0 ? $reportId : null,
                (int) $actor['id']
            );
            Http::flash(self::FLASH, 'success', 'Consignación identificada y registrada como pago (comprobante ' . BillingService::receiptNumber($paymentId) . '). Se avisó al residente.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/consignaciones');
    }

    public function discard(): void
    {
        $actor = $this->requireWrite();
        try {
            (new UnidentifiedDepositService())->discard(
                Http::id($_POST['deposit_id'] ?? null),
                Http::string($_POST['reason'] ?? null),
                (int) $actor['id']
            );
            Http::flash(self::FLASH, 'success', 'Consignación descartada; queda en la pestaña Descartadas con el motivo.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/consignaciones');
    }

    public function proof(): void
    {
        if (Authorization::requirePermission(self::PERMISSION, 'read') === null) {
            return;
        }
        PaymentReportController::streamProof((new UnidentifiedDepositService())->proof(Http::id($_GET['id'] ?? null)));
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
}
