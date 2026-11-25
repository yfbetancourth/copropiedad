<?php

declare(strict_types=1);

use App\Modules\Privacy\PrivacyService;

$escape = static fn (mixed $value): string => htmlspecialchars(
    is_scalar($value) ? (string) $value : '',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$related = array_filter(
    PrivacyService::DOCUMENTS,
    static fn (string $key): bool => $key !== $documentKey,
    ARRAY_FILTER_USE_KEY
);
?>
<article class="legal-page section-block" aria-labelledby="legal-title">
    <header class="legal-header">
        <p class="eyebrow"><span class="eyebrow-line"></span> Privacidad</p>
        <h1 id="legal-title"><?= $escape($title) ?></h1>
        <?php if ($version !== null): ?>
            <p class="legal-meta">
                Versión <?= (int) $version['version'] ?> · vigente desde el <?= $escape(PrivacyService::longDate($version['published_at'])) ?>
            </p>
        <?php endif; ?>
    </header>

    <?php if ($version === null): ?>
        <p class="legal-empty">Este documento aún no ha sido publicado por la administración del conjunto.</p>
    <?php else: ?>
        <div class="legal-body">
            <?php foreach ($blocks as $block): ?>
                <?php if ($block['type'] === 'heading'): ?>
                    <h2><?= $escape($block['text']) ?></h2>
                <?php elseif ($block['type'] === 'list'): ?>
                    <ul>
                        <?php foreach ($block['items'] as $item): ?><li><?= $escape($item) ?></li><?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p><?= $escape($block['text']) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <nav class="legal-related" aria-label="Otros documentos de privacidad">
        <?php foreach ($related as $definition): ?>
            <a class="text-link" href="<?= $escape($basePath . $definition['public_path']) ?>"><?= $escape($definition['label']) ?> <span aria-hidden="true">→</span></a>
        <?php endforeach; ?>
    </nav>
</article>
