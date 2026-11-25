<?php

declare(strict_types=1);

/**
 * Crea o actualiza la copia propia de un conjunto residencial en conjuntos/{slug}/.
 *
 *   php bin/deploy-conjunto.php st-trope                         crea o actualiza la copia de St Trope
 *   php bin/deploy-conjunto.php st-trope --url=https://st-trope.com   y además fija su dirección (APP_URL)
 *   php bin/deploy-conjunto.php --all                            actualiza todos los conjuntos
 *
 * El código se edita SOLO en este proyecto (el repositorio); este script lo replica en cada copia.
 * En cada copia se conservan siempre su .env, storage/ y public/uploads/ (los archivos del conjunto).
 * El dominio del conjunto debe apuntar a conjuntos/{slug}/public.
 */

use App\Core\Database;
use App\Core\Env;
use App\Core\Tenant;
use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
Dotenv::createImmutable($projectRoot)->safeLoad();

if (Env::get('APP_TENANT') !== '') {
    fail('Este script se ejecuta desde el proyecto original, no desde la copia de un conjunto.');
}

/** Carpetas y archivos de código que se replican (todo lo demás de la copia se conserva). */
const CODE_DIRECTORIES = ['app', 'bin', 'database', 'public', 'vendor'];
const CODE_FILES = ['composer.json', 'composer.lock'];
/** Dentro de las carpetas replicadas, rutas que pertenecen al conjunto y nunca se copian ni se borran. */
const PRESERVED_PATHS = ['public/uploads'];

$options = parseArguments(array_slice($argv, 1));
$tenants = selectTenants($options['slug'], $options['all']);
if ($options['url'] !== null && count($tenants) !== 1) {
    fail('--url solo se puede usar con un conjunto a la vez.');
}

$version = codeVersion($projectRoot);
foreach ($tenants as $tenant) {
    $target = $projectRoot . DIRECTORY_SEPARATOR . Tenant::SITES_DIRECTORY . DIRECTORY_SEPARATOR . $tenant['slug'];
    deploy($projectRoot, $target, $tenant, $options['url'], $version);
}

/**
 * @param array{id: int, slug: string, name: string} $tenant
 */
function deploy(string $source, string $target, array $tenant, ?string $url, string $version): void
{
    $isNew = !is_dir($target);
    ensureDirectory($target);

    $copied = 0;
    $removed = 0;
    foreach (CODE_DIRECTORIES as $directory) {
        [$c, $r] = mirror($source . DIRECTORY_SEPARATOR . $directory, $target . DIRECTORY_SEPARATOR . $directory, $directory);
        $copied += $c;
        $removed += $r;
    }
    foreach (CODE_FILES as $file) {
        if (is_file($source . DIRECTORY_SEPARATOR . $file) && copyIfChanged($source . DIRECTORY_SEPARATOR . $file, $target . DIRECTORY_SEPARATOR . $file)) {
            $copied++;
        }
    }

    // Archivos propios del conjunto.
    foreach (['storage', 'storage/classifieds', 'storage/payment-proofs', 'public/uploads'] as $directory) {
        ensureDirectory($target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory));
    }
    copyIfChanged($source . '/public/uploads/.htaccess', $target . '/public/uploads/.htaccess');
    // Fuera de public/ nada debe ser accesible por web, aunque el servidor publique toda la carpeta.
    file_put_contents($target . '/.htaccess', "# Solo public/ es accesible por web.\nRequire all denied\n");

    $siteUrl = writeEnvironment($source, $target, $tenant['slug'], $url);
    file_put_contents(
        $target . '/DEPLOYED',
        'Conjunto: ' . $tenant['name'] . ' (' . $tenant['slug'] . ")\n"
        . 'Código: ' . $version . "\n"
        . 'Desplegado: ' . date('Y-m-d H:i:s') . "\n"
    );

    fwrite(STDOUT, sprintf(
        "%s %s: %d archivo(s) copiado(s), %d obsoleto(s) eliminado(s).\n  Directorio: %s\n  Dirección:  %s\n  El dominio debe apuntar a: %s\n",
        $isNew ? 'Creada la copia de' : 'Actualizada la copia de',
        $tenant['slug'],
        $copied,
        $removed,
        $target,
        $siteUrl,
        $target . DIRECTORY_SEPARATOR . 'public'
    ));
}

/**
 * Replica $source en $target: copia lo nuevo o modificado y elimina lo que ya no existe en el original.
 *
 * @return array{0: int, 1: int} [copiados, eliminados]
 */
function mirror(string $source, string $target, string $relative): array
{
    if (isPreserved($relative)) {
        return [0, 0];
    }
    ensureDirectory($target);
    $copied = 0;
    $removed = 0;

    $sourceEntries = is_dir($source) ? array_diff(scandir($source) ?: [], ['.', '..']) : [];
    foreach ($sourceEntries as $entry) {
        $from = $source . DIRECTORY_SEPARATOR . $entry;
        $to = $target . DIRECTORY_SEPARATOR . $entry;
        if (is_link($from)) {
            continue;
        }
        if (is_dir($from)) {
            [$c, $r] = mirror($from, $to, $relative . '/' . $entry);
            $copied += $c;
            $removed += $r;
        } elseif (copyIfChanged($from, $to)) {
            $copied++;
        }
    }

    foreach (array_diff(scandir($target) ?: [], ['.', '..'], $sourceEntries) as $entry) {
        if (isPreserved($relative . '/' . $entry)) {
            continue;
        }
        $removed += removePath($target . DIRECTORY_SEPARATOR . $entry);
    }

    return [$copied, $removed];
}

