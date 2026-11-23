<?php

declare(strict_types=1);

use App\Modules\Billing\BillingService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formatDate = static fn (string $value): string => date('d/m/Y H:i', strtotime($value) ?: time());
$stateBadges = ['active' => ['success', 'Vigente'], 'expired' => ['secondary', 'Vencido'], 'revoked' => ['danger', 'Revocado']];
$payable = (int) $statement['payable_balance'];
$superseded = $statement['superseded_by'];
?>
<section class="content">
    <a class="small text-decoration-none d-inline-block mb-2" href="<?= $escape($basePath) ?>/cartera/cobros?periodo=<?= $escape(rawurlencode((string) $statement['period'])) ?>#notas">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Cartera / Cobros
    </a>
    <div class="mb-3">
        <h1 class="h3 mb-1"><i class="bi bi-qr-code me-2" aria-hidden="true"></i>Enlace de pago y QR</h1>
        <p class="text-secondary mb-0">Genera un enlace temporal para pagar esta nota en línea (Bold o Wompi) sin iniciar sesión. Envíalo por correo, compártelo por WhatsApp o muestra el QR para que lo escaneen con el celular.</p>
    </div>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" role="status"><?= $escape((string) $flash['message']) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="card-title h6 mb-0">Nota <?= $escape((string) $statement['number']) ?></h2></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5">Inmueble</dt><dd class="col-7"><?= $escape((string) $statement['unit_name']) ?></dd>
                        <dt class="col-5">Período</dt><dd class="col-7"><?= $escape((string) $statement['period']) ?></dd>
                        <dt class="col-5">Saldo</dt><dd class="col-7 fw-semibold"><?= $escape(BillingService::money($payable)) ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100 card-primary card-outline">
                <div class="card-header"><h2 class="card-title h6 mb-0">Nuevo enlace temporal</h2></div>
                <div class="card-body">
                    <?php if ($superseded !== null): ?>
                        <div class="alert alert-light border mb-0">El saldo de esta nota pasó a la nota de <?= $escape((string) $superseded['period']) ?>. Genera el enlace desde la nota más reciente.</div>
                    <?php elseif ($payable <= 0): ?>
                        <div class="alert alert-light border mb-0">La nota no tiene saldo pendiente.</div>
                    <?php elseif (!$onlineReady): ?>
                        <div class="alert alert-warning mb-0">Configura Bold o Wompi en <a href="<?= $escape($basePath) ?>/configuracion/metodos-pago">Métodos de pago</a> (el Superadmin debe habilitar su módulo) para generar enlaces.</div>
                    <?php elseif ($recipients === []): ?>
                        <div class="alert alert-warning mb-0">La nota no tiene cuentas destinatarias. Asigna el inmueble a una cuenta y emite la nota para poder atribuir el pago.</div>
                    <?php elseif (!$canWrite): ?>
                        <div class="alert alert-light border mb-0">Tu rol puede consultar los enlaces, pero no generarlos.</div>
                    <?php else: ?>
                        <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/enlaces/crear" class="row g-3 align-items-end">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="statement_id" value="<?= (int) $statement['id'] ?>">
                            <div class="col-md-6">
                                <label class="form-label" for="link-user">Pago a nombre de</label>
                                <select class="form-select" id="link-user" name="user_id" required>
                                    <?php foreach ($recipients as $recipient): ?>
                                        <option value="<?= (int) $recipient['id'] ?>"><?= $escape($recipient['name']) ?> · <?= $escape($recipient['email']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="link-hours">Vigencia</label>
                                <select class="form-select" id="link-hours" name="hours">
                                    <?php foreach ($durations as $hours => $label): ?>
                                        <option value="<?= (int) $hours ?>"<?= $hours === 72 ? ' selected' : '' ?>><?= $escape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="link-send" name="send" value="1" checked>
                                    <label class="form-check-label" for="link-send">Enviar por correo</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Generar enlace</button>
                                <span class="small text-secondary ms-2">El enlace cobra el saldo vigente al momento de pagar.</span>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <h2 class="h5 mt-4 mb-3">Enlaces de esta nota</h2>
    <?php if ($links === []): ?>
        <p class="text-secondary">Aún no se han generado enlaces para esta nota.</p>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($links as $link): ?>
            <?php
            [$badge, $stateLabel] = $stateBadges[$link['state']];
            $active = $link['state'] === 'active' && $link['url'] !== '';
            $qrId = 'qr-' . (int) $link['id'];
            $shareText = 'Enlace para pagar la nota ' . $statement['number'] . ' (' . $statement['unit_name'] . '): ' . $link['url'];
            ?>
            <div class="col-md-6 col-xl-4" id="enlace-<?= (int) $link['id'] ?>">
                <div class="card h-100<?= $highlight === (int) $link['id'] ? ' border-primary shadow' : '' ?>">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span class="badge text-bg-<?= $badge ?>"><?= $escape($stateLabel) ?></span>
                        <span class="small text-secondary me-auto"><?= $link['channel'] === 'portal' ? 'Creado por el residente' : 'Creado por ' . $escape((string) $link['creator_name']) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($active): ?>
                            <div class="mx-auto mb-3" style="max-width: 220px" id="<?= $escape($qrId) ?>" data-qr="<?= $escape($link['url']) ?>"
                                 data-qr-label="QR para pagar la nota <?= $escape((string) $statement['number']) ?>"></div>
                        <?php endif; ?>
                        <dl class="row small mb-0">
                            <dt class="col-5">A nombre de</dt><dd class="col-7"><?= $escape((string) $link['payer_name']) ?></dd>
                            <dt class="col-5">Vence</dt><dd class="col-7"><?= $escape($formatDate((string) $link['expires_at'])) ?></dd>
                            <dt class="col-5">Enviado</dt><dd class="col-7"><?= $link['sent_at'] !== null ? $escape((string) $link['sent_to']) . ' · ' . $escape($formatDate((string) $link['sent_at'])) : '—' ?></dd>
                            <dt class="col-5">Aperturas</dt><dd class="col-7"><?= (int) $link['open_count'] ?><?= $link['last_opened_at'] !== null ? ' · última ' . $escape($formatDate((string) $link['last_opened_at'])) : '' ?></dd>
                        </dl>
                    </div>
                    <?php if ($active): ?>
                        <div class="card-footer d-flex flex-wrap gap-2">
                            <button class="btn btn-outline-primary btn-sm" type="button" data-copy="<?= $escape($link['url']) ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copiar enlace</button>
                            <a class="btn btn-outline-success btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=<?= $escape(rawurlencode($shareText)) ?>"><i class="bi bi-whatsapp me-1" aria-hidden="true"></i>WhatsApp</a>
                            <a class="btn btn-outline-secondary btn-sm" data-qr-download="<?= $escape($qrId) ?>" download="qr-pago-<?= $escape((string) $statement['number']) ?>.gif" href="#"><i class="bi bi-download me-1" aria-hidden="true"></i>QR</a>
                            <?php if ($canWrite): ?>
                                <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/enlaces/enviar">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="statement_id" value="<?= (int) $statement['id'] ?>">
                                    <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= $link['sent_at'] !== null ? 'Reenviar' : 'Enviar' ?></button>
                                </form>
                                <form method="post" action="<?= $escape($basePath) ?>/cartera/cobros/enlaces/revocar" onsubmit="return confirm('¿Revocar este enlace? Dejará de permitir el pago.');">
                                    <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="statement_id" value="<?= (int) $statement['id'] ?>">
                                    <input type="hidden" name="link_id" value="<?= (int) $link['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Revocar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php require dirname(__DIR__, 3) . '/Shared/qr-script.php'; ?>
