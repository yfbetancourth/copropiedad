<?php

declare(strict_types=1);

/**
 * Tarjeta «Conectar tu cuenta de Google o Microsoft», compartida por Configuración / Correo y Mi correo.
 *
 * Espera: $escape, $basePath, $csrfToken, $canWrite (bool), $connectedAccount (?array), $oauthProviders (array),
 *         $oauthResult (?array), $connectPath, $disconnectPath, $accountDescription.
 */
use App\Modules\Configuration\MailOAuthService;

$providerIcons = ['google' => 'bi-google', 'microsoft' => 'bi-microsoft'];
?>
<?php if (is_array($oauthResult ?? null)): ?>
    <div class="alert alert-<?= $oauthResult['type'] === 'success' ? 'success' : 'danger' ?>" role="<?= $oauthResult['type'] === 'success' ? 'status' : 'alert' ?>"><?= $escape($oauthResult['message']) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="card-title h6 mb-0"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Conectar tu cuenta de correo <span class="badge text-bg-success ms-1">Recomendado</span></h2>
        <?php if ($connectedAccount !== null): ?>
            <span class="badge text-bg-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Conectada</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($connectedAccount !== null): ?>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <i class="bi <?= $escape($providerIcons[$connectedAccount['provider']] ?? 'bi-envelope') ?> fs-2 text-primary" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= $escape($connectedAccount['email']) ?></div>
                    <div class="small text-secondary">
                        <?= $escape($connectedAccount['provider_label']) ?> · conectada el <?= $escape(date('d/m/Y h:i a', strtotime($connectedAccount['connected_at']) ?: 0)) ?>
                        <?= $connectedAccount['last_sent_at'] !== null ? ' · último envío ' . $escape(date('d/m/Y h:i a', strtotime($connectedAccount['last_sent_at']) ?: 0)) : '' ?>
                    </div>
                    <div class="small"><?= $escape($accountDescription) ?></div>
                </div>
                <?php if ($canWrite): ?>
                    <form method="post" action="<?= $escape($basePath . $disconnectPath) ?>" onsubmit="return confirm('¿Desconectar esta cuenta? Se revoca el permiso de envío.');">
                        <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                        <button class="btn btn-outline-danger btn-sm" type="submit">Desconectar</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($connectedAccount['last_error'] !== null): ?>
                <div class="alert alert-warning small mt-3 mb-0"><strong>Último error al enviar:</strong> <?= $escape($connectedAccount['last_error']) ?></div>
            <?php endif; ?>
        <?php elseif ($oauthProviders === []): ?>
            <p class="small text-secondary mb-0">El inicio de sesión con Google o Microsoft aún no está habilitado en la plataforma. El administrador de la plataforma debe registrar la aplicación (ver README, «Correo con Google y Microsoft»). Mientras tanto, usa el servidor SMTP.</p>
        <?php else: ?>
            <p class="text-secondary">Inicia sesión con el correo que ya usas. La plataforma solo recibe permiso para <strong>enviar</strong> (no lee tu buzón) y nunca ve tu contraseña. Puedes quitar el permiso cuando quieras.</p>
            <?php if ($canWrite): ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($oauthProviders as $providerKey => $providerLabel): ?>
                        <form method="post" action="<?= $escape($basePath . $connectPath) ?>">
                            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="provider" value="<?= $escape($providerKey) ?>">
                            <button class="btn btn-outline-primary" type="submit" title="<?= $escape($providerLabel) ?>">
                                <i class="bi <?= $escape($providerIcons[$providerKey] ?? 'bi-envelope') ?> me-1" aria-hidden="true"></i><?= $escape(MailOAuthService::PROVIDERS[$providerKey]['button']) ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <p class="small text-secondary mt-2 mb-0">Límites de envío de los proveedores: unos 500 correos al día en Gmail personal, 2.000 en Google Workspace y 300 en Outlook.com.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
