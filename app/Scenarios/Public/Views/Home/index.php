<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$name = trim((string) ($settings['property_name'] ?? '')) ?: $propertyName;
$address = trim((string) ($settings['address'] ?? ''));
$mapsUrl = $address !== ''
    ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address)
    : '';
$operationLabels = ['sale' => 'En venta', 'purchase' => 'Busca comprar'];
$propertyTypeLabels = [
    'house' => 'Casa',
    'apartment' => 'Apartamento',
    'commercial' => 'Local u oficina',
    'other' => 'Inmueble',
];
?>
<section class="hero-section" id="comunidad" aria-labelledby="home-title">
    <div class="hero-copy">
        <p class="eyebrow"><span class="eyebrow-line"></span> Un lugar para vivir mejor</p>
        <h1 id="home-title">La vida se siente distinta <em>cuando es en comunidad.</em></h1>
        <p class="hero-lede"><?= $escape($name) ?> es más que un lugar para vivir. Es el espacio que compartimos, cuidamos y hacemos nuestro cada día.</p>
        <?php if ($address !== ''): ?>
            <p class="hero-address"><span class="pin-icon" aria-hidden="true">⌖</span><?= $escape($address) ?></p>
        <?php endif; ?>
        <div class="hero-actions">
            <a class="button button-dark" href="<?= $escape($basePath) ?>/login?destino=usuario">Entrar a mi portal <span aria-hidden="true">↗</span></a>
            <?php if ($classifiedsEnabled ?? true): ?><a class="text-link" href="#clasificados">Explorar clasificados <span aria-hidden="true">↓</span></a><?php endif; ?>
        </div>
        <div class="hero-footnote"><span class="avatar-stack" aria-hidden="true"><i></i><i></i><i></i></span><span>Un espacio compartido. Muchas formas de sentirse en casa.</span></div>
    </div>
    <div class="hero-visual" aria-label="Espacios de <?= $escape($name) ?>">
        <?php if ($galleryImages !== []): ?>
            <img class="hero-photo" src="<?= $escape($basePath . $galleryImages[0]['url']) ?>" alt="Espacio de <?= $escape($name) ?>" width="1100" height="900" fetchpriority="high" decoding="async">
            <div class="photo-note"><span class="photo-note-dot"></span> Nuestro lugar, todos los días</div>
            <?php if (isset($galleryImages[1])): ?>
                <div class="hero-inset"><img src="<?= $escape($basePath . $galleryImages[1]['url']) ?>" alt="Otro espacio de <?= $escape($name) ?>" width="400" height="300" loading="lazy" decoding="async"><span>Hecho para compartir</span></div>
            <?php endif; ?>
        <?php else: ?>
            <img class="hero-photo placeholder-photo" src="<?= $escape($basePath) ?>/assets/portada/images/community-placeholder.svg" alt="Ilustración de espacios de vivienda en comunidad" width="900" height="760" fetchpriority="high" decoding="async">
            <div class="photo-note"><span class="photo-note-dot"></span> Nuestro lugar, todos los días</div>
        <?php endif; ?>
        <div class="hero-stamp"><span>HOGAR</span><b>+</b><span>COMUNIDAD</span></div>
    </div>
    <a class="scroll-cue" href="#espacios" aria-label="Descubre los espacios">DESLIZA <span aria-hidden="true">↓</span></a>
</section>

<section class="intro-strip" aria-label="Nuestra comunidad">
    <span>Un buen vecino</span><i></i><span>Un espacio cuidado</span><i></i><span>Una comunidad que crece</span><b aria-hidden="true">✳</b>
</section>

<?php if ($galleryImages !== []): ?>
<section class="section-block spaces-section" id="espacios" aria-labelledby="spaces-title">
    <div class="section-heading">
        <div><p class="eyebrow"><span class="eyebrow-line"></span> Nuestro entorno</p><h2 id="spaces-title">Un lugar que <em>se siente propio.</em></h2></div>
        <p>Los pequeños momentos también hacen hogar: compartir, descansar, encontrarse y disfrutar los espacios que son de todos.</p>
    </div>
    <div class="gallery-grid gallery-count-<?= min(4, count($galleryImages)) ?>">
        <?php foreach (array_slice($galleryImages, 0, 4) as $index => $image): ?>
            <figure class="gallery-tile gallery-tile-<?= $index + 1 ?>">
                <img src="<?= $escape($basePath . $image['url']) ?>" alt="Espacio <?= $index + 1 ?> de <?= $escape($name) ?>" width="<?= $index === 0 ? '1000' : '700' ?>" height="<?= $index === 0 ? '700' : '520' ?>" loading="lazy" decoding="async">
                <figcaption><span><?= sprintf('%02d', $index + 1) ?></span> <?= ['Momentos para compartir', 'Un respiro en casa', 'La vida en común', 'Cada rincón cuenta'][$index] ?></figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
