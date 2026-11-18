<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isHomePage = ($viewName ?? '') === 'Home/index';
$appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
$canonicalUrl = is_string($canonicalUrl ?? null) && $canonicalUrl !== ''
    ? $canonicalUrl
    : $appUrl . '/clasificados';
$metaDescription = is_string($metaDescription ?? null) && $metaDescription !== ''
    ? $metaDescription
    : 'Conoce la comunidad, la ubicación y los clasificados de ' . $propertyName . '.';
$ogImage = $appUrl !== '' && !empty($galleryImages[0]['url'])
    ? $appUrl . $galleryImages[0]['url']
    : '';
$structuredSite = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => $propertyName,
    'url' => $appUrl !== '' ? $appUrl . '/' : '',
    'inLanguage' => 'es-CO',
];
if (trim((string) ($settings['address'] ?? '')) !== '') {
    $structuredSite['about'] = [
        '@type' => 'Place',
        'name' => $propertyName,
        'address' => trim((string) $settings['address']),
    ];
}
?>
<!doctype html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?> | <?= $escape($propertyName) ?></title>
    <meta name="description" content="<?= $escape($metaDescription) ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?= $escape($canonicalUrl) ?>"><?php endif; ?>
    <meta name="theme-color" content="#f5f4ef">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="es_CO">
    <meta property="og:site_name" content="<?= $escape($propertyName) ?>">
    <meta property="og:title" content="<?= $escape($pageTitle . ' | ' . $propertyName) ?>">
    <meta property="og:description" content="<?= $escape($metaDescription) ?>">
    <?php if ($canonicalUrl !== ''): ?><meta property="og:url" content="<?= $escape($canonicalUrl) ?>"><?php endif; ?>
    <?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= $escape($ogImage) ?>"><?php endif; ?>
    <?php if ($canonicalUrl !== ''): ?><meta name="twitter:card" content="<?= $ogImage !== '' ? 'summary_large_image' : 'summary' ?>"><?php endif; ?>
    <link rel="stylesheet" href="<?= $escape($basePath) ?>/assets/portada/css/public.css">
    <?php if ($isHomePage): ?>
        <link rel="preload" as="image" href="<?= $escape($basePath . ($galleryImages[0]['url'] ?? '/assets/portada/images/community-placeholder.svg')) ?>" fetchpriority="high">
        <script type="application/ld+json"><?= json_encode($structuredSite, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
</head>
<body class="<?= $isHomePage ? 'home-page' : 'classifieds-page' ?>">
<?php if ($isHomePage): ?>
<header class="site-header">
    <div class="site-header-inner">
        <a class="site-brand" href="<?= $escape($basePath) ?>/" aria-label="Inicio: <?= $escape($propertyName) ?>">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 36 36" focusable="false"><path d="M5 29V14.5L18 6l13 8.5V29H5Z"/><path d="M12 29V18h12v11M9.5 15h17"/></svg></span>
            <span><?= $escape($propertyName) ?><small>VIVIR EN COMUNIDAD</small></span>
        </a>
        <nav class="site-nav" aria-label="Navegación principal">
            <a href="#comunidad">Comunidad</a><a href="#espacios">Espacios</a><a href="#ubicacion">Ubicación</a><a href="#clasificados">Clasificados</a>
        </nav>
        <a class="header-login" href="<?= $escape($basePath) ?>/login?destino=usuario">Portal de usuario <span aria-hidden="true">↗</span></a>
    </div>
</header>
<?php else: ?>
<header class="catalog-header">
    <div class="catalog-header-inner">
        <a class="site-brand" href="<?= $escape($basePath) ?>/">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 36 36" focusable="false"><path d="M5 29V14.5L18 6l13 8.5V29H5Z"/><path d="M12 29V18h12v11M9.5 15h17"/></svg></span>
            <span><?= $escape($propertyName) ?><small>VIVIR EN COMUNIDAD</small></span>
        </a>
        <a class="header-login" href="<?= $escape($basePath) ?>/login?destino=usuario">Panel de usuario <span aria-hidden="true">↗</span></a>
    </div>
</header>
<?php endif; ?>
<main id="main-content" class="<?= $isHomePage ? 'site-main' : 'container-fluid' ?>">
    <?= $content ?>
</main>
<?php if ($isHomePage): ?>
<footer class="site-footer">
    <div class="footer-inner"><a class="site-brand footer-brand" href="<?= $escape($basePath) ?>/"><span class="brand-mark" aria-hidden="true">⌂</span><span><?= $escape($propertyName) ?><small>VIVIR EN COMUNIDAD</small></span></a><p>Un buen lugar se construye entre todos.</p><a href="<?= $escape($basePath) ?>/login?destino=usuario">Acceso de residentes ↗</a></div>
    <div class="footer-bottom"><span>© <?= date('Y') ?> <?= $escape($propertyName) ?></span><a href="<?= $escape($basePath) ?>/clasificados">Clasificados</a></div>
</footer>
<?php else: ?>
<footer class="catalog-footer"><span><?= $escape($propertyName) ?> · Clasificados publicados por usuarios.</span><a href="<?= $escape($basePath) ?>/">Volver al inicio</a></footer>
<?php endif; ?>
</body>
</html>
