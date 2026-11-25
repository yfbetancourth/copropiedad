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
// Textos editables desde Configuración / Sitio público; siempre escapados, {nombre} = nombre del conjunto.
$siteTexts = is_array($siteTexts ?? null) ? $siteTexts : \App\Modules\Configuration\PublicSiteService::defaults();
$text = static fn (string $key): string => nl2br($escape(
    \App\Modules\Configuration\PublicSiteService::fill((string) ($siteTexts[$key] ?? ''), $name)
), false);
$operationLabels = ['sale' => 'En venta', 'rent' => 'En arriendo', 'purchase' => 'Busca comprar'];
$propertyTypeLabels = [
    'house' => 'Casa',
    'apartment' => 'Apartamento',
    'commercial' => 'Local u oficina',
    'other' => 'Inmueble',
];
?>
<section class="hero-section" id="comunidad" aria-labelledby="home-title">
    <div class="hero-copy">
        <p class="eyebrow"><span class="eyebrow-line"></span> <?= $text('hero_eyebrow') ?></p>
        <h1 id="home-title"><?= $text('hero_title') ?> <em><?= $text('hero_title_em') ?></em></h1>
        <p class="hero-lede"><?= $text('hero_lede') ?></p>
        <?php if ($address !== ''): ?>
            <p class="hero-address"><span class="pin-icon" aria-hidden="true">⌖</span><?= $escape($address) ?></p>
        <?php endif; ?>
        <div class="hero-actions">
            <a class="button button-dark" href="<?= $escape($basePath) ?>/login?destino=usuario"><?= $text('hero_button') ?> <span aria-hidden="true">↗</span></a>
            <?php if ($classifiedsEnabled ?? true): ?><a class="text-link" href="#clasificados">Explorar clasificados <span aria-hidden="true">↓</span></a><?php endif; ?>
        </div>
        <div class="hero-footnote"><span class="avatar-stack" aria-hidden="true"><i></i><i></i><i></i></span><span><?= $text('hero_footnote') ?></span></div>
    </div>
    <div class="hero-visual" aria-label="Espacios de <?= $escape($name) ?>">
        <?php if ($galleryImages !== []): ?>
            <img class="hero-photo" src="<?= $escape($basePath . $galleryImages[0]['url']) ?>" alt="Espacio de <?= $escape($name) ?>" width="1100" height="900" fetchpriority="high" decoding="async">
            <div class="photo-note"><span class="photo-note-dot"></span> <?= $text('hero_photo_note') ?></div>
            <?php if (isset($galleryImages[1])): ?>
                <div class="hero-inset"><img src="<?= $escape($basePath . $galleryImages[1]['url']) ?>" alt="Otro espacio de <?= $escape($name) ?>" width="400" height="300" loading="lazy" decoding="async"><span><?= $text('hero_inset_caption') ?></span></div>
            <?php endif; ?>
        <?php else: ?>
            <img class="hero-photo placeholder-photo" src="<?= $escape($basePath) ?>/assets/portada/images/community-placeholder.svg" alt="Ilustración de espacios de vivienda en comunidad" width="900" height="760" fetchpriority="high" decoding="async">
            <div class="photo-note"><span class="photo-note-dot"></span> <?= $text('hero_photo_note') ?></div>
        <?php endif; ?>
        <div class="hero-stamp"><span><?= $text('hero_stamp_1') ?></span><b>+</b><span><?= $text('hero_stamp_2') ?></span></div>
    </div>
    <a class="scroll-cue" href="#espacios" aria-label="Descubre los espacios">DESLIZA <span aria-hidden="true">↓</span></a>
</section>

<section class="intro-strip" aria-label="Nuestra comunidad">
    <span><?= $text('strip_1') ?></span><i></i><span><?= $text('strip_2') ?></span><i></i><span><?= $text('strip_3') ?></span><b aria-hidden="true">✳</b>
</section>

<?php if ($galleryImages !== []): ?>
<section class="section-block spaces-section" id="espacios" aria-labelledby="spaces-title">
    <div class="section-heading">
        <div><p class="eyebrow"><span class="eyebrow-line"></span> <?= $text('spaces_eyebrow') ?></p><h2 id="spaces-title"><?= $text('spaces_title') ?> <em><?= $text('spaces_title_em') ?></em></h2></div>
        <p><?= $text('spaces_text') ?></p>
    </div>
    <div class="gallery-grid gallery-count-<?= min(4, count($galleryImages)) ?>">
        <?php foreach (array_slice($galleryImages, 0, 4) as $index => $image): ?>
            <figure class="gallery-tile gallery-tile-<?= $index + 1 ?>">
                <img src="<?= $escape($basePath . $image['url']) ?>" alt="Espacio <?= $index + 1 ?> de <?= $escape($name) ?>" width="<?= $index === 0 ? '1000' : '700' ?>" height="<?= $index === 0 ? '700' : '520' ?>" loading="lazy" decoding="async">
                <figcaption><span><?= sprintf('%02d', $index + 1) ?></span> <?= $text('caption_' . ($index + 1)) ?></figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
