<?php
// Bienvenida con la misma estructura que la del panel administrativo.
$welcomeNow = new DateTimeImmutable('now');
$welcomeHour = (int) $welcomeNow->format('G');
$welcomeGreeting = $welcomeHour < 12 ? 'Buenos días' : ($welcomeHour < 19 ? 'Buenas tardes' : 'Buenas noches');
$welcomeWeekdays = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$welcomeMonths = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$welcomeDate = ucfirst($welcomeWeekdays[(int) $welcomeNow->format('w')]) . ', ' . $welcomeNow->format('j') . ' de '
    . $welcomeMonths[(int) $welcomeNow->format('n') - 1] . ' de ' . $welcomeNow->format('Y');
$welcomePending = (int) ($notifications['message_count'] ?? 0) + (int) ($notifications['billing_count'] ?? 0);
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
    $currency = static function (mixed $amount): string {
        if (!is_numeric($amount)) {
            return 'Sin configurar';
        }

        return '$ ' . number_format((float) $amount, 2, ',', '.') . ' COP';
    };
    ?>

    <?php
    // Indicadores con las mismas tarjetas de la bienvenida del panel administrativo.
    $portalKpis = [
        ['Inmuebles asignados', (string) count($units), 'bi-house-door', 'text-primary', count($units) === 1 ? '1 inmueble a tu nombre' : count($units) . ' inmuebles a tu nombre', '#inmuebles', 'Ver mis inmuebles'],
        ['Cuota de administración', $currency($settings['administration_fee'] ?? null), 'bi-cash-stack', 'text-success', 'Aplica desde ' . ($settings['effective_date'] ?? 'sin fecha'), $basePath . '/usuario/pagos', 'Ir a pagos'],
        ['Descuento por pronto pago', $currency($settings['early_payment_discount'] ?? null), 'bi-piggy-bank', 'text-info', 'Si pagas antes de la fecha límite', $basePath . '/usuario/pagos', 'Ver fechas'],
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
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead>
                                <tr><th>Inmueble</th><th>Categoría</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($units as $unit): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($unit['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($unit['category'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (\App\Core\ModuleCatalog::enabled('cartera')): ?>
            <div class="card card-success card-outline mt-3" id="documentos">
                <div class="card-header">
                    <h2 class="card-title">Mis documentos de cobro</h2>
                    <span class="badge text-bg-primary"><?= count($billingDocuments) ?></span>
                </div>
                <div class="card-body">
                    <?php if ($billingDocuments === []): ?>
                        <p class="text-secondary mb-0">No tienes notas de cobro emitidas.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead>
                                <tr><th>Nota</th><th>Inmueble</th><th>Período</th><th>Saldo</th><th>Estado</th><th>Acción</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($billingDocuments as $document): ?>
                                    <?php
                                    $balance = (int) $document['balance'];
                                    $supersededPeriod = is_array($document["superseded_by"] ?? null) ? (string) $document["superseded_by"]["period"] : null;
                                    $payable = $balance > 0 && $supersededPeriod === null;
                                    $paymentStatus = $document['terminal_payment_status'] ?? $document['online_payment_status'] ?? null;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $document['number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $document['unit_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $document['period'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= $supersededPeriod !== null ? '<span class="text-secondary">Incluido en ' . htmlspecialchars($supersededPeriod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' : htmlspecialchars($currency($balance / 100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td>
                                            <?php if ($supersededPeriod !== null): ?><span class="badge text-bg-secondary">Saldo trasladado a <?= htmlspecialchars($supersededPeriod, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
                                    <?php elseif ($balance <= 0): ?>
                                                <span class="badge text-bg-success">Pagada</span>
                                            <?php elseif ($paymentStatus === 'approved'): ?>
                                                <span class="badge text-bg-success">Pago aprobado</span>
                                            <?php elseif ($paymentStatus === 'pending'): ?>
                                                <span class="badge text-bg-warning">Pago en proceso</span>
                                            <?php elseif ($paymentStatus === 'rejected'): ?>
                                                <span class="badge text-bg-danger">Pago rechazado</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($payable && $onlinePaymentsReady): ?>
                                                <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/pagar">
                                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                    <input type="hidden" name="statement_id" value="<?= (int) $document['id'] ?>">
                                                    <button class="btn btn-success btn-sm" type="submit">
                                                        <?= $document['checkout_url'] !== null ? 'Continuar pago en línea' : 'Pago en línea' ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($payable && $terminalPaymentsReady): ?>
                                                <form class="mt-1" method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/pagar-datafono" aria-label="Pagar nota en datáfono Bold">
                                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                    <input type="hidden" name="statement_id" value="<?= (int) $document['id'] ?>">
                                                    <button class="btn btn-outline-success btn-sm" type="submit">Datáfono Bold · <?= htmlspecialchars($currency($balance / 100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($supersededPeriod !== null): ?>
                                        <span class="text-secondary small">Paga la nota más reciente</span>
                                    <?php elseif ($payable && !$onlinePaymentsReady && !$terminalPaymentsReady): ?>
                                                <span class="text-secondary small">Pago en línea no disponible</span>
                                            <?php elseif ($balance <= 0): ?>
                                                <span class="text-secondary small">Sin saldo pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-xl-4">
            <div class="card" id="perfil">
                <div class="card-header"><h2 class="card-title">Personalizar mi cuenta</h2></div>
                <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/perfil">
                    <div class="card-body">
                        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label for="profile-username" class="form-label">Nombre de usuario</label>
                            <input id="profile-username" name="username" class="form-control" minlength="3" maxlength="64" autocomplete="username" required value="<?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        </div>
                        <div class="mb-3">
                            <label for="profile-email" class="form-label">Correo electrónico</label>
                            <input id="profile-email" name="email" type="email" class="form-control" maxlength="254" autocomplete="email" required value="<?= htmlspecialchars($user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        </div>
                        <hr>
                        <p class="small text-secondary">Confirma tu contraseña actual para guardar los cambios. La nueva contraseña es opcional.</p>
                        <div class="mb-3">
                            <label for="current-password" class="form-label">Contraseña actual</label>
                            <input id="current-password" name="current_password" type="password" class="form-control" autocomplete="current-password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new-password" class="form-label">Nueva contraseña</label>
                            <input id="new-password" name="new_password" type="password" class="form-control" minlength="12" autocomplete="new-password">
                            <small class="text-secondary">Déjala vacía si no deseas cambiarla. Mínimo 12 caracteres.</small>
                        </div>
                        <div class="mb-0">
                            <label for="password-confirmation" class="form-label">Confirmar nueva contraseña</label>
                            <input id="password-confirmation" name="password_confirmation" type="password" class="form-control" minlength="12" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary" type="submit">Guardar datos de mi cuenta</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (\App\Core\ModuleCatalog::enabled('clasificados')): ?>
    <div class="card card-info card-outline mt-3" id="clasificados">
        <div class="card-header">
            <h2 class="card-title">Mis clasificados de inmuebles</h2>
            <span class="badge text-bg-primary"><?= count($classifieds) ?></span>
        </div>
        <div class="card-body">
            <p class="text-secondary">Publica un inmueble en venta o una búsqueda de compra. Las publicaciones activas son visibles en la página pública.</p>
            <form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/clasificados/guardar">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="classified-operation" class="form-label">Publicación</label>
                        <select id="classified-operation" name="operation" class="form-select" required>
                            <option value="sale">Ofrezco en venta</option>
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
                        <label for="classified-price" class="form-label">Precio o presupuesto (COP, opcional)</label>
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
                <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>Publicar clasificado</button>
            </form>
        </div>
        <?php if ($classifieds !== []): ?>
            <div class="list-group list-group-flush">
                <?php foreach ($classifieds as $classified): ?>
                    <article class="list-group-item p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                            <div>
                                <h3 class="h5 mb-1"><?= htmlspecialchars($classified['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                                <p class="small text-secondary mb-2">
                                    <?= $classified['operation'] === 'sale' ? 'En venta' : 'Busca comprar' ?>
                                    · <?= htmlspecialchars($classified['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    · <?= $classified['status'] === 'published' ? 'Publicado' : 'Cerrado' ?>
                                    · <?= count($classified['images']) ?> imagen(es)
                                </p>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($classified['images'] as $image): ?>
                                        <img src="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/clasificados/imagen?id=<?= (int) $image['id'] ?>"
                                             alt="Imagen de <?= htmlspecialchars($classified['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                             loading="lazy" class="rounded object-fit-cover" style="width: 7rem; height: 5rem">
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/clasificados/estado">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <input type="hidden" name="listing_id" value="<?= (int) $classified['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $classified['status'] === 'published' ? 'closed' : 'published' ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">
                                        <?= $classified['status'] === 'published' ? 'Cerrar publicación' : 'Volver a publicar' ?>
                                    </button>
                                </form>
                                <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/clasificados/eliminar" onsubmit="return confirm('¿Eliminar este clasificado y sus imágenes?');">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <input type="hidden" name="listing_id" value="<?= (int) $classified['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
