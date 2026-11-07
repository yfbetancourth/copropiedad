<?php

declare(strict_types=1);

use App\Core\Database;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

try {
    Database::connection()->query('SELECT 1');
    fwrite(STDOUT, "Conexion MySQL correcta.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("No fue posible conectar a MySQL: %s\n", $exception->getMessage()));
    exit(1);
}
