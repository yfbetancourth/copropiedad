<?php

declare(strict_types=1);

use App\Core\Database;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$password = getenv('SUPERADMIN_PASSWORD');
if (!is_string($password) || strlen($password) < 12) {
    fwrite(STDERR, "Define SUPERADMIN_PASSWORD temporalmente en el entorno (minimo 12 caracteres) y vuelve a ejecutar este comando.\n");
    exit(1);
}

$pdo = Database::connection();
$roleId = $pdo->query(
    "SELECT id FROM admin_roles WHERE name = 'Superadmin' AND is_system = 1 LIMIT 1"
)->fetchColumn();

if ($roleId === false) {
    fwrite(STDERR, "Aplica la migracion 002_create_configuration_roles.sql antes de crear la cuenta Superadmin.\n");
    exit(1);
}

$statement = $pdo->prepare(
    'INSERT INTO admin_users (username, email, password_hash, role_id)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
);
$statement->execute([
    'Superadmin',
    'johnfbetancourth@gmail.com',
    password_hash($password, PASSWORD_DEFAULT),
    $roleId,
]);

if ($statement->rowCount() === 1) {
    fwrite(STDOUT, "Cuenta Superadmin creada; la contrasena se almaceno cifrada.\n");
} else {
    fwrite(STDERR, "Ya existe una cuenta con ese usuario o correo; no se modifico ninguna cuenta.\n");
    exit(2);
}
