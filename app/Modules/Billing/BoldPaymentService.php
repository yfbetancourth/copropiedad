<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\SecretBox;
use App\Core\Database;
use App\Core\ModuleCatalog;
use App\Core\Tenant;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class BoldPaymentService
{
    /** Contexto de cifrado de las llaves de Bold (enlace de pago y datáfono). */
    public const SECRET_CONTEXT = 'copropiedad-bold-payment-settings-v1';

    private const API_URL = 'https://integrations.api.bold.co';
    private const PAYMENT_METHODS = [
        'CREDIT_CARD' => 'Tarjeta de crédito o débito',
        'PSE' => 'PSE',
        'BOTON_BANCOLOMBIA' => 'Botón Bancolombia',
        'NEQUI' => 'Nequi',
    ];

    public function settings(): array
    {
        $settingsQuery = Database::connection()->prepare(
            'SELECT environment, api_key_ciphertext, payment_methods
             FROM bold_payment_settings WHERE tenant_id = ?'
        );
        $settingsQuery->execute([Tenant::id()]);
        $row = $settingsQuery->fetch();

        if (!is_array($row)) {
            return [
                'environment' => 'sandbox',
                'api_key_configured' => false,
                'payment_methods' => [],
            ];
        }

        $methods = array_values(array_filter(
            explode(',', (string) $row['payment_methods']),
            static fn (string $method): bool => isset(self::PAYMENT_METHODS[$method])
        ));

        return [
            'environment' => (string) $row['environment'],
            'api_key_configured' => (string) $row['api_key_ciphertext'] !== '',
            'payment_methods' => $methods,
        ];
    }

    /** @return list<array{code: string, label: string}> */
    /** Pago en línea disponible: módulo habilitado, llave y métodos configurados y URL HTTPS para el webhook. */
    public function isReady(): bool
    {
        if (!ModuleCatalog::enabled('pagos_en_linea')) {
            return false;
        }
        $settings = $this->settings();

        return $settings['api_key_configured'] && $settings['payment_methods'] !== [] && $this->webhookUrl() !== '';
    }

    public function configuredMethods(): array
    {
        $settings = $this->settings();
        $methods = [];
        foreach ($settings['payment_methods'] as $code) {
            if (isset(self::PAYMENT_METHODS[$code])) {
                $methods[] = ['code' => $code, 'label' => self::PAYMENT_METHODS[$code]];
            }
        }
        return $methods;
    }

    public function availableMethods(): array
    {
        $response = $this->request('GET', '/online/link/v1/payment_methods');
        $methods = $response['payload']['payment_methods'] ?? null;
        if (!is_array($methods)) {
            throw new RuntimeException('Bold devolvió una lista de métodos de pago no válida.');
        }

        $available = [];
        foreach (self::PAYMENT_METHODS as $code => $label) {
            $limits = $methods[$code] ?? null;
            if (is_array($limits) && isset($limits['min'], $limits['max'])) {
                $available[$code] = [
                    'label' => $label,
                    'min' => (int) $limits['min'],
                    'max' => (int) $limits['max'],
                ];
            }
        }

        return $available;
    }

    public function saveSettings(string $environment, string $apiKey, array $paymentMethods): array
    {
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('Selecciona un ambiente de Bold válido.');
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            $currentSettings = $this->settings();
            if ($currentSettings['api_key_configured'] && $currentSettings['environment'] !== $environment) {
                throw new RuntimeException('Ingresa la llave de Bold correspondiente al nuevo ambiente.');
            }
            $apiKey = $this->apiKey();
        }
        if ($apiKey === '' || strlen($apiKey) > 512) {
            throw new RuntimeException('Ingresa la llave de identidad de Bold.');
        }

        $selectedMethods = [];
        foreach ($paymentMethods as $method) {
            if (is_string($method) && isset(self::PAYMENT_METHODS[$method])) {
                $selectedMethods[$method] = true;
            }
        }
        if ($selectedMethods === []) {
            throw new RuntimeException('Selecciona al menos un método de pago.');
        }

        $available = $this->request('GET', '/online/link/v1/payment_methods', null, $apiKey);
        $providerMethods = $available['payload']['payment_methods'] ?? null;
        if (!is_array($providerMethods)) {
            throw new RuntimeException('Bold devolvió una lista de métodos de pago no válida.');
        }
        $unavailableMethods = [];
        foreach (array_keys($selectedMethods) as $method) {
            $limits = $providerMethods[$method] ?? null;
            if (!is_array($limits) || !isset($limits['min'], $limits['max'])) {
                $unavailableMethods[] = $method;
                unset($selectedMethods[$method]);
            }
        }
        if ($selectedMethods === []) {
            throw new RuntimeException('Bold no tiene habilitado ninguno de los métodos seleccionados.');
        }

        $ciphertext = SecretBox::encrypt($apiKey, self::SECRET_CONTEXT);
        $methods = implode(',', array_keys($selectedMethods));
        Database::connection()->prepare(
            'INSERT INTO bold_payment_settings (tenant_id, environment, api_key_ciphertext, payment_methods)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                environment = VALUES(environment),
                api_key_ciphertext = VALUES(api_key_ciphertext),
                payment_methods = VALUES(payment_methods)'
        )->execute([Tenant::id(), $environment, $ciphertext, $methods]);

        return $unavailableMethods;
    }

    public function payableStatements(int $userId): array
    {
        $query = Database::connection()->prepare(
            'SELECT statements.id
             FROM billing_statements AS statements
             JOIN billing_statement_recipients AS recipients
               ON recipients.statement_id = statements.id
             WHERE statements.tenant_id = ? AND recipients.user_id = ?
             ORDER BY statements.period DESC, statements.id DESC'
        );
        $query->execute([Tenant::id(), $userId]);

        $documents = [];
        $billing = new BillingService();
        $paymentQuery = Database::connection()->prepare(
            'SELECT status, checkout_url
             FROM billing_online_payments
             WHERE tenant_id = ? AND statement_id = ?
             ORDER BY id DESC LIMIT 1'
        );
        $terminalQuery = Database::connection()->prepare(
            'SELECT status FROM billing_bold_terminal_payments WHERE tenant_id = ? AND statement_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1'
        );
        foreach ($query->fetchAll() as $row) {
            $statement = $billing->statement((int) $row['id']);
            $paymentQuery->execute([Tenant::id(), (int) $row['id']]);
            $latestPayment = $paymentQuery->fetch();
            $terminalQuery->execute([Tenant::id(), (int) $row['id'], $userId]);
            $latestTerminalPayment = $terminalQuery->fetch();
            $statement['online_payment_status'] = is_array($latestPayment)
                ? (string) $latestPayment['status']
                : null;
            $statement['terminal_payment_status'] = is_array($latestTerminalPayment)
                ? (string) $latestTerminalPayment['status']
                : null;
            $statement['checkout_url'] = is_array($latestPayment)
                && $latestPayment['status'] === 'pending'
                ? (string) $latestPayment['checkout_url']
                : null;
            $documents[] = $statement;
        }

        return $documents;
    }

    public function createPaymentLink(int $statementId, int $userId): string
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT statements.id, statements.period, statements.total_due
             FROM billing_statements AS statements
             JOIN billing_statement_recipients AS recipients
               ON recipients.statement_id = statements.id AND recipients.user_id = ?
             WHERE statements.id = ? AND statements.tenant_id = ?
             LIMIT 1'
        );
        $statement->execute([$userId, $statementId, Tenant::id()]);
        if (!is_array($statement->fetch())) {
            throw new RuntimeException('La nota no existe o no corresponde a tu cuenta.');
        }

        (new BillingService())->payableStatement($statementId);

        $settings = $this->settings();
        if (!$settings['api_key_configured'] || $settings['payment_methods'] === []) {
            throw new RuntimeException('Los métodos de pago en línea aún no están configurados.');
        }
        $availableMethods = $this->availableMethods();

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM billing_statements WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $lock->execute([$statementId, Tenant::id()]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('La nota de cobro ya no está disponible.');
            }

            $pending = $pdo->prepare(
                'SELECT reference, bold_payment_link, checkout_url, expected_amount
                 FROM billing_online_payments
                 WHERE tenant_id = ? AND statement_id = ? AND status = "pending"
                 ORDER BY id DESC LIMIT 1'
            );
            $pending->execute([Tenant::id(), $statementId]);
            $existing = $pending->fetch();
            if (is_array($existing)) {
                $remote = $this->paymentLink((string) $existing['bold_payment_link']);
                $remoteStatus = strtoupper((string) ($remote['status'] ?? ''));
                if ($remoteStatus === 'ACTIVE' || $remoteStatus === 'PROCESSING') {
                    $currentBalance = (int) (new BillingService())->statement($statementId)['balance'];
                    $remoteAmountCents = is_numeric($remote['total'] ?? null)
                        ? (int) round((float) $remote['total'] * 100)
                        : -1;
                    if ($currentBalance !== (int) round((float) $existing['expected_amount'] * 100)
                        || $currentBalance !== $remoteAmountCents
                    ) {
                        throw new RuntimeException('El saldo cambió mientras había un enlace de pago activo. Contacta a la administración antes de pagar.');
                    }
                    $pdo->commit();
                    return (string) $existing['checkout_url'];
                }
                $this->updatePaymentStatus((string) $existing['reference'], $remoteStatus);
                if ($remoteStatus === 'PAID') {
                    $pdo->commit();
                    $this->recordApprovedPayment((string) $existing['reference'], $remote);
                    throw new RuntimeException('Bold confirmó que esta nota ya fue pagada.');
                }
                if (!in_array($remoteStatus, ['REJECTED', 'CANCELLED', 'EXPIRED'], true)) {
                    throw new RuntimeException('Bold devolvió un estado de pago desconocido.');
                }
            }

            $billingStatement = (new BillingService())->statement($statementId);
            $amountCents = (int) $billingStatement['balance'];
            if ($amountCents <= 0) {
                $pdo->commit();
                throw new RuntimeException('Esta nota no tiene saldo pendiente.');
            }
            $amount = round($amountCents / 100, 2);
            $paymentMethods = [];
            foreach ($settings['payment_methods'] as $method) {
                $limits = $availableMethods[$method] ?? null;
                if (is_array($limits) && $amount >= $limits['min'] && $amount <= $limits['max']) {
                    $paymentMethods[] = $method;
                }
            }
            if ($paymentMethods === []) {
                throw new RuntimeException('El saldo de esta nota está por fuera de los límites de los métodos de pago habilitados en Bold.');
            }
            $reference = 'CP-' . strtoupper(bin2hex(random_bytes(16)));
            $callbackUrl = $this->callbackUrl($reference);
            $description = 'Nota de cobro ' . $billingStatement['number'];
            $created = $this->request('POST', '/online/link/v1', [
                'amount_type' => 'CLOSE',
                'amount' => [
                    'currency' => 'COP',
                    'total_amount' => $amount,
                ],
                'reference' => $reference,
                'description' => $description,
                'callback_url' => $callbackUrl,
                'payer_email' => $this->recipientEmail($statementId, $userId),
                'payment_methods' => $paymentMethods,
            ]);
            $payload = $created['payload'] ?? null;
            $paymentLink = is_array($payload) ? (string) ($payload['payment_link'] ?? '') : '';
            $checkoutUrl = is_array($payload) ? (string) ($payload['url'] ?? '') : '';
            $checkoutHost = parse_url($checkoutUrl, PHP_URL_HOST);
            if ($paymentLink === '' || !is_string($checkoutHost) || strtolower($checkoutHost) !== 'checkout.bold.co'
                || parse_url($checkoutUrl, PHP_URL_SCHEME) !== 'https'
            ) {
                throw new RuntimeException('Bold no devolvió un enlace de pago válido.');
            }

            $pdo->prepare(
                'INSERT INTO billing_online_payments
                    (tenant_id, statement_id, user_id, reference, bold_payment_link, checkout_url, expected_amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "pending")'
            )->execute([
                Tenant::id(),
                $statementId,
                $userId,
                $reference,
                $paymentLink,
                $checkoutUrl,
                number_format($amount, 2, '.', ''),
            ]);
            $pdo->commit();

            return $checkoutUrl;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function handleWebhook(array $event): void
    {
        if (($event['type'] ?? null) !== 'SALE_APPROVED') {
            return;
        }

        $data = $event['data'] ?? null;
        $metadata = is_array($data) ? ($data['metadata'] ?? null) : null;
        $reference = is_array($metadata) ? ($metadata['reference'] ?? null) : null;
        if (!is_string($reference) || !preg_match('/^CP-[A-F0-9]{32}$/D', $reference)) {
            throw new RuntimeException('El evento de Bold no contiene una referencia de pago válida.');
        }

        if ($this->refreshPayment($reference, 2) !== 'approved') {
            throw new RuntimeException('Bold aún no confirma la aprobación del pago.');
        }
    }

    public function refreshPayment(string $reference, int $timeout = 5): string
    {
        $query = Database::connection()->prepare(
            'SELECT bold_payment_link, status FROM billing_online_payments WHERE reference = ? AND tenant_id = ?'
        );
        $query->execute([$reference, Tenant::id()]);
        $row = $query->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('No se encontró el pago relacionado con la referencia recibida.');
        }
        if ($row['status'] === 'approved') {
            return 'approved';
        }

        $remote = $this->paymentLink((string) $row['bold_payment_link'], $timeout);
        $status = strtoupper((string) ($remote['status'] ?? ''));
        if ($status === 'PAID') {
            if (isset($remote['reference']) && $remote['reference'] !== $reference) {
                throw new RuntimeException('La referencia confirmada por Bold no coincide con la nota.');
            }
            $this->recordApprovedPayment($reference, $remote);
            return 'approved';
        }
        $this->updatePaymentStatus($reference, $status);

        return match ($status) {
            'ACTIVE', 'PROCESSING' => 'pending',
            'REJECTED', 'CANCELLED', 'EXPIRED' => 'rejected',
            default => throw new RuntimeException('Bold devolvió un estado de pago desconocido.'),
        };
    }

    /** El retorno y el webhook usan el subdominio del conjunto: así Bold vuelve al cliente correcto. */
    public function callbackUrl(string $reference): string
    {
        $url = Tenant::absoluteUrl('/pagos/bold/retorno');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !is_string(parse_url($url, PHP_URL_HOST))) {
            throw new RuntimeException('Configura APP_URL con el dominio público HTTPS para recibir el retorno de Bold.');
        }

        return $url . '?reference=' . rawurlencode($reference);
    }

    public function webhookUrl(): string
    {
        $url = Tenant::absoluteUrl('/pagos/bold/webhook');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !is_string(parse_url($url, PHP_URL_HOST))) {
            return '';
        }

        return $url;
    }

    private function recordApprovedPayment(string $reference, array $remote): void
    {
        $pdo = Database::connection();
        $statementQuery = $pdo->prepare(
            'SELECT statement_id FROM billing_online_payments WHERE reference = ? AND tenant_id = ?'
        );
        $statementQuery->execute([$reference, Tenant::id()]);
        $statementId = $statementQuery->fetchColumn();
        if ($statementId === false) {
            throw new RuntimeException('No se encontró el pago relacionado con la referencia recibida.');
        }

        $pdo->beginTransaction();
        try {
            $lockStatement = $pdo->prepare('SELECT id FROM billing_statements WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $lockStatement->execute([(int) $statementId, Tenant::id()]);
            if ($lockStatement->fetchColumn() === false) {
                throw new RuntimeException('La nota de cobro del pago ya no existe.');
            }

            $query = $pdo->prepare(
                'SELECT id, statement_id, user_id, expected_amount, status
                 FROM billing_online_payments WHERE reference = ? AND tenant_id = ? FOR UPDATE'
            );
            $query->execute([$reference, Tenant::id()]);
            $payment = $query->fetch();
            if (!is_array($payment)) {
                throw new RuntimeException('No se encontró el pago relacionado con la referencia recibida.');
            }
            if ($payment['status'] === 'approved') {
                $pdo->commit();
                return;
            }
            if (strtoupper((string) ($remote['status'] ?? '')) !== 'PAID') {
                throw new RuntimeException('Bold aún no confirma el pago.');
            }
            $environmentQuery = $pdo->prepare('SELECT environment FROM bold_payment_settings WHERE tenant_id = ?');
            $environmentQuery->execute([Tenant::id()]);
            $environment = (string) $environmentQuery->fetchColumn();
            if (!is_bool($remote['is_sandbox'] ?? null)
                || $remote['is_sandbox'] !== ($environment === 'sandbox')
            ) {
                throw new RuntimeException('El ambiente reportado por Bold no coincide con la configuración.');
            }

            $total = $remote['total'] ?? null;
            if (!is_numeric($total)
                || (int) round((float) $total * 100) !== (int) round((float) $payment['expected_amount'] * 100)
            ) {
                throw new RuntimeException('El monto confirmado por Bold no coincide con la nota de cobro.');
            }
            $transactionId = trim((string) ($remote['transaction_id'] ?? ''));
            if ($transactionId === '' || strlen($transactionId) > 80) {
                throw new RuntimeException('Bold no devolvió un identificador de transacción válido.');
            }

            $pdo->prepare(
                'INSERT INTO billing_payments (tenant_id, statement_id, amount, received_on, reference, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                Tenant::id(),
                (int) $payment['statement_id'],
                number_format((float) $payment['expected_amount'], 2, '.', ''),
                (new DateTimeImmutable('today'))->format('Y-m-d'),
                $transactionId,
                'Pago en línea Bold aprobado',
                (int) $payment['user_id'],
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE billing_online_payments
                 SET status = "approved", transaction_id = ?, approved_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND tenant_id = ?'
            )->execute([$transactionId, (int) $payment['id'], Tenant::id()]);
            $pdo->commit();
            PaymentNotificationService::defer('payment', ['payment_id' => $paymentId, 'origin' => 'bold']);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function updatePaymentStatus(string $reference, string $status): void
    {
        $mapped = match ($status) {
            'ACTIVE', 'PROCESSING' => 'pending',
            'REJECTED', 'CANCELLED', 'EXPIRED' => 'rejected',
            default => null,
        };
        if ($mapped === null) {
            return;
        }

        Database::connection()->prepare(
            'UPDATE billing_online_payments
             SET status = ?
             WHERE reference = ? AND tenant_id = ? AND status <> "approved"'
        )->execute([$mapped, $reference, Tenant::id()]);
    }

    private function paymentLink(string $paymentLink, int $timeout = 5): array
    {
        $response = $this->request('GET', '/online/link/v1/' . rawurlencode($paymentLink), null, null, $timeout);
        $payload = $response['payload'] ?? $response;
        if (!is_array($payload)) {
            throw new RuntimeException('Bold devolvió un estado de pago no válido.');
        }

        return $payload;
    }

    private function recipientEmail(int $statementId, int $userId): string
    {
        $query = Database::connection()->prepare(
            'SELECT recipient_email
             FROM billing_statement_recipients
             WHERE statement_id = ? AND user_id = ?
             LIMIT 1'
        );
        $query->execute([$statementId, $userId]);
        $email = $query->fetchColumn();
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('La nota no tiene un correo válido asociado a tu cuenta.');
        }

        return $email;
    }

    private function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $apiKey = null,
        int $timeout = 5
    ): array
    {
        $apiKey ??= $this->apiKey();
        if ($apiKey === '') {
            throw new RuntimeException('Configura primero la llave de identidad de Bold.');
        }
        $handle = curl_init(self::API_URL . $path);
        if ($handle === false) {
            throw new RuntimeException('No fue posible iniciar la conexión segura con Bold.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: x-api-key ' . $apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if (!is_string($response)) {
            throw new RuntimeException('No se pudo conectar con Bold.' . ($curlError !== '' ? ' ' . $curlError : ''));
        }
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Bold devolvió una respuesta no válida.');
        }
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)
            || (isset($decoded['errors']) && $decoded['errors'] !== [])
        ) {
            throw new RuntimeException('Bold rechazó la solicitud. Verifica las llaves, el ambiente y los métodos habilitados.');
        }

        return $decoded;
    }

    private function apiKey(): string
    {
        $keyQuery = Database::connection()->prepare(
            'SELECT api_key_ciphertext FROM bold_payment_settings WHERE tenant_id = ?'
        );
        $keyQuery->execute([Tenant::id()]);
        $row = $keyQuery->fetch();
        if (!is_array($row) || (string) $row['api_key_ciphertext'] === '') {
            return '';
        }

        return SecretBox::decrypt((string) $row['api_key_ciphertext'], self::SECRET_CONTEXT);
    }




}
