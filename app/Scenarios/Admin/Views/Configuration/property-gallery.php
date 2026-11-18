<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.parametrizacion', 2);
$publicBasePath = preg_replace('~/admin$~', '', $basePath) ?? $basePath;
?>
<section class="content">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <div>
            <h1 class="h3 mb-1">Galería pública</h1>
            <p class="text-secondary mb-0">Fotos de las zonas y espacios que quieres compartir en la página principal.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/parametrizacion">Volver a parametrización</a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($canWrite): ?>
        <div class="card mb-4">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" action="<?= $escape($basePath) ?>/configuracion/galeria-publica/agregar" class="row align-items-end g-3">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <div class="col-lg">
                        <label for="gallery-images" class="form-label">Agregar fotos (<?= count($images) ?>/8)</label>
                        <input id="gallery-images" class="form-control" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required <?= count($images) >= 8 ? 'disabled' : '' ?>>
                        <div class="form-text">JPG, PNG o WebP · máximo 3 MB y 24 megapíxeles por foto. Elige imágenes nítidas y horizontales.</div>
                    </div>
                    <div class="col-lg-auto"><button class="btn btn-primary" type="submit" <?= count($images) >= 8 ? 'disabled' : '' ?>>Subir fotos</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($images === []): ?>
        <div class="alert alert-info" role="status">La portada se mostrará sin galería hasta que agregues fotos de la copropiedad.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($images as $index => $image): ?>
                <article class="col-12 col-sm-6 col-xl-3">
                    <div class="card h-100 overflow-hidden">
                        <img src="<?= $escape($publicBasePath . $image['url']) ?>" alt="Foto <?= $index + 1 ?> de la copropiedad" class="w-100" style="aspect-ratio: 4/3; object-fit: cover" loading="lazy">
                        <div class="card-body d-flex align-items-center justify-content-between gap-2">
                            <span class="small text-secondary">Foto <?= $index + 1 ?></span>
                            <?php if ($canWrite): ?>
                                <form method="post" action="<?= $escape($basePath) ?>/configuracion/galeria-publica/eliminar" onsubmit="return confirm('¿Retirar esta foto de la portada?')">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="image" value="<?= $escape($image['name']) ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Retirar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
