<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Configuration\CompositionService;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Consultas de solo lectura sobre cartera: estado por inmueble, antigüedad, recaudo e
 * indicadores del panel. Usa la misma regla de saldo que BillingService::settle().
 */
final class ReportService
{
    public const AGE_LABELS = [
        'al_dia' => 'Al día',
        'corriente' => 'Corriente (no vencida)',
        '1_30' => '1 a 30 días',
        '31_60' => '31 a 60 días',
        '61_90' => '61 a 90 días',
        'mas_90' => 'Más de 90 días',
    ];

    /**
     * Estado de la nota más reciente de cada inmueble. El saldo de notas anteriores ya está
     * incluido en la más reciente, por eso solo se suma esa.
     *
     * La antigüedad se estima contando los períodos consecutivos que recibieron saldo
     * arrastrado, más el período actual si ya pasó su vencimiento.
     */
    public function portfolio(?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $pdo = Database::connection();
        $portfolioQuery = $pdo->prepare(
            "SELECT statements.id, statements.unit_id, statements.unit_name, statements.unit_category,
                    statements.period, statements.total_due, statements.early_discount,
                    statements.early_payment_date, statements.payment_due_date,
                    COALESCE(paid.total, 0) AS payments_total, COALESCE(paid.early, 0) AS early_payments,
                    (SELECT GROUP_CONCAT(recipients.recipient_name ORDER BY recipients.recipient_name SEPARATOR ', ')
                     FROM billing_statement_recipients AS recipients
                     WHERE recipients.statement_id = statements.id) AS recipients
             FROM billing_statements AS statements
             JOIN (
                 SELECT unit_id, MAX(period) AS period FROM billing_statements WHERE tenant_id = ? GROUP BY unit_id
             ) AS latest ON latest.unit_id = statements.unit_id AND latest.period = statements.period
             LEFT JOIN (
                 SELECT payments.statement_id,
                        SUM(payments.amount) AS total,
                        SUM(CASE WHEN payments.received_on <= owner.early_payment_date THEN payments.amount ELSE 0 END) AS early
                 FROM billing_payments AS payments
                 JOIN billing_statements AS owner ON owner.id = payments.statement_id
                 WHERE payments.tenant_id = ? AND payments.voided_at IS NULL
                 GROUP BY payments.statement_id
             ) AS paid ON paid.statement_id = statements.id
             WHERE statements.tenant_id = ?
             ORDER BY statements.unit_name"
        );
        $portfolioQuery->execute([Tenant::id(), Tenant::id(), Tenant::id()]);
        $rows = $portfolioQuery->fetchAll();

        $carried = $this->carriedPeriods();
        $result = [];
        foreach ($rows as $row) {
            $totalDue = BillingService::moneyToCents($row['total_due']);
            $paymentsTotal = BillingService::moneyToCents($row['payments_total']);
            $settlement = BillingService::settle(
                $totalDue,
                BillingService::moneyToCents($row['early_discount']),
                $paymentsTotal,
                BillingService::moneyToCents($row['early_payments'])
            );
            $balance = $settlement['balance'];
            $pastDue = $balance > 0 && $today->format('Y-m-d') > (string) $row['payment_due_date'];
            $overdueMonths = $balance > 0 ? ($carried[(string) $row['unit_id']] ?? 0) + ($pastDue ? 1 : 0) : 0;

            $result[] = [
                'statement_id' => (int) $row['id'],
                'number' => BillingService::statementNumber((string) $row['period'], (int) $row['id']),
                'unit_id' => (string) $row['unit_id'],
                'unit_name' => (string) $row['unit_name'],
                'unit_category' => (string) $row['unit_category'],
                'period' => (string) $row['period'],
                'total_due' => $totalDue,
                'payments_total' => $paymentsTotal,
                'discount' => $settlement['discount'],
                'balance' => $balance,
                'payment_due_date' => (string) $row['payment_due_date'],
                'past_due' => $pastDue,
                'overdue_months' => $overdueMonths,
                'age' => self::ageBucket($balance, $overdueMonths),
                'recipients' => (string) ($row['recipients'] ?? ''),
            ];
        }

        return $result;
    }

