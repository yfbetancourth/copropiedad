<?php

declare(strict_types=1);

use App\Core\Session;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$userPortalName = isset($user['username']) && is_string($user['username']) ? $user['username'] : '';

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?> | <?= $escape($propertyName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0/dist/css/adminlte.min.css">
</head>
<body class="bg-body-tertiary">
<div class="d-flex min-vh-100 flex-column">
    <header class="navbar navbar-expand bg-body shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-semibold text-wrap" href="<?= $escape($basePath) ?>/usuario">
                <i class="bi bi-buildings me-2" aria-hidden="true"></i><?= $escape($propertyName) ?>
            </a>
            <div class="d-flex align-items-center gap-3 ms-auto">
                <span class="text-secondary d-none d-sm-inline"><?= $escape($userPortalName) ?></span>
                <form method="post" action="<?= $escape($basePath) ?>/logout">
                    <input type="hidden" name="_token" value="<?= $escape(Session::csrfToken()) ?>">
                    <button class="btn btn-outline-secondary btn-sm" type="submit">Cerrar sesión</button>
                </form>
            </div>
        </div>
    </header>
    <main class="flex-grow-1">
        <div class="container-fluid py-4">
            <?= $content ?>
        </div>
    </main>
    <footer class="border-top bg-body py-3 mt-auto">
        <div class="container-fluid d-flex flex-wrap justify-content-between gap-2 text-secondary small">
            <span><?= $escape($propertyName) ?></span>
            <a href="<?= $escape($basePath) ?>/usuario">Panel de Usuario</a>
        </div>
    </footer>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0/dist/js/adminlte.min.js"></script>
</body>
</html>
