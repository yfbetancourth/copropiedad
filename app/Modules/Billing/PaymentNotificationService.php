<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Auth\UserNotifier;
use DateTimeImmutable;
use RuntimeException;

/**
 * Informa los pagos: aviso en el portal y correo con comprobante a las cuentas de la nota, y correo a la
 * administración. Los avisos nunca interrumpen el registro del pago: cualquier falla se deja en el log.
 */
final class PaymentNotificationService
{
    public function __construct(private readonly UserNotifier $notifier = new UserNotifier())
    {
    }

    public const ORIGIN_LABELS = [
        'bold' => 'Pago en línea Bold',
        'terminal' => 'Datáfono Bold',
        'wompi' => 'Pago en línea Wompi',
        'manual' => 'Registrado por la administración',
        'report' => 'Pago reportado por el residente',
    ];

    /**
     * Programa el aviso para después de responder: un webhook o una redirección no esperan al servidor SMTP.
     *
     * @param array<string, mixed> $context
     */
    public static function defer(string $event, array $context): void
    {
        UserNotifier::afterResponse(static function () use ($event, $context): void {
            $service = new self();
            match ($event) {
                'payment' => $service->notifyPaymentRecorded((int) $context['payment_id'], (string) $context['origin']),
                'report_created' => $service->notifyReportCreated((int) $context['report_id']),
                'report_rejected' => $service->notifyReportRejected((int) $context['report_id']),
            };
        });
    }

    public function notifyPaymentRecorded(int $paymentId, string $origin): void
    {
        $receipt = $this->receipt($paymentId);
        $originLabel = self::ORIGIN_LABELS[$origin] ?? 'Pago';
        $amount = BillingService::money($receipt['amount_cents']);
        $title = $origin === 'report'
            ? 'Se aprobó tu pago reportado de ' . $amount
            : 'Pago recibido: ' . $amount;
        $body = sprintf(
            '%s · %s · nota %s. Saldo pendiente: %s.',
            $originLabel,
            $receipt['unit_name'],
            $receipt['statement_number'],
            BillingService::money($receipt['balance_cents'])
        );
        $link = '/usuario/pagos/comprobante?id=' . $paymentId;
        $kind = $origin === 'report' ? 'payment_report_approved' : 'payment_received';

        foreach ($this->statementAccounts($receipt['statement_id']) as $account) {
            $this->notifier->notice((int) $account['id'], $kind, $title, $body, $link);
            $this->notifier->mail(
                (string) $account['email'],
                (string) $account['username'],
                sprintf('Comprobante de pago %s · %s', $receipt['number'], $receipt['property_name']),
                $this->receiptText($receipt, $originLabel),
                $this->receiptHtml($receipt, $originLabel)
            );
        }

        $this->notifier->mailAdministration(
            sprintf('Pago recibido · %s · %s', $receipt['unit_name'], $amount),
            sprintf(
                "Se registró un pago en la nota %s (%s).\n\nOrigen: %s\nValor: %s\nFecha: %s\nReferencia: %s\nSaldo pendiente de la nota: %s",
                $receipt['statement_number'],
                $receipt['unit_name'],
                $originLabel,
                $amount,
                $receipt['received_on_label'],
                $receipt['reference'] !== '' ? $receipt['reference'] : '—',
                BillingService::money($receipt['balance_cents'])
            )
        );
    }

    public function notifyReportCreated(int $reportId): void
    {
        $report = $this->report($reportId);
        $this->notifier->mailAdministration(
            sprintf('Pago reportado por revisar · %s · %s', $report['unit_name'], BillingService::money($report['amount_cents'])),
            sprintf(
                "%s reportó un pago con soporte para la nota %s (%s).\n\nValor: %s\nFecha de pago: %s\nMedio: %s\nReferencia: %s\n\nRevísalo en Cartera > Pagos reportados%s.",
                $report['username'],
                $report['statement_number'],
                $report['unit_name'],
                BillingService::money($report['amount_cents']),
                $report['paid_on'],
                PaymentReportService::METHOD_LABELS[$report['method']] ?? $report['method'],
                $report['reference'] !== '' ? $report['reference'] : '—',
                ($url = Tenant::absoluteUrl('/cartera/pagos-reportados')) !== '' ? ': ' . $url : ''
            )
        );
    }