    /** @return array<string, array{count: int, amount: int}> */
    public static function agingSummary(array $portfolio): array
    {
        $summary = array_fill_keys(array_keys(self::AGE_LABELS), ['count' => 0, 'amount' => 0]);
        foreach ($portfolio as $row) {
            $summary[$row['age']]['count']++;
            $summary[$row['age']]['amount'] += max(0, $row['balance']);
        }

        return $summary;
    }

    /** Recaudo (pagos vigentes) por mes de recepción, de los últimos $months meses incluido el actual. */
    public function collectionsByMonth(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $start = (new DateTimeImmutable('first day of this month'))->modify('-' . ($months - 1) . ' months');
        $query = Database::connection()->prepare(
            "SELECT DATE_FORMAT(received_on, '%Y-%m') AS month, SUM(amount) AS total, COUNT(*) AS payments
             FROM billing_payments
             WHERE tenant_id = ? AND voided_at IS NULL AND received_on >= ?
             GROUP BY month"
        );
        $query->execute([Tenant::id(), $start->format('Y-m-d')]);
        $byMonth = [];
        foreach ($query->fetchAll() as $row) {
            $byMonth[(string) $row['month']] = [
                'total' => BillingService::moneyToCents($row['total']),
                'payments' => (int) $row['payments'],
            ];
        }
        $result = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->modify('+' . $i . ' months')->format('Y-m');
            $result[] = ['month' => $month] + ($byMonth[$month] ?? ['total' => 0, 'payments' => 0]);
        }

