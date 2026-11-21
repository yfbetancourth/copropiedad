<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\Tenant;
use DateTimeImmutable;
use RuntimeException;

/** Bold API Integrations: starts a charge on a paired Smart / Smart Pro terminal. */
final class BoldTerminalService
{
    private const API_URL = 'https://integrations.api.bold.co';
    private const METHODS = ['POS', 'NEQUI', 'PAY_BY_QR_BOLD', 'DAVIPLATA', 'MANUAL'];

    public function settings(): array
    {
        $settingsQuery = Database::connection()->prepare(
            'SELECT api_integrations_environment, api_integrations_key_ciphertext, api_integrations_webhook_secret_ciphertext,
                    api_integrations_terminal_model, api_integrations_terminal_serial,
                    api_integrations_terminal_name, api_integrations_payment_method
             FROM bold_payment_settings WHERE tenant_id = ?'
        );
        $settingsQuery->execute([Tenant::id()]);
        $row = $settingsQuery->fetch();
        if (!is_array($row)) {
            return ['environment' => 'sandbox', 'api_key_configured' => false, 'configured' => false, 'terminal_model' => '',
                'terminal_serial' => '', 'terminal_name' => '', 'payment_method' => 'POS',
                'webhook_secret_configured' => false];
        }
        return [
            'environment' => (string) $row['api_integrations_environment'],
            'api_key_configured' => (string) $row['api_integrations_key_ciphertext'] !== '',
            'configured' => (string) $row['api_integrations_key_ciphertext'] !== ''
                && (string) $row['api_integrations_terminal_serial'] !== '',
            'terminal_model' => (string) $row['api_integrations_terminal_model'],
            'terminal_serial' => (string) $row['api_integrations_terminal_serial'],
            'terminal_name' => (string) $row['api_integrations_terminal_name'],
            'payment_method' => (string) $row['api_integrations_payment_method'],
            'webhook_secret_configured' => (string) $row['api_integrations_webhook_secret_ciphertext'] !== '',
        ];
    }

    public function paymentMethods(): array
    {
        $response = $this->request('GET', '/payments/payment-methods');
        $methods = $response['payload']['payment_methods'] ?? [];
        if (!is_array($methods)) {
            throw new RuntimeException('Bold devolvió métodos de pago no válidos.');
        }
        return array_values(array_filter($methods, static fn ($method): bool => is_array($method)
            && in_array($method['name'] ?? '', self::METHODS, true) && ($method['enabled'] ?? false) === true));
    }

    public function terminals(): array
    {
        $response = $this->request('GET', '/payments/binded-terminals');
        $terminals = $response['payload']['available_terminals'] ?? [];
        if (!is_array($terminals)) {
            throw new RuntimeException('Bold devolvió terminales no válidas.');
        }
        return array_values(array_filter($terminals, static fn ($terminal): bool => is_array($terminal)
            && ($terminal['status'] ?? '') === 'BINDED'
            && is_string($terminal['terminal_model'] ?? null)
            && is_string($terminal['terminal_serial'] ?? null)));
    }

