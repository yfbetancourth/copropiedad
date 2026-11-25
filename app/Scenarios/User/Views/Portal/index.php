<?php
// Bienvenida con la misma estructura que la del panel administrativo.
$welcomeNow = new DateTimeImmutable('now');
$welcomeHour = (int) $welcomeNow->format('G');
$welcomeGreeting = $welcomeHour < 12 ? 'Buenos días' : ($welcomeHour < 19 ? 'Buenas tardes' : 'Buenas noches');
$welcomeWeekdays = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$welcomeMonths = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$welcomeDate = ucfirst($welcomeWeekdays[(int) $welcomeNow->format('w')]) . ', ' . $welcomeNow->format('j') . ' de '
    . $welcomeMonths[(int) $welcomeNow->format('n') - 1] . ' de ' . $welcomeNow->format('Y');
$welcomePending = (int) ($notifications['message_count'] ?? 0) + (int) ($notifications['billing_count'] ?? 0) + (int) ($notifications['notice_count'] ?? 0);
?>
<section class="content">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <p class="text-secondary small text-uppercase fw-semibold mb-1"><?= htmlspecialchars($propertyName . ' · ' . $welcomeDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <h1 class="h3 mb-1"><?= htmlspecialchars($welcomeGreeting . ', ' . $user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
                <p class="text-secondary mb-0">
                    <?php if ($welcomePending > 0): ?>
                        <i class="bi bi-bell-fill text-warning me-1" aria-hidden="true"></i>Tienes <?= $welcomePending ?> notificación(es) sin leer.
                    <?php else: ?>
                        <i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>Estás al día con tus notificaciones.
                    <?php endif; ?>
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/pagos">
                    <i class="bi bi-credit-card me-1" aria-hidden="true"></i>Pagar mis cuotas
                </a>
                <a class="btn btn-outline-secondary" href="#documentos">
                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>Mis documentos de cobro
                </a>
            </div>
        </div>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= htmlspecialchars($flash['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php
    // Cuota del residente: la de sus inmuebles según su coeficiente (no la base general ni el presupuesto del conjunto).
    $cop = static function (int $cents): string {
        $text = '$ ' . number_format(intdiv($cents, 100), 0, ',', '.');
        return $cents % 100 !== 0 ? $text . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) : $text;
    };
    $unitFees = is_array($unitFees ?? null) ? $unitFees : ['configured' => false, 'units' => [], 'effective_date' => '', 'discount_day' => 0, 'basis' => 'base'];
    $myFee = array_sum(array_column($unitFees['units'], 'fee'));
    $myDiscount = array_sum(array_column($unitFees['units'], 'discount'));
    $effectiveDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $unitFees['effective_date']);
    $effectiveText = $effectiveDate instanceof DateTimeImmutable ? $effectiveDate->format('d/m/Y') : 'sin fecha';
    if (!$unitFees['configured']) {
        [$feeValue, $feeHint] = ['Sin configurar', 'La administración aún no ha definido la cuota.'];
    } elseif ($units === []) {
        [$feeValue, $feeHint] = ['—', 'Sin inmuebles asignados.'];
    } elseif (count($units) === 1) {
        $onlyUnit = $units[0];
        $coefficient = $unitFees['units'][$onlyUnit['id']]['coefficient'] ?? null;
        [$feeValue, $feeHint] = [$cop($myFee), $onlyUnit['name']
            . ($coefficient !== null ? ' · coef. ' . rtrim(rtrim(number_format((float) $coefficient, 4, ',', '.'), '0'), ',') . ' %' : '')
            . ' · mensual desde ' . $effectiveText];
    } else {
        [$feeValue, $feeHint] = [$cop($myFee), 'Mensual, suma de tus ' . count($units) . ' inmuebles · desde ' . $effectiveText];
    }
    $discountDay = (int) $unitFees['discount_day'];
    $discountValue = !$unitFees['configured'] ? 'Sin configurar' : $cop($myDiscount);
    $discountHint = $myDiscount > 0
        ? 'Pagas ' . $cop(max(0, $myFee - $myDiscount)) . ($discountDay > 0 ? ' si pagas hasta el día ' . $discountDay . ' de cada mes' : ' si pagas antes de la fecha límite')
        : 'Sin descuento por pronto pago';

    // Indicadores con las mismas tarjetas de la bienvenida del panel administrativo.
    $portalKpis = [
        ['Inmuebles asignados', (string) count($units), 'bi-house-door', 'text-primary', count($units) === 1 ? '1 inmueble a tu nombre' : count($units) . ' inmuebles a tu nombre', '#inmuebles', 'Ver mis inmuebles'],
        ['Mi cuota de administración', $feeValue, 'bi-cash-stack', 'text-success', $feeHint, $basePath . '/usuario/pagos', 'Ir a pagos'],
        ['Descuento por pronto pago', $discountValue, 'bi-piggy-bank', 'text-info', $discountHint, $basePath . '/usuario/pagos', 'Ver fechas'],
        ['Multas', 'Sin registro', 'bi-exclamation-octagon', 'text-warning', 'Aún no hay historial de multas', '#documentos', 'Ver documentos'],
    ];
    ?>
    <h2 class="visually-hidden">Resumen de mi cuenta</h2>
    <div class="row g-3 mb-4">
        <?php foreach ($portalKpis as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone, $kpiHint, $kpiLink, $kpiAction]): ?>
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-secondary small"><?= htmlspecialchars($kpiLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <i class="bi <?= $kpiIcon ?> fs-5 <?= $kpiTone ?>" aria-hidden="true"></i>
                        </div>
                        <p class="h4 mb-1"><?= htmlspecialchars($kpiValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <p class="small text-secondary mb-0"><?= htmlspecialchars((string) $kpiHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    </div>
                    <a class="card-footer small text-decoration-none bg-transparent" href="<?= htmlspecialchars($kpiLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($kpiAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card card-primary card-outline" id="inmuebles">
                <div class="card-header">
                    <h2 class="card-title">Mis inmuebles asignados</h2>
                    <span class="badge text-bg-primary"><?= count($units) ?></span>
                </div>
                <div class="card-body">
                    <?php if ($units === []): ?>
                        <p class="text-secondary mb-0">Todavía no tienes inmuebles asignados. Comunícate con la administración si crees que esto es un error.</p>
                    <?php else: ?>
                        <div class="table-responsive" role="region" tabindex="0" aria-labelledby="tbl-portal-index-1">
                            <table class="table table-striped align-middle mb-0">
                                <caption id="tbl-portal-index-1" class="visually-hidden">Mis inmuebles asignados</caption>
                                <thead>
                                <tr><th scope="col">Inmueble</th><th scope="col">Categoría</th><th scope="col">Tu vínculo</th><?php if ($unitFees['configured']): ?><th scope="col" class="text-end">Cuota mensual</th><?php endif; ?></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($units as $unit): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($unit['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($unit['category'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td>
                                            <?= htmlspecialchars((string) ($unit['relation_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            <span class="d-block small text-secondary">Desde <?= htmlspecialchars((string) ($unit['started_on'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        </td>
                                        <?php if ($unitFees['configured']): ?>
                                            <?php $unitFee = $unitFees['units'][$unit['id'] ?? ''] ?? null; ?>
                                            <td class="text-end text-nowrap">
                                                <?php if ($unitFee === null || $unitFee['coefficient'] === null): ?>
                                                    <span class="text-secondary small">Sin coeficiente</span>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($cop($unitFee['fee']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                    <span class="d-block small text-secondary">coef. <?= htmlspecialchars(rtrim(rtrim(number_format((float) $unitFee['coefficient'], 4, ',', '.'), '0'), ','), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> %</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (is_array($billingSummary)): ?>
            <div class="card card-success card-outline mt-3" id="documentos">
                <div class="card-header">
                    <h2 class="card-title">Mis documentos de cobro</h2>
                    <span class="badge text-bg-primary"><?= (int) $billingSummary['count'] ?></span>
                </div>
                <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <?php if ($billingSummary['count'] === 0): ?>
                        <p class="text-secondary mb-0">No tienes notas de cobro emitidas.</p>
                    <?php elseif ($billingSummary['pending'] === 0): ?>
                        <p class="mb-0"><i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>Estás al día: no tienes saldos pendientes.</p>
                    <?php else: ?>
                        <div>
                            <p class="text-secondary small mb-1"><?= (int) $billingSummary['pending'] ?> nota(s) con saldo pendiente</p>
                            <p class="h4 mb-0"><?= htmlspecialchars(\App\Modules\Billing\BillingService::money((int) $billingSummary['balance_cents']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        </div>
                    <?php endif; ?>
                    <a class="btn btn-outline-success" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/pagos">
                        <i class="bi bi-receipt me-1" aria-hidden="true"></i>Ver notas, pagos y comprobantes
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-xl-4">
            <?php $accountFormAction = '/usuario/perfil'; require dirname(__DIR__, 3) . '/Shared/account-form.php'; ?>
        </div>
    </div>

    <?php if (\App\Core\ModuleCatalog::enabled('clasificados')): ?>
    <?php $classifiedOperationLabels = ['sale' => 'En venta', 'rent' => 'En arriendo', 'purchase' => 'Busca comprar']; ?>
    <div class="card card-info card-outline mt-3" id="clasificados">
        <div class="card-header">
            <h2 class="card-title">Mis clasificados de inmuebles</h2>
            <span class="badge text-bg-primary"><?= count($classifieds) ?></span>
        </div>
        <div class="card-body">
            <p class="text-secondary">Publica un inmueble en venta o en arriendo, o una búsqueda de compra. La administración revisa cada clasificado (también cuando lo editas) antes de mostrarlo en la página pública; te avisaremos cuando lo publique.</p>
            <form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/clasificados/guardar">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="classified-operation" class="form-label">Publicación</label>
                        <select id="classified-operation" name="operation" class="form-select" required>
                            <option value="sale">Ofrezco en venta</option>
                            <option value="rent">Ofrezco en arriendo</option>
                            <option value="purchase">Busco comprar</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="classified-title" class="form-label">Título</label>
                        <input id="classified-title" name="title" class="form-control" minlength="3" maxlength="160" required>
                    </div>
                    <div class="col-md-4">
                        <label for="classified-type" class="form-label">Tipo de inmueble</label>
                        <select id="classified-type" name="property_type" class="form-select" required>
                            <option value="apartment">Apartamento</option>
                            <option value="house">Casa</option>
                            <option value="commercial">Local u oficina</option>
                            <option value="other">Otro</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="classified-location" class="form-label">Barrio o sector</label>
                        <input id="classified-location" name="location" class="form-control" maxlength="120" required>
                        <div class="form-text">No publiques una dirección exacta si no deseas hacerla pública.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="classified-price" class="form-label">Precio, canon mensual o presupuesto (COP, opcional)</label>
                        <input id="classified-price" name="price" type="number" class="form-control" min="0.01" max="999999999999.99" step="0.01">
                    </div>
                    <div class="col-12">
                        <label for="classified-description" class="form-label">Descripción</label>
                        <textarea id="classified-description" name="description" class="form-control" rows="4" maxlength="3000" required></textarea>
                    </div>
                    <div class="col-12">
                        <label for="classified-images" class="form-label">Imágenes del inmueble</label>
                        <input id="classified-images" name="images[]" type="file" class="form-control" accept="image/jpeg,image/png,image/webp" multiple required>
                        <div class="form-text">Carga de 1 a 5 imágenes JPEG, PNG o WebP; máximo 4 MB cada una. Se adaptan automáticamente a pantallas móviles y de escritorio.</div>
                    </div>
                </div>

                <fieldset class="border rounded p-3 mt-3">
                    <legend class="float-none w-auto px-2 fs-6">Datos de contacto públicos (opcional)</legend>
                    <p class="small text-secondary">Escribe solo los datos que quieres compartir. No se mostrarán sin marcar la autorización expresa.</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="classified-contact-name" class="form-label">Nombre de contacto</label>
                            <input id="classified-contact-name" name="contact_name" class="form-control" maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label for="classified-contact-email" class="form-label">Correo de contacto</label>
                            <input id="classified-contact-email" name="contact_email" type="email" class="form-control" maxlength="254">
                        </div>
                        <div class="col-md-4">
                            <label for="classified-contact-phone" class="form-label">Teléfono de contacto</label>
                            <input id="classified-contact-phone" name="contact_phone" type="tel" class="form-control" maxlength="30" autocomplete="tel">
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input id="classified-contact-consent" name="contact_authorized" value="1" type="checkbox" class="form-check-input">
                        <label for="classified-contact-consent" class="form-check-label">Autorizo publicar en la página pública los datos de contacto ingresados arriba.</label>
                    </div>
                </fieldset>
                <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>Enviar a revisión</button>
            </form>
        </div>
        <?php if ($classifieds !== []): ?>
            <div class="list-group list-group-flush">
                <?php foreach ($classifieds as $classified): ?>
                    <article class="list-group-item p-3" id="clasificado-<?= (int) $classified['id'] ?>">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div>
                                <h3 class="h5 mb-1"><?= htmlspecialchars($classified['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                                <p class="small text-secondary mb-2">
                                    <?= $classifiedOperationLabels[$classified['operation']] ?? 'Clasificado' ?>
                                    · <?= htmlspecialchars($classified['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    · <?= count($classified['images']) ?> imagen(es)
                                    <?php $classifiedBadge = ['pending' => 'text-bg-warning', 'published' => 'text-bg-success', 'rejected' => 'text-bg-danger', 'closed' => 'text-bg-secondary'][$classified['status']] ?? 'text-bg-light'; ?>
                                    <span class="badge <?= $classifiedBadge ?> ms-1"><?= htmlspecialchars(\App\Modules\Classifieds\ClassifiedsService::STATUS_LABELS[$classified['status']] ?? (string) $classified['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                </p>
                                <?php if ($classified['status'] === 'pending'): ?>
                                    <p class="small text-secondary mb-2"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>La administración lo está revisando; aún no se ve en la página pública.</p>
                                <?php elseif ($classified['status'] === 'rejected'): ?>
                                    <div class="alert alert-danger py-2 small mb-2"><strong>No se publicó:</strong> <?= htmlspecialchars((string) ($classified['review_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>Corrígelo con <strong>Editar</strong> y se enviará de nuevo a revisión.</div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($classified['images'] as $image): ?>
                                        <img src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/clasificados/imagen?id=<?= (int) $image['id'] ?>"
                                             alt="Imagen de <?= htmlspecialchars($classified['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                             loading="lazy" class="rounded object-fit-cover" style="width: 7rem; height: 5rem">
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($classified['status'] !== 'rejected'): ?>
                                <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/clasificados/estado">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <input type="hidden" name="listing_id" value="<?= (int) $classified['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $classified['status'] === 'closed' ? 'open' : 'closed' ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">
                                        <?= match ($classified['status']) { 'closed' => 'Volver a publicar', 'pending' => 'Retirar de revisión', default => 'Cerrar publicación' } ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/clasificados/eliminar" onsubmit="return confirm('¿Eliminar este clasificado y sus imágenes?');">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <input type="hidden" name="listing_id" value="<?= (int) $classified['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                </form>
                                <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#edit-classified-<?= (int) $classified['id'] ?>" aria-expanded="false"
                                        aria-controls="edit-classified-<?= (int) $classified['id'] ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar</button>
                            </div>
                        </div>
                        <?php $cid = (int) $classified['id']; $ce = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        <form class="collapse border rounded p-3 mt-3 bg-body-tertiary" id="edit-classified-<?= $cid ?>" method="post" enctype="multipart/form-data"
                              action="<?= $ce($basePath) ?>/usuario/clasificados/actualizar">
                            <input type="hidden" name="_token" value="<?= $ce($csrfToken) ?>">
                            <input type="hidden" name="listing_id" value="<?= $cid ?>">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="edit-operation-<?= $cid ?>" class="form-label">Publicación</label>
                                    <select id="edit-operation-<?= $cid ?>" name="operation" class="form-select" required>
                                        <?php foreach (['sale' => 'Ofrezco en venta', 'rent' => 'Ofrezco en arriendo', 'purchase' => 'Busco comprar'] as $value => $label): ?>
                                            <option value="<?= $value ?>"<?= $classified['operation'] === $value ? ' selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit-title-<?= $cid ?>" class="form-label">Título</label>
                                    <input id="edit-title-<?= $cid ?>" name="title" class="form-control" minlength="3" maxlength="160" required value="<?= $ce($classified['title']) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="edit-type-<?= $cid ?>" class="form-label">Tipo de inmueble</label>
                                    <select id="edit-type-<?= $cid ?>" name="property_type" class="form-select" required>
                                        <?php foreach (['apartment' => 'Apartamento', 'house' => 'Casa', 'commercial' => 'Local u oficina', 'other' => 'Otro'] as $value => $label): ?>
                                            <option value="<?= $value ?>"<?= $classified['property_type'] === $value ? ' selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit-location-<?= $cid ?>" class="form-label">Barrio o sector</label>
                                    <input id="edit-location-<?= $cid ?>" name="location" class="form-control" maxlength="120" required value="<?= $ce($classified['location']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="edit-price-<?= $cid ?>" class="form-label">Precio, canon mensual o presupuesto (COP, opcional)</label>
                                    <input id="edit-price-<?= $cid ?>" name="price" type="number" class="form-control" min="0.01" max="999999999999.99" step="0.01" value="<?= $ce($classified['price'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label for="edit-description-<?= $cid ?>" class="form-label">Descripción</label>
                                    <textarea id="edit-description-<?= $cid ?>" name="description" class="form-control" rows="4" maxlength="3000" required><?= $ce($classified['description']) ?></textarea>
                                </div>
                                <div class="col-12">
                                    <span class="form-label d-block">Imágenes actuales (marca las que quieres quitar)</span>
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php foreach ($classified['images'] as $image): ?>
                                            <label class="text-center small">
                                                <img src="<?= $ce($basePath) ?>/clasificados/imagen?id=<?= (int) $image['id'] ?>" alt="" loading="lazy" class="rounded object-fit-cover d-block mb-1" style="width: 7rem; height: 5rem">
                                                <input type="checkbox" class="form-check-input me-1" name="remove_images[]" value="<?= (int) $image['id'] ?>">Quitar
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label for="edit-images-<?= $cid ?>" class="form-label">Agregar imágenes (opcional)</label>
                                    <input id="edit-images-<?= $cid ?>" name="images[]" type="file" class="form-control" accept="image/jpeg,image/png,image/webp" multiple>
                                    <div class="form-text">Máximo 5 imágenes en total y al menos una; JPEG, PNG o WebP de hasta 4 MB.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit-contact-name-<?= $cid ?>" class="form-label">Nombre de contacto</label>
                                    <input id="edit-contact-name-<?= $cid ?>" name="contact_name" class="form-control" maxlength="100" value="<?= $ce($classified['contact_name'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="edit-contact-email-<?= $cid ?>" class="form-label">Correo de contacto</label>
                                    <input id="edit-contact-email-<?= $cid ?>" name="contact_email" type="email" class="form-control" maxlength="254" value="<?= $ce($classified['contact_email'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="edit-contact-phone-<?= $cid ?>" class="form-label">Teléfono de contacto</label>
                                    <input id="edit-contact-phone-<?= $cid ?>" name="contact_phone" type="tel" class="form-control" maxlength="30" value="<?= $ce($classified['contact_phone'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input id="edit-consent-<?= $cid ?>" name="contact_authorized" value="1" type="checkbox" class="form-check-input"<?= !empty($classified['contact_authorized']) ? ' checked' : '' ?>>
                                        <label for="edit-consent-<?= $cid ?>" class="form-check-label">Autorizo publicar en la página pública los datos de contacto ingresados arriba.</label>
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm mt-3" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar y enviar a revisión</button>
                            <div class="form-text">Al guardar, el clasificado deja de verse en la página pública hasta que la administración apruebe los cambios.</div>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
