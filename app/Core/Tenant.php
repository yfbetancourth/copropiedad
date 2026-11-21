<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;
use PDOException;

/**
 * Cliente (conjunto residencial) de la solicitud actual, resuelto a partir del subdominio.
 *
 *   {slug}.APP_BASE_DOMAIN       -> el conjunto con ese slug
 *   APP_BASE_DOMAIN / www.       -> la plataforma (consola del Superadmin)
 *   otro host (p. ej. localhost) -> APP_DEFAULT_TENANT fuera de producción; en producción, 404
 *
 * Toda consulta de datos de negocio debe filtrar por Tenant::id().
 */
final class Tenant
{
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /** Subdominios que no pueden usarse como slug de un cliente. */
    public const RESERVED_SLUGS = ['www', 'admin', 'app', 'api', 'mail', 'smtp', 'ftp', 'static', 'cdn', 'soporte', 'plataforma'];

    private static bool $resolved = false;
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
            $slug = self::setting('APP_DEFAULT_TENANT');
            if ($slug === '') {
                self::set(null);
                return;
            }
        }

        $tenant = is_string($slug) && self::isValidSlug($slug) ? self::findBySlug($slug) : null;
        if ($tenant === null) {
            http_response_code(404);
            header('Content-Type: text/html; charset=UTF-8');
            echo 'No encontramos este conjunto residencial. Verifica la dirección.';
            exit;
        }

        self::set($tenant);
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
        $appUrl = self::setting('APP_URL');
        $parts = parse_url($appUrl);
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
        $port = is_array($parts) && isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $basePath = is_array($parts) && is_string($parts['path'] ?? null) ? rtrim($parts['path'], '/') : '';
        $baseDomain = self::baseDomain();

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
        return strtolower(trim(self::setting('APP_BASE_DOMAIN'), ". \t\n\r\0\x0B"));
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
        return (self::setting('APP_ENV') ?: 'production') === 'production';
    }

    private static function setting(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
