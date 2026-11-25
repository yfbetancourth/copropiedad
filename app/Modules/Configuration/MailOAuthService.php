<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use App\Core\Env;
use App\Core\SecretBox;
use App\Core\Tenant;
use Closure;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Correo con «Iniciar sesión con Google / Microsoft» (OAuth 2.0 con PKCE). El administrador autoriza a la plataforma
 * a ENVIAR desde su cuenta; nunca se ve ni se guarda su contraseña.
 *   - Google: API de Gmail con el permiso gmail.send (solo enviar; Google lo clasifica como sensible, sin auditoría
 *     anual, a diferencia del acceso completo que exige SMTP).
 *   - Microsoft (Outlook, Hotmail, Microsoft 365): Microsoft Graph con Mail.Send.
 * Una sola dirección de retorno para toda la plataforma (MAIL_OAUTH_REDIRECT_URI → public/correo-oauth.php): el
 * «state» (aleatorio, guardado como hash, de un solo uso, con vencimiento) dice a qué conjunto, usuario y cuenta
 * pertenece la conexión, así funciona aunque cada conjunto tenga su propio dominio.
 */
final class MailOAuthService
{
    public const PROVIDERS = [
        'google' => [
            'label' => 'Google (Gmail o Google Workspace)',
            'button' => 'Conectar con Google',
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'scope' => 'openid email https://www.googleapis.com/auth/gmail.send',
            'profile' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'send' => 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            'revoke' => 'https://oauth2.googleapis.com/revoke',
            'env' => 'GOOGLE',
        ],
        'microsoft' => [
            'label' => 'Microsoft (Outlook, Hotmail o Microsoft 365)',
            'button' => 'Conectar con Microsoft',
            'authorize' => 'https://login.microsoftonline.com/{authority}/oauth2/v2.0/authorize',
            'token' => 'https://login.microsoftonline.com/{authority}/oauth2/v2.0/token',
            'scope' => 'openid email offline_access User.Read Mail.Send',
            'profile' => 'https://graph.microsoft.com/v1.0/me',
            'send' => 'https://graph.microsoft.com/v1.0/me/sendMail',
            'revoke' => null,
            'env' => 'MICROSOFT',
        ],
    ];
    public const TARGETS = ['tenant', 'personal'];
    /** Códigos con los que el retorno vuelve al panel (?correo=...). Nunca se pasa texto libre por la URL. */
    public const RESULT_MESSAGES = [
        'conectado' => 'Cuenta conectada. Envía el correo de prueba para confirmar que todo funciona.',
        'cancelado' => 'No se conectó la cuenta: cancelaste el permiso en el inicio de sesión del proveedor.',
        'rechazado' => 'El proveedor no autorizó la conexión. Vuelve a intentarlo; si persiste, avisa al administrador de la plataforma.',
        'sin_permiso_permanente' => 'El proveedor no entregó el permiso permanente. Quita el acceso de la aplicación en la seguridad de tu cuenta y vuelve a conectarla.',
        'sin_correo' => 'No se pudo leer una dirección de correo verificada de esa cuenta.',
        'error' => 'No se pudo completar la conexión con el proveedor. Vuelve a intentarlo.',
    ];
    private const STATE_MINUTES = 10;

    /** @var Closure(string, string, array<string, string>, ?string): array{status: int, body: string} */
    private Closure $http;

    /**
     * @param (callable(string, string, array<string, string>, ?string): array{status: int, body: string})|null $http
     *        cliente HTTP (método, URL, cabeceras, cuerpo); por defecto cURL. Las pruebas inyectan uno simulado.
     */
    public function __construct(?callable $http = null)
    {
        $this->http = $http !== null ? Closure::fromCallable($http) : Closure::fromCallable([self::class, 'curl']);
    }

    /** @return array<string, string> proveedores configurados por el dueño de la plataforma => nombre */
    public static function availableProviders(): array
    {
        $available = [];
        foreach (self::PROVIDERS as $key => $provider) {
            if (Env::get($provider['env'] . '_OAUTH_CLIENT_ID') !== '' && Env::get($provider['env'] . '_OAUTH_CLIENT_SECRET') !== ''
                && self::redirectUri() !== '') {
                $available[$key] = $provider['label'];
            }
        }

        return $available;
    }

