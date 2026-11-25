<?php

declare(strict_types=1);

use App\Modules\Billing\AccountDocumentService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$date = static fn (string $value): string => date('d/m/Y', strtotime($value) ?: 0);
?>
<article class="legal-page section-block" aria-labelledby="verify-title">
    <header class="legal-header">
        <p class="eyebrow"><span class="eyebrow-line"></span> Cartera</p>
        <h1 id="verify-title">Verificar paz y salvo</h1>
        <p class="legal-meta">Escribe el código de verificación que aparece en el documento para comprobar que lo expidió la administración de <?= $escape($propertyName) ?> y si sigue vigente.</p>
    </header>

    <form class="contact-form" method="get" action="<?= $escape($basePath) ?>/verificar-paz-y-salvo" style="max-width: 460px">
        <div class="field">
            <label for="verify-code">Código de verificación</label>
            <input type="text" id="verify-code" name="codigo" value="<?= $escape($code) ?>" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX" required>
        </div>
        <button class="button button-dark" type="submit">Verificar <span aria-hidden="true">↗</span></button>
    </form>

    <?php if ($code !== ''): ?>
        <div style="margin-top: 32px; max-width: 560px" role="status">
            <?php if ($clearance === null): ?>
                <div class="form-notice form-notice-error"><strong>No encontramos un paz y salvo con ese código.</strong> Revisa que lo hayas escrito completo; si el problema continúa, comunícate con la administración.</div>
            <?php elseif ($clearance['expired']): ?>
                <div class="form-notice form-notice-error">
                    <strong>Paz y salvo vencido.</strong> El código <?= $escape(AccountDocumentService::formatCode($clearance['code'])) ?> corresponde a <?= $escape($clearance['unit_name']) ?>, expedido el <?= $escape($date($clearance['issued_at'])) ?> y vigente hasta el <?= $escape($date($clearance['valid_until'])) ?>. Pide uno actualizado.
                </div>
            <?php else: ?>
                <div class="form-notice form-notice-success">
                    <strong>Paz y salvo válido.</strong> El inmueble <strong><?= $escape($clearance['unit_name']) ?></strong> estaba a paz y salvo cuando se expidió el documento, el <?= $escape($date($clearance['issued_at'])) ?>. Vigente hasta el <?= $escape($date($clearance['valid_until'])) ?>.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>
