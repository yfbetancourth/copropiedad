<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Tenant;
use DateTimeImmutable;
use PDOException;
use RuntimeException;

/**
 * Documentos de cartera por inmueble: estado de cuenta, paz y salvo (con código de verificación) y los adjuntos que
 * la administración envía en Atención a residentes. Los saldos salen de BillingService para que coincidan siempre
 * con las notas de cobro.
 */
final class AccountDocumentService
{
    public const TYPE_LABELS = [
        'receipt' => 'Comprobante de pago',
        'statement' => 'Nota de cobro',
        'account' => 'Estado de cuenta',
        'clearance' => 'Paz y salvo',
    ];
    private const ACCOUNT_PERIODS = 12;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly BillingService $billing = new BillingService())
    {
    }

    /** @return array<string, string> unit_id => nombre, de los inmuebles asignados al usuario que ya tienen notas */
    public function unitsForUser(int $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT assignments.unit_id,
                    (SELECT statements.unit_name FROM billing_statements AS statements
                     WHERE statements.tenant_id = assignments.tenant_id AND statements.unit_id = assignments.unit_id
                     ORDER BY statements.period DESC LIMIT 1) AS unit_name
             FROM property_unit_assignments AS assignments
             WHERE assignments.tenant_id = ? AND assignments.user_id = ?'
        );
        $statement->execute([Tenant::id(), $userId]);
        $units = [];
        foreach ($statement->fetchAll() as $row) {
            if ($row['unit_name'] !== null) {
                $units[(string) $row['unit_id']] = (string) $row['unit_name'];
            }
        }
        asort($units, SORT_NATURAL | SORT_FLAG_CASE);

        return $units;
    }

    public function userHasUnit(int $userId, string $unitId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM property_unit_assignments WHERE tenant_id = ? AND unit_id = ? AND user_id = ? LIMIT 1'
        );
        $statement->execute([Tenant::id(), $unitId, $userId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Estado de cuenta: últimas notas del inmueble con sus pagos y el saldo actual.
     *
     * @return array<string, mixed>
     */
    public function account(string $unitId): array
    {
        $ids = Database::connection()->prepare(
            'SELECT id FROM billing_statements WHERE tenant_id = ? AND unit_id = ? ORDER BY period DESC LIMIT ' . self::ACCOUNT_PERIODS
        );
        $ids->execute([Tenant::id(), $unitId]);
        $statements = array_map(fn ($id): array => $this->billing->statement((int) $id), $ids->fetchAll(\PDO::FETCH_COLUMN));
        if ($statements === []) {
            throw new RuntimeException('El inmueble aún no tiene notas de cobro.');
        }
        $latest = $statements[0];
        $payments = [];
        foreach ($statements as $statement) {
            foreach ($statement['payments'] as $payment) {
                $payments[] = [
                    'id' => (int) $payment['id'],
                    'number' => BillingService::receiptNumber((int) $payment['id']),
                    'received_on' => (string) $payment['received_on'],
                    'amount' => BillingService::moneyToCents($payment['amount']),
                    'reference' => (string) $payment['reference'],
                    'period' => (string) $statement['period'],
                    'voided' => $payment['voided_at'] !== null,
                ];
            }
        }
        usort($payments, static fn (array $a, array $b): int => [$b['received_on'], $b['id']] <=> [$a['received_on'], $a['id']]);

        return [
            'unit_id' => $unitId,
            'unit_name' => (string) $latest['unit_name'],
            'reference' => (new PaymentReferenceService())->forUnit($unitId),
            'property_name' => (string) $latest['property_name'],
            'property_tax_id' => (string) $latest['property_tax_id'],
            'property_address' => (string) $latest['property_address'],
            'cut_date' => date('Y-m-d'),
            'statements' => array_map(static fn (array $statement): array => [
                'number' => (string) $statement['number'],
                'period' => (string) $statement['period'],
                'total_due' => BillingService::moneyToCents($statement['total_due']),
                'discount' => (int) $statement['discount_applied'],
                'payments' => (int) $statement['payments_total'],
                'balance' => (int) $statement['balance'],
                'carried' => $statement['superseded_by'] !== null,
                'due_date' => (string) $statement['payment_due_date'],
            ], $statements),
            'payments' => $payments,
            'balance' => (int) $latest['payable_balance'],
            'latest_statement_id' => (int) $latest['id'],
        ];
    }

    /** @return array{available: bool, reason: string} */
    public function clearanceStatus(string $unitId): array
    {
        try {
            $account = $this->account($unitId);
        } catch (RuntimeException $exception) {
            return ['available' => false, 'reason' => $exception->getMessage()];
        }

        return $account['balance'] > 0
            ? ['available' => false, 'reason' => 'Tiene un saldo pendiente de ' . BillingService::money($account['balance']) . '.']
            : ['available' => true, 'reason' => ''];
    }

    /**
     * Expide un paz y salvo si el inmueble está al día. Vigente hasta el último día del mes en curso.
     *
     * @return array<string, mixed>
     */
    public function issueClearance(string $unitId, ?int $issuedBy, ?int $requestedBy): array
    {
        $account = $this->account($unitId);
        if ($account['balance'] > 0) {
            throw new RuntimeException('No se puede expedir el paz y salvo: el inmueble tiene un saldo pendiente de ' . BillingService::money($account['balance']) . '.');
        }
        $validUntil = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
        for ($attempt = 1; ; $attempt++) {
            $code = '';
            for ($index = 0; $index < 12; $index++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            try {
                Database::connection()->prepare(
                    'INSERT INTO billing_clearances (tenant_id, unit_id, unit_name, statement_id, code, valid_until, issued_by, requested_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([Tenant::id(), $unitId, $account['unit_name'], $account['latest_statement_id'], $code, $validUntil, $issuedBy, $requestedBy]);

                return $this->clearance((int) Database::connection()->lastInsertId());
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() !== '23000' || $attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }

    public function clearance(int $clearanceId): array
    {
        return $this->findClearance('clearances.id = ?', [$clearanceId])
            ?? throw new RuntimeException('El paz y salvo no existe.');
    }

    /** Busca por código (con o sin guiones, sin distinguir mayúsculas). */
    public function clearanceByCode(string $code): ?array
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if (preg_match('/^[' . self::CODE_ALPHABET . ']{12}$/D', $code) !== 1) {
            return null;
        }

        return $this->findClearance('clearances.code = ?', [$code]);
    }

    /** @return list<array<string, mixed>> */
    public function recentClearances(int $limit = 15): array
    {
        $statement = Database::connection()->prepare(
            $this->clearanceSql() . ' WHERE clearances.tenant_id = ? ORDER BY clearances.issued_at DESC, clearances.id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $statement->execute([Tenant::id()]);

        return array_map([$this, 'normalizeClearance'], $statement->fetchAll());
    }

    /** @return list<array<string, mixed>> paz y salvo de un inmueble (para el portal) */
    public function clearancesForUnit(string $unitId): array
    {
        $statement = Database::connection()->prepare(
            $this->clearanceSql() . ' WHERE clearances.tenant_id = ? AND clearances.unit_id = ? ORDER BY clearances.issued_at DESC, clearances.id DESC LIMIT 5'
        );
        $statement->execute([Tenant::id(), $unitId]);

        return array_map([$this, 'normalizeClearance'], $statement->fetchAll());
    }

    public static function formatCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    /**
     * Documentos que la administración puede adjuntar en una conversación con el residente, por inmueble.
     *
     * @return list<array{unit_name: string, options: list<array{value: string, label: string, disabled?: bool}>}>
     */
    public function documentsForResident(int $residentId): array
    {
        $groups = [];
        foreach ($this->unitsForUser($residentId) as $unitId => $unitName) {
            try {
                $account = $this->account($unitId);
            } catch (RuntimeException) {
                continue;
            }
            $options = [['value' => 'account:' . $unitId, 'label' => 'Estado de cuenta de ' . $unitName]];
            $options[] = $account['balance'] > 0
                ? ['value' => '', 'label' => 'Paz y salvo (no disponible: saldo de ' . BillingService::money($account['balance']) . ')', 'disabled' => true]
                : ['value' => 'clearance:' . $unitId, 'label' => 'Paz y salvo de ' . $unitName . ' (se expide al enviar)'];
            $ids = Database::connection()->prepare(
                'SELECT id, period FROM billing_statements WHERE tenant_id = ? AND unit_id = ? ORDER BY period DESC LIMIT 6'
            );
            $ids->execute([Tenant::id(), $unitId]);
            foreach ($ids->fetchAll() as $row) {
                $options[] = [
                    'value' => 'statement:' . (int) $row['id'],
                    'label' => 'Nota de cobro ' . BillingService::statementNumber((string) $row['period'], (int) $row['id']) . ' (' . $row['period'] . ')',
                ];
            }
            foreach (array_slice(array_filter($account['payments'], static fn (array $payment): bool => !$payment['voided']), 0, 10) as $payment) {
                $options[] = [
                    'value' => 'receipt:' . $payment['id'],
                    'label' => 'Comprobante ' . $payment['number'] . ' · ' . BillingService::money($payment['amount']) . ' del ' . date('d/m/Y', strtotime($payment['received_on']) ?: 0),
                ];
            }
            $groups[] = ['unit_name' => $unitName, 'options' => $options];
        }

        return $groups;
    }

    /**
     * Valida que el documento elegido pertenezca a un inmueble del residente y lo prepara para adjuntarlo.
     * Un paz y salvo se expide en este momento.
     *
     * @return array{type: string, ref: string, label: string}
     */
    public function resolveAttachment(int $residentId, string $value, int $actorId): array
    {
        [$type, $ref] = array_pad(explode(':', $value, 2), 2, '');
        $denied = new RuntimeException('El documento elegido no corresponde a los inmuebles de este residente.');
        switch ($type) {
            case 'account':
            case 'clearance':
                if (!preg_match('/^[a-f0-9]{32}$/D', $ref) || !$this->userHasUnit($residentId, $ref)) {
                    throw $denied;
                }
                if ($type === 'account') {
                    return ['type' => 'account', 'ref' => $ref, 'label' => 'Estado de cuenta de ' . $this->account($ref)['unit_name']];
                }
                $clearance = $this->issueClearance($ref, $actorId, $residentId);

                return ['type' => 'clearance', 'ref' => (string) $clearance['id'], 'label' => 'Paz y salvo de ' . $clearance['unit_name'] . ' · ' . self::formatCode($clearance['code'])];
            case 'statement':
                $statement = ctype_digit($ref) ? $this->billing->statement((int) $ref) : null;
                if ($statement === null || !$this->userHasUnit($residentId, (string) $statement['unit_id'])) {
                    throw $denied;
                }

                return ['type' => 'statement', 'ref' => $ref, 'label' => 'Nota de cobro ' . $statement['number'] . ' (' . $statement['unit_name'] . ')'];
            case 'receipt':
                try {
                    $receipt = ctype_digit($ref) ? (new PaymentNotificationService())->receipt((int) $ref, $residentId) : null;
                } catch (RuntimeException) {
                    $receipt = null;
                }
                if ($receipt === null) {
                    throw $denied;
                }

                return ['type' => 'receipt', 'ref' => $ref, 'label' => 'Comprobante ' . $receipt['number'] . ' · ' . BillingService::money($receipt['amount_cents'])];
        }
        throw new RuntimeException('Selecciona un documento válido.');
    }

    /**
     * Ruta del documento: en el portal (relativa a la base pública) o en el panel (relativa a la base del panel,
     * que ya termina en /admin).
     */
    public static function link(string $type, string $ref, bool $forResident): string
    {
        return match ($type) {
            'receipt' => ($forResident ? '/usuario/pagos/comprobante?id=' : '/cartera/cobros/comprobante?id=') . rawurlencode($ref),
            'statement' => ($forResident ? '/usuario/pagos/nota?id=' : '/cartera/cobros/nota?id=') . rawurlencode($ref),
            'account' => ($forResident ? '/usuario/pagos/estado-de-cuenta?inmueble=' : '/cartera/documentos/estado-de-cuenta?inmueble=') . rawurlencode($ref),
            'clearance' => ($forResident ? '/usuario/pagos/paz-y-salvo?id=' : '/cartera/documentos/paz-y-salvo?id=') . rawurlencode($ref),
            default => '',
        };
    }

    public function accountHtml(array $account): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn (int $cents): string => BillingService::money($cents);
        $cell = 'padding:6px 8px;border-bottom:1px solid #e5e7eb';
        $statementRows = '';
        foreach ($account['statements'] as $row) {
            $statementRows .= '<tr><td style="' . $cell . '">' . $e($row['period']) . '</td><td style="' . $cell . '">' . $e($row['number']) . '</td>'
                . '<td style="' . $cell . ';text-align:right">' . $money($row['total_due']) . '</td>'
                . '<td style="' . $cell . ';text-align:right">' . ($row['discount'] > 0 ? '-' . $money($row['discount']) : '—') . '</td>'
                . '<td style="' . $cell . ';text-align:right">' . ($row['payments'] > 0 ? $money($row['payments']) : '—') . '</td>'
                . '<td style="' . $cell . ';text-align:right">' . ($row['carried'] ? '<span style="color:#6b7280">Trasladado</span>' : $money($row['balance'])) . '</td></tr>';
        }
        $paymentRows = '';
        foreach ($account['payments'] as $payment) {
            $paymentRows .= '<tr' . ($payment['voided'] ? ' style="color:#9ca3af;text-decoration:line-through"' : '') . '><td style="' . $cell . '">' . $e(date('d/m/Y', strtotime($payment['received_on']) ?: 0)) . '</td>'
                . '<td style="' . $cell . '">' . $e($payment['number']) . '</td><td style="' . $cell . '">' . $e($payment['period']) . '</td>'
                . '<td style="' . $cell . '">' . $e($payment['reference'] !== '' ? $payment['reference'] : '—') . '</td>'
                . '<td style="' . $cell . ';text-align:right">' . $money($payment['amount']) . '</td></tr>';
        }
        $head = static fn (array $labels): string => '<tr>' . implode('', array_map(
            static fn (string $label): string => '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #d1d5db;font-size:12px;color:#4b5563">' . $label . '</th>',
            $labels
        )) . '</tr>';

        return $this->document(
            'Estado de cuenta · ' . $account['unit_name'],
            $account,
            '<table style="width:100%;font-size:14px;margin-bottom:16px"><tr>'
            . '<td><strong>Inmueble:</strong> ' . $e($account['unit_name']) . ($account['reference'] !== null ? '<br><strong>Referencia de pago:</strong> ' . $e($account['reference']) : '') . '</td>'
            . '<td style="text-align:right"><strong>Fecha de corte:</strong> ' . $e(date('d/m/Y', strtotime($account['cut_date']) ?: 0)) . '<br>'
            . '<span style="font-size:18px;font-weight:700;color:' . ($account['balance'] > 0 ? '#b91c1c' : '#15803d') . '">Saldo: ' . $money($account['balance']) . '</span></td></tr></table>'
            . '<h2 style="font-size:15px;margin:16px 0 8px">Notas de cobro (últimos ' . self::ACCOUNT_PERIODS . ' períodos)</h2>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px">' . $head(['Período', 'Nota', 'Valor', 'Descuento', 'Pagos', 'Saldo']) . $statementRows . '</table>'
            . '<h2 style="font-size:15px;margin:20px 0 8px">Pagos registrados</h2>'
            . ($paymentRows !== ''
                ? '<table style="width:100%;border-collapse:collapse;font-size:13px">' . $head(['Fecha', 'Comprobante', 'Período', 'Referencia', 'Valor']) . $paymentRows . '</table>'
                : '<p style="color:#6b7280;font-size:13px">No hay pagos registrados en estos períodos.</p>')
            . '<p style="color:#6b7280;font-size:12px;margin-top:16px">Una nota «trasladada» quedó incluida en el saldo de la nota siguiente.</p>'
        );
    }

    public function clearanceHtml(array $clearance): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $verifyUrl = rtrim(Tenant::absoluteUrl('/'), '/') . '/verificar-paz-y-salvo';
        $property = $this->billing->statement((int) $clearance['statement_id']);
        $expired = $clearance['expired']
            ? '<p style="margin:0 0 16px;padding:10px 12px;background:#fef3c7;color:#92400e;border-radius:6px"><strong>Documento vencido</strong> el ' . $e(date('d/m/Y', strtotime($clearance['valid_until']) ?: 0)) . '. Solicita uno nuevo.</p>'
            : '';

        return $this->document(
            'Paz y salvo · ' . $clearance['unit_name'],
            $property,
            $expired
            . '<p style="font-size:16px;line-height:1.7;margin:8px 0 20px">La administración de <strong>' . $e((string) $property['property_name']) . '</strong>'
            . ($property['property_tax_id'] !== '' ? ', NIT ' . $e((string) $property['property_tax_id']) . ',' : '')
            . ' certifica que el inmueble <strong>' . $e($clearance['unit_name']) . '</strong> se encuentra <strong>a paz y salvo</strong> por concepto de cuotas de administración, intereses, multas y demás conceptos facturados hasta el período <strong>' . $e((string) $property['period']) . '</strong>.</p>'
            . '<table style="font-size:14px;border-collapse:collapse;margin-bottom:16px">'
            . '<tr><th style="text-align:left;padding:4px 16px 4px 0;color:#4b5563">Fecha de expedición</th><td>' . $e(date('d/m/Y h:i a', strtotime($clearance['issued_at']) ?: 0)) . '</td></tr>'
            . '<tr><th style="text-align:left;padding:4px 16px 4px 0;color:#4b5563">Vigente hasta</th><td>' . $e(date('d/m/Y', strtotime($clearance['valid_until']) ?: 0)) . '</td></tr>'
            . '<tr><th style="text-align:left;padding:4px 16px 4px 0;color:#4b5563">Código de verificación</th><td style="font-family:monospace;font-size:16px;font-weight:700;letter-spacing:.08em">' . $e(self::formatCode($clearance['code'])) . '</td></tr>'
            . '</table>'
            . '<p style="font-size:13px;color:#374151">Cualquier persona puede comprobar la validez de este documento en <strong>' . $e($verifyUrl) . '</strong> con el código de verificación.</p>'
        );
    }

    /** Marco común de los documentos imprimibles (mismo estilo que el comprobante de pago). */
    private function document(string $title, array $property, string $body): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($title) . '</title>'
            . '<style>@media print{.no-print{display:none}body{background:#fff!important;padding:0!important}}body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#111827;background:#f3f4f6;margin:0;padding:24px}</style></head><body>'
            . '<div style="max-width:760px;margin:0 auto;background:#fff;border-radius:10px;padding:28px;border:1px solid #e5e7eb">'
            . '<h1 style="font-size:20px;margin:0 0 4px">' . $e((string) $property['property_name']) . '</h1>'
            . '<p style="margin:0 0 20px;color:#6b7280;font-size:14px">'
            . ((string) $property['property_tax_id'] !== '' ? 'NIT ' . $e((string) $property['property_tax_id']) . ' · ' : '')
            . $e((string) $property['property_address']) . '</p>'
            . '<h2 style="font-size:17px;margin:0 0 12px">' . $e(explode(' · ', $title)[0]) . '</h2>'
            . $body
            . '<p style="margin:20px 0 0;color:#6b7280;font-size:12px">Documento generado por la plataforma de administración el ' . $e(date('d/m/Y h:i a')) . '. No es una factura electrónica.</p>'
            . '<p class="no-print" style="margin:20px 0 0"><button type="button" onclick="window.print()" style="padding:8px 14px;border-radius:6px;border:1px solid #2563eb;background:#2563eb;color:#fff;cursor:pointer">Imprimir o guardar en PDF</button></p>'
            . '</div></body></html>';
    }

    private function findClearance(string $condition, array $parameters): ?array
    {
        $statement = Database::connection()->prepare($this->clearanceSql() . ' WHERE clearances.tenant_id = ? AND ' . $condition);
        $statement->execute([Tenant::id(), ...$parameters]);
        $row = $statement->fetch();

        return is_array($row) ? $this->normalizeClearance($row) : null;
    }

    private function clearanceSql(): string
    {
        return 'SELECT clearances.*, issuers.username AS issued_by_name, requesters.username AS requested_by_name
                FROM billing_clearances AS clearances
                LEFT JOIN admin_users AS issuers ON issuers.id = clearances.issued_by
                LEFT JOIN admin_users AS requesters ON requesters.id = clearances.requested_by';
    }

    private function normalizeClearance(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['statement_id'] = (int) $row['statement_id'];
        $row['expired'] = (string) $row['valid_until'] < date('Y-m-d');

        return $row;
    }
}