</section>
<?php else: ?>
<section class="section-block spaces-section" id="espacios" aria-labelledby="spaces-title">
    <div class="section-heading">
        <div><p class="eyebrow"><span class="eyebrow-line"></span> <?= $text('values_eyebrow') ?></p><h2 id="spaces-title"><?= $text('values_title') ?> <em><?= $text('values_title_em') ?></em></h2></div>
        <p><?= $text('values_text') ?></p>
    </div>
    <div class="values-grid">
        <article>
            <span class="value-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/><path d="M2.5 19c.8-3 3-4.5 5.5-4.5s4.7 1.5 5.5 4.5M10.5 19c.8-3 3-4.5 5.5-4.5s4.7 1.5 5.5 4.5"/></svg></span>
            <span class="value-number" aria-hidden="true">01</span><h3><?= $text('value_1_title') ?></h3><p><?= $text('value_1_text') ?></p>
        </article>
        <article>
            <span class="value-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z"/></svg></span>
            <span class="value-number" aria-hidden="true">02</span><h3><?= $text('value_2_title') ?></h3><p><?= $text('value_2_text') ?></p>
        </article>
        <article>
            <span class="value-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M9 15 15 9M8 12l-2 2a3.5 3.5 0 0 0 5 5l2-2M16 12l2-2a3.5 3.5 0 0 0-5-5l-2 2"/></svg></span>
            <span class="value-number" aria-hidden="true">03</span><h3><?= $text('value_3_title') ?></h3><p><?= $text('value_3_text') ?></p>
        </article>
    </div>
</section>
<?php endif; ?>

<section class="community-band">
    <div class="community-mark" aria-hidden="true">✳</div>
    <div><p class="eyebrow"><?= $text('band_eyebrow') ?></p><h2><?= $text('band_title') ?><br><em><?= $text('band_title_em') ?></em></h2></div>
    <a class="button button-light" href="<?= $escape($basePath) ?>/login?destino=usuario"><?= $text('band_button') ?> <span aria-hidden="true">↗</span></a>
</section>

<section class="location-section section-block" id="ubicacion" aria-labelledby="location-title">
    <div class="location-art" aria-hidden="true"><div class="map-orbit orbit-one"></div><div class="map-orbit orbit-two"></div><span class="map-pin">⌖</span><span class="map-label label-one">TU LUGAR</span><span class="map-label label-two">TU COMUNIDAD</span><div class="map-path"></div></div>
    <div class="location-copy">
        <p class="eyebrow"><span class="eyebrow-line"></span> <?= $text('location_eyebrow') ?></p>
        <h2 id="location-title"><?= $text('location_title') ?> <em><?= $text('location_title_em') ?></em></h2>
        <?php if ($address !== ''): ?>
            <p class="location-address"><?= $escape($address) ?></p>
            <p class="location-help"><?= $text('location_text') ?></p>
            <a class="button button-dark" href="<?= $escape($mapsUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $text('location_button') ?> <span aria-hidden="true">↗</span><span class="visually-hidden"> (se abre en una pestaña nueva)</span></a>
        <?php else: ?>
            <p class="location-help">La dirección de la copropiedad aparecerá aquí cuando esté configurada en el panel administrativo.</p>
        <?php endif; ?>
    </div>
</section>

<?php if ($classifiedsEnabled ?? true): ?>
<section class="classifieds-section section-block" id="clasificados" aria-labelledby="classifieds-title">
    <div class="section-heading">
        <div><p class="eyebrow"><span class="eyebrow-line"></span> <?= $text('classifieds_eyebrow') ?></p><h2 id="classifieds-title"><?= $text('classifieds_title') ?> <em><?= $text('classifieds_title_em') ?></em></h2></div>
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
                        <?php if ($price !== null): ?><strong><?= $escape($listing['operation'] === 'purchase' ? 'Presupuesto ' : '') ?><?= $escape($price) ?> <small>COP<?= $listing['operation'] === 'rent' ? ' / mes' : '' ?></small></strong><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="classifieds-empty"><span class="empty-spark" aria-hidden="true">✳</span><div><h3><?= $text('classifieds_empty_title') ?></h3><p><?= $text('classifieds_empty_text') ?></p></div><a class="button button-outline" href="<?= $escape($basePath) ?>/login?destino=usuario">Publicar desde mi portal <span aria-hidden="true">↗</span></a></div>
    <?php endif; ?>
    <div class="resident-cta"><div><p class="eyebrow"><?= $text('resident_cta_eyebrow') ?></p><h3><?= $text('resident_cta_title') ?></h3><p><?= $text('resident_cta_text') ?></p></div><a class="button button-dark" href="<?= $escape($basePath) ?>/login?destino=usuario"><?= $text('resident_cta_button') ?> <span aria-hidden="true">↗</span></a></div>
