<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Database;
use App\Core\ModuleCatalog;
use App\Core\SecretBox;
use App\Core\Tenant;
use DateTimeImmutable;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Wompi (Bancolombia) como pasarela de pago: Web Checkout con firma de integridad, eventos firmados
 * (transaction.updated) y confirmación de cada transacción consultando la API con la llave privada.
 * Documentación: https://docs.wompi.co/docs/colombia/widget-checkout-web/ y .../eventos/
 */
final class WompiPaymentService
{
    public const MODULE = 'pagos_wompi';
    private const SECRET_CONTEXT = 'copropiedad-wompi-settings-v1';
    private const CHECKOUT_URL = 'https://checkout.wompi.co/p/';
    private const API_URLS = [
        'sandbox' => 'https://sandbox.wompi.co/v1',
        'production' => 'https://production.wompi.co/v1',
    ];
    /** Prefijos de cada llave según el ambiente, tal como los entrega el panel de Wompi. */
    private const KEY_PREFIXES = [
        'sandbox' => ['public' => 'pub_test_', 'private' => 'prv_test_', 'events' => 'test_events_', 'integrity' => 'test_integrity_'],
        'production' => ['public' => 'pub_prod_', 'private' => 'prv_prod_', 'events' => 'prod_events_', 'integrity' => 'prod_integrity_'],
    ];
    private const REFERENCE_PATTERN = '/^CPW-[A-F0-9]{32}$/D';

    /**
     * @return array{environment: string, public_key: string, private_key_configured: bool,
     *               events_secret_configured: bool, integrity_secret_configured: bool, configured: bool}
     */
    public function settings(): array
    {
        $row = $this->storedRow();

        $settings = [
            'environment' => is_array($row) ? (string) $row['environment'] : 'sandbox',
            'public_key' => is_array($row) ? (string) $row['public_key'] : '',
            'private_key_configured' => is_array($row) && (string) $row['private_key_ciphertext'] !== '',
            'events_secret_configured' => is_array($row) && (string) $row['events_secret_ciphertext'] !== '',
            'integrity_secret_configured' => is_array($row) && (string) $row['integrity_secret_ciphertext'] !== '',
        ];
        $settings['configured'] = $settings['public_key'] !== '' && $settings['private_key_configured']
            && $settings['events_secret_configured'] && $settings['integrity_secret_configured'];

        return $settings;
    }

    /** Wompi disponible: módulo habilitado por el Superadmin, llaves completas y URL HTTPS para eventos. */
    public function isReady(): bool
    {
        return ModuleCatalog::enabled(self::MODULE) && $this->settings()['configured'] && $this->webhookUrl() !== '';
    }

    /**
     * Guarda las llaves (las secretas vacías conservan la guardada si no cambia el ambiente) y verifica la
     * llave pública consultando el comercio en Wompi.
     */
    public function saveSettings(string $environment, string $publicKey, string $privateKey, string $eventsSecret, string $integritySecret): void
    {
        if (!isset(self::API_URLS[$environment])) {
            throw new RuntimeException('Selecciona un ambiente válido para Wompi.');
        }
        $prefixes = self::KEY_PREFIXES[$environment];
        $current = $this->storedRow();
        $keepSecrets = is_array($current) && $current['environment'] === $environment;
        $publicKey = trim($publicKey);
        $secrets = ['private' => trim($privateKey), 'events' => trim($eventsSecret), 'integrity' => trim($integritySecret)];

        if (!str_starts_with($publicKey, $prefixes['public']) || strlen($publicKey) > 120) {
            throw new RuntimeException('La llave pública debe empezar por ' . $prefixes['public'] . ' en este ambiente.');
        }
        $columns = ['private' => 'private_key_ciphertext', 'events' => 'events_secret_ciphertext', 'integrity' => 'integrity_secret_ciphertext'];
        $labels = ['private' => 'La llave privada', 'events' => 'El secreto de eventos', 'integrity' => 'El secreto de integridad'];
        $ciphertexts = [];
        foreach ($secrets as $kind => $value) {
            if ($value === '') {
                if (!$keepSecrets || (string) $current[$columns[$kind]] === '') {
                    throw new RuntimeException($labels[$kind] . ' es obligatori' . ($kind === 'private' ? 'a' : 'o') . ' para este ambiente.');
                }
                $ciphertexts[$kind] = (string) $current[$columns[$kind]];
                continue;
            }
            if (!str_starts_with($value, $prefixes[$kind]) || strlen($value) > 200) {
                throw new RuntimeException($labels[$kind] . ' debe empezar por ' . $prefixes[$kind] . ' en este ambiente.');
            }
            $ciphertexts[$kind] = SecretBox::encrypt($value, self::SECRET_CONTEXT);
        }

        // La llave pública identifica al comercio; si Wompi no la reconoce, no se guarda nada.
        $this->request($environment, '/merchants/' . rawurlencode($publicKey), null);

        Database::connection()->prepare(
            'INSERT INTO wompi_payment_settings
                (tenant_id, environment, public_key, private_key_ciphertext, events_secret_ciphertext, integrity_secret_ciphertext)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE environment = VALUES(environment), public_key = VALUES(public_key),
                private_key_ciphertext = VALUES(private_key_ciphertext), events_secret_ciphertext = VALUES(events_secret_ciphertext),
                integrity_secret_ciphertext = VALUES(integrity_secret_ciphertext)'
        )->execute([Tenant::id(), $environment, $publicKey, $ciphertexts['private'], $ciphertexts['events'], $ciphertexts['integrity']]);
    }