    public function saveSettings(string $environment, string $apiKey, string $webhookSecret, string $terminalModel, string $terminalSerial, string $method): void
    {
        if (!in_array($environment, ['sandbox', 'production'], true)
            || ($method !== '' && !in_array($method, self::METHODS, true))) {
            throw new RuntimeException('Selecciona un ambiente y método de pago Bold válidos.');
        }
        $current = $this->settings();
        $apiKey = trim($apiKey);
        $webhookSecret = trim($webhookSecret);
        if ($apiKey === '') {
            if ($current['api_key_configured'] && $current['environment'] !== $environment) {
                throw new RuntimeException('Ingresa la llave de API Integrations del ambiente seleccionado.');
            }
            $apiKey = $this->credential('api_integrations_key_ciphertext');
        }
        if ($apiKey === '') {
            throw new RuntimeException('Ingresa la llave de identidad API Datáfono de Bold.');
        }
        $methods = $this->paymentMethodsForKey($apiKey);
        if ($method === '') {
            $enabledNames = array_column(array_filter($methods, static fn ($item): bool => is_array($item) && ($item['enabled'] ?? false) === true), 'name');
            $method = (string) ($enabledNames[0] ?? '');
        }
        if ($method === '') {
            throw new RuntimeException('Bold no reportó métodos activos para API Integrations.');
        }
        if ($method !== 'MANUAL' && !in_array($method, array_column($methods, 'name'), true)) {
            throw new RuntimeException('Bold no tiene habilitado ese método de pago para API Integrations.');
        }
        $terminal = null;
        if ($terminalModel !== '' || $terminalSerial !== '') {
            foreach ($this->terminalsForKey($apiKey) as $candidate) {
                if ($candidate['terminal_model'] === $terminalModel && $candidate['terminal_serial'] === $terminalSerial) {
                    $terminal = $candidate;
                    break;
                }
            }
            if ($terminal === null) {
                throw new RuntimeException('Selecciona un datáfono vinculado y habilitado en Bold.');
            }
        }
        if ($webhookSecret === '') {
            $webhookSecret = $this->credential('api_integrations_webhook_secret_ciphertext');
        }
        if ($environment === 'production' && $webhookSecret === '') {
            throw new RuntimeException('Ingresa la llave secreta de API Datáfono para validar la firma del webhook.');
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO bold_payment_settings
                (tenant_id, environment, api_key_ciphertext, payment_methods, api_integrations_key_ciphertext,
                 api_integrations_webhook_secret_ciphertext, api_integrations_terminal_model,
                 api_integrations_terminal_serial, api_integrations_terminal_name, api_integrations_payment_method,
                 api_integrations_environment)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                api_integrations_key_ciphertext = VALUES(api_integrations_key_ciphertext),
                api_integrations_webhook_secret_ciphertext = VALUES(api_integrations_webhook_secret_ciphertext),
                api_integrations_terminal_model = VALUES(api_integrations_terminal_model),
                api_integrations_terminal_serial = VALUES(api_integrations_terminal_serial),
                api_integrations_terminal_name = VALUES(api_integrations_terminal_name),
                api_integrations_payment_method = VALUES(api_integrations_payment_method),
                api_integrations_environment = VALUES(api_integrations_environment)'
        )->execute([
            Tenant::id(), $environment, $this->credential('api_key_ciphertext'), '', $this->encrypt($apiKey),
            $webhookSecret === '' ? '' : $this->encrypt($webhookSecret), $terminalModel, $terminalSerial,
            (string) ($terminal['name'] ?? ''), $method, $environment,
        ]);
    }

    public function startPayment(int $statementId, int $userId, string $userEmail): string
    {
        $pdo = Database::connection();
        $q = $pdo->prepare('SELECT s.id FROM billing_statements s JOIN billing_statement_recipients r ON r.statement_id=s.id WHERE s.id=? AND s.tenant_id=? AND r.user_id=?');
        $q->execute([$statementId, Tenant::id(), $userId]);
        if ($q->fetchColumn() === false) {
            throw new RuntimeException('La nota no existe o no corresponde a tu cuenta.');
        }
        if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Tu cuenta no tiene un correo válido para iniciar el pago.');
        }
        $settings = $this->settings();
        if (!$settings['configured']) {
            throw new RuntimeException('El pago con datáfono Bold no está configurado.');
        }
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM billing_statements WHERE id=? AND tenant_id=? FOR UPDATE');
        $lock->execute([$statementId, Tenant::id()]);
        if ($lock->fetchColumn() === false) {
            $pdo->rollBack();
            throw new RuntimeException('La nota ya no está disponible.');
        }
        $billingStatement = (new BillingService())->statement($statementId);
        if ($billingStatement['superseded_by'] !== null) {
            $pdo->rollBack();
            throw new RuntimeException(sprintf(
                'El saldo de esta nota ya se incluyó en la nota del período %s. Paga la nota más reciente.',
                $billingStatement['superseded_by']['period']
            ));
        }
        $amountCents = (int) $billingStatement['balance'];
        if ($amountCents <= 0) {
            $pdo->rollBack();
            throw new RuntimeException('Esta nota no tiene saldo pendiente.');
        }
        $pending = $pdo->prepare("SELECT id FROM billing_bold_terminal_payments WHERE tenant_id=? AND statement_id=? AND user_id=? AND status='pending' LIMIT 1");
        $pending->execute([Tenant::id(), $statementId, $userId]);
        if ($pending->fetchColumn() !== false) {
            $pdo->rollBack();
            throw new RuntimeException('Ya hay un cobro pendiente en el datáfono para esta nota. Espera la confirmación de Bold antes de iniciar otro.');
        }