    /** Dirección de retorno registrada en Google Cloud y Microsoft Entra (la misma para todos los conjuntos). */
    public static function redirectUri(): string
    {
        $uri = Env::get('MAIL_OAUTH_REDIRECT_URI');

        return filter_var($uri, FILTER_VALIDATE_URL) !== false ? $uri : '';
    }

    /**
     * Inicia la conexión: guarda el «state» y devuelve la URL de inicio de sesión del proveedor.
     */
    public function start(string $provider, string $target, int $actorId, string $returnUrl, string $loginHint = ''): string
    {
        $config = $this->provider($provider);
        if (!in_array($target, self::TARGETS, true)) {
            throw new RuntimeException('Cuenta de correo no válida.');
        }
        $state = self::base64Url(random_bytes(32));
        $verifier = self::base64Url(random_bytes(48));
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM mail_oauth_states WHERE expires_at < NOW()')->execute();
        $pdo->prepare(
            'INSERT INTO mail_oauth_states (state_hash, tenant_id, user_id, target, provider, code_verifier_ciphertext, return_url, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . self::STATE_MINUTES . ' MINUTE))'
        )->execute([
            hash('sha256', $state), Tenant::id(), $actorId, $target, $provider,
            SecretBox::encrypt($verifier, 'mail-oauth-verifier'), mb_substr($returnUrl, 0, 500, 'UTF-8'),
        ]);

        $parameters = [
            'client_id' => $this->clientId($provider),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => $config['scope'],
            'state' => $state,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];
        if ($provider === 'google') {
            // offline + consent: Google entrega el token de actualización que permite enviar sin volver a iniciar sesión.
            $parameters += ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'false'];
        } else {
            $parameters += ['prompt' => 'select_account', 'response_mode' => 'query'];
        }
        if (filter_var($loginHint, FILTER_VALIDATE_EMAIL)) {
            $parameters['login_hint'] = $loginHint;
        }

        return $this->endpoint($provider, 'authorize') . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Retorno del proveedor: valida el «state» (y lo consume), cambia el código por los tokens, averigua el correo
     * y guarda la conexión. Devuelve la dirección de regreso y el mensaje para el usuario.
     *
     * @return array{return_url: string, ok: bool, code: string, message: string}
     */
    public function complete(string $state, string $code, string $error): array
    {
        if ($state === '' || strlen($state) > 128) {
            throw new RuntimeException('La solicitud de conexión no es válida. Vuelve a intentarlo desde la configuración de correo.');
        }
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM mail_oauth_states WHERE state_hash = ? AND expires_at >= NOW()');
        $statement->execute([hash('sha256', $state)]);
        $row = $statement->fetch();
        // Un solo uso: se borra aunque luego falle el intercambio.
        $pdo->prepare('DELETE FROM mail_oauth_states WHERE state_hash = ?')->execute([hash('sha256', $state)]);
        if (!is_array($row)) {
            throw new RuntimeException('La solicitud de conexión venció o ya se usó. Vuelve a intentarlo desde la configuración de correo.');
        }
        $returnUrl = (string) $row['return_url'];
        $result = static fn (string $code): array => [
            'return_url' => $returnUrl, 'ok' => $code === 'conectado', 'code' => $code, 'message' => self::RESULT_MESSAGES[$code],
        ];
        if ($error !== '') {
            return $result($error === 'access_denied' ? 'cancelado' : 'rechazado');
        }
        if ($code === '' || strlen($code) > 2048) {
            return $result('rechazado');
        }

        $tenant = Database::connection()->prepare('SELECT id, slug, name, status FROM tenants WHERE id = ?');
        $tenant->execute([(int) $row['tenant_id']]);
        $tenantRow = $tenant->fetch();
        if (!is_array($tenantRow)) {
            throw new RuntimeException('El conjunto de la solicitud ya no existe.');
        }
        $tenantRow['id'] = (int) $tenantRow['id'];
        Tenant::set($tenantRow);

        $provider = (string) $row['provider'];
        try {
            $tokens = $this->tokenRequest($provider, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => self::redirectUri(),
                'code_verifier' => SecretBox::decrypt((string) $row['code_verifier_ciphertext'], 'mail-oauth-verifier'),
            ]);
        } catch (RuntimeException $exception) {
            error_log('Conexión de correo OAuth: el proveedor rechazó el intercambio del código: ' . $exception->getMessage());

            return $result('rechazado');
        }
        if (($tokens['refresh_token'] ?? '') === '') {
            return $result('sin_permiso_permanente');
        }
        try {
            $profile = $this->profile($provider, (string) $tokens['access_token']);
        } catch (RuntimeException $exception) {
            error_log('Conexión de correo OAuth: ' . $exception->getMessage());

            return $result('sin_correo');
        }

        $userId = (int) $row['user_id'];
        $ownerScope = $row['target'] === 'personal' ? $userId : 0;
        $pdo->prepare(
            'INSERT INTO mail_oauth_connections
                (tenant_id, owner_scope, user_id, provider, email, display_name, refresh_token_ciphertext, access_token_ciphertext,
                 access_expires_at, connected_by, last_error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, NULL)
             ON DUPLICATE KEY UPDATE provider = VALUES(provider), email = VALUES(email), display_name = VALUES(display_name),
                 refresh_token_ciphertext = VALUES(refresh_token_ciphertext), access_token_ciphertext = VALUES(access_token_ciphertext),
                 access_expires_at = VALUES(access_expires_at), connected_by = VALUES(connected_by),
                 connected_at = CURRENT_TIMESTAMP, last_error = NULL'
        )->execute([
            Tenant::id(), $ownerScope, $ownerScope > 0 ? $userId : null, $provider, $profile['email'], $profile['name'],
            SecretBox::encrypt((string) $tokens['refresh_token'], self::context($ownerScope, 'refresh')),
            SecretBox::encrypt((string) $tokens['access_token'], self::context($ownerScope, 'access')),
            max(60, (int) ($tokens['expires_in'] ?? 3600) - 120), $userId,
        ]);

        return $result('conectado');
    }

    /** @return array{id: int, provider: string, provider_label: string, email: string, display_name: string, connected_at: string, last_sent_at: ?string, last_error: ?string}|null */
    public function connection(string $target, ?int $userId = null): ?array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT id, provider, email, display_name, connected_at, last_sent_at, last_error
                 FROM mail_oauth_connections WHERE tenant_id = ? AND owner_scope = ?'
            );
            $statement->execute([Tenant::id(), $this->ownerScope($target, $userId)]);
            $row = $statement->fetch();
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '42S02') {
                return null;
            }
            throw $exception;
        }
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'provider_label' => self::PROVIDERS[$row['provider']]['label'] ?? (string) $row['provider'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'connected_at' => (string) $row['connected_at'],
            'last_sent_at' => $row['last_sent_at'] !== null ? (string) $row['last_sent_at'] : null,
            'last_error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
        ];
    }

    /**
     * Configuración de envío para SmtpMailer cuando la cuenta está conectada; null si no lo está.
     *
     * @return array<string, mixed>|null
     */
    public function sendingSettings(string $target, ?int $userId, string $fromName): ?array
    {
        $connection = $this->connection($target, $userId);
        if ($connection === null) {
            return null;
        }

        return [
            'host' => '', 'port' => 0, 'username' => '', 'password' => '', 'encryption' => '',
            'from_address' => $connection['email'],
            'from_name' => $fromName !== '' ? $fromName : $connection['display_name'],
            'oauth_connection_id' => $connection['id'],
            'oauth_provider' => $connection['provider'],
        ];
    }

    /** Desconecta: revoca el permiso en el proveedor (si lo permite) y borra los tokens guardados. */
    public function disconnect(string $target, ?int $userId = null): void
    {
        $ownerScope = $this->ownerScope($target, $userId);
        $statement = Database::connection()->prepare(
            'SELECT provider, refresh_token_ciphertext FROM mail_oauth_connections WHERE tenant_id = ? AND owner_scope = ?'
        );
        $statement->execute([Tenant::id(), $ownerScope]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('No hay una cuenta conectada.');
        }
        Database::connection()->prepare('DELETE FROM mail_oauth_connections WHERE tenant_id = ? AND owner_scope = ?')
            ->execute([Tenant::id(), $ownerScope]);
        $revoke = self::PROVIDERS[$row['provider']]['revoke'] ?? null;
        if ($revoke !== null) {
            try {
                ($this->http)('POST', $revoke, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
                    'token' => SecretBox::decrypt((string) $row['refresh_token_ciphertext'], self::context($ownerScope, 'refresh')),
                ]));
            } catch (Throwable $exception) {
                error_log('No se pudo revocar el permiso de correo en el proveedor: ' . $exception->getMessage());
            }
        }
    }

    /** Envía un mensaje MIME ya armado con la cuenta conectada. */
    public function sendMime(int $connectionId, string $mime): void
    {
        $row = $this->connectionRow($connectionId);
        $provider = (string) $row['provider'];
        try {
            $token = $this->accessToken($row);
            $response = $provider === 'google'
                ? ($this->http)('POST', self::PROVIDERS['google']['send'], [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ], json_encode(['raw' => self::base64Url($mime)], JSON_THROW_ON_ERROR))
                : ($this->http)('POST', self::PROVIDERS['microsoft']['send'], [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'text/plain',
                ], base64_encode($mime));
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new RuntimeException($this->apiError($provider, $response));
            }
        } catch (RuntimeException $exception) {
            $this->recordError($connectionId, $exception->getMessage());
            throw $exception;
        }
        Database::connection()->prepare('UPDATE mail_oauth_connections SET last_sent_at = NOW(), last_error = NULL WHERE id = ?')
            ->execute([$connectionId]);
    }

    /* ------------------------------------------- Apoyo ------------------------------------------- */

    /** Token de acceso vigente; lo renueva con el de actualización si venció (y guarda el nuevo si el proveedor rota). */
    private function accessToken(array $row): string
    {
        $ownerScope = (int) $row['owner_scope'];
        if ($row['access_token_ciphertext'] !== null && (int) $row['access_valid'] === 1) {
            return SecretBox::decrypt((string) $row['access_token_ciphertext'], self::context($ownerScope, 'access'));
        }
        $tokens = $this->tokenRequest((string) $row['provider'], [
            'grant_type' => 'refresh_token',
            'refresh_token' => SecretBox::decrypt((string) $row['refresh_token_ciphertext'], self::context($ownerScope, 'refresh')),
        ]);
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        Database::connection()->prepare(
            'UPDATE mail_oauth_connections SET access_token_ciphertext = ?, access_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)'
            . ($refresh !== '' ? ', refresh_token_ciphertext = ?' : '') . ' WHERE id = ?'
        )->execute(array_values(array_filter([
            SecretBox::encrypt((string) $tokens['access_token'], self::context($ownerScope, 'access')),
            max(60, (int) ($tokens['expires_in'] ?? 3600) - 120),
            $refresh !== '' ? SecretBox::encrypt($refresh, self::context($ownerScope, 'refresh')) : null,
            (int) $row['id'],
        ], static fn ($value): bool => $value !== null)));

        return (string) $tokens['access_token'];
    }

    /** @return array<string, mixed> */
    private function tokenRequest(string $provider, array $parameters): array
    {
        $response = ($this->http)('POST', $this->endpoint($provider, 'token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query($parameters + [
            'client_id' => $this->clientId($provider),
            'client_secret' => Env::get(self::PROVIDERS[$provider]['env'] . '_OAUTH_CLIENT_SECRET'),
        ] + ($provider === 'microsoft' ? ['scope' => self::PROVIDERS['microsoft']['scope']] : [])));
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null)) {
            $code = is_array($data) ? (string) ($data['error'] ?? '') : '';
            throw new RuntimeException($code === 'invalid_grant'
                ? 'El permiso de la cuenta conectada venció o fue revocado. Vuelve a conectar la cuenta en la configuración de correo.'
                : 'El proveedor rechazó la autorización' . ($code !== '' ? ' (' . mb_substr(preg_replace('/[^a-z_]/', '', $code) ?? '', 0, 40) . ')' : '') . '. Vuelve a intentarlo.');
        }

        return $data;
    }

    /** @return array{email: string, name: string} */
    private function profile(string $provider, string $accessToken): array
    {
        $response = ($this->http)('GET', self::PROVIDERS[$provider]['profile'], ['Authorization' => 'Bearer ' . $accessToken], null);
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data)) {
            throw new RuntimeException('No se pudo leer la dirección de correo de la cuenta conectada.');
        }
        $email = $provider === 'google'
            ? (string) ($data['email'] ?? '')
            : (string) (($data['mail'] ?? '') ?: ($data['userPrincipalName'] ?? ''));
        if ($provider === 'google' && ($data['email_verified'] ?? true) === false) {
            throw new RuntimeException('La cuenta de Google no tiene el correo verificado.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('La cuenta conectada no tiene una dirección de correo válida.');
        }
        $name = (string) ($provider === 'google' ? ($data['name'] ?? '') : ($data['displayName'] ?? ''));

        return ['email' => mb_strtolower($email, 'UTF-8'), 'name' => mb_substr(trim($name), 0, 160, 'UTF-8')];
    }

    private function connectionRow(int $connectionId): array
    {
        // La vigencia se compara en la base (NOW()), con el mismo reloj con que se guardó; PHP puede tener otra zona horaria.
        $statement = Database::connection()->prepare(
            'SELECT *, (access_expires_at IS NOT NULL AND access_expires_at > NOW()) AS access_valid
             FROM mail_oauth_connections WHERE id = ? AND tenant_id = ?'
        );
        $statement->execute([$connectionId, Tenant::id()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('La cuenta de correo conectada ya no existe. Vuelve a conectarla.');
        }

        return $row;
    }

    private function recordError(int $connectionId, string $message): void
    {
        try {
            Database::connection()->prepare('UPDATE mail_oauth_connections SET last_error = ? WHERE id = ?')
                ->execute([mb_substr($message, 0, 255, 'UTF-8'), $connectionId]);
        } catch (Throwable) {
            // Registrar el error nunca debe ocultar el error original.
        }
    }

    private function apiError(string $provider, array $response): string
    {
        $data = json_decode($response['body'], true);
        $detail = is_array($data) ? (string) ($data['error']['message'] ?? $data['error']['code'] ?? '') : '';
        if ($response['status'] === 401 || $response['status'] === 403) {
            return 'El proveedor no permitió enviar con la cuenta conectada' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 150, 'UTF-8') : '') . '. Vuelve a conectarla.';
        }
        if ($response['status'] === 429) {
            return 'La cuenta conectada alcanzó el límite de envíos del proveedor. Intenta más tarde.';
        }

        return 'El proveedor rechazó el envío (' . $response['status'] . ')' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 150, 'UTF-8') : '') . '.';
    }

    private function ownerScope(string $target, ?int $userId): int
    {
        if ($target === 'personal') {
            if ($userId === null || $userId < 1) {
                throw new RuntimeException('Falta el usuario de la cuenta personal.');
            }

            return $userId;
        }

        return 0;
    }

    private function provider(string $provider): array
    {
        if (!isset(self::availableProviders()[$provider])) {
            throw new RuntimeException('Ese proveedor de correo no está disponible en la plataforma.');
        }

        return self::PROVIDERS[$provider];
    }

    private function clientId(string $provider): string
    {
        return Env::get(self::PROVIDERS[$provider]['env'] . '_OAUTH_CLIENT_ID');
    }

    private function endpoint(string $provider, string $kind): string
    {
        $authority = preg_replace('/[^a-zA-Z0-9.-]/', '', Env::get('MICROSOFT_OAUTH_AUTHORITY') ?: 'common') ?: 'common';

        return str_replace('{authority}', $authority, (string) self::PROVIDERS[$provider][$kind]);
    }

    /** Contexto de cifrado ligado al conjunto y al dueño: un token copiado a otra fila no se puede descifrar. */
    private static function context(int $ownerScope, string $kind): string
    {
        return 'mail-oauth-' . $kind . ':' . Tenant::id() . ':' . $ownerScope;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array{status: int, body: string} */
    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if ($responseBody === false) {
            throw new RuntimeException('No hubo conexión con el proveedor de correo' . ($error !== '' ? ' (' . mb_substr($error, 0, 120, 'UTF-8') . ')' : '') . '.');
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