function isPreserved(string $relative): bool
{
    foreach (PRESERVED_PATHS as $preserved) {
        if ($relative === $preserved || str_starts_with($relative, $preserved . '/')) {
            return true;
        }
    }

    return false;
}

function copyIfChanged(string $from, string $to): bool
{
    if (is_file($to) && filesize($to) === filesize($from) && filemtime($to) >= filemtime($from)
        && hash_file('sha256', $to) === hash_file('sha256', $from)) {
        return false;
    }
    if (!copy($from, $to)) {
        fail('No se pudo copiar ' . $from);
    }
    touch($to, (int) filemtime($from));

    return true;
}

function removePath(string $path): int
{
    if (is_dir($path) && !is_link($path)) {
        $count = 0;
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $count += removePath($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);

        return $count;
    }
    unlink($path);

    return 1;
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        fail('No se pudo crear ' . $directory);
    }
}

/**
 * Crea el .env de la copia a partir del original (misma base de datos, correo y llaves) fijando APP_TENANT y
 * APP_URL. Si ya existe, lo conserva y solo cambia APP_URL cuando se pasa --url.
 */
function writeEnvironment(string $source, string $target, string $slug, ?string $url): string
{
    $envFile = $target . DIRECTORY_SEPARATOR . '.env';
    if (is_file($envFile)) {
        $content = (string) file_get_contents($envFile);
        if ($url !== null) {
            $content = setEnvValue($content, 'APP_URL', $url);
            file_put_contents($envFile, $content);
        }

        return (string) (Dotenv::parse($content)['APP_URL'] ?? '');
    }

    $original = (string) file_get_contents($source . DIRECTORY_SEPARATOR . '.env');
    $url ??= defaultUrl((string) (Dotenv::parse($original)['APP_URL'] ?? ''), $slug);
    $content = preg_replace('/^APP_DEFAULT_TENANT=.*\R?/m', '', $original) ?? $original;
    $content = setEnvValue($content, 'APP_URL', $url);
    $content = setEnvValue($content, 'APP_TENANT', $slug);
    $content = "# Copia propia del conjunto «{$slug}», creada por bin/deploy-conjunto.php.\n"
        . "# APP_TENANT fija el conjunto: esta copia lo atiende con cualquier dominio que apunte a public/.\n"
        . $content;
    file_put_contents($envFile, $content);

    return $url;
}

/** Dirección local por defecto: la del original, cambiando /public por /conjuntos/{slug}/public. */
function defaultUrl(string $originalUrl, string $slug): string
{
    $base = rtrim($originalUrl, '/');
    if (str_ends_with(strtolower($base), '/public')) {
        $base = substr($base, 0, -strlen('/public'));
    }

    return $base . '/' . Tenant::SITES_DIRECTORY . '/' . $slug . '/public';
}

function setEnvValue(string $content, string $name, string $value): string
{
    $line = $name . '=' . $value;
    $pattern = '/^' . preg_quote($name, '/') . '=.*$/m';
    if (preg_match($pattern, $content) === 1) {
        return preg_replace_callback($pattern, static fn (): string => $line, $content) ?? $content;
    }

    return rtrim($content, "\r\n") . "\n" . $line . "\n";
}

function codeVersion(string $projectRoot): string
{
    $git = static fn (string $arguments): string => trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' ' . $arguments . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
    $commit = $git('rev-parse --short HEAD');
    if ($commit === '') {
        return 'desconocido';
    }

    return $git('status --porcelain') === '' ? $commit : $commit . ' + cambios sin commit';
}

/**
 * @return array{slug: ?string, all: bool, url: ?string}
 */
function parseArguments(array $arguments): array
{
    $options = ['slug' => null, 'all' => false, 'url' => null];
    foreach ($arguments as $argument) {
        if ($argument === '--all') {
            $options['all'] = true;
        } elseif (str_starts_with($argument, '--url=')) {
            $url = rtrim(substr($argument, strlen('--url=')), '/');
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
                fail('La dirección de --url no es válida: ' . $url);
            }
            $options['url'] = $url;
        } elseif (!str_starts_with($argument, '-') && $options['slug'] === null) {
            $options['slug'] = strtolower($argument);
        } else {
            fail('Argumento no reconocido: ' . $argument);
        }
    }
    if (($options['slug'] === null) === !$options['all']) {
        fail("Uso: php bin/deploy-conjunto.php <slug> [--url=https://dominio]\n     php bin/deploy-conjunto.php --all");
    }

    return $options;
}

/**
 * @return list<array{id: int, slug: string, name: string}>
 */
function selectTenants(?string $slug, bool $all): array
{
    if (!$all && !Tenant::isValidSlug((string) $slug)) {
        fail('El identificador del conjunto no es válido: ' . $slug);
    }
    $sql = 'SELECT id, slug, name FROM tenants' . ($all ? ' ORDER BY slug' : ' WHERE slug = ?');
    $statement = Database::connection()->prepare($sql);
    $statement->execute($all ? [] : [$slug]);
    $tenants = array_map(
        static fn (array $row): array => ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'name' => (string) $row['name']],
        $statement->fetchAll()
    );
    if ($tenants === []) {
        fail($all ? 'No hay conjuntos registrados.' : 'No existe un conjunto con el identificador ' . $slug . '.');
    }

    return $tenants;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
