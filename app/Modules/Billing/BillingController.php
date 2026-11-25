<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Http;
use App\Core\Authorization;
use App\Core\Session;
use App\Core\View;
use DateTimeImmutable;
use PDOException;
use RuntimeException;

final class BillingController
{
    private const FLASH = 'billing_flash';
    private const PERMISSION = 'cartera.cobros';

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }

        $period = $_GET['periodo'] ?? (new DateTimeImmutable('first day of this month'))->format('Y-m');
        if (!is_string($period) || !BillingService::validPeriod($period)) {
            Http::flash(self::FLASH, 'error', 'Selecciona un período mensual válido.');
            Http::redirect('/cartera/cobros');
        }

        try {
            $service = new BillingService();
            $statements = $service->statements($period);
            $payments = $service->periodPayments($period);
            try {
                $preview = $service->preview($period, (int) $actor['id']);
            } catch (RuntimeException $exception) {
                $preview = null;
                Http::flash(self::FLASH, 'error', $exception->getMessage());
            }
        } catch (RuntimeException $exception) {
            $preview = null;
            $statements = [];
            $payments = [];
            Http::flash(self::FLASH, 'error', $exception->getMessage());
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
            $statement['number'] = BillingService::statementNumber((string) $statement['period'], (int) $statement['id']);
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
        Http::requireCsrf();
        $period = Http::string($_POST['period'] ?? null);
        $unitId = Http::string($_POST['unit_id'] ?? null);
        try {
            (new BillingService())->addItem(
                $unitId,
                $period,
                Http::string($_POST['category'] ?? null),
                trim(Http::string($_POST['description'] ?? null)),
                Http::string($_POST['amount'] ?? null),
                (int) $actor['id']
            );
            Http::flash(self::FLASH, 'success', 'El concepto quedó registrado para este inmueble y período.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->redirectToPeriod($period, 'preparar');
    }

    /** El mismo concepto para los inmuebles seleccionados en la tabla de Preparar. */
    public function addItemBulk(): void
    {
        $actor = $this->requireAction('write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        $period = Http::string($_POST['period'] ?? null);
        $unitIds = is_array($_POST['unit_ids'] ?? null) ? array_map(static fn (mixed $id): string => Http::string($id), $_POST['unit_ids']) : [];
        try {
            $result = (new BillingService())->addItemToUnits(
                $unitIds,
                $period,
                Http::string($_POST['category'] ?? null),
                trim(Http::string($_POST['description'] ?? null)),
                Http::string($_POST['amount'] ?? null),
                (int) $actor['id']
            );
            $message = sprintf('El concepto quedó registrado en %d inmueble(s).', $result['added']);
            if ($result['skipped'] !== []) {
                $shown = array_slice($result['skipped'], 0, 5);
                $message .= ' Se omitieron ' . count($result['skipped']) . ': ' . implode(', ', $shown) . (count($result['skipped']) > 5 ? ', …' : '') . '.';
            }
            Http::flash(self::FLASH, $result['added'] > 0 ? 'success' : 'error', $message);
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->redirectToPeriod($period, 'preparar');
    }

    public function addPayment(): void
    {
        $actor = $this->requireAction('write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        $period = Http::string($_POST['period'] ?? null);
        $statementId = Http::integer($_POST['statement_id'] ?? null);
        try {
            if (!BillingService::validPeriod($period)) {
                throw new RuntimeException('Selecciona un período mensual válido.');
            }
            if ($statementId === false || $statementId === null || $statementId < 1) {
                throw new RuntimeException('Selecciona una nota de cobro válida.');
            }
            (new BillingService())->addPayment(
                $statementId,
                Http::string($_POST['amount'] ?? null),
                Http::string($_POST['received_on'] ?? null),
                trim(Http::string($_POST['reference'] ?? null)),
                trim(Http::string($_POST['notes'] ?? null)),
                (int) $actor['id']
            );
            Http::flash(self::FLASH, 'success', 'El pago quedó registrado en la nota de cobro.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
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
        Http::requireCsrf();
        $period = Http::string($_POST['period'] ?? null);
        $paymentId = Http::integer($_POST['payment_id'] ?? null);
        try {
            if ($paymentId === false || $paymentId === null || $paymentId < 1) {
                throw new RuntimeException('Selecciona un pago válido.');
            }
            (new BillingService())->voidPayment(
                $paymentId,
                trim(Http::string($_POST['reason'] ?? null)),
                (int) $actor['id']
            );
            Http::flash(self::FLASH, 'success', 'El pago quedó anulado; el registro se conserva con el motivo indicado.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->redirectToPeriod($period, 'pagos');
    }

    public function removeItem(): void
    {
        if ($this->requireAction('write') === null) {
            return;
        }
        Http::requireCsrf();
        $period = Http::string($_POST['period'] ?? null);
        $itemId = Http::integer($_POST['item_id'] ?? null);
        try {
            if (!BillingService::validPeriod($period) || $itemId === false || $itemId === null || $itemId < 1) {
                throw new RuntimeException('El concepto seleccionado no es válido.');
            }
            (new BillingService())->removeItem($itemId, $period);
            Http::flash(self::FLASH, 'success', 'Se eliminó el concepto antes de emitir la nota.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->redirectToPeriod($period, 'preparar');
    }

    public function issue(): void
    {
        $actor = $this->requireAction('write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        $period = Http::string($_POST['period'] ?? null);
        // Con unit_ids[] se emiten solo los inmuebles seleccionados en la tabla; sin él, todo el período.
        $unitIds = isset($_POST['unit_ids']) && is_array($_POST['unit_ids'])
            ? array_map(static fn (mixed $id): string => Http::string($id), $_POST['unit_ids'])
            : null;
        try {
            $result = (new BillingService())->issue($period, (int) $actor['id'], $unitIds);
            $message = sprintf(
                'Se emitieron %d notas%s; se enviaron %d correos desde %s, %d fallaron y %d siguen pendientes.',
                $result['issued'],
                $unitIds !== null ? ' de los inmuebles seleccionados' : '',
                $result['sent'],
                $this->senderLabel((int) $actor['id']),
                $result['failed'],
                $result['pending']
            );
            if ($result['skipped'] !== []) {
                $message .= ' Se omitieron ' . count($result['skipped']) . ': ' . implode(', ', array_slice($result['skipped'], 0, 5))
                    . (count($result['skipped']) > 5 ? ', …' : '') . '.';
            }
            Http::flash(self::FLASH, $result['failed'] > 0 ? 'error' : 'success', $message);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                Http::flash(self::FLASH, 'error', 'El período ya fue emitido en otra sesión. Recarga la página para revisar los documentos.');
            } else {
                throw $exception;
            }
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->redirectToPeriod($period, 'notas');
    }

    public function retry(): void
    {
        $actor = $this->requireAction('write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        $period = Http::string($_POST['period'] ?? null);
        try {
            if (!BillingService::validPeriod($period)) {
                throw new RuntimeException('Selecciona un período mensual válido.');
            }
            $result = (new BillingService())->sendPeriod($period, (int) $actor['id']);
            Http::flash(self::FLASH, 
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
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->redirectToPeriod($period, 'notas');
    }

    public function statement(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $statementId = Http::integer($_GET['id'] ?? null);
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
        echo \App\Core\Csp::printable((new BillingService())->statementHtml($statement));
    }

    /**
     * Vista previa del documento que recibirá cada residente al emitir (borrador, no se guarda).
     * Se muestra dentro del modal de emisión: permite marcos del mismo origen y no ejecuta scripts.
     */
    public function draft(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $period = Http::string($_GET['periodo'] ?? null);
        $unitId = Http::string($_GET['inmueble'] ?? null);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox allow-modals");
        header('Cache-Control: no-store');
        try {
            if (!BillingService::validPeriod($period) || preg_match('/^[a-f0-9]{32}$/D', $unitId) !== 1) {
                throw new RuntimeException('Elige un inmueble y un período válidos.');
            }
            $draft = (new BillingService())->draftStatement($period, $unitId, (int) $actor['id']);
        } catch (RuntimeException $exception) {
            http_response_code(404);
            echo '<!doctype html><meta charset="utf-8"><body style="font:15px Arial,sans-serif;color:#687386;padding:24px">'
                . htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</body>';
            return;
        }
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $to = implode(', ', array_map(static fn (array $r): string => $escape($r['name'] . ' <' . $r['email'] . '>'), $draft['recipients']));
        $banner = '<div style="box-sizing:border-box;max-width:8.5in;margin:0 auto 12px;background:#fff3cd;border:1px solid #ffe69c;color:#664d03;border-radius:6px;padding:8px 12px;font:12px Arial,sans-serif">'
            . '<strong>Vista previa · borrador sin emitir.</strong> Así llegará el correo; el número se asigna al emitir.'
            . '<br><strong>Para:</strong> ' . $to . '<br><strong>Asunto:</strong> ' . $escape($draft['subject']) . '</div>';
        $html = (new BillingService())->statementHtml($draft['statement']);
        // El botón de imprimir no aplica al borrador.
        $html = preg_replace('#<button type="button" data-print.*?</button>#s', '', $html) ?? $html;
        echo preg_replace('#(<body[^>]*>)#', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $banner), $html, 1);
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

    private function render(string $template, string $title, array $data): void
    {
        Session::start();
        $data['title'] = $title;
        $data['csrfToken'] = Session::csrfToken();
        $data['flash'] = Http::pullFlash(self::FLASH);
        View::renderAdmin($template, $data);
    }

    /** Vuelve al período y a la pestaña donde se hizo la acción (preparar, notas o pagos). */
    private function redirectToPeriod(string $period, string $tab = ''): never
    {
        $query = BillingService::validPeriod($period) ? '?periodo=' . rawurlencode($period) : '';
        Http::redirect('/cartera/cobros' . $query . ($tab !== '' ? '#' . $tab : ''));
    }

}
