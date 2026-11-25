<?php

declare(strict_types=1);

use App\Modules\Configuration\PublicSiteService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$canWrite = \App\Core\Authorization::hasPermission($actor, 'configuracion.parametrizacion', 2);
$publicBasePath = preg_replace('~/admin$~', '', $basePath) ?? $basePath;
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    return $date ? $date->format('d/m/Y h:i a') : $value;
};
$available = $status['available'];
$hasDraft = $status['has_draft'];
// Secciones que dependen de la galería: se avisa cuál se ve hoy.
$hiddenByGallery = $galleryCount > 0 ? 'values' : 'spaces';
?>
<section class="content">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <div>
            <h1 class="h3 mb-1">Sitio público</h1>
            <p class="text-secondary mb-0">Textos de la página pública del conjunto. Los cambios se guardan como borrador y el público los ve cuando los publicas.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary" href="<?= $escape($publicBasePath) ?>/" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Ver sitio publicado</a>
            <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/galeria-publica"><i class="bi bi-images me-1" aria-hidden="true"></i>Galería pública</a>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$available): ?>
        <div class="alert alert-warning" role="status"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Falta aplicar la migración 035. Mientras tanto el sitio público muestra los textos por defecto y no se pueden guardar cambios.</div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div>
                <?php if ($hasDraft): ?>
                    <span class="badge text-bg-warning me-1">Borrador sin publicar</span>
                    <span class="small text-secondary">Guardado el <?= $escape($formatDate($status['draft_updated_at'])) ?>.</span>
                <?php else: ?>
                    <span class="badge text-bg-success me-1">Sin cambios pendientes</span>
                <?php endif; ?>
                <span class="small text-secondary d-block mt-1">
                    <?= $status['published_at'] !== null
                        ? 'Última publicación: ' . $escape($formatDate($status['published_at'])) . ' · ' . (int) $status['customized'] . ' texto(s) personalizados.'
                        : 'El sitio usa los textos por defecto.' ?>
                </span>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-primary" href="<?= $escape($basePath) ?>/configuracion/sitio-publico/vista-previa" target="_blank" rel="noopener"><i class="bi bi-eye me-1" aria-hidden="true"></i>Vista previa</a>
                <?php if ($canWrite && $hasDraft): ?>
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/sitio-publico/descartar" onsubmit="return confirm('¿Descartar el borrador? Se pierden los cambios sin publicar.')">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <button class="btn btn-outline-danger" type="submit">Descartar borrador</button>
                    </form>
                    <form method="post" action="<?= $escape($basePath) ?>/configuracion/sitio-publico/publicar" onsubmit="return confirm('¿Publicar el borrador? El público verá los cambios de inmediato.')">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <button class="btn btn-success" type="submit"><i class="bi bi-send-check me-1" aria-hidden="true"></i>Publicar</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="alert alert-info small" role="note">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Deja un campo vacío para usar el texto por defecto (aparece en gris).
        Escribe <code><?= $escape(PublicSiteService::NAME_TOKEN) ?></code> para mostrar el nombre del conjunto. Los textos se muestran tal cual: no admiten HTML.
    </div>

    <form method="post" action="<?= $escape($basePath) ?>/configuracion/sitio-publico/guardar" novalidate>
        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
        <?php foreach (PublicSiteService::SECTIONS as $sectionKey => $section): ?>
            <?php
            $sectionErrors = array_intersect_key($errors, $section['fields']);
            $sectionChanged = array_intersect_key($values, $section['fields']) !== [];
            ?>
            <details class="card mb-2" <?= $sectionErrors !== [] || $sectionKey === 'hero' ? 'open' : '' ?>>
                <summary class="card-header d-flex align-items-center gap-2" style="cursor:pointer">
                    <span class="fw-semibold"><?= $escape($section['label']) ?></span>
                    <?php if ($sectionChanged): ?><span class="badge text-bg-primary">Personalizada</span><?php endif; ?>
                    <?php if ($sectionErrors !== []): ?><span class="badge text-bg-danger">Revisar</span><?php endif; ?>
                    <?php if ($sectionKey === $hiddenByGallery): ?><span class="badge text-bg-light border">No se ve hoy</span><?php endif; ?>
                </summary>
                <div class="card-body">
                    <p class="small text-secondary"><?= $escape($section['help']) ?></p>
                    <div class="row g-3">
                        <?php foreach ($section['fields'] as $key => [$label, $default, $maxLength, $multiline]): ?>
                            <?php
                            $id = 'site-' . str_replace('_', '-', $key);
                            $value = (string) ($values[$key] ?? '');
                            $error = $errors[$key] ?? null;
                            $placeholder = $default !== '' ? $default : ($key === 'meta_description' ? 'Automática: nombre, dirección y secciones del sitio.' : '');
                            ?>
                            <div class="<?= $multiline ? 'col-12' : 'col-md-6' ?>">
                                <label for="<?= $escape($id) ?>" class="form-label"><?= $escape($label) ?></label>
                                <?php if ($multiline): ?>
                                    <textarea id="<?= $escape($id) ?>" name="site[<?= $escape($key) ?>]" class="form-control<?= $error ? ' is-invalid' : '' ?>" rows="2" maxlength="<?= (int) $maxLength ?>" placeholder="<?= $escape($placeholder) ?>" aria-describedby="<?= $escape($id) ?>-help" <?= $canWrite && $available ? '' : 'readonly' ?>><?= $escape($value) ?></textarea>
                                <?php else: ?>
                                    <input id="<?= $escape($id) ?>" name="site[<?= $escape($key) ?>]" type="text" class="form-control<?= $error ? ' is-invalid' : '' ?>" maxlength="<?= (int) $maxLength ?>" placeholder="<?= $escape($placeholder) ?>" value="<?= $escape($value) ?>" aria-describedby="<?= $escape($id) ?>-help" <?= $canWrite && $available ? '' : 'readonly' ?>>
                                <?php endif; ?>
                                <?php if ($error): ?><div class="invalid-feedback"><?= $escape($error) ?></div><?php endif; ?>
                                <div id="<?= $escape($id) ?>-help" class="form-text">Máximo <?= (int) $maxLength ?> caracteres.</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>

        <?php if ($canWrite && $available): ?>
            <div class="d-flex gap-2 flex-wrap justify-content-end mt-3 position-sticky bottom-0 py-2 bg-body">
                <button class="btn btn-outline-primary" type="submit" name="after" value="preview"><i class="bi bi-eye me-1" aria-hidden="true"></i>Guardar y ver vista previa</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar borrador</button>
            </div>
        <?php endif; ?>
    </form>
</section>
