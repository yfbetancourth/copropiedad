<?php

declare(strict_types=1);

use App\Modules\Privacy\PrivacyService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$publicBasePath = preg_replace('~/admin$~', '', $basePath) ?? $basePath;
$date = static fn (?string $value): string => $value ? date('d/m/Y h:i a', strtotime($value) ?: 0) : '';
$tabLabels = [
    'data_policy' => ['bi-file-earmark-text', 'Política de datos'],
    'authorization' => ['bi-check2-square', 'Autorización'],
    'cookie_policy' => ['bi-cookie', 'Cookies'],
    'responsable' => ['bi-person-badge', 'Responsable'],
];
$help = [
    'data_policy' => 'Política de tratamiento de datos personales (Ley 1581 de 2012 y art. 13 del Decreto 1377 de 2013). Debe indicar el responsable y sus datos de contacto, las finalidades, los derechos de los titulares, el área que atiende las solicitudes, el procedimiento para ejercerlos y la fecha de vigencia.',
    'authorization' => 'Texto que el visitante acepta al marcar la casilla del formulario de contacto. Debe decir quién trata los datos, para qué, cuáles son sus derechos y cómo ejercerlos. La casilla nunca aparece marcada: el visitante debe marcarla para enviar el mensaje.',
    'cookie_policy' => 'Explica qué cookies usa el sitio. Hoy el sitio solo usa cookies necesarias (la de inicio de sesión), por eso la página pública muestra un aviso informativo. Si en el futuro se agregan cookies de analítica o publicidad, habrá que pedir consentimiento previo.',
];
$settings = $settings ?? [];
$current = $documents[$tab]['current'] ?? null;
$missing = [];
foreach (['data_policy', 'authorization'] as $required) {
    if ($documents[$required]['current'] === null) {
        $missing[] = $documents[$required]['label'];
    }
}
?>
<section class="content">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Privacidad y cookies</h1>
            <p class="text-secondary mb-0">Políticas que se muestran en la página pública y texto de la autorización que aceptan los visitantes en el formulario de contacto. Cada cambio publica una versión nueva y las anteriores se conservan como prueba.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/privacidad/autorizaciones">
            <i class="bi bi-journal-check me-1" aria-hidden="true"></i>Registro de autorizaciones
            <span class="badge text-bg-light border ms-1"><?= (int) ($consentCounts['active'] ?? 0) ?></span>
        </a>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($settings['contact_email'] === ''): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Empieza por la pestaña <a class="alert-link" href="?documento=responsable">Responsable</a>: el correo para peticiones, consultas y reclamos es obligatorio antes de publicar la política y la autorización.</div>
    <?php elseif ($missing !== []): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>El formulario de contacto de la página pública aparecerá cuando publiques: <?= $escape(implode(' y ', $missing)) ?>.</div>
    <?php elseif (!$formAvailable): ?>
        <div class="alert alert-secondary">El formulario de contacto está desactivado en la pestaña Responsable.</div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
        <?php foreach ($tabLabels as $key => [$icon, $label]): ?>
            <?php $published = isset($documents[$key]) ? $documents[$key]['current'] : null; ?>
            <li class="nav-item">
                <a class="nav-link text-nowrap<?= $tab === $key ? ' active' : '' ?>" href="?documento=<?= $escape($key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
                    <i class="bi <?= $escape($icon) ?> me-1" aria-hidden="true"></i><?= $escape($label) ?>
                    <?php if (isset($documents[$key])): ?>
                        <span class="badge <?= $published === null ? 'text-bg-warning' : 'text-bg-light border' ?> ms-1"><?= $published === null ? 'Sin publicar' : 'v' . (int) $published['version'] ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($tab === 'responsable'): ?>
        <div class="card">
            <div class="card-body">
                <h2 class="h5">Responsable del tratamiento</h2>
                <p class="text-secondary">El nombre, NIT y dirección se toman de Configuración / Parametrización. Aquí indicas el canal para que los titulares ejerzan sus derechos (consultas en 10 días hábiles y reclamos en 15 días hábiles).</p>
                <form method="post" action="<?= $escape($basePath) ?>/configuracion/privacidad/responsable" class="row g-3" style="max-width: 760px">
                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                    <div class="col-md-6">
                        <label class="form-label" for="privacy-email">Correo para peticiones, consultas y reclamos</label>
                        <input class="form-control" type="email" id="privacy-email" name="contact_email" value="<?= $escape($settings['contact_email']) ?>" maxlength="254" required<?= $canWrite ? '' : ' disabled' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="privacy-phone">Teléfono <span class="text-secondary">(opcional)</span></label>
                        <input class="form-control" type="tel" id="privacy-phone" name="contact_phone" value="<?= $escape($settings['contact_phone']) ?>" maxlength="30"<?= $canWrite ? '' : ' disabled' ?>>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="privacy-area">Área o persona responsable de atender las solicitudes</label>
                        <input class="form-control" type="text" id="privacy-area" name="responsible_area" value="<?= $escape($settings['responsible_area']) ?>" maxlength="120" required<?= $canWrite ? '' : ' disabled' ?>>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" switch role="switch" id="privacy-form" name="contact_form_enabled" value="1"<?= $settings['contact_form_enabled'] ? ' checked' : '' ?><?= $canWrite ? '' : ' disabled' ?>>
                            <label class="form-check-label" for="privacy-form">Mostrar el formulario de contacto en la página pública</label>
                        </div>
                    </div>
                    <?php if ($canWrite): ?>
                        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar</button></div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
                            <h2 class="h5 mb-0"><?= $escape($documents[$tab]['label']) ?></h2>
                            <?php if ($current !== null): ?>
                                <a class="small" href="<?= $escape($publicBasePath . $documents[$tab]['public_path']) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Ver publicada</a>
                            <?php endif; ?>
                        </div>
                        <p class="text-secondary small"><?= $escape($help[$tab]) ?></p>

                        <?php if ($usingTemplate): ?>
                            <div class="alert alert-warning small">
                                <strong>Plantilla inicial:</strong> se completó con los datos del conjunto como punto de partida. Revísala y ajústala a la realidad del conjunto antes de publicarla; lo ideal es que la revise un abogado. Reemplaza cualquier texto entre corchetes, como [NIT pendiente].
                            </div>
                        <?php elseif ($canWrite): ?>
                            <p class="small"><a href="?documento=<?= $escape($tab) ?>&amp;plantilla=1">Cargar de nuevo la plantilla inicial</a> <span class="text-secondary">(reemplaza el texto del editor; no publica nada)</span></p>
                        <?php endif; ?>

                        <form method="post" action="<?= $escape($basePath) ?>/configuracion/privacidad/publicar">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="document" value="<?= $escape($tab) ?>">
                            <label class="form-label" for="privacy-content">Texto</label>
                            <textarea class="form-control font-monospace" id="privacy-content" name="content" rows="<?= $tab === 'authorization' ? 7 : 24 ?>" maxlength="<?= (int) PrivacyService::DOCUMENTS[$tab]['max_length'] ?>" aria-describedby="privacy-format"<?= $canWrite ? '' : ' readonly' ?>><?= $escape($editorText) ?></textarea>
                            <div class="form-text" id="privacy-format">
                                <?php if ($tab === 'authorization'): ?>
                                    Texto plano. Se muestra junto a la casilla, seguido del enlace «Ver política».
                                <?php else: ?>
                                    Formato: una línea que empieza con <code>#&nbsp;</code> es un título, una que empieza con <code>-&nbsp;</code> es una viñeta y una línea en blanco separa párrafos.
                                <?php endif; ?>
                            </div>
                            <?php if ($canWrite): ?>
                                <div class="mt-3">
                                    <label class="form-label" for="privacy-summary">¿Qué cambió en esta versión?</label>
                                    <input class="form-control" type="text" id="privacy-summary" name="change_summary" value="<?= $escape($changeSummary) ?>" maxlength="255" required placeholder="Ej.: se agrega la finalidad de control de acceso vehicular">
                                </div>
                                <div class="form-check mt-3">
                                    <input class="form-check-input" type="checkbox" id="privacy-confirm" name="confirm_review" value="1" required>
                                    <label class="form-check-label" for="privacy-confirm">Revisé el texto y quiero publicarlo como nueva versión vigente.</label>
                                </div>
                                <?php if ($tab !== 'cookie_policy' && $current !== null): ?>
                                    <p class="small text-secondary mt-2 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Las autorizaciones ya registradas quedan asociadas a la versión que aceptó cada titular. Si el cambio agrega finalidades nuevas, la ley exige una nueva autorización para usar los datos con esas finalidades.</p>
                                <?php endif; ?>
                                <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-upload me-1" aria-hidden="true"></i>Publicar versión <?= $current === null ? 1 : (int) $current['version'] + 1 ?></button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-body">
                        <h2 class="h6">Historial de versiones</h2>
                        <?php if ($history === []): ?>
                            <p class="text-secondary small mb-0">Aún no se ha publicado ninguna versión.</p>
                        <?php else: ?>
                            <ol class="list-unstyled mb-0">
                                <?php foreach ($history as $index => $item): ?>
                                    <li class="border-bottom py-2">
                                        <div class="d-flex justify-content-between gap-2">
                                            <a href="<?= $escape($basePath) ?>/configuracion/privacidad/version?id=<?= (int) $item['id'] ?>"><strong>Versión <?= (int) $item['version'] ?></strong></a>
                                            <?php if ($index === 0): ?><span class="badge text-bg-success">Vigente</span><?php endif; ?>
                                        </div>
                                        <div class="small text-secondary"><?= $escape($date($item['published_at'])) ?><?= $item['published_by_name'] !== null ? ' · ' . $escape($item['published_by_name']) : '' ?></div>
                                        <div class="small"><?= $escape($item['change_summary']) ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
