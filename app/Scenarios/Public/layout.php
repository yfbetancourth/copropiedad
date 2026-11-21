<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isHomePage = ($viewName ?? '') === 'Home/index';
$settings = is_array($settings ?? null) ? $settings : [];
$galleryImages = is_array($galleryImages ?? null) ? $galleryImages : [];
$address = trim((string) ($settings['address'] ?? ''));
$appUrl = rtrim(\App\Core\Tenant::absoluteUrl('/'), '/');
$classifiedsEnabled = \App\Core\ModuleCatalog::enabled('clasificados');
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
if ($address !== '') {
    $structuredSite['about'] = ['@type' => 'Place', 'name' => $propertyName, 'address' => $address];
}
// En la portada los enlaces son anclas; desde otras páginas vuelven a la sección de la portada.
$homeLink = $isHomePage ? '' : $basePath . '/';
$navLinks = [
    ['Comunidad', $homeLink . '#comunidad', false],
    ['Espacios', $homeLink . '#espacios', false],
    ['Ubicación', $homeLink . '#ubicacion', false],
];
if ($classifiedsEnabled) {
    $navLinks[] = ['Clasificados', $isHomePage ? '#clasificados' : $basePath . '/clasificados', !$isHomePage];
}
$loginUrl = $basePath . '/login?destino=usuario';
$brandIcon = '<svg viewBox="0 0 36 36" focusable="false"><path d="M5 29V14.5L18 6l13 8.5V29H5Z"/><path d="M12 29V18h12v11M9.5 15h17"/></svg>';
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
    <meta name="theme-color" content="#f6f4ee">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="es_CO">
    <meta property="og:site_name" content="<?= $escape($propertyName) ?>">
    <meta property="og:title" content="<?= $escape($pageTitle . ' | ' . $propertyName) ?>">
    <meta property="og:description" content="<?= $escape($metaDescription) ?>">
    <?php if ($canonicalUrl !== ''): ?><meta property="og:url" content="<?= $escape($canonicalUrl) ?>"><?php endif; ?>
    <?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= $escape($ogImage) ?>"><?php endif; ?>
    <?php if ($canonicalUrl !== ''): ?><meta name="twitter:card" content="<?= $ogImage !== '' ? 'summary_large_image' : 'summary' ?>"><?php endif; ?>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/inter@5.0.18/index.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/fraunces@5.0.21/wght.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/fraunces@5.0.21/wght-italic.css">
    <link rel="stylesheet" href="<?= $escape($basePath) ?>/assets/portada/css/public.css?v=2">
    <?php if ($isHomePage): ?>
        <link rel="preload" as="image" href="<?= $escape($basePath . ($galleryImages[0]['url'] ?? '/assets/portada/images/community-placeholder.svg')) ?>" fetchpriority="high">
        <script type="application/ld+json"><?= json_encode($structuredSite, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>
</head>
<body class="<?= $isHomePage ? 'home-page' : 'classifieds-page' ?>">
<a class="skip-link" href="#main-content">Saltar al contenido</a>
<header class="site-header">
    <div class="site-header-inner">
        <a class="site-brand" href="<?= $escape($basePath) ?>/" aria-label="Inicio: <?= $escape($propertyName) ?>">
            <span class="brand-mark" aria-hidden="true"><?= $brandIcon ?></span>
            <span class="brand-text"><?= $escape($propertyName) ?><small>Vivir en comunidad</small></span>
        </a>
        <nav class="site-nav" aria-label="Navegación principal">
            <?php foreach ($navLinks as [$label, $href, $current]): ?>
                <a href="<?= $escape($href) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= $escape($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <a class="header-login" href="<?= $escape($loginUrl) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
            <span>Portal<span class="login-long"> de residentes</span></span>
        </a>
    </div>
</header>
<main id="main-content" class="site-main" tabindex="-1">
    <?= $content ?>
</main>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-about">
            <a class="site-brand footer-brand" href="<?= $escape($basePath) ?>/">
                <span class="brand-mark" aria-hidden="true"><?= $brandIcon ?></span>
                <span class="brand-text"><?= $escape($propertyName) ?><small>Vivir en comunidad</small></span>
            </a>
            <p>Un buen lugar se construye entre todos.</p>
        </div>
        <nav class="footer-links" aria-label="Enlaces del pie de página">
            <h2>Explora</h2>
            <a href="<?= $escape($basePath) ?>/">Inicio</a>
            <?php if ($classifiedsEnabled): ?><a href="<?= $escape($basePath) ?>/clasificados">Clasificados</a><?php endif; ?>
            <a href="<?= $escape($loginUrl) ?>">Portal de residentes</a>
        </nav>
        <?php if ($address !== ''): ?>
            <div class="footer-links">
                <h2>Encuéntranos</h2>
                <p><?= $escape($address) ?></p>
                <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= $escape(rawurlencode($address)) ?>" target="_blank" rel="noopener noreferrer">Cómo llegar <span aria-hidden="true">↗</span><span class="visually-hidden"> (se abre en una pestaña nueva)</span></a>
            </div>
        <?php endif; ?>
    </div>
    <div class="footer-bottom">
        <span>© <?= date('Y') ?> <?= $escape($propertyName) ?></span>
        <span>Clasificados publicados por los residentes.</span>
    </div>
</footer>
</body>
</html>
