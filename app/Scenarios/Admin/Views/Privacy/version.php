<?php

declare(strict_types=1);

use App\Modules\Privacy\PrivacyService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$date = static fn (?string $value): string => $value ? date('d/m/Y h:i a', strtotime($value) ?: 0) : '';
?>
<section class="content">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <div>
            <h1 class="h3 mb-1"><?= $escape($label) ?> · versión <?= (int) $version['version'] ?></h1>
            <p class="text-secondary mb-0">
                Publicada el <?= $escape($date($version['published_at'])) ?><?= $version['published_by_name'] !== null ? ' por ' . $escape($version['published_by_name']) : '' ?>.
                Cambio: <?= $escape($version['change_summary']) ?>
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary" href="<?= $escape($basePath) ?>/configuracion/privacidad?documento=<?= $escape($version['document']) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver</a>
            <button class="btn btn-outline-secondary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir</button>
        </div>
    </div>
    <div class="card">
        <div class="card-body" style="max-width: 860px">
            <p class="small text-secondary">Texto exacto de esta versión, tal como lo vieron y aceptaron los titulares mientras estuvo vigente.</p>
            <?php foreach (PrivacyService::blocks($version['content']) as $block): ?>
                <?php if ($block['type'] === 'heading'): ?>
                    <h2 class="h6 mt-4"><?= $escape($block['text']) ?></h2>
                <?php elseif ($block['type'] === 'list'): ?>
                    <ul><?php foreach ($block['items'] as $item): ?><li><?= $escape($item) ?></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p><?= $escape($block['text']) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
