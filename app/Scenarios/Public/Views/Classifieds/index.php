<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$operationLabels = ['sale' => 'En venta', 'purchase' => 'Busca comprar'];
$propertyTypeLabels = [
    'house' => 'Casa',
    'apartment' => 'Apartamento',
    'commercial' => 'Local u oficina',
    'other' => 'Otro',
];
?>
<section class="catalog-shell">
    <header class="catalog-intro">
        <div><p class="eyebrow"><span class="eyebrow-line"></span> Comunidad</p><h1>Clasificados de inmuebles</h1><p>Ofertas en venta y búsquedas de compra compartidas por los residentes de <?= $escape($propertyName) ?>.</p></div>
        <a class="button button-dark" href="<?= $escape($basePath) ?>/login?destino=usuario">Publicar desde mi panel <span aria-hidden="true">↗</span></a>
    </header>

    <?php if ($listings === []): ?>
        <div class="catalog-empty"><span aria-hidden="true">✳</span><h2>Aún no hay clasificados publicados.</h2><p>Vuelve pronto o inicia sesión en el portal para compartir una oferta o búsqueda.</p><a class="button button-outline" href="<?= $escape($basePath) ?>/login?destino=usuario">Entrar al portal de usuario <span aria-hidden="true">↗</span></a></div>
    <?php else: ?>
        <div class="catalog-grid">
            <?php foreach ($listings as $listing): ?>
                <?php
                $operation = $operationLabels[$listing['operation']] ?? 'Clasificado';
                $propertyType = $propertyTypeLabels[$listing['property_type']] ?? 'Inmueble';
                $amount = is_numeric($listing['price'] ?? null)
                    ? '$ ' . number_format((float) $listing['price'], 0, ',', '.') . ' COP'
                    : null;
                $cover = $listing['images'][0] ?? null;
                ?>
                <article class="catalog-card">
                    <?php if ($cover !== null): ?>
                        <div class="catalog-card-image">
                            <img src="<?= $escape($basePath) ?>/clasificados/imagen?id=<?= (int) $cover['id'] ?>"
                                 alt="<?= $escape('Foto del inmueble: ' . $listing['title']) ?>"
                                 width="720" height="500" loading="lazy" decoding="async">
                            <span class="catalog-operation"><?= $escape($operation) ?></span>
                            <?php if (count($listing['images']) > 1): ?><span class="catalog-image-count" aria-label="<?= count($listing['images']) ?> fotos">▧ <?= count($listing['images']) ?> fotos</span><?php endif; ?>
                        </div>
                        <?php if (count($listing['images']) > 1): ?>
                            <div class="catalog-thumbnails" aria-label="Más fotos de <?= $escape($listing['title']) ?>">
                                <?php foreach (array_slice($listing['images'], 1, 4) as $image): ?>
                                    <img src="<?= $escape($basePath) ?>/clasificados/imagen?id=<?= (int) $image['id'] ?>" alt="Otra foto de <?= $escape($listing['title']) ?>" width="160" height="110" loading="lazy" decoding="async">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="catalog-card-body">
                        <div class="catalog-labels"><span><?= $escape($propertyType) ?></span><span><?= $escape($operation) ?></span></div>
                        <h2><?= $escape($listing['title']) ?></h2>
                        <p class="catalog-location"><span aria-hidden="true">⌖</span> <?= $escape($listing['location']) ?></p>
                        <?php if ($amount !== null): ?><p class="catalog-price"><?= $escape($listing['operation'] === 'purchase' ? 'Presupuesto ' : '') ?><?= $escape($amount) ?></p><?php endif; ?>
                        <p class="catalog-description"><?= nl2br($escape($listing['description'])) ?></p>
                        <?php if ($listing['contact_name'] !== null || $listing['contact_email'] !== null || $listing['contact_phone'] !== null): ?>
                            <div class="catalog-contact"><h3>Contacto autorizado</h3>
                                <?php if ($listing['contact_name'] !== null): ?><p><?= $escape($listing['contact_name']) ?></p><?php endif; ?>
                                <?php if ($listing['contact_email'] !== null): ?><p><a href="mailto:<?= $escape($listing['contact_email']) ?>"><?= $escape($listing['contact_email']) ?></a></p><?php endif; ?>
                                <?php if ($listing['contact_phone'] !== null): ?><p><a href="tel:<?= $escape(preg_replace('/[^0-9+]/', '', $listing['contact_phone']) ?: '') ?>"><?= $escape($listing['contact_phone']) ?></a></p><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="catalog-no-contact">El usuario no autorizó publicar datos de contacto.</p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