        return $result;
    }

    /** Pagos recibidos en un mes (incluye anulados, marcados), para exportación. */
    public function paymentsReceivedIn(string $month): array
    {
        if (!BillingService::validPeriod($month)) {
            throw new RuntimeException('Selecciona un mes válido.');
        }
        $start = new DateTimeImmutable($month . '-01');
        $query = Database::connection()->prepare(
            'SELECT payments.id, payments.received_on, payments.amount, payments.reference, payments.notes,
                    payments.voided_at, payments.void_reason, payments.statement_id,
                    statements.unit_name, statements.period, creators.username AS created_by_name
             FROM billing_payments AS payments
             JOIN billing_statements AS statements ON statements.id = payments.statement_id
             LEFT JOIN admin_users AS creators ON creators.id = payments.created_by
             WHERE payments.tenant_id = ? AND payments.received_on BETWEEN ? AND ?
             ORDER BY payments.received_on, payments.id'
        );
        $query->execute([Tenant::id(), $start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')]);

        return $query->fetchAll();
    }

    /** Indicadores del panel administrativo. Cada bloque falla de forma aislada. */
    public function dashboard(): array
    {
        $portfolio = [];
        try {
            $portfolio = $this->portfolio();
        } catch (Throwable $exception) {
            error_log('No se pudo calcular la cartera del panel: ' . $exception->getMessage());
        }
        $receivable = 0;
        $credit = 0;
        $inArrears = 0;
        foreach ($portfolio as $row) {
            if ($row['balance'] > 0) {
                $receivable += $row['balance'];
            } else {
                $credit += -$row['balance'];
            }
            if ($row['past_due']) {
                $inArrears++;
            }
        }
        $topDebtors = array_values(array_filter($portfolio, static fn (array $row): bool => $row['balance'] > 0));
        usort($topDebtors, static fn (array $a, array $b): int => $b['balance'] <=> $a['balance']);

        $pdo = Database::connection();
        $currentPeriod = (new DateTimeImmutable('first day of this month'))->format('Y-m');
        $issuedThisPeriod = $pdo->prepare('SELECT COUNT(*) FROM billing_statements WHERE tenant_id = ? AND period = ?');
        $issuedThisPeriod->execute([Tenant::id(), $currentPeriod]);
        $deliveryQuery = $pdo->prepare(
            "SELECT
                SUM(recipients.delivery_status = 'failed') AS failed,
                SUM(recipients.delivery_status IN ('pending', 'sending')) AS pending
             FROM billing_statement_recipients AS recipients
             JOIN billing_statements AS statements ON statements.id = recipients.statement_id
             WHERE statements.tenant_id = ?"
        );
        $deliveryQuery->execute([Tenant::id()]);
        $delivery = $deliveryQuery->fetch();
        $collections = $this->collectionsByMonth(6);

        return [
            'current_period' => $currentPeriod,
            'receivable' => $receivable,
            'credit' => $credit,
            'units_billed' => count($portfolio),
            'units_in_arrears' => $inArrears,
            'aging' => self::agingSummary($portfolio),
            'top_debtors' => array_slice($topDebtors, 0, 5),
            'collections' => $collections,
            'collected_this_month' => $collections[count($collections) - 1]['total'] ?? 0,
            'issued_this_period' => (int) $issuedThisPeriod->fetchColumn(),
            'failed_deliveries' => (int) ($delivery['failed'] ?? 0),
            'pending_deliveries' => (int) ($delivery['pending'] ?? 0),
            'units' => $this->unitCoverage(),
            'late_payments' => $this->paymentsOnSupersededStatements(),
        ];
    }

    /**
     * Pagos registrados en una nota después de que se emitió la siguiente (p. ej. confirmación
     * tardía de Bold). Su valor no llegó al saldo trasladado y requiere un ajuste manual.
     */
    public function paymentsOnSupersededStatements(): array
    {
        $query = Database::connection()->prepare(
            'SELECT payments.id, payments.amount, payments.received_on, payments.created_at,
                    statements.unit_name, statements.period, MIN(newer.period) AS newer_period
             FROM billing_payments AS payments
             JOIN billing_statements AS statements ON statements.id = payments.statement_id
             JOIN billing_statements AS newer
               ON newer.tenant_id = statements.tenant_id
              AND newer.unit_id = statements.unit_id
              AND newer.period > statements.period
              AND newer.issued_at < payments.created_at
             WHERE payments.tenant_id = ? AND payments.voided_at IS NULL
             GROUP BY payments.id
             ORDER BY payments.created_at DESC
             LIMIT 20'
        );
        $query->execute([Tenant::id()]);

        return $query->fetchAll();
    }

    /** @return array{total: int, assigned: int, unassigned: int, missing_coefficient: int}|null */
    private function unitCoverage(): ?array
    {
        $composition = (new CompositionService())->current();
        if (!is_array($composition) || !$composition['completed'] || !is_array($composition['units'])) {
            return null;
        }
        $assigned = [];
        $assignedQuery = Database::connection()->prepare(
            'SELECT DISTINCT assignments.unit_id
             FROM property_unit_assignments AS assignments
             JOIN admin_users AS users ON users.id = assignments.user_id AND users.is_active = 1
             WHERE assignments.tenant_id = ?'
        );
        $assignedQuery->execute([Tenant::id()]);
        foreach ($assignedQuery->fetchAll() as $row) {
            $assigned[(string) $row['unit_id']] = true;
        }
        $total = 0;
        $withUser = 0;
        $missingCoefficient = 0;
        foreach ($composition['units'] as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $total++;
            if (isset($assigned[(string) ($unit['id'] ?? '')])) {
                $withUser++;
            }
            if (!is_numeric($unit['coefficient'] ?? null) || (float) $unit['coefficient'] < 0) { // 0 % es válido
                $missingCoefficient++;
            }
        }

        return [
            'total' => $total,
            'assigned' => $withUser,
            'unassigned' => $total - $withUser,
            'missing_coefficient' => $missingCoefficient,
        ];
    }

    /** @return array<string, int> unit_id => períodos consecutivos (desde el último) con saldo arrastrado */
    private function carriedPeriods(): array
    {
        $carriedQuery = Database::connection()->prepare(
            'SELECT unit_id, opening_balance FROM billing_statements WHERE tenant_id = ? ORDER BY unit_id, period DESC'
        );
        $carriedQuery->execute([Tenant::id()]);
        $rows = $carriedQuery->fetchAll();
        $carried = [];
        $closed = [];
        foreach ($rows as $row) {
            $unitId = (string) $row['unit_id'];
            $carried[$unitId] ??= 0;
            if (isset($closed[$unitId])) {
                continue;
            }
            if (BillingService::moneyToCents($row['opening_balance']) > 0) {
                $carried[$unitId]++;
            } else {
                $closed[$unitId] = true;
            }
        }

        return $carried;
    }

    private static function ageBucket(int $balance, int $overdueMonths): string
    {
        if ($balance <= 0) {
            return 'al_dia';
        }

        return match (true) {
            $overdueMonths <= 0 => 'corriente',
            $overdueMonths === 1 => '1_30',
            $overdueMonths === 2 => '31_60',
            $overdueMonths === 3 => '61_90',
            default => 'mas_90',
        };
    }
}
