<?php

declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$operationLabels = ['sale' => 'En venta', 'rent' => 'En arriendo', 'purchase' => 'Busca comprar'];
$propertyTypeLabels = [
    'house' => 'Casa',
    'apartment' => 'Apartamento',
    'commercial' => 'Local u oficina',
    'other' => 'Otro',
];
$operationCounts = array_count_values(array_map(static fn (array $listing): string => (string) $listing['operation'], $listings));
?>
<section class="catalog-shell">
    <header class="catalog-intro">
        <div>
            <p class="eyebrow"><span class="eyebrow-line"></span> Comunidad</p>
            <h1>Clasificados de <em>inmuebles</em></h1>
            <p>Ofertas en venta y búsquedas de compra compartidas por los residentes de <?= $escape($propertyName) ?>.</p>
        </div>
        <a class="button button-dark" href="<?= $escape($basePath) ?>/login?destino=usuario">Publicar desde mi portal <span aria-hidden="true">↗</span></a>
    </header>

    <?php if ($listings === []): ?>
        <div class="catalog-empty">
            <span class="empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 20V10l8-6 8 6v10H4Z"/><path d="M9 20v-6h6v6"/></svg></span>
            <h2>Aún no hay clasificados publicados</h2>
            <p>Vuelve pronto, o inicia sesión en el portal para compartir una oferta o una búsqueda.</p>
            <a class="button button-outline" href="<?= $escape($basePath) ?>/login?destino=usuario">Entrar al portal de residentes <span aria-hidden="true">↗</span></a>
        </div>
    <?php else: ?>
        <div class="catalog-toolbar">
            <div class="catalog-filters" role="group" aria-label="Filtrar clasificados" hidden>
                <button type="button" class="filter-chip" data-filter="all" aria-pressed="true">Todos <span><?= count($listings) ?></span></button>
                <?php foreach ($operationLabels as $key => $label): ?>
                    <?php if (($operationCounts[$key] ?? 0) > 0): ?>
                        <button type="button" class="filter-chip" data-filter="<?= $escape($key) ?>" aria-pressed="false"><?= $escape($label) ?> <span><?= (int) $operationCounts[$key] ?></span></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="catalog-count" aria-live="polite"><span data-visible-count><?= count($listings) ?></span> publicación(es)</p>
        </div>
        <div class="catalog-grid">
            <?php foreach ($listings as $listing): ?>
                <?php
                $operation = $operationLabels[$listing['operation']] ?? 'Clasificado';
                $propertyType = $propertyTypeLabels[$listing['property_type']] ?? 'Inmueble';
                $amount = is_numeric($listing['price'] ?? null)
                    ? '$ ' . number_format((float) $listing['price'], 0, ',', '.')
                    : null;
                $cover = $listing['images'][0] ?? null;
                ?>
                <article class="catalog-card" data-operation="<?= $escape((string) $listing['operation']) ?>">
                    <div class="catalog-card-image">
                        <?php if ($cover !== null): ?>
                            <img src="<?= $escape($basePath) ?>/clasificados/imagen?id=<?= (int) $cover['id'] ?>"
                                 alt="<?= $escape('Foto del inmueble: ' . $listing['title']) ?>"
                                 width="720" height="500" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span class="catalog-image-empty" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 20V10l8-6 8 6v10H4Z"/><path d="M9 20v-6h6v6"/></svg></span>
                        <?php endif; ?>
                        <span class="catalog-operation catalog-operation-<?= $escape((string) $listing['operation']) ?>"><?= $escape($operation) ?></span>
                        <?php if (count($listing['images']) > 1): ?><span class="catalog-image-count"><?= count($listing['images']) ?> fotos</span><?php endif; ?>
                    </div>
                    <?php if (count($listing['images']) > 1): ?>
                        <div class="catalog-thumbnails" aria-label="Más fotos de <?= $escape($listing['title']) ?>">
                            <?php foreach (array_slice($listing['images'], 1, 4) as $image): ?>
                                <img src="<?= $escape($basePath) ?>/clasificados/imagen?id=<?= (int) $image['id'] ?>" alt="Otra foto de <?= $escape($listing['title']) ?>" width="160" height="110" loading="lazy" decoding="async">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="catalog-card-body">
                        <p class="catalog-kicker"><?= $escape($propertyType) ?> <span aria-hidden="true">·</span> <?= $escape($listing['location']) ?></p>
                        <h2><?= $escape($listing['title']) ?></h2>
                        <?php if ($amount !== null): ?>
                            <p class="catalog-price"><?php if ($listing['operation'] === 'purchase'): ?><small>Presupuesto</small> <?php endif; ?><?= $escape($amount) ?> <small>COP<?= $listing['operation'] === 'rent' ? ' / mes' : '' ?></small></p>
                        <?php endif; ?>
                        <p class="catalog-description"><?= nl2br($escape($listing['description'])) ?></p>
                        <?php if ($listing['contact_name'] !== null || $listing['contact_email'] !== null || $listing['contact_phone'] !== null): ?>
                            <div class="catalog-contact">
                                <h3>Contacto</h3>
                                <?php if ($listing['contact_name'] !== null): ?><p><?= $escape($listing['contact_name']) ?></p><?php endif; ?>
                                <div class="catalog-contact-actions">
                                    <?php if ($listing['contact_phone'] !== null): ?><a class="contact-chip" href="tel:<?= $escape(preg_replace('/[^0-9+]/', '', $listing['contact_phone']) ?: '') ?>">Llamar · <?= $escape($listing['contact_phone']) ?></a><?php endif; ?>
                                    <?php if ($listing['contact_email'] !== null): ?><a class="contact-chip" href="mailto:<?= $escape($listing['contact_email']) ?>">Escribir correo</a><?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="catalog-no-contact">El residente no autorizó publicar datos de contacto.</p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <script nonce="<?= \App\Core\Csp::nonce() ?>">
        (() => {
            const group = document.querySelector('.catalog-filters');
            const chips = group ? group.querySelectorAll('[data-filter]') : [];
            if (!group || chips.length < 3) return; // con un solo tipo no hace falta filtrar
            const cards = document.querySelectorAll('.catalog-card[data-operation]');
            const counter = document.querySelector('[data-visible-count]');
            group.hidden = false;
            group.addEventListener('click', (event) => {
                const chip = event.target.closest('[data-filter]');
                if (!chip) return;
                const filter = chip.dataset.filter;
                let visible = 0;
                chips.forEach((item) => item.setAttribute('aria-pressed', String(item === chip)));
                cards.forEach((card) => {
                    const show = filter === 'all' || card.dataset.operation === filter;
                    card.hidden = !show;
                    if (show) visible++;
                });
                if (counter) counter.textContent = String(visible);
            });
        })();
        </script>
    <?php endif; ?>
</section>
