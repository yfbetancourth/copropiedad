<?php

declare(strict_types=1);

/**
 * Hilo de una conversación de Atención a residentes, compartido por el portal y el panel.
 *
 * Espera:
 * - $escape        callable(string): string
 * - $conversation  array con 'messages' (author_side, author_name, body, created_at) y 'full_name'/'username'
 * - $threadViewer  'resident' | 'staff'  (sus mensajes se alinean a la derecha)
 */
$threadResident = (string) ($conversation['full_name'] ?: $conversation['username']);
?>
<div class="d-flex flex-column gap-3">
    <?php foreach ($conversation['messages'] as $threadMessage): ?>
        <?php
        $threadOwn = $threadMessage['author_side'] === $threadViewer;
        $threadFromStaff = $threadMessage['author_side'] === 'staff';
        $threadAuthor = $threadFromStaff
            ? ($threadViewer === 'staff' ? (string) ($threadMessage['author_name'] ?? 'Administración') : 'Administración')
            : ($threadViewer === 'resident' ? 'Tú' : $threadResident);
        ?>
        <div class="d-flex <?= $threadOwn ? 'justify-content-end' : 'justify-content-start' ?>">
            <div class="rounded-3 p-3 <?= $threadOwn ? 'bg-primary-subtle' : 'bg-body-secondary' ?>" style="max-width: min(42rem, 90%)">
                <div class="small fw-semibold mb-1">
                    <i class="bi <?= $threadFromStaff ? 'bi-building' : 'bi-person' ?> me-1" aria-hidden="true"></i><?= $escape($threadAuthor) ?>
                    <span class="fw-normal text-secondary ms-1"><?= $escape(date('d/m/Y H:i', strtotime((string) $threadMessage['created_at']) ?: time())) ?></span>
                </div>
                <div style="white-space: pre-wrap"><?= $escape((string) $threadMessage['body']) ?></div>
                <?php if (($threadMessage['attachment_type'] ?? null) !== null): ?>
                    <?php
                    // Documento adjunto: el enlace abre el documento con el control de acceso de cada escenario.
                    $threadLink = \App\Modules\Billing\AccountDocumentService::link(
                        (string) $threadMessage['attachment_type'],
                        (string) $threadMessage['attachment_ref'],
                        $threadViewer === 'resident'
                    );
                    ?>
                    <a class="d-flex align-items-center gap-2 mt-2 p-2 rounded-2 border bg-body text-decoration-none" href="<?= $escape($basePath . $threadLink) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-file-earmark-text fs-4 text-primary" aria-hidden="true"></i>
                        <span>
                            <span class="d-block small text-secondary"><?= $escape(\App\Modules\Billing\AccountDocumentService::TYPE_LABELS[$threadMessage['attachment_type']] ?? 'Documento') ?></span>
                            <span class="fw-semibold"><?= $escape((string) $threadMessage['attachment_label']) ?></span>
                        </span>
                        <i class="bi bi-box-arrow-up-right ms-auto small" aria-hidden="true"></i><span class="visually-hidden"> (se abre en una pestaña nueva)</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
