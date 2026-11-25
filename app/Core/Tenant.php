<?php

declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;
use LogicException;
use PDOException;

/**
 * Cliente (conjunto residencial) de la solicitud actual, resuelto a partir del subdominio.
 *
 *   {slug}.APP_BASE_DOMAIN       -> el conjunto con ese slug
 *   APP_BASE_DOMAIN / www.       -> la plataforma (consola del Superadmin)
 *   otro host (p. ej. localhost) -> APP_DEFAULT_TENANT fuera de producción; en producción, 404
 *   copia propia (APP_TENANT)    -> siempre ese conjunto, con cualquier dominio (conjuntos/{slug}/)
 *
 * Toda consulta de datos de negocio debe filtrar por Tenant::id().
 */
final class Tenant
{
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /** Subdominios que no pueden usarse como slug de un cliente. */
    public const RESERVED_SLUGS = ['www', 'admin', 'app', 'api', 'mail', 'smtp', 'ftp', 'static', 'cdn', 'soporte', 'plataforma'];

    /** Directorio (dentro del proyecto original) con la copia propia de cada conjunto: conjuntos/{slug}/. */
    public const SITES_DIRECTORY = 'conjuntos';

    private static bool $resolved = false;
    /** @var array<string, ?string> */
    private static array $siteUrls = [];
    private static ?array $current = null;
    /** @var array<string, bool> */
    private static array $modules = [];

