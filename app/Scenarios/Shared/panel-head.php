<?php

declare(strict_types=1);

/** Encabezado HTML común del panel administrativo y del portal (AdminLTE + Bootstrap). */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?> | <?= $escape($propertyName) ?></title>
    <?php $vendor = $escape(\App\Core\Url::publicBasePath()) . '/assets/vendor'; // Servidos localmente: sin terceros. ?>
    <link rel="stylesheet" href="<?= $vendor ?>/source-sans-3@5.0.12/index.css">
    <link rel="stylesheet" href="<?= $vendor ?>/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $vendor ?>/admin-lte@4.10.2/css/adminlte.min.css">
</head>
