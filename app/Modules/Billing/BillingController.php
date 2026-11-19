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
            $payments = $service->periodPayments($period);
            try {
                $preview = $service->preview($period, (int) $actor['id']);
            } catch (RuntimeException $exception) {
                $preview = null;
                $this->flash('error', $exception->getMessage());
            }
        } catch (RuntimeException $exception) {
            $preview = null;
            $statements = [];
            $payments = [];
            $this->flash('error', $exception->getMessage());
        }
        // Saldo de cada nota con la misma regla de la cartera (incluye el descuento por pronto pago).
        $summary = ['billed' => 0, 'collected' => 0, 'outstanding' => 0, 'recipients' => 0, 'sent' => 0, 'failed' => 0, 'pending_mail' => 0];
        foreach ($statements as &$statement) {
            $totalDue = BillingService::moneyToCents($statement['total_due']);
            $paid = BillingService::moneyToCents($statement['payments_total']);
            $settlement = BillingService::settle(
                $totalDue,
                BillingService::moneyToCents($statement['early_discount'] ?? '0'),
                $paid,
                BillingService::moneyToCents($statement['early_payments_total'] ?? '0')
            );
            $statement['number'] = sprintf('NC-%s-%06d', str_replace('-', '', (string) $statement['period']), (int) $statement['id']);
            $statement['total_cents'] = $totalDue;
            $statement['paid_cents'] = $paid;
            $statement['discount_cents'] = $settlement['discount'];
            $statement['balance_cents'] = $settlement['balance'];
            $summary['billed'] += max(0, $totalDue);
            $summary['collected'] += $paid;
            if ($statement['superseded_by_period'] === null) {
                $summary['outstanding'] += max(0, $settlement['balance']);
            }
            $summary['recipients'] += (int) $statement['recipient_count'];
            $summary['sent'] += (int) $statement['sent_count'];
            $summary['failed'] += (int) $statement['failed_count'];
            $summary['pending_mail'] += (int) $statement['pending_count'];
        }
        unset($statement);
        $summary['to_issue'] = is_array($preview)
            ? array_sum(array_map(
                static fn (array $row): int => $row['already_issued'] ? 0 : (int) $row['due'],
                $preview['rows']
            ))
            : 0;

        $periodDate = new DateTimeImmutable($period . '-01');
        $currentMonth = new DateTimeImmutable('first day of this month');
        $this->render('Billing/index', 'Cartera / Cobros', [
            'actor' => $actor,
            'period' => $period,
            'periodLabel' => self::monthLabel($periodDate),
            'previousPeriod' => $periodDate->modify('-1 month')->format('Y-m'),
            'nextPeriod' => $periodDate < $currentMonth ? $periodDate->modify('+1 month')->format('Y-m') : null,
            'currentPeriod' => $currentMonth->format('Y-m'),
            'preview' => $preview,
            'statements' => $statements,
            'payments' => $payments,
            'summary' => $summary,
            // Desde qué cuenta saldrán los correos que envíe este administrador (sin descifrar contraseñas).
            'sender' => (static function (int $actorId): array {
                $personal = (new \App\Modules\Configuration\PersonalMailService())->current($actorId);
                if ($personal !== null && $personal['active']) {
                    return ['address' => $personal['from_address'], 'personal' => true, 'pending' => false];
                }
                return [
                    'address' => (new \App\Modules\Configuration\MailSettingsService())->current()['from_address'],
                    'personal' => false,
                    'pending' => $personal !== null, // configuró su correo pero no está activo
                ];
            })((int) $actor['id']),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
            'canVoid' => Authorization::hasPermission($actor, self::PERMISSION, 3),
        ]);
    }

    /** «Noviembre 2026», sin depender de la extensión intl. */
    public static function monthLabel(DateTimeImmutable $date): string
    {
        $months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        return $months[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
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
        $this->redirectToPeriod($period, 'preparar');
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
        $this->redirectToPeriod($period, 'pagos');
    }

    public function voidPayment(): void
    {
        // Anular afecta saldos ya informados al residente: exige el nivel Total.
        $actor = $this->requireAction('total');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();
        $period = $this->stringInput($_POST['period'] ?? null);
        $paymentId = $this->integerInput($_POST['payment_id'] ?? null);
        try {
            if ($paymentId === false || $paymentId === null || $paymentId < 1) {
                throw new RuntimeException('Selecciona un pago válido.');
            }
            (new BillingService())->voidPayment(
                $paymentId,
                trim($this->stringInput($_POST['reason'] ?? null)),
                (int) $actor['id']
            );
            $this->flash('success', 'El pago quedó anulado; el registro se conserva con el motivo indicado.');
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }
        $this->redirectToPeriod($period, 'pagos');
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
        $this->redirectToPeriod($period, 'preparar');
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
                    'Se emitieron %d notas; se enviaron %d correos desde %s, %d fallaron y %d siguen pendientes.',
                    $result['issued'],
                    $result['sent'],
                    $this->senderLabel((int) $actor['id']),
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
        $this->redirectToPeriod($period, 'notas');
    }

    public function retry(): void
    {
        $actor = $this->requireAction('write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();
        $period = $this->stringInput($_POST['period'] ?? null);
        try {
            if (!BillingService::validPeriod($period)) {
                throw new RuntimeException('Selecciona un período mensual válido.');
            }
            $result = (new BillingService())->sendPeriod($period, (int) $actor['id']);
            $this->flash(
                $result['failed'] > 0 ? 'error' : 'success',
                sprintf(
                    'Envío procesado desde %s: %d correos enviados, %d fallidos y %d pendientes.',
                    $this->senderLabel((int) $actor['id']),
                    $result['sent'],
                    $result['failed'],
                    $result['pending']
                )
            );
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }
        $this->redirectToPeriod($period, 'notas');
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

    /** «tucorreo@certificado.co (tu correo personal)» o la dirección de la cuenta general. */
    private function senderLabel(int $actorId): string
    {
        try {
            $sender = (new BillingService())->senderFor($actorId);
        } catch (RuntimeException) {
            return 'la cuenta configurada';
        }

        return $sender['address'] . ($sender['personal'] ? ' (tu correo personal)' : ' (cuenta general)');
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
        View::renderAdmin($template, $data);
    }

    private function flash(string $type, string $message): void
    {
        Session::start();
        $_SESSION['billing_flash'] = ['type' => $type, 'message' => $message];
    }

    /** Vuelve al período y a la pestaña donde se hizo la acción (preparar, notas o pagos). */
    private function redirectToPeriod(string $period, string $tab = ''): never
    {
        $query = BillingService::validPeriod($period) ? '?periodo=' . rawurlencode($period) : '';
        $this->redirect('/cartera/cobros' . $query . ($tab !== '' ? '#' . $tab : ''));
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