</section>
<?php else: ?>
<section class="section-block spaces-section" id="espacios" aria-labelledby="spaces-title">
    <div class="section-heading">
        <div><p class="eyebrow"><span class="eyebrow-line"></span> Nuestro entorno</p><h2 id="spaces-title">La comunidad se construye <em>en cada detalle.</em></h2></div>
        <p>Esta es una invitación a encontrarnos, cuidar lo que compartimos y hacer de este lugar un hogar para todos.</p>
    </div>
    <div class="values-grid">
        <article>
            <span class="value-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/><path d="M2.5 19c.8-3 3-4.5 5.5-4.5s4.7 1.5 5.5 4.5M10.5 19c.8-3 3-4.5 5.5-4.5s4.7 1.5 5.5 4.5"/></svg></span>
            <span class="value-number" aria-hidden="true">01</span><h3>Compartir</h3><p>Los buenos momentos crecen cuando se viven en compañía.</p>
        </article>
        <article>
            <span class="value-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z"/></svg></span>
            <span class="value-number" aria-hidden="true">02</span><h3>Cuidar</h3><p>Un espacio común se siente mejor cuando todos lo hacemos nuestro.</p>
        </article>
        <article>
            <span class="value-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M9 15 15 9M8 12l-2 2a3.5 3.5 0 0 0 5 5l2-2M16 12l2-2a3.5 3.5 0 0 0-5-5l-2 2"/></svg></span>
            <span class="value-number" aria-hidden="true">03</span><h3>Conectar</h3><p>Pequeños encuentros que hacen más cercana la vida de cada día.</p>
        </article>
    </div>
</section>
<?php endif; ?>

<section class="community-band">
    <div class="community-mark" aria-hidden="true">✳</div>
    <div><p class="eyebrow">Una comunidad, muchas historias</p><h2>Lo mejor de vivir aquí<br><em>lo hacemos entre todos.</em></h2></div>
    <a class="button button-light" href="<?= $escape($basePath) ?>/login?destino=usuario">Conoce tu portal <span aria-hidden="true">↗</span></a>
</section>

<section class="location-section section-block" id="ubicacion" aria-labelledby="location-title">
    <div class="location-art" aria-hidden="true"><div class="map-orbit orbit-one"></div><div class="map-orbit orbit-two"></div><span class="map-pin">⌖</span><span class="map-label label-one">TU LUGAR</span><span class="map-label label-two">TU COMUNIDAD</span><div class="map-path"></div></div>
    <div class="location-copy">
        <p class="eyebrow"><span class="eyebrow-line"></span> Cerca de lo que importa</p>
        <h2 id="location-title">Aquí empieza <em>tu lugar.</em></h2>
        <?php if ($address !== ''): ?>
            <p class="location-address"><?= $escape($address) ?></p>
            <p class="location-help">Encuéntranos fácilmente y descubre cómo llegar.</p>
            <a class="button button-dark" href="<?= $escape($mapsUrl) ?>" target="_blank" rel="noopener noreferrer">Ver ubicación en Google Maps <span aria-hidden="true">↗</span></a>
        <?php else: ?>
            <p class="location-help">La dirección de la copropiedad aparecerá aquí cuando esté configurada en el panel administrativo.</p>
        <?php endif; ?>
    </div>
</section>

<?php if ($classifiedsEnabled ?? true): ?>
<section class="classifieds-section section-block" id="clasificados" aria-labelledby="classifieds-title">
    <div class="section-heading">
        <div><p class="eyebrow"><span class="eyebrow-line"></span> Oportunidades de la comunidad</p><h2 id="classifieds-title">Algo bueno puede <em>estar más cerca.</em></h2></div>
        <a class="text-link" href="<?= $escape($basePath) ?>/clasificados">Ver todos los clasificados <span aria-hidden="true">↗</span></a>
    </div>
    <?php if ($listings !== []): ?>
        <div class="listing-grid">
            <?php foreach ($listings as $listing): ?>
                <?php
                $image = $listing['images'][0] ?? null;
                $operation = $operationLabels[$listing['operation']] ?? 'Clasificado';
                $kind = $propertyTypeLabels[$listing['property_type']] ?? 'Inmueble';
                $price = is_numeric($listing['price'] ?? null) ? '$ ' . number_format((float) $listing['price'], 0, ',', '.') : null;
                ?>
                <article class="listing-card">
                    <a class="listing-image-link" href="<?= $escape($basePath) ?>/clasificados" aria-label="Ver <?= $escape($listing['title']) ?>">
                        <?php if ($image !== null): ?>
                            <img src="<?= $escape($basePath) ?>/clasificados/imagen?id=<?= (int) $image['id'] ?>" alt="<?= $escape('Foto del inmueble: ' . $listing['title']) ?>" width="720" height="500" loading="lazy" decoding="async">
                        <?php else: ?><div class="listing-image-empty" aria-hidden="true">⌂</div><?php endif; ?>
                        <span class="listing-type"><?= $escape($operation) ?></span>
                    </a>
                    <div class="listing-copy"><p class="listing-kicker"><?= $escape($kind) ?> <span>·</span> <?= $escape($listing['location']) ?></p><h3><a href="<?= $escape($basePath) ?>/clasificados"><?= $escape($listing['title']) ?></a></h3>
                        <?php if ($price !== null): ?><strong><?= $escape($listing['operation'] === 'purchase' ? 'Presupuesto ' : '') ?><?= $escape($price) ?> <small>COP</small></strong><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="classifieds-empty"><span class="empty-spark" aria-hidden="true">✳</span><div><h3>Las buenas oportunidades empiezan cerca.</h3><p>Pronto podrás descubrir publicaciones de venta y búsqueda compartidas por los residentes.</p></div><a class="button button-outline" href="<?= $escape($basePath) ?>/login?destino=usuario">Publicar desde mi portal <span aria-hidden="true">↗</span></a></div>
    <?php endif; ?>
    <div class="resident-cta"><div><p class="eyebrow">¿Ya haces parte de <?= $escape($name) ?>?</p><h3>Tu comunidad también está en línea.</h3><p>Consulta tu inmueble, revisa tus mensajes y comparte un clasificado desde tu panel.</p></div><a class="button button-dark" href="<?= $escape($basePath) ?>/login?destino=usuario">Entrar al panel de usuario <span aria-hidden="true">↗</span></a></div>
</section>
<?php endif; ?>
