<?php

declare(strict_types=1);

use App\Modules\Billing\BillingService;

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$results = [
    'approved' => ['bi-check-circle-fill text-success', 'Pago aprobado', 'La pasarela confirmó el pago. La administración ya lo ve aplicado en la nota y las cuentas del inmueble reciben el comprobante por correo.'],
    'pending' => ['bi-hourglass-split text-warning', 'Pago en proceso', 'La pasarela aún no confirma la transacción. Cuando se apruebe, el saldo se actualizará y llegará el comprobante por correo.'],
    'rejected' => ['bi-x-circle-fill text-danger', 'Pago no aprobado', 'La pasarela no aprobó el pago. Puedes volver a intentarlo desde el mismo enlace mientras esté vigente.'],
];
?>
<?php if ($result !== null): ?>
    <?php [$icon, $heading, $text] = $results[$result]; ?>
    <div class="text-center">
        <i class="bi <?= $escape($icon) ?> d-block mb-3" style="font-size: 3rem" aria-hidden="true"></i>
        <h1><?= $escape($heading) ?></h1>
        <p class="lead-text"><?= $escape($text) ?></p>
    </div>
<?php elseif ($link === null): ?>
    <div class="text-center">
        <i class="bi bi-link-45deg d-block mb-3 text-secondary" style="font-size: 3rem" aria-hidden="true"></i>
        <h1>Enlace no disponible</h1>
        <p class="lead-text">Este enlace de pago venció, fue revocado o no es válido. Pide a la administración un enlace nuevo o paga desde tu portal de residente.</p>
        <a class="btn btn-primary" href="<?= $escape($basePath) ?>/login?destino=usuario">Ingresar al portal</a>
    </div>
<?php else: ?>
    <h1>Pagar nota de cobro</h1>
    <p class="lead-text"><?= $escape($propertyName) ?></p>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-exclamation-circle-fill mt-1" aria-hidden="true"></i><div><?= $escape($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($statement !== null): ?>
        <div class="border rounded-3 p-3 mb-3 bg-white">
            <dl class="row mb-0">
                <dt class="col-5 fw-normal text-secondary">Inmueble</dt><dd class="col-7 fw-semibold"><?= $escape((string) $statement['unit_name']) ?></dd>
                <dt class="col-5 fw-normal text-secondary">Nota</dt><dd class="col-7"><?= $escape((string) $statement['number']) ?></dd>
                <dt class="col-5 fw-normal text-secondary">Período</dt><dd class="col-7"><?= $escape((string) $statement['period']) ?></dd>
                <dt class="col-5 fw-normal text-secondary">Vence el enlace</dt><dd class="col-7"><?= $escape(date('d/m/Y H:i', strtotime((string) $link['expires_at']) ?: time())) ?></dd>
                <dt class="col-5 fw-normal text-secondary">Saldo a pagar</dt><dd class="col-7 fs-4 fw-bold mb-0"><?= $escape(BillingService::money((int) $statement['payable_balance'])) ?></dd>
            </dl>
        </div>
        <form method="post" action="<?= $escape($basePath) ?>/pagar" data-auth-form class="d-grid gap-2">
            <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="t" value="<?= $escape($token) ?>">
            <?php foreach ($gateways as $index => $gateway): ?>
                <button class="btn <?= $index === 0 ? 'btn-primary' : 'btn-outline-primary' ?> btn-lg" type="submit" name="gateway" value="<?= $escape($gateway) ?>">
                    <i class="bi bi-credit-card me-2" aria-hidden="true"></i>Pagar en línea con <?= $escape(\App\Modules\Billing\PaymentGateways::LABELS[$gateway]) ?>
                </button>
            <?php endforeach; ?>
        </form>
        <p class="small text-secondary mt-3 mb-0"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>El pago se procesa en la página segura de la pasarela. El saldo se actualiza cuando la pasarela confirma la transacción y el comprobante llega por correo a las cuentas del inmueble.</p>
    <?php endif; ?>
<?php endif; ?>