    /**
     * Resuelve el cliente desde el host. Responde 404 y termina si el subdominio no corresponde a ningún cliente.
     */
    public static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }

        // Copia propia de un conjunto (conjuntos/{slug}/): atiende solo a ese conjunto, con cualquier dominio.
        if (self::isFixed()) {
            $fixed = self::isValidSlug(self::fixedSlug()) ? self::findBySlug(self::fixedSlug()) : null;
            if ($fixed === null) {
                self::notFound();
            }
            self::set($fixed);
            return;
        }

        $host = self::requestHost();
        $baseDomain = self::baseDomain();
        $slug = null;

        if ($baseDomain !== '' && ($host === $baseDomain || $host === 'www.' . $baseDomain)) {
            self::set(null);
            return;
        }

        if ($baseDomain !== '' && str_ends_with($host, '.' . $baseDomain)) {
            $slug = substr($host, 0, -strlen('.' . $baseDomain));
        } elseif (!self::isProduction()) {
            $slug = Env::get('APP_DEFAULT_TENANT');
            if ($slug === '') {
                self::set(null);
                return;
            }
        }

        $tenant = is_string($slug) && self::isValidSlug($slug) ? self::findBySlug($slug) : null;
        if ($tenant === null) {
            self::notFound();
        }

        self::redirectToOwnSite($tenant['slug']);
        self::set($tenant);
    }

    /** true en la copia de un conjunto: su .env define APP_TENANT. */
    public static function isFixed(): bool
    {
        return self::fixedSlug() !== '';
    }

    /**
     * Directorio raíz de los archivos de un conjunto (storage/ y public/uploads/): su copia en conjuntos/{slug}/
     * si ya la tiene; si no, este proyecto. Sin $tenantId, el conjunto actual.
     */
    public static function homeDirectory(?int $tenantId = null): string
    {
        $projectRoot = dirname(__DIR__, 2);
        if (self::isFixed()) {
            return $projectRoot;
        }

        $slug = $tenantId === null || $tenantId === self::scopeId()
            ? (string) (self::current()['slug'] ?? '')
            : self::slugOf($tenantId);
        $siteDirectory = $slug === '' ? null : self::siteDirectory($slug);

        return $siteDirectory ?? $projectRoot;
    }

    /** URL pública (APP_URL) de la copia propia de un conjunto, o null si aún no tiene una. */
    public static function siteUrl(string $slug): ?string
    {
        if (array_key_exists($slug, self::$siteUrls)) {
            return self::$siteUrls[$slug];
        }

        $url = null;
        $directory = self::siteDirectory($slug);
        if ($directory !== null) {
            $values = Dotenv::parse((string) file_get_contents($directory . DIRECTORY_SEPARATOR . '.env'));
            $candidate = rtrim(trim((string) ($values['APP_URL'] ?? '')), '/');
            $url = filter_var($candidate, FILTER_VALIDATE_URL) !== false ? $candidate : null;
        }

        return self::$siteUrls[$slug] = $url;
    }

    private static function fixedSlug(): string
    {
        return strtolower(Env::get('APP_TENANT'));
    }

    /** conjuntos/{slug}/ si existe y está configurado (.env); solo desde el proyecto original. */
    private static function siteDirectory(string $slug): ?string
    {
        if (self::isFixed() || !self::isValidSlug($slug)) {
            return null;
        }
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::SITES_DIRECTORY . DIRECTORY_SEPARATOR . $slug;

        return is_file($directory . DIRECTORY_SEPARATOR . '.env') ? $directory : null;
    }

    /**
     * Un conjunto con copia propia se navega desde ella: las visitas (GET/HEAD) por subdominio se redirigen.
     * Los POST siguen atendiéndose aquí para no romper webhooks de pasarelas registrados con la dirección anterior.
     */
    private static function redirectToOwnSite(string $slug): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $siteUrl = self::siteUrl($slug);
        if ($siteUrl === null || ($method !== 'GET' && $method !== 'HEAD') || PHP_SAPI === 'cli') {
            return;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $basePath = rtrim((string) parse_url(Env::get('APP_URL'), PHP_URL_PATH), '/');
        if ($basePath !== '' && strncasecmp($uri, $basePath, strlen($basePath)) === 0) {
            $uri = substr($uri, strlen($basePath));
        }

        header('Location: ' . $siteUrl . '/' . ltrim($uri, '/'), true, 302);
        exit;
    }

    private static function slugOf(int $tenantId): string
    {
        $statement = Database::connection()->prepare('SELECT slug FROM tenants WHERE id = ? LIMIT 1');
        $statement->execute([$tenantId]);

        return (string) ($statement->fetchColumn() ?: '');
    }

    private static function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo 'No encontramos este conjunto residencial. Verifica la dirección.';
        exit;
    }

    /** Fija el cliente de forma explícita (scripts de consola y pruebas). */
    public static function set(?array $tenant): void
    {
        self::$current = $tenant;
        self::$resolved = true;
        self::$modules = $tenant === null ? [] : self::loadModules((int) $tenant['id']);
    }

    public static function current(): ?array
    {
        if (!self::$resolved) {
            self::resolve();
        }

        return self::$current;
    }

    public static function isPlatform(): bool
    {
        return self::current() === null;
    }

    /** Identificador del cliente actual. Falla en la plataforma: allí no hay datos de un conjunto. */
    public static function id(): int
    {
        $tenant = self::current();
        if ($tenant === null) {
            throw new LogicException('Esta operación requiere un conjunto residencial y la solicitud es de la plataforma.');
        }

        return (int) $tenant['id'];
    }

    /** null en la plataforma; el id del cliente en un conjunto. */
    public static function scopeId(): ?int
    {
        $tenant = self::current();

        return $tenant === null ? null : (int) $tenant['id'];
    }

    public static function isActive(): bool
    {
        $tenant = self::current();

        return $tenant !== null && $tenant['status'] === 'active';
    }

    public static function name(): string
    {
        return (string) (self::current()['name'] ?? 'Copropiedad');
    }

    public static function moduleEnabled(string $moduleKey): bool
    {
        return self::current() !== null && (self::$modules[$moduleKey] ?? false);
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1 && !in_array($slug, self::RESERVED_SLUGS, true);
    }

    /**
     * URL absoluta dentro de un cliente (o de la plataforma con $slug null), para correos, retornos y webhooks.
     * Usa el esquema, puerto y ruta base de APP_URL y el subdominio del cliente.
     */
    public static function absoluteUrl(string $path, ?string $slug = null, bool $platform = false): string
    {
        $appUrl = Env::get('APP_URL');
        $parts = parse_url($appUrl);
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
        $port = is_array($parts) && isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $basePath = is_array($parts) && is_string($parts['path'] ?? null) ? rtrim($parts['path'], '/') : '';
        $baseDomain = self::baseDomain();

        if (!$platform) {
            // La copia del propio conjunto usa su APP_URL; desde el original, la del conjunto si ya tiene copia.
            if (self::isFixed() && ($slug === null || $slug === self::fixedSlug())) {
                return rtrim($appUrl, '/') . '/' . ltrim($path, '/');
            }
            $targetSlug = $slug ?? (string) (self::current()['slug'] ?? '');
            $siteUrl = $targetSlug === '' ? null : self::siteUrl($targetSlug);
            if ($siteUrl !== null) {
                return $siteUrl . '/' . ltrim($path, '/');
            }
        }

        if ($baseDomain === '') {
            $host = is_array($parts) && is_string($parts['host'] ?? null) ? $parts['host'] : self::requestHost();
        } elseif ($platform) {
            $host = $baseDomain;
        } else {
            $slug ??= (string) (self::current()['slug'] ?? '');
            $host = $slug === '' ? $baseDomain : $slug . '.' . $baseDomain;
        }

        return $scheme . '://' . $host . $port . $basePath . '/' . ltrim($path, '/');
    }

    public static function baseDomain(): string
    {
        return strtolower(trim(Env::get('APP_BASE_DOMAIN'), ". \t\n\r\0\x0B"));
    }

    private static function findBySlug(string $slug): ?array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT id, slug, name, status FROM tenants WHERE slug = ? LIMIT 1'
            );
            $statement->execute([$slug]);
            $tenant = $statement->fetch();
        } catch (PDOException $exception) {
            error_log('No se pudo resolver el conjunto residencial: ' . $exception->getMessage());
            return null;
        }

        if (!is_array($tenant)) {
            return null;
        }
        $tenant['id'] = (int) $tenant['id'];

        return $tenant;
    }

    /** @return array<string, bool> */
    private static function loadModules(int $tenantId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT module_key, enabled FROM tenant_modules WHERE tenant_id = ?'
        );
        $statement->execute([$tenantId]);
        $modules = [];
        foreach ($statement->fetchAll() as $row) {
            $modules[(string) $row['module_key']] = (bool) $row['enabled'];
        }

        return $modules;
    }

    private static function requestHost(): string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));

        return preg_replace('/:\d+$/', '', $host) ?? '';
    }

    private static function isProduction(): bool
    {
        return (Env::get('APP_ENV') ?: 'production') === 'production';
    }

}
