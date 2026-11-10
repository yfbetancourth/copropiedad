<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Authorization;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use DateTimeImmutable;
use PDOException;
use RuntimeException;

final class BillingController
{
    private const PERMISSION = 'cartera.cobros';

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }

        $period = $_GET['periodo'] ?? (new DateTimeImmutable('first day of this month'))->format('Y-m');
        if (!is_string($period) || !BillingService::validPeriod($period)) {
            $this->flash('error', 'Selecciona un período mensual válido.');
            $this->redirect('/cartera/cobros');
        }

        try {
            $service = new BillingService();
            $statements = $service->statements($period);
            try {
                $preview = $service->preview($period);
            } catch (RuntimeException $exception) {
                $preview = null;
                $this->flash('error', $exception->getMessage());
            }
        } catch (RuntimeException $exception) {
            $preview = null;
            $statements = [];
            $this->flash('error', $exception->getMessage());
        }
        $this->render('Billing/index', 'Cartera / Cobros', [
            'actor' => $actor,
            'period' => $period,
            'preview' => $preview,
            'statements' => $statements,
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function addItem(): void
    {
        $actor = $this->requireAction('write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();
        $period = $this->stringInput($_POST['period'] ?? null);
        $unitId = $this->stringInput($_POST['unit_id'] ?? null);
        try {
            (new BillingService())->addItem(
                $unitId,
                $period,
                $this->stringInput($_POST['category'] ?? null),
                trim($this->stringInput($_POST['description'] ?? null)),
                $this->stringInput($_POST['amount'] ?? null),
                (int) $actor['id']
            );
            $this->flash('success', 'El concepto quedó registrado para este inmueble y período.');
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }
        $this->redirectToPeriod($period);
    }

    public function addPayment(): void
    {
        $actor = $this->requireAction('write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();
        $period = $this->stringInput($_POST['period'] ?? null);
        $statementId = $this->integerInput($_POST['statement_id'] ?? null);
        try {
            if (!BillingService::validPeriod($period)) {
                throw new RuntimeException('Selecciona un período mensual válido.');
            }
            if ($statementId === false || $statementId === null || $statementId < 1) {
                throw new RuntimeException('Selecciona una nota de cobro válida.');
            }
            (new BillingService())->addPayment(
                $statementId,
                $this->stringInput($_POST['amount'] ?? null),
                $this->stringInput($_POST['received_on'] ?? null),
                trim($this->stringInput($_POST['reference'] ?? null)),
                trim($this->stringInput($_POST['notes'] ?? null)),
                (int) $actor['id']
            );
            $this->flash('success', 'El pago quedó registrado en la nota de cobro.');
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }
        $this->redirectToPeriod($period);
    }

    public function removeItem(): void
    {
        if ($this->requireAction('write') === null) {
            return;
        }
        $this->requireCsrf();
        $period = $this->stringInput($_POST['period'] ?? null);
        $itemId = $this->integerInput($_POST['item_id'] ?? null);
        try {
            if (!BillingService::validPeriod($period) || $itemId === false || $itemId === null || $itemId < 1) {
                throw new RuntimeException('El concepto seleccionado no es válido.');
            }
            (new BillingService())->removeItem($itemId, $period);
            $this->flash('success', 'Se eliminó el concepto antes de emitir la nota.');
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }
        $this->redirectToPeriod($period);
    }

    public function issue(): void
    {
        $actor = $this->requireAction('write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();
        $period = $this->stringInput($_POST['period'] ?? null);
        try {
            $result = (new BillingService())->issue($period, (int) $actor['id']);
            $this->flash(
                $result['failed'] > 0 ? 'error' : 'success',
                sprintf(
                    'Se emitieron %d notas; se enviaron %d correos, %d fallaron y %d siguen pendientes.',
                    $result['issued'],
                    $result['sent'],
                    $result['failed'],
                    $result['pending']
                )
            );
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $this->flash('error', 'El período ya fue emitido en otra sesión. Recarga la página para revisar los documentos.');
            } else {
                throw $exception;
            }
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }
        $this->redirectToPeriod($period);
    }

    public function retry(): void
    {
        if ($this->requireAction('write') === null) {
            return;
        }
        $this->requireCsrf();
        $period = $this->stringInput($_POST['period'] ?? null);
        try {
            if (!BillingService::validPeriod($period)) {
                throw new RuntimeException('Selecciona un período mensual válido.');
            }
            $result = (new BillingService())->sendPeriod($period);
            $this->flash(
                $result['failed'] > 0 ? 'error' : 'success',
                sprintf(
                    'Envío procesado: %d correos enviados, %d fallidos y %d pendientes.',
                    $result['sent'],
                    $result['failed'],
                    $result['pending']
                )
            );
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }
        $this->redirectToPeriod($period);
    }

    public function statement(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $statementId = $this->integerInput($_GET['id'] ?? null);
        if ($statementId === false || $statementId === null || $statementId < 1) {
            http_response_code(404);
            echo 'La nota de cobro no existe.';
            return;
        }

        try {
            $statement = (new BillingService())->statement($statementId);
        } catch (RuntimeException) {
            http_response_code(404);
            echo 'La nota de cobro no existe.';
            return;
        }
        header('Content-Type: text/html; charset=UTF-8');
        echo (new BillingService())->statementHtml($statement);
    }

    private function requireAction(string $level): ?array
    {
        return Authorization::requirePermission(self::PERMISSION, $level);
    }

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(419);
            echo 'La sesión del formulario venció. Recarga la página e intenta nuevamente.';
            exit;
        }
    }

    private function render(string $template, string $title, array $data): void
    {
        Session::start();
        $data['title'] = $title;
        $data['csrfToken'] = Session::csrfToken();
        $data['flash'] = $_SESSION['billing_flash'] ?? null;
        unset($_SESSION['billing_flash']);
        View::render($template, $data);
    }

    private function flash(string $type, string $message): void
    {
        Session::start();
        $_SESSION['billing_flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirectToPeriod(string $period): never
    {
        $query = BillingService::validPeriod($period) ? '?periodo=' . rawurlencode($period) : '';
        $this->redirect('/cartera/cobros' . $query);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::to($path), true, 303);
        exit;
    }

    private function integerInput(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : false;
    }

    private function stringInput(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
