<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Authorization;
use App\Core\View;
use DateTimeImmutable;
use RuntimeException;

final class ReportController
{
    private const PERMISSION = 'cartera.cobros';

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $service = new ReportService();
        $portfolio = $service->portfolio();
        $age = $_GET['antiguedad'] ?? '';
        $filtered = is_string($age) && isset(ReportService::AGE_LABELS[$age])
            ? array_values(array_filter($portfolio, static fn (array $row): bool => $row['age'] === $age))
            : $portfolio;

        header('Cache-Control: no-store, private');
        View::renderAdmin('Billing/reports', [
            'title' => 'Cartera / Reportes',
            'portfolio' => $filtered,
            'aging' => ReportService::agingSummary($portfolio),
            'selectedAge' => is_string($age) && isset(ReportService::AGE_LABELS[$age]) ? $age : '',
            'collections' => $service->collectionsByMonth(12),
            'latePayments' => $service->paymentsOnSupersededStatements(),
            'currentMonth' => (new DateTimeImmutable('first day of this month'))->format('Y-m'),
        ]);
    }

    /** Búsqueda en toda la cartera, en todos los períodos. */
    public function search(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100, 'UTF-8') : '';

        header('Cache-Control: no-store, private');
        View::renderAdmin('Billing/search', [
            'title' => $query !== '' ? 'Buscar: ' . $query : 'Buscar en cartera',
            'query' => $query,
            'results' => (new BillingSearchService())->search($query),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function export(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        $type = $_GET['tipo'] ?? '';
        $service = new ReportService();

        if ($type === 'cartera') {
            $rows = [[
                'Inmueble', 'Categoría', 'Última nota', 'Período', 'Total nota', 'Pagos', 'Descuento pronto pago',
                'Saldo', 'Vencimiento', 'Vencida', 'Antigüedad', 'Destinatarios',
            ]];
            foreach ($service->portfolio() as $row) {
                $rows[] = [
                    $row['unit_name'], $row['unit_category'], $row['number'], $row['period'],
                    self::amount($row['total_due']), self::amount($row['payments_total']), self::amount($row['discount']),
                    self::amount($row['balance']), $row['payment_due_date'], $row['past_due'] ? 'Sí' : 'No',
                    ReportService::AGE_LABELS[$row['age']], $row['recipients'],
                ];
            }
            $this->sendCsv('cartera-' . date('Y-m-d') . '.csv', $rows);
        }

        if ($type === 'pagos') {
            $month = $_GET['mes'] ?? '';
            try {
                $payments = $service->paymentsReceivedIn(is_string($month) ? $month : '');
            } catch (RuntimeException $exception) {
                http_response_code(422);
                echo htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return;
            }
            $rows = [[
                'Fecha de recepción', 'Inmueble', 'Nota', 'Período de la nota', 'Valor', 'Referencia', 'Notas',
                'Registrado por', 'Estado', 'Motivo de anulación',
            ]];
            foreach ($payments as $payment) {
                $rows[] = [
                    $payment['received_on'], $payment['unit_name'],
                    BillingService::statementNumber((string) $payment['period'], (int) $payment['statement_id']),
                    $payment['period'], self::amount(BillingService::moneyToCents($payment['amount'])),
                    $payment['reference'], $payment['notes'], $payment['created_by_name'] ?? '',
                    $payment['voided_at'] === null ? 'Vigente' : 'Anulado', $payment['void_reason'] ?? '',
                ];
            }
            $this->sendCsv('pagos-' . $month . '.csv', $rows);
        }

        http_response_code(404);
        echo 'Reporte no disponible.';
    }

    /** @param list<list<mixed>> $rows */
    private function sendCsv(string $filename, array $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $filename) . '"');
        header('Cache-Control: no-store, private');
        $out = fopen('php://output', 'wb');
        // BOM y punto y coma: Excel en configuración regional es-CO abre el archivo sin asistente.
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, array_map(self::safeCell(...), $row), ';', '"', '');
        }
        fclose($out);
        exit;
    }

    /** Evita que una hoja de cálculo interprete como fórmula un texto ingresado por usuarios. */
    private static function safeCell(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 && !is_numeric(str_replace(',', '.', $text))
            ? "'" . $text
            : $text;
    }

    private static function amount(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign . intdiv($absolute, 100) . ',' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