        $reference = 'CPT-' . strtoupper(bin2hex(random_bytes(16)));
        $amount = round($amountCents / 100, 2);
        $pdo->prepare('INSERT INTO billing_bold_terminal_payments (tenant_id,statement_id,user_id,reference,expected_amount) VALUES (?,?,?,?,?)')
            ->execute([Tenant::id(), $statementId, $userId, $reference, number_format($amount, 2, '.', '')]);
        $pdo->commit();
        try {
            $response = $this->request('POST', '/payments/app-checkout', [
                'amount' => ['currency' => 'COP', 'taxes' => [], 'tip_amount' => 0, 'total_amount' => $amount],
                'payment_method' => $settings['payment_method'] === 'MANUAL' ? '' : $settings['payment_method'],
                'terminal_model' => $settings['terminal_model'],
                'terminal_serial' => $settings['terminal_serial'],
                'reference' => $reference,
                'user_email' => $userEmail,
                'description' => 'Pago de nota de cobro ' . $statementId,
                'payer' => ['email' => $userEmail],
            ]);
            $integrationId = $response['payload']['integration_id'] ?? null;
            if (!is_string($integrationId) || $integrationId === '' || strlen($integrationId) > 80) {
                throw new RuntimeException('Bold no devolvió un identificador de cobro válido.');
            }
            $pdo->prepare('UPDATE billing_bold_terminal_payments SET integration_id=? WHERE reference=? AND tenant_id=?')
                ->execute([$integrationId, $reference, Tenant::id()]);
            return $reference;
        } catch (\Throwable $exception) {
            if (str_starts_with($exception->getMessage(), 'Bold rechazó la solicitud.')) {
                $pdo->prepare("UPDATE billing_bold_terminal_payments SET status='rejected' WHERE reference=? AND tenant_id=?")->execute([$reference, Tenant::id()]);
                throw $exception;
            }
            error_log('No se pudo confirmar la creación del cobro de datáfono Bold: ' . $exception->getMessage());
            throw new RuntimeException('No se pudo confirmar si Bold recibió el cobro. No lo reintentes; contacta a la administración para verificar el datáfono.');
        }
    }

    public function webhookUrl(): string
    {
        // Webhook en el subdominio del conjunto: el evento llega al cliente dueño del pago.
        $url = Tenant::absoluteUrl('/pagos/bold/webhook');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !parse_url($url, PHP_URL_HOST)) {
            return '';
        }
        return $url;
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $event = json_decode($rawBody, true);
        $reference = $event['data']['metadata']['reference'] ?? null;
        if (!is_string($reference) || !preg_match('/^CPT-[A-F0-9]{32}$/D', $reference)) {
            return false;
        }
        $environmentQuery = Database::connection()->prepare('SELECT api_integrations_environment FROM bold_payment_settings WHERE tenant_id=?');
        $environmentQuery->execute([Tenant::id()]);
        $row = $environmentQuery->fetch();
        if (!is_array($row)) {
            return false;
        }
        $secret = $this->credential('api_integrations_webhook_secret_ciphertext');
        if ((string) $row['api_integrations_environment'] === 'sandbox') {
            $secret = '';
        }
        if ((string) $row['api_integrations_environment'] === 'production' && $secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', base64_encode($rawBody), $secret);
        return $signature !== '' && hash_equals($expected, $signature);
    }

    public function handleWebhook(array $event): void
    {
        $data = $event['data'] ?? null;
        $reference = is_array($data) ? ($data['metadata']['reference'] ?? null) : null;
        if (!is_string($reference) || !preg_match('/^CPT-[A-F0-9]{32}$/D', $reference)) {
            throw new RuntimeException('Referencia de pago de datáfono no válida.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM billing_bold_terminal_payments WHERE reference=? AND tenant_id=? FOR UPDATE');
            $q->execute([$reference, Tenant::id()]);
            $payment = $q->fetch();
            if (!is_array($payment)) {
                throw new RuntimeException('No se encontró el pago Bold asociado.');
            }
            if ($payment['status'] === 'approved') {
                $pdo->commit();
                return;
            }
            $type = (string) ($event['type'] ?? '');
            if ($type === 'SALE_REJECTED') {
                if (($data['integration'] ?? '') !== 'API_INTEGRATIONS') {
                    throw new RuntimeException('El evento rechazado no corresponde a API Integrations.');
                }
                $pdo->prepare("UPDATE billing_bold_terminal_payments SET status='rejected' WHERE id=? AND tenant_id=?")->execute([$payment['id'], Tenant::id()]);
                $pdo->commit();
                return;
            }
            if ($type !== 'SALE_APPROVED'
                || ($data['integration'] ?? '') !== 'API_INTEGRATIONS'
                || ($data['amount']['currency'] ?? '') !== 'COP'
                || !is_numeric($data['amount']['total'] ?? null)
                || (int) round((float) $data['amount']['total'] * 100) !== (int) round((float) $payment['expected_amount'] * 100)
            ) {
                throw new RuntimeException('El evento aprobado no coincide con el tipo o monto esperado.');
            }
            $transactionId = trim((string) ($data['payment_id'] ?? $event['subject'] ?? ''));
            if ($transactionId === '' || strlen($transactionId) > 80) {
                throw new RuntimeException('Bold no devolvió un identificador de transacción válido.');
            }
            $pdo->prepare('INSERT INTO billing_payments (tenant_id,statement_id,amount,received_on,reference,notes,created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([Tenant::id(), $payment['statement_id'], $payment['expected_amount'], (new DateTimeImmutable('today'))->format('Y-m-d'), $transactionId, 'Pago en datáfono Bold API Integrations aprobado', $payment['user_id']]);
            $pdo->prepare("UPDATE billing_bold_terminal_payments SET status='approved',transaction_id=? WHERE id=? AND tenant_id=?")
                ->execute([$transactionId, $payment['id'], Tenant::id()]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    private function paymentMethodsForKey(string $key): array { return $this->getForKey('/payments/payment-methods', $key)['payload']['payment_methods'] ?? []; }
    private function terminalsForKey(string $key): array { return $this->getForKey('/payments/binded-terminals', $key)['payload']['available_terminals'] ?? []; }
    private function getForKey(string $path, string $key): array { return $this->request('GET', $path, null, $key); }

    private function request(string $method, string $path, ?array $body = null, ?string $key = null): array
    {
        $key ??= $this->credential('api_integrations_key_ciphertext');
        if ($key === '') throw new RuntimeException('Configura la llave API Datáfono de Bold.');
        $ch = curl_init(self::API_URL . $path);
        if ($ch === false) throw new RuntimeException('No fue posible iniciar la conexión con Bold.');
        $headers = ['Accept: application/json', 'Authorization: x-api-key ' . $key];
        $options = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
        if ($body !== null) { $options[CURLOPT_HTTPHEADER][]='Content-Type: application/json'; $options[CURLOPT_POSTFIELDS]=json_encode($body, JSON_THROW_ON_ERROR); }
        curl_setopt_array($ch,$options); $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        $decoded=is_string($raw)?json_decode($raw,true):null;
        if ($status<200||$status>=300||!is_array($decoded)||($decoded['errors']??[])!==[]) throw new RuntimeException('Bold rechazó la solicitud. Revisa las llaves, terminales y métodos habilitados.');
        return $decoded;
    }

    private function credential(string $column): string
    {
        $allowed=['api_key_ciphertext','api_integrations_key_ciphertext','api_integrations_webhook_secret_ciphertext'];
        if (!in_array($column,$allowed,true)) throw new RuntimeException('Credencial no válida.');
        $q=Database::connection()->prepare('SELECT '.$column.' FROM bold_payment_settings WHERE tenant_id=?'); $q->execute([Tenant::id()]); $v=$q->fetchColumn();
        return is_string($v)&&$v!==''?$this->decrypt($v):'';
    }
    private function encrypt(string $plain): string
    {
        $nonce=random_bytes(12); $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->encryptionKey(),OPENSSL_RAW_DATA,$nonce,$tag,'copropiedad-bold-payment-settings-v1');
        if (!is_string($cipher)) throw new RuntimeException('No se pudo proteger la credencial Bold.');
        return 'v1.'.base64_encode($nonce).'.'.base64_encode($tag).'.'.base64_encode($cipher);
    }
    private function decrypt(string $payload): string
    {
        $parts=explode('.',$payload); if (count($parts)!==4||$parts[0]!=='v1') throw new RuntimeException('Credencial Bold guardada no válida.');
        $plain=openssl_decrypt(base64_decode($parts[3],true)?:'','aes-256-gcm',$this->encryptionKey(),OPENSSL_RAW_DATA,base64_decode($parts[1],true)?:'',base64_decode($parts[2],true)?:'','copropiedad-bold-payment-settings-v1');
        if (!is_string($plain)) throw new RuntimeException('No se pudo descifrar la credencial Bold. Verifica APP_KEY.');
        return $plain;
    }
    private function encryptionKey(): string
    {
        $key=$_ENV['APP_KEY']??$_SERVER['APP_KEY']??getenv('APP_KEY');
        if (!is_string($key)||!str_starts_with($key,'base64:')) throw new RuntimeException('Configura APP_KEY para proteger las credenciales Bold.');
        $decoded=base64_decode(substr($key,7),true); if (!is_string($decoded)||strlen($decoded)!==32) throw new RuntimeException('APP_KEY debe codificar 32 bytes.');
        return $decoded;
    }
}
