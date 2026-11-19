<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Modules\Configuration\CompositionService;

/**
 * Búsqueda de solo lectura en toda la cartera (todos los períodos): inmuebles, notas de cobro,
 * pagos y conceptos. Admite nombre de inmueble, residente, correo, número de nota (NC-…),
 * referencia o notas de pago, descripción de conceptos y valores exactos en pesos.
 */
final class BillingSearchService
{
    public const MIN_LENGTH = 2;
    private const LIMIT = 60;

    /**
     * @return array{query: string, amount_cents: ?int, units: list<array>, statements: list<array>,
     *               payments: list<array>, items: list<array>, truncated: bool}
     */
    public function search(string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        $result = ['query' => $query, 'amount_cents' => null, 'units' => [], 'statements' => [], 'payments' => [], 'items' => [], 'truncated' => false];
        if (mb_strlen($query, 'UTF-8') < self::MIN_LENGTH) {
            return $result;
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';
        $amount = self::amountFromQuery($query);
        $result['amount_cents'] = $amount;
        $amountValue = $amount === null ? null : intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
        // «NC-202611-000005», «202611-5» o «000005» encuentran la nota por su consecutivo.
        $statementNumber = preg_match('/^(?:NC-?)?(?:(\d{6})-?)?0*(\d{1,9})$/i', $query, $parts) === 1 ? $parts : null;

        $pdo = Database::connection();

        // Notas de cobro.
        $where = ['statements.unit_name LIKE :like', 'recipients_match.statement_id IS NOT NULL'];
        $params = ['like' => $like];
        if ($statementNumber !== null) {
            $where[] = '(statements.id = :number_id' . ($statementNumber[1] !== '' ? ' AND statements.period = :number_period' : '') . ')';
            $params['number_id'] = (int) $statementNumber[2];
            if ($statementNumber[1] !== '') {
                $params['number_period'] = substr($statementNumber[1], 0, 4) . '-' . substr($statementNumber[1], 4, 2);
            }
        }
        if ($amountValue !== null) {
            $where[] = 'statements.total_due = :amount';
            $params['amount'] = $amountValue;
        }
        $statement = $pdo->prepare(
            'SELECT statements.id, statements.unit_id, statements.unit_name, statements.unit_category, statements.period,
                    statements.total_due, statements.early_discount, statements.early_payment_date, statements.payment_due_date,
                    COALESCE(paid.total, 0) AS payments_total, COALESCE(paid.early, 0) AS early_payments,
                    (SELECT GROUP_CONCAT(r.recipient_name ORDER BY r.recipient_name SEPARATOR ", ")
                     FROM billing_statement_recipients r WHERE r.statement_id = statements.id) AS recipients,
                    (SELECT MIN(newer.period) FROM billing_statements newer
                     WHERE newer.unit_id = statements.unit_id AND newer.period > statements.period) AS superseded_by_period
             FROM billing_statements AS statements
             LEFT JOIN (
                 SELECT DISTINCT statement_id FROM billing_statement_recipients
                 WHERE recipient_name LIKE :like_recipient OR recipient_email LIKE :like_email
             ) AS recipients_match ON recipients_match.statement_id = statements.id
             LEFT JOIN (
                 SELECT payments.statement_id, SUM(payments.amount) AS total,
                        SUM(CASE WHEN payments.received_on <= owner.early_payment_date THEN payments.amount ELSE 0 END) AS early
                 FROM billing_payments payments
                 JOIN billing_statements owner ON owner.id = payments.statement_id
                 WHERE payments.voided_at IS NULL
                 GROUP BY payments.statement_id
             ) AS paid ON paid.statement_id = statements.id
             WHERE ' . implode(' OR ', $where) . '
             ORDER BY statements.period DESC, statements.unit_name
             LIMIT ' . (self::LIMIT + 1)
        );
        $statement->execute($params + ['like_recipient' => $like, 'like_email' => $like]);
        $statements = $statement->fetchAll();
        $result['truncated'] = count($statements) > self::LIMIT;
        foreach (array_slice($statements, 0, self::LIMIT) as $row) {
            $total = BillingService::moneyToCents($row['total_due']);
            $paid = BillingService::moneyToCents($row['payments_total']);
            $settlement = BillingService::settle($total, BillingService::moneyToCents($row['early_discount']), $paid, BillingService::moneyToCents($row['early_payments']));
            $result['statements'][] = [
                'id' => (int) $row['id'],
                'number' => sprintf('NC-%s-%06d', str_replace('-', '', (string) $row['period']), (int) $row['id']),
                'unit_id' => (string) $row['unit_id'],
                'unit_name' => (string) $row['unit_name'],
                'period' => (string) $row['period'],
                'total' => $total,
                'paid' => $paid,
                'balance' => $settlement['balance'],
                'due_date' => (string) $row['payment_due_date'],
                'recipients' => (string) ($row['recipients'] ?? ''),
                'superseded_by' => $row['superseded_by_period'] === null ? null : (string) $row['superseded_by_period'],
            ];
        }

        // Pagos (incluidos los anulados, para auditoría).
        $paymentWhere = ['payments.reference LIKE :like', 'payments.notes LIKE :like_notes', 'statements.unit_name LIKE :like_unit'];
        $paymentParams = ['like' => $like, 'like_notes' => $like, 'like_unit' => $like];
        if ($amountValue !== null) {
            $paymentWhere[] = 'payments.amount = :amount';
            $paymentParams['amount'] = $amountValue;
        }
        $payments = $pdo->prepare(
            'SELECT payments.id, payments.amount, payments.received_on, payments.reference, payments.notes, payments.voided_at,
                    payments.void_reason, statements.id AS statement_id, statements.period, statements.unit_name,
                    creators.username AS created_by_name
             FROM billing_payments AS payments
             JOIN billing_statements AS statements ON statements.id = payments.statement_id
             LEFT JOIN admin_users AS creators ON creators.id = payments.created_by
             WHERE ' . implode(' OR ', $paymentWhere) . '
             ORDER BY payments.received_on DESC, payments.id DESC
             LIMIT ' . self::LIMIT
        );
        $payments->execute($paymentParams);
        foreach ($payments->fetchAll() as $row) {
            $result['payments'][] = [
                'id' => (int) $row['id'],
                'amount' => BillingService::moneyToCents($row['amount']),
                'received_on' => (string) $row['received_on'],
                'reference' => (string) $row['reference'],
                'notes' => (string) $row['notes'],
                'voided' => $row['voided_at'] !== null,
                'void_reason' => (string) ($row['void_reason'] ?? ''),
                'statement_id' => (int) $row['statement_id'],
                'statement_number' => sprintf('NC-%s-%06d', str_replace('-', '', (string) $row['period']), (int) $row['statement_id']),
                'period' => (string) $row['period'],
                'unit_name' => (string) $row['unit_name'],
                'created_by' => (string) ($row['created_by_name'] ?? ''),
            ];
        }

        // Conceptos adicionales (los conceptos no guardan el nombre del inmueble: se toma de la estructura).
        $unitNames = [];
        foreach ((new CompositionService())->current()['units'] ?? [] as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null)) {
                $unitNames[$unit['id']] = (string) ($unit['name'] ?? '');
            }
        }
        $matchingUnitIds = array_keys(array_filter($unitNames, static fn (string $name): bool => mb_stripos($name, $query, 0, 'UTF-8') !== false));
        $itemWhere = ['items.description LIKE :like'];
        $itemParams = ['like' => $like];
        if ($matchingUnitIds !== [] && count($matchingUnitIds) <= 500) {
            $placeholders = [];
            foreach (array_values($matchingUnitIds) as $index => $unitId) {
                $placeholders[] = ':unit' . $index;
                $itemParams['unit' . $index] = $unitId;
            }
            $itemWhere[] = 'items.unit_id IN (' . implode(', ', $placeholders) . ')';
        }
        if ($amountValue !== null) {
            $itemWhere[] = 'items.amount = :amount';
            $itemParams['amount'] = $amountValue;
        }
        $items = $pdo->prepare(
            'SELECT items.id, items.unit_id, items.period, items.category, items.description, items.amount,
                    (SELECT statements.id FROM billing_statements statements
                     WHERE statements.unit_id = items.unit_id AND statements.period = items.period) AS statement_id
             FROM billing_monthly_items AS items
             WHERE ' . implode(' OR ', $itemWhere) . '
             ORDER BY items.period DESC, items.id DESC
             LIMIT ' . self::LIMIT
        );
        $items->execute($itemParams);
        foreach ($items->fetchAll() as $row) {
            $result['items'][] = [
                'id' => (int) $row['id'],
                'unit_name' => $unitNames[(string) $row['unit_id']] ?? 'Inmueble retirado',
                'period' => (string) $row['period'],
                'category' => (string) $row['category'],
                'description' => (string) $row['description'],
                'amount' => BillingService::moneyToCents($row['amount']),
                'issued' => $row['statement_id'] !== null,
            ];
        }