</section>
<?php endif; ?>

<?php if (is_array($contactForm ?? null)): ?>
<?php
$contactErrors = $contactForm['errors'];
$contactOld = $contactForm['old'];
$contactValue = static fn (string $key): string => $escape($contactOld[$key] ?? '');
$contactInvalid = static fn (string $key): string => isset($contactErrors[$key]) ? ' aria-invalid="true" aria-describedby="contact-' . $key . '-error"' : '';
$contactError = static fn (string $key): string => isset($contactErrors[$key])
    ? '<p class="field-error" id="contact-' . $key . '-error">' . $escape($contactErrors[$key]) . '</p>'
    : '';
?>
<section class="contact-section section-block" id="contacto" aria-labelledby="contact-title">
    <div class="contact-copy">
        <p class="eyebrow"><span class="eyebrow-line"></span> Contacto</p>
        <h2 id="contact-title">Escríbenos <em>tu mensaje</em></h2>
        <p>¿Tienes una pregunta sobre <?= $escape($name) ?>? Déjanos tus datos y la administración te responderá por correo.</p>
        <p class="contact-legal">Tratamos tus datos según la <a href="<?= $escape($basePath) ?>/politica-de-datos">Política de tratamiento de datos personales</a>. Puedes consultar, actualizar o suprimir tus datos y revocar tu autorización en cualquier momento.</p>
    </div>
    <div class="contact-card">
        <?php if ($contactForm['sent']): ?>
            <div class="form-notice form-notice-success" role="status" tabindex="-1" data-focus-on-load>
                <strong>¡Mensaje enviado!</strong> Gracias por escribirnos. La administración te responderá al correo que indicaste.
            </div>
        <?php endif; ?>
        <?php if (isset($contactErrors['form'])): ?>
            <div class="form-notice form-notice-error" role="alert"><?= $escape($contactErrors['form']) ?></div>
        <?php elseif ($contactErrors !== []): ?>
            <div class="form-notice form-notice-error" role="alert" tabindex="-1" data-focus-on-load>Revisa los campos marcados para enviar tu mensaje.</div>
        <?php endif; ?>
        <form class="contact-form" method="post" action="<?= $escape($basePath) ?>/contacto#contacto" novalidate>
            <input type="hidden" name="token" value="<?= $escape($contactForm['token']) ?>">
            <div class="contact-trap" aria-hidden="true">
                <label for="contact-<?= $escape($contactForm['honeypot']) ?>">No llenes este campo</label>
                <input type="text" id="contact-<?= $escape($contactForm['honeypot']) ?>" name="<?= $escape($contactForm['honeypot']) ?>" tabindex="-1" autocomplete="off">
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="contact-name">Nombre completo</label>
                    <input type="text" id="contact-name" name="name" value="<?= $contactValue('name') ?>" maxlength="100" autocomplete="name" required<?= $contactInvalid('name') ?>>
                    <?= $contactError('name') ?>
                </div>
                <div class="field">
                    <label for="contact-email">Correo electrónico</label>
                    <input type="email" id="contact-email" name="email" value="<?= $contactValue('email') ?>" maxlength="254" autocomplete="email" required<?= $contactInvalid('email') ?>>
                    <?= $contactError('email') ?>
                </div>
            </div>
            <div class="field">
                <label for="contact-phone">Teléfono <span class="field-optional">(opcional)</span></label>
                <input type="tel" id="contact-phone" name="phone" value="<?= $contactValue('phone') ?>" maxlength="30" autocomplete="tel"<?= $contactInvalid('phone') ?>>
                <?= $contactError('phone') ?>
            </div>
            <div class="field">
                <label for="contact-message">Mensaje</label>
                <textarea id="contact-message" name="message" rows="5" minlength="10" maxlength="2000" required<?= $contactInvalid('message') ?>><?= $contactValue('message') ?></textarea>
                <?= $contactError('message') ?>
            </div>
            <div class="field field-consent">
                <input type="checkbox" id="contact-authorization" name="authorization" value="1" required<?= ($contactOld['authorization'] ?? '') === '1' ? ' checked' : '' ?><?= $contactInvalid('authorization') ?>>
                <label for="contact-authorization"><?= $escape($contactForm['authorizationText']) ?> <a href="<?= $escape($basePath) ?>/politica-de-datos" target="_blank" rel="noopener">Ver política<span class="visually-hidden"> (se abre en una pestaña nueva)</span></a>.</label>
                <?= $contactError('authorization') ?>
            </div>
            <button class="button button-dark" type="submit"<?= $contactForm['preview'] ? ' disabled title="No disponible en la vista previa"' : '' ?>>Enviar mensaje <span aria-hidden="true">↗</span></button>
        </form>
    </div>
</section>
<script>
    // Lleva el foco al resultado del envío para que lectores de pantalla lo anuncien.
    document.querySelector('[data-focus-on-load]')?.focus();
</script>
<?php endif; ?>