    /**
     * Crea un intento de pago con referencia única y devuelve la URL del Web Checkout firmada. El valor es el saldo
     * vigente de la nota; el pago queda a nombre de $userId, que debe ser destinatario de la nota.
     */
    public function createCheckout(int $statementId, int $userId): string
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Los pagos con Wompi no están disponibles en este momento.');
        }
        $statement = (new BillingService())->payableStatement($statementId);
        if (!BillingService::isRecipient($statement, $userId)) {
            throw new RuntimeException('La nota no existe o no corresponde a tu cuenta.');
        }
        $amountCents = (int) $statement['payable_balance'];
        $reference = 'CPW-' . strtoupper(bin2hex(random_bytes(16)));

        Database::connection()->prepare(
            'INSERT INTO billing_wompi_payments (tenant_id, statement_id, user_id, reference, expected_amount)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([Tenant::id(), $statementId, $userId, $reference, sprintf('%d.%02d', intdiv($amountCents, 100), $amountCents % 100)]);

        $settings = $this->settings();
        $params = [
            'public-key' => $settings['public_key'],
            'currency' => 'COP',
            'amount-in-cents' => (string) $amountCents,
            'reference' => $reference,
            'signature:integrity' => hash('sha256', $reference . $amountCents . 'COP' . $this->secret('integrity_secret_ciphertext')),
            'redirect-url' => Tenant::absoluteUrl('/pagos/wompi/retorno'),
        ];
        $email = $this->recipientEmail($statement, $userId);
        if ($email !== '') {
            $params['customer-data:email'] = $email;
        }

        return self::CHECKOUT_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Procesa un evento de Wompi. Solo acepta eventos con checksum válido y, aun así, confirma la transacción
     * consultándola con la llave privada antes de mover la cartera.
     */
    public function handleEvent(array $event, string $headerChecksum): void
    {
        if (($event['event'] ?? null) !== 'transaction.updated') {
            return; // Otros eventos (tokens de Nequi o Bancolombia) no aplican a la cartera.
        }
        if (!$this->validChecksum($event, $headerChecksum)) {
            throw new RuntimeException('El evento de Wompi no tiene una firma válida.');
        }
        $transactionId = $event['data']['transaction']['id'] ?? null;
        if (!is_string($transactionId) || $transactionId === '') {
            throw new RuntimeException('El evento de Wompi no trae el identificador de la transacción.');
        }
        $this->applyTransaction($this->fetchTransaction($transactionId));
    }

    /** Retorno del navegador: consulta la transacción (no confía en la URL) y devuelve approved, pending o rejected. */
    public function confirmReturn(string $transactionId): string
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,80}$/D', $transactionId)) {
            return 'pending';
        }

        return $this->applyTransaction($this->fetchTransaction($transactionId));
    }

    public function webhookUrl(): string
    {
        $url = Tenant::absoluteUrl('/pagos/wompi/eventos');

        return parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : '';
    }

    /**
     * Aplica el estado confirmado por Wompi a su intento de pago. Una transacción aprobada se registra una sola vez
     * como pago de la nota, con el valor y la moneda verificados.
     */
    private function applyTransaction(array $transaction): string
    {
        $reference = $transaction['reference'] ?? null;
        if (!is_string($reference) || !preg_match(self::REFERENCE_PATTERN, $reference)) {
            throw new RuntimeException('La transacción de Wompi no corresponde a un pago de esta plataforma.');
        }
        $remoteStatus = strtoupper((string) ($transaction['status'] ?? ''));
        $transactionId = (string) ($transaction['id'] ?? '');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare(
                'SELECT id, statement_id, user_id, expected_amount, status
                 FROM billing_wompi_payments WHERE reference = ? AND tenant_id = ? FOR UPDATE'
            );
            $query->execute([$reference, Tenant::id()]);
            $payment = $query->fetch();
            if (!is_array($payment)) {
                throw new RuntimeException('No se encontró el pago de Wompi con la referencia recibida.');
            }
            if ($payment['status'] === 'approved') {
                $pdo->commit();

                return 'approved';
            }

            if ($remoteStatus === 'APPROVED') {
                $expectedCents = BillingService::moneyToCents($payment['expected_amount']);
                if (($transaction['currency'] ?? '') !== 'COP' || (int) ($transaction['amount_in_cents'] ?? -1) !== $expectedCents) {
                    throw new RuntimeException('El valor confirmado por Wompi no coincide con el intento de pago.');
                }
                if ($transactionId === '' || strlen($transactionId) > 80) {
                    throw new RuntimeException('Wompi no devolvió un identificador de transacción válido.');
                }
                $method = substr((string) ($transaction['payment_method_type'] ?? ''), 0, 40);
                $pdo->prepare(
                    'INSERT INTO billing_payments (tenant_id, statement_id, amount, received_on, reference, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    Tenant::id(),
                    (int) $payment['statement_id'],
                    (string) $payment['expected_amount'],
                    (new DateTimeImmutable('today'))->format('Y-m-d'),
                    $transactionId,
                    'Pago en línea Wompi aprobado' . ($method !== '' ? ' (' . $method . ')' : ''),
                    (int) $payment['user_id'],
                ]);
                $paymentId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    "UPDATE billing_wompi_payments
                     SET status = 'approved', transaction_id = ?, payment_method_type = ?, approved_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND tenant_id = ?"
                )->execute([$transactionId, $method !== '' ? $method : null, (int) $payment['id'], Tenant::id()]);
                $pdo->commit();
                PaymentNotificationService::defer('payment', ['payment_id' => $paymentId, 'origin' => 'wompi']);

                return 'approved';
            }

            if (in_array($remoteStatus, ['DECLINED', 'VOIDED', 'ERROR'], true)) {
                $pdo->prepare(
                    "UPDATE billing_wompi_payments SET status = 'rejected', transaction_id = ? WHERE id = ? AND tenant_id = ?"
                )->execute([$transactionId !== '' ? $transactionId : null, (int) $payment['id'], Tenant::id()]);
                $pdo->commit();

                return 'rejected';
            }

            $pdo->commit();

            return 'pending';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function validChecksum(array $event, string $headerChecksum): bool
    {
        $received = $headerChecksum !== '' ? $headerChecksum : (string) ($event['signature']['checksum'] ?? '');
        $expected = self::eventChecksum($event, $this->secret('events_secret_ciphertext'));

        return $expected !== null && $received !== '' && hash_equals($expected, strtolower($received));
    }

    /**
     * Checksum de un evento según Wompi: SHA256 de los valores indicados en signature.properties (en ese orden,
     * leídos dentro de data), seguidos del timestamp y del secreto de eventos. Null si el evento no trae firma.
     */
    public static function eventChecksum(array $event, string $eventsSecret): ?string
    {
        $properties = $event['signature']['properties'] ?? null;
        if (!is_array($properties) || $properties === [] || !isset($event['timestamp']) || !is_scalar($event['timestamp'])) {
            return null;
        }
        $concatenated = '';
        foreach ($properties as $path) {
            $value = $event['data'] ?? null;
            foreach (explode('.', (string) $path) as $segment) {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }
            if (!is_scalar($value)) {
                return null;
            }
            $concatenated .= (string) $value;
        }

        return hash('sha256', $concatenated . $event['timestamp'] . $eventsSecret);
    }

    private function fetchTransaction(string $transactionId): array
    {
        $response = $this->request($this->settings()['environment'], '/transactions/' . rawurlencode($transactionId), $this->secret('private_key_ciphertext'));
        $transaction = $response['data'] ?? null;
        if (!is_array($transaction)) {
            throw new RuntimeException('Wompi no devolvió la transacción consultada.');
        }

        return $transaction;
    }

    private function request(string $environment, string $path, ?string $privateKey, int $timeout = 6): array
    {
        $handle = curl_init(self::API_URLS[$environment] . $path);
        if ($handle === false) {
            throw new RuntimeException('No fue posible iniciar la conexión segura con Wompi.');
        }
        $headers = ['Accept: application/json'];
        if ($privateKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $privateKey;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if (!is_string($response)) {
            throw new RuntimeException('No se pudo conectar con Wompi.' . ($curlError !== '' ? ' ' . $curlError : ''));
        }
        $decoded = json_decode($response, true);
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            throw new RuntimeException($statusCode === 401 || $statusCode === 404
                ? 'Wompi no reconoce las llaves o la transacción. Verifica las llaves y el ambiente.'
                : 'Wompi rechazó la solicitud (HTTP ' . $statusCode . ').');
        }

        return $decoded;
    }

    private function secret(string $column): string
    {
        $row = $this->storedRow();
        if (!is_array($row) || (string) $row[$column] === '') {
            throw new RuntimeException('Completa la configuración de Wompi.');
        }

        return SecretBox::decrypt((string) $row[$column], self::SECRET_CONTEXT);
    }

    private function recipientEmail(array $statement, int $userId): string
    {
        foreach ($statement['recipients'] as $recipient) {
            if ((int) ($recipient['user_id'] ?? 0) === $userId && filter_var($recipient['recipient_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                return (string) $recipient['recipient_email'];
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null (null también si aún no se aplicó la migración 027) */
    private function storedRow(): ?array
    {
        try {
            $query = Database::connection()->prepare('SELECT * FROM wompi_payment_settings WHERE tenant_id = ?');
            $query->execute([Tenant::id()]);
            $row = $query->fetch();
        } catch (PDOException) {
            return null;
        }

        return is_array($row) ? $row : null;
    }
}
