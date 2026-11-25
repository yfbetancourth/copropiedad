<?php

declare(strict_types=1);

use App\Modules\Classifieds\ClassifiedsService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$publicBasePath = preg_replace('~/admin$~', '', $basePath) ?? $basePath;
$operationLabels = ['sale' => 'En venta', 'rent' => 'En arriendo', 'purchase' => 'Busca comprar'];
$typeLabels = ['house' => 'Casa', 'apartment' => 'Apartamento', 'commercial' => 'Local u oficina', 'other' => 'Otro'];
$statusBadges = ['pending' => 'text-bg-warning', 'published' => 'text-bg-success', 'rejected' => 'text-bg-danger', 'closed' => 'text-bg-secondary'];
$tabs = ['pending' => 'En revisión', 'published' => 'Publicados', 'rejected' => 'No publicados', 'closed' => 'Cerrados', 'todos' => 'Todos'];
$emptyMessages = [
    'pending' => 'No hay clasificados esperando revisión.',
    'published' => 'No hay clasificados publicados.',
    'rejected' => 'No hay clasificados rechazados.',
    'closed' => 'No hay clasificados cerrados por sus autores.',
    'todos' => 'Los residentes aún no han enviado clasificados.',
];
$date = static fn (?string $value): string => $value ? date('d/m/Y h:i a', strtotime($value) ?: 0) : '';
?>
<section class="content">
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-megaphone me-2" aria-hidden="true"></i>Clasificados</h1>
        <p class="text-secondary mb-0">Los clasificados de los residentes se publican en la página pública solo cuando los apruebas. Si un residente edita uno publicado, vuelve a revisión. Si no lo publicas, el residente recibe el motivo y puede corregirlo.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <nav aria-label="Filtrar por estado">
        <ul class="nav nav-pills flex-wrap gap-1 mb-3">
            <?php foreach ($tabs as $key => $label): ?>
                <?php $count = $key === 'todos' ? array_sum($counts) : (int) ($counts[$key] ?? 0); ?>
                <li class="nav-item">
                    <a class="nav-link<?= $filter === $key ? ' active' : '' ?>" href="<?= $escape($basePath) ?>/clasificados?estado=<?= $escape($key) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>>
                        <?= $escape($label) ?> <span class="badge <?= $key === 'pending' && $count > 0 ? 'text-bg-warning' : 'text-bg-light border' ?> ms-1"><?= $count ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <?php if ($listings === []): ?>
        <div class="alert alert-light border"><?= $escape($emptyMessages[$filter] ?? '') ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <?php foreach ($listings as $listing): ?>
            <?php
            $listingId = (int) $listing['id'];
            $status = (string) $listing['status'];
            $price = is_numeric($listing['price'] ?? null) ? '$ ' . number_format((float) $listing['price'], 0, ',', '.') . ' COP' . ($listing['operation'] === 'rent' ? ' / mes' : '') : 'Sin precio';
            $canApprove = $canWrite && in_array($status, ['pending', 'rejected'], true);
            $canReject = $canWrite && in_array($status, ['pending', 'published'], true);
            ?>
            <div class="col-xl-6">
                <article class="card h-100 <?= $status === 'pending' ? 'card-warning card-outline' : '' ?>" id="clasificado-<?= $listingId ?>" aria-labelledby="classified-title-<?= $listingId ?>">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <h2 class="card-title h6 mb-0 me-auto" id="classified-title-<?= $listingId ?>"><?= $escape($listing['title']) ?></h2>
                        <span class="badge <?= $statusBadges[$status] ?? 'text-bg-light' ?>"><?= $escape(ClassifiedsService::STATUS_LABELS[$status] ?? $status) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($listing['images'] !== []): ?>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php foreach ($listing['images'] as $index => $image): ?>
                                    <?php $imageUrl = $publicBasePath . '/clasificados/imagen?id=' . (int) $image['id']; ?>
                                    <a href="<?= $escape($imageUrl) ?>" target="_blank" rel="noopener" title="Abrir imagen <?= $index + 1 ?> en tamaño completo">
                                        <img src="<?= $escape($imageUrl) ?>" alt="Imagen <?= $index + 1 ?> de <?= $escape($listing['title']) ?>" loading="lazy" class="rounded object-fit-cover border" style="width: 7rem; height: 5rem">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <p class="small text-secondary mb-2">
                            <?= $escape($operationLabels[$listing['operation']] ?? 'Clasificado') ?> · <?= $escape($typeLabels[$listing['property_type']] ?? 'Inmueble') ?>
                            · <?= $escape($listing['location']) ?> · <strong class="text-body"><?= $escape($price) ?></strong>
                        </p>
                        <p class="mb-3" style="white-space: pre-line"><?= $escape($listing['description']) ?></p>
                        <dl class="row small mb-0">
                            <dt class="col-sm-4">Residente</dt>
                            <dd class="col-sm-8"><?= $escape($listing['author_username'] ?? '') ?> <span class="text-secondary"><?= $escape($listing['author_email'] ?? '') ?></span></dd>
                            <dt class="col-sm-4">Contacto público</dt>
                            <dd class="col-sm-8">
                                <?php if ((int) $listing['contact_authorized'] === 1): ?>
                                    <?= $escape(implode(' · ', array_filter([(string) $listing['contact_name'], (string) $listing['contact_email'], (string) $listing['contact_phone']], static fn (string $v): bool => $v !== ''))) ?>
                                <?php else: ?>
                                    <span class="text-secondary">No autorizó publicar datos de contacto.</span>
                                <?php endif; ?>
                            </dd>
                            <dt class="col-sm-4">Enviado</dt>
                            <dd class="col-sm-8"><?= $escape($date($listing['created_at'])) ?><?= $listing['updated_at'] !== $listing['created_at'] ? ' · actualizado ' . $escape($date($listing['updated_at'])) : '' ?></dd>
                            <?php if (!empty($listing['reviewed_at'])): ?>
                                <dt class="col-sm-4">Última revisión</dt>
                                <dd class="col-sm-8"><?= $escape($date($listing['reviewed_at'])) ?><?= !empty($listing['reviewer_username']) ? ' por ' . $escape($listing['reviewer_username']) : '' ?></dd>
                            <?php endif; ?>
                        </dl>
                        <?php if ($status === 'rejected' && (string) $listing['review_note'] !== ''): ?>
                            <div class="alert alert-danger py-2 small mt-3 mb-0"><strong>Motivo enviado al residente:</strong> <?= $escape($listing['review_note']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canApprove || $canReject): ?>
                        <div class="card-footer">
                            <div class="row g-2">
                                <?php if ($canApprove): ?>
                                    <form class="col-md-6" method="post" action="<?= $escape($basePath) ?>/clasificados/publicar">
                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="listing_id" value="<?= $listingId ?>">
                                        <input type="hidden" name="estado" value="<?= $escape($filter) ?>">
                                        <button class="btn btn-success btn-sm w-100" type="submit"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Publicar</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canReject): ?>
                                    <form class="col-md-6" method="post" action="<?= $escape($basePath) ?>/clasificados/rechazar"<?= $status === 'published' ? ' data-confirm="¿Retirar este clasificado de la página pública?"' : '' ?>>
                                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="listing_id" value="<?= $listingId ?>">
                                        <input type="hidden" name="estado" value="<?= $escape($filter) ?>">
                                        <label class="visually-hidden" for="reject-reason-<?= $listingId ?>">Motivo para el residente</label>
                                        <input class="form-control form-control-sm mb-2" id="reject-reason-<?= $listingId ?>" name="reason" minlength="5" maxlength="255" required placeholder="Motivo para el residente">
                                        <button class="btn btn-outline-danger btn-sm w-100" type="submit"><i class="bi bi-x-lg me-1" aria-hidden="true"></i><?= $status === 'published' ? 'Despublicar' : 'No publicar' ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
</section>
