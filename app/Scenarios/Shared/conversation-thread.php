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
            </div>
        </div>
    <?php endforeach; ?>
</div>
