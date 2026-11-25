<?php

declare(strict_types=1);

use App\Modules\Privacy\ConsentService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$date = static fn (?string $value): string => $value ? date('d/m/Y h:i a', strtotime($value) ?: 0) : '';
$tabs = ['todas' => ['Todas', $counts['active'] + $counts['revoked']], 'vigentes' => ['Vigentes', $counts['active']], 'revocadas' => ['Revocadas', $counts['revoked']]];
$filterQuery = static fn (array $overrides = []): string => http_build_query(array_merge(['estado' => $status, 'q' => $query], $overrides));
?>
<section class="content">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-journal-check me-2" aria-hidden="true"></i>Registro de autorizaciones</h1>
            <p class="text-secondary mb-0">Prueba de cada autorización de tratamiento de datos: quién la dio, cuándo, desde qué dirección IP y qué versiones aceptó. La ley exige conservarla; por eso no se borra. Si un titular revoca su autorización, regístralo aquí.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/privacidad"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Políticas</a>
            <a class="btn btn-outline-primary" href="<?= $escape($basePath) ?>/configuracion/privacidad/autorizaciones/exportar?<?= $escape($filterQuery()) ?>"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Exportar CSV</a>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
        <ul class="nav nav-pills flex-wrap gap-1">
            <?php foreach ($tabs as $key => [$label, $count]): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $status === $key ? ' active' : '' ?>" href="?<?= $escape($filterQuery(['estado' => $key])) ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>><?= $escape($label) ?> <span class="badge text-bg-light border ms-1"><?= (int) $count ?></span></a>
                </li>
            <?php endforeach; ?>
        </ul>
        <form class="d-flex gap-2" method="get" role="search">
            <input type="hidden" name="estado" value="<?= $escape($status) ?>">
            <label class="visually-hidden" for="consent-search">Buscar por nombre, correo o teléfono</label>
            <input class="form-control" type="search" id="consent-search" name="q" value="<?= $escape($query) ?>" placeholder="Nombre, correo o teléfono" maxlength="100">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Buscar</span></button>
        </form>
    </div>

    <?php if ($result['rows'] === []): ?>
        <div class="alert alert-light border"><?= $query !== '' ? 'No hay autorizaciones que coincidan con la búsqueda.' : 'Aún no hay autorizaciones registradas. Se registran cuando un visitante envía el formulario de contacto de la página pública.' ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <caption class="visually-hidden">Autorizaciones de tratamiento de datos</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Titular</th>
                        <th scope="col">Origen</th>
                        <th scope="col">Versiones aceptadas</th>
                        <th scope="col">IP</th>
                        <th scope="col">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result['rows'] as $row): ?>
                        <?php $consentId = (int) $row['id']; ?>
                        <tr>
                            <td class="text-nowrap small"><?= $escape($date($row['granted_at'])) ?></td>
                            <td>
                                <div><?= $escape($row['subject_name']) ?></div>
                                <div class="small text-secondary"><?= $escape($row['subject_email']) ?><?= $row['subject_phone'] ? ' · ' . $escape($row['subject_phone']) : '' ?></div>
                            </td>
                            <td class="small"><?= $escape(ConsentService::SOURCE_LABELS[$row['source']] ?? $row['source']) ?></td>
                            <td class="small text-nowrap">
                                <a href="<?= $escape($basePath) ?>/configuracion/privacidad/version?id=<?= (int) $row['data_policy_version_id'] ?>">Política v<?= (int) $row['policy_version'] ?></a><br>
                                <a href="<?= $escape($basePath) ?>/configuracion/privacidad/version?id=<?= (int) $row['authorization_version_id'] ?>">Autorización v<?= (int) $row['authorization_version'] ?></a>
                            </td>
                            <td class="small font-monospace" title="<?= $escape($row['user_agent'] ?? '') ?>"><?= $escape($row['ip_address']) ?></td>
                            <td>
                                <?php if ($row['revoked_at'] === null): ?>
                                    <span class="badge text-bg-success">Vigente</span>
                                    <?php if ($canWrite): ?>
                                        <details class="mt-1">
                                            <summary class="small">Registrar revocación</summary>
                                            <form method="post" action="<?= $escape($basePath) ?>/configuracion/privacidad/autorizaciones/revocar" class="mt-2" style="min-width: 240px">
                                                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="consent_id" value="<?= $consentId ?>">
                                                <input type="hidden" name="estado" value="<?= $escape($status) ?>">
                                                <input type="hidden" name="q" value="<?= $escape($query) ?>">
                                                <input type="hidden" name="pagina" value="<?= (int) $result['page'] ?>">
                                                <label class="form-label small" for="revoke-note-<?= $consentId ?>">¿Cómo y cuándo la pidió el titular?</label>
                                                <input class="form-control form-control-sm" type="text" id="revoke-note-<?= $consentId ?>" name="note" maxlength="255" required placeholder="Ej.: correo del 20/11/2026">
                                                <button class="btn btn-sm btn-outline-danger mt-2" type="submit">Registrar revocación</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Revocada</span>
                                    <div class="small text-secondary"><?= $escape($date($row['revoked_at'])) ?><?= $row['revoked_by_name'] ? ' · ' . $escape($row['revoked_by_name']) : '' ?></div>
                                    <div class="small"><?= $escape($row['revocation_note'] ?? '') ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($result['pages'] > 1): ?>
            <nav aria-label="Páginas del registro">
                <ul class="pagination pagination-sm">
                    <?php for ($page = 1; $page <= $result['pages']; $page++): ?>
                        <li class="page-item<?= $page === $result['page'] ? ' active' : '' ?>"><a class="page-link" href="?<?= $escape($filterQuery(['pagina' => $page])) ?>"<?= $page === $result['page'] ? ' aria-current="page"' : '' ?>><?= $page ?></a></li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
