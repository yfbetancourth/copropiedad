<section class="content">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-1">Panel de Usuario</h1>
            <p class="text-secondary mb-0">Bienvenido, <?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</p>
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

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
            <div class="info-box">
                <span class="info-box-icon text-bg-primary"><i class="bi bi-house-door" aria-hidden="true"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Inmuebles asignados</span>
                    <span class="info-box-number"><?= count($units) ?></span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="info-box">
                <span class="info-box-icon text-bg-info"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Canon mensual actual</span>
                    <span class="info-box-number"><?= htmlspecialchars($currency($settings['administration_fee'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="progress-description">Aplica desde <?= htmlspecialchars((string) ($settings['effective_date'] ?? 'sin fecha'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="info-box">
                <span class="info-box-icon text-bg-warning"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Multas generadas</span>
                    <span class="info-box-number">Sin registro</span>
                    <span class="progress-description">Aún no hay historial de multas en el sistema.</span>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="info-box">
                <span class="info-box-icon text-bg-success"><i class="bi bi-piggy-bank" aria-hidden="true"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Ahorro por pronto pago</span>
                    <span class="info-box-number"><?= htmlspecialchars($currency($settings['early_payment_discount'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="progress-description">Descuento configurado; el ahorro aplicado requiere historial de pagos.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card card-primary card-outline">
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
                                    $paymentStatus = $document['online_payment_status'] ?? null;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $document['number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $document['unit_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) $document['period'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($currency($balance / 100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td>
                                            <?php if ($balance <= 0): ?>
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
                                            <?php if ($balance > 0 && $onlinePaymentsReady): ?>
                                                <form method="post" action="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/usuario/pagar">
                                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                    <input type="hidden" name="statement_id" value="<?= (int) $document['id'] ?>">
                                                    <button class="btn btn-success btn-sm" type="submit">
                                                        <?= $document['checkout_url'] !== null ? 'Continuar pago' : 'Pagar con Bold' ?>
                                                    </button>
                                                </form>
                                            <?php elseif ($balance > 0): ?>
                                                <span class="text-secondary small">Pago en línea no disponible</span>
                                            <?php else: ?>
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
        </div>
        <div class="col-xl-4">
            <div class="card">
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
</section>