    public function notifyReportRejected(int $reportId): void
    {
        $report = $this->report($reportId);
        $title = 'Tu pago reportado de ' . BillingService::money($report['amount_cents']) . ' no fue aprobado';
        $body = 'Motivo: ' . ($report['review_notes'] ?? '');
        $this->notifier->notice($report['user_id'], 'payment_report_rejected', $title, $body, '/usuario/pagos#reportes');
        $text = sprintf(
            "Hola %s,\n\nLa administración revisó el pago que reportaste para la nota %s (%s) y no pudo aprobarlo.\n\nValor reportado: %s\nFecha de pago: %s\nMotivo: %s\n\nSi tienes dudas, comunícate con la administración o reporta el pago nuevamente con el soporte correcto.",
            $report['username'],
            $report['statement_number'],
            $report['unit_name'],
            BillingService::money($report['amount_cents']),
            $report['paid_on'],
            (string) ($report['review_notes'] ?? '')
        );
        $this->notifier->mail($report['email'], $report['username'], 'Pago reportado no aprobado · ' . $report['statement_number'], $text);
    }

    /**
     * Datos del comprobante. Con $userId solo se entrega si la cuenta es destinataria de la nota
     * o tiene asignado hoy el inmueble.
     */
    /** Reenvía por correo el comprobante a las cuentas del inmueble. Devuelve a cuántas cuentas se envió. */
    public function resendReceipt(int $paymentId): int
    {
        $receipt = $this->receipt($paymentId);
        if ($receipt['voided']) {
            throw new RuntimeException('El pago está anulado: su comprobante no tiene validez.');
        }
        $sent = 0;
        foreach ($this->statementAccounts($receipt['statement_id']) as $account) {
            if (!filter_var((string) $account['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $this->notifier->mail(
                (string) $account['email'],
                (string) $account['username'],
                sprintf('Comprobante de pago %s · %s', $receipt['number'], $receipt['property_name']),
                $this->receiptText($receipt, 'Reenvío del comprobante'),
                $this->receiptHtml($receipt)
            );
            $sent++;
        }

        return $sent;
    }

    public function receipt(int $paymentId, ?int $userId = null): array
    {
        $query = Database::connection()->prepare(
            'SELECT id, statement_id, amount, received_on, reference, notes, created_at, voided_at
             FROM billing_payments WHERE id = ? AND tenant_id = ?'
        );
        $query->execute([$paymentId, Tenant::id()]);
        $payment = $query->fetch();
        if (!is_array($payment)) {
            throw new RuntimeException('El pago no existe.');
        }
        $statement = (new BillingService())->statement((int) $payment['statement_id']);
        if ($userId !== null && !$this->userCanSeeStatement($statement, $userId)) {
            throw new RuntimeException('El pago no existe.');
        }

        $receivedOn = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $payment['received_on']);

        return [
            'payment_id' => (int) $payment['id'],
            'number' => BillingService::receiptNumber((int) $payment['id']),
            'statement_id' => (int) $statement['id'],
            'statement_number' => (string) $statement['number'],
            'period' => (string) $statement['period'],
            'unit_name' => (string) $statement['unit_name'],
            'property_name' => (string) $statement['property_name'],
            'property_tax_id' => (string) $statement['property_tax_id'],
            'property_address' => (string) $statement['property_address'],
            'amount_cents' => BillingService::moneyToCents($payment['amount']),
            'received_on' => (string) $payment['received_on'],
            'received_on_label' => $receivedOn instanceof DateTimeImmutable ? $receivedOn->format('d/m/Y') : (string) $payment['received_on'],
            'reference' => (string) $payment['reference'],
            'notes' => (string) $payment['notes'],
            'voided' => $payment['voided_at'] !== null,
            'balance_cents' => (int) $statement['payable_balance'],
        ];
    }

    public function receiptHtml(array $receipt, ?string $originLabel = null): string
    {
        $e = self::escape(...);
        $rows = [
            'Comprobante' => $receipt['number'],
            'Inmueble' => $receipt['unit_name'],
            'Nota de cobro' => $receipt['statement_number'] . ' · período ' . $receipt['period'],
            'Fecha de pago' => $receipt['received_on_label'],
            'Valor pagado' => BillingService::money($receipt['amount_cents']),
            'Referencia' => $receipt['reference'] !== '' ? $receipt['reference'] : '—',
            'Detalle' => $originLabel ?? ($receipt['notes'] !== '' ? $receipt['notes'] : '—'),
            'Saldo pendiente de la nota' => BillingService::money($receipt['balance_cents']),
        ];
        $rowsHtml = '';
        foreach ($rows as $label => $value) {
            $rowsHtml .= '<tr><th style="text-align:left;padding:8px 12px;border-bottom:1px solid #e5e7eb;color:#4b5563;font-weight:600">'
                . $e($label) . '</th><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb">' . $e((string) $value) . '</td></tr>';
        }
        $voided = $receipt['voided']
            ? '<p style="margin:16px 0;padding:10px 12px;background:#fee2e2;color:#991b1b;border-radius:6px"><strong>Pago anulado.</strong> Este comprobante no tiene validez.</p>'
            : '';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Comprobante ' . $e($receipt['number']) . '</title>'
            . '<style>@media print{.no-print{display:none}}body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#111827;background:#f3f4f6;margin:0;padding:24px}</style></head><body>'
            . '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:10px;padding:28px;border:1px solid #e5e7eb">'
            . '<h1 style="font-size:20px;margin:0 0 4px">' . $e($receipt['property_name']) . '</h1>'
            . '<p style="margin:0 0 20px;color:#6b7280;font-size:14px">'
            . ($receipt['property_tax_id'] !== '' ? 'NIT ' . $e($receipt['property_tax_id']) . ' · ' : '')
            . $e($receipt['property_address']) . '</p>'
            . '<h2 style="font-size:16px;margin:0 0 12px">Comprobante de pago</h2>' . $voided
            . '<table style="width:100%;border-collapse:collapse;font-size:14px">' . $rowsHtml . '</table>'
            . '<p style="margin:20px 0 0;color:#6b7280;font-size:12px">Documento informativo generado por la plataforma de administración. No es una factura electrónica.</p>'
            . '<p class="no-print" style="margin:20px 0 0"><button type="button" onclick="window.print()" style="padding:8px 14px;border-radius:6px;border:1px solid #2563eb;background:#2563eb;color:#fff;cursor:pointer">Imprimir o guardar en PDF</button></p>'
            . '</div></body></html>';
    }

    private function receiptText(array $receipt, string $originLabel): string
    {
        return sprintf(
            "%s\nComprobante de pago %s\n\nInmueble: %s\nNota de cobro: %s (período %s)\nFecha de pago: %s\nValor pagado: %s\nReferencia: %s\nDetalle: %s\nSaldo pendiente de la nota: %s\n",
            $receipt['property_name'],
            $receipt['number'],
            $receipt['unit_name'],
            $receipt['statement_number'],
            $receipt['period'],
            $receipt['received_on_label'],
            BillingService::money($receipt['amount_cents']),
            $receipt['reference'] !== '' ? $receipt['reference'] : '—',
            $originLabel,
            BillingService::money($receipt['balance_cents'])
        );
    }

    /** Cuentas activas que recibieron la nota o tienen hoy asignado el inmueble. */
    private function statementAccounts(int $statementId): array
    {
        $query = Database::connection()->prepare(
            'SELECT DISTINCT users.id, users.username, users.email
             FROM billing_statements AS statements
             JOIN admin_users AS users ON users.tenant_id = statements.tenant_id AND users.is_active = 1
             WHERE statements.id = ? AND statements.tenant_id = ?
               AND (
                    EXISTS (SELECT 1 FROM billing_statement_recipients AS recipients
                            WHERE recipients.statement_id = statements.id AND recipients.user_id = users.id)
                    OR EXISTS (SELECT 1 FROM property_unit_assignments AS assignments
                               WHERE assignments.tenant_id = statements.tenant_id
                                 AND assignments.unit_id = statements.unit_id AND assignments.user_id = users.id)
               )'
        );
        $query->execute([$statementId, Tenant::id()]);

        return $query->fetchAll();
    }

    public function userCanSeeStatement(array $statement, int $userId): bool
    {
        if (BillingService::isRecipient($statement, $userId)) {
            return true;
        }
        $query = Database::connection()->prepare(
            'SELECT 1 FROM property_unit_assignments WHERE tenant_id = ? AND unit_id = ? AND user_id = ? LIMIT 1'
        );
        $query->execute([Tenant::id(), (string) $statement['unit_id'], $userId]);

        return $query->fetchColumn() !== false;
    }

    private function report(int $reportId): array
    {
        $query = Database::connection()->prepare(
            'SELECT reports.*, users.username, users.email, statements.unit_name, statements.period
             FROM billing_payment_reports AS reports
             JOIN admin_users AS users ON users.id = reports.user_id
             JOIN billing_statements AS statements ON statements.id = reports.statement_id
             WHERE reports.id = ? AND reports.tenant_id = ?'
        );
        $query->execute([$reportId, Tenant::id()]);
        $report = $query->fetch();
        if (!is_array($report)) {
            throw new RuntimeException('El pago reportado no existe.');
        }
        $report['user_id'] = (int) $report['user_id'];
        $report['amount_cents'] = BillingService::moneyToCents($report['amount']);
        $report['statement_number'] = BillingService::statementNumber((string) $report['period'], (int) $report['statement_id']);
        $report['username'] = (string) $report['username'];
        $report['email'] = (string) $report['email'];
        $report['reference'] = (string) $report['reference'];

        return $report;
    }




    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