        // Inmuebles: estado actual (nota más reciente) de cada inmueble encontrado.
        $foundUnitIds = array_fill_keys(array_column($result['statements'], 'unit_id'), true);
        foreach ($matchingUnitIds as $unitId) {
            $foundUnitIds[$unitId] = true;
        }
        if ($foundUnitIds !== []) {
            foreach ((new ReportService())->portfolio() as $row) {
                if (isset($foundUnitIds[$row['unit_id']])) {
                    $result['units'][] = $row;
                    unset($foundUnitIds[$row['unit_id']]);
                }
            }
            // Inmuebles de la estructura que coinciden pero aún no tienen notas.
            foreach (array_keys($foundUnitIds) as $unitId) {
                if (isset($unitNames[$unitId])) {
                    $result['units'][] = ['unit_id' => $unitId, 'unit_name' => $unitNames[$unitId], 'statement_id' => null];
                }
            }
            $result['units'] = array_slice($result['units'], 0, self::LIMIT);
        }

        return $result;
    }

    /** «350.000», «$ 350.000,50» o «350000» → centavos; null si no parece un valor en pesos. */
    public static function amountFromQuery(string $query): ?int
    {
        $text = trim(str_replace(['$', 'COP', 'cop', ' '], '', $query));
        // Los números con ceros a la izquierda («000004») son consecutivos de nota, no valores.
        if (preg_match('/^[1-9]\d{0,2}(?:\.\d{3})+(?:,\d{1,2})?$/D', $text) === 1 || preg_match('/^[1-9]\d{3,12}(?:,\d{1,2})?$/D', $text) === 1) {
            [$whole, $fraction] = array_pad(explode(',', str_replace('.', '', $text), 2), 2, '');
            return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        }

        return null;
    }
}
