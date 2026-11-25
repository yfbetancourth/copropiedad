<?php

declare(strict_types=1);

/**
 * Menú de la cuenta (avatar en la cabecera) compartido por el panel administrativo y el portal.
 *
 * Espera:
 * - $escape        callable(string): string
 * - $account       array{name: string, email: string, role: string, initial: string}
 * - $accountGroups list<array{title: string, items: list<array{href: string, icon: string, label: string, active?: bool, newTab?: bool}>}>
 * - $logoutAction  string
 * - $csrfToken     string
 * - $sidebarSections (opcional) las opciones del menú con contador se suman en el avatar y se listan en «Pendientes»
 */
$accountPending = [];
foreach ($sidebarSections ?? [] as $pendingSection) {
    foreach ($pendingSection['items'] as $pendingItem) {
        if (($pendingItem['badge'] ?? 0) > 0) {
            $accountPending[] = $pendingItem;
        }
    }
}
$accountPendingTotal = array_sum(array_map(static fn (array $item): int => (int) $item['badge'], $accountPending));
?>
<li class="nav-item dropdown">
    <button popovertarget="account-menu" class="btn btn-link nav-link d-flex align-items-center gap-2 px-2 dropdown-toggle text-body text-decoration-none"
            type="button"
            aria-label="Menú de la cuenta de <?= $escape($account['name']) ?><?= $accountPendingTotal > 0 ? ': ' . $accountPendingTotal . ' pendiente(s)' : '' ?>">
        <?php $avatarInitial = $account['initial']; $avatarSize = '2.25rem'; $avatarBadge = $accountPendingTotal; $avatarClass = ''; ?>
        <?php require __DIR__ . '/avatar.php'; ?>
        <span class="d-none d-md-flex flex-column align-items-start lh-sm text-start">
            <span class="fw-semibold"><?= $escape($account['name']) ?></span>
            <?php if ($account['role'] !== ''): ?>
                <span class="small text-secondary"><?= $escape($account['role']) ?></span>
            <?php endif; ?>
        </span>
    </button>
    <div id="account-menu" popover class="dropdown-menu dropdown-menu-end shadow border-0 p-0 overflow-hidden" style="min-width: 18rem">
        <div class="d-flex align-items-center gap-3 px-3 py-3 bg-body-tertiary border-bottom">
            <span class="rounded-circle bg-primary text-white fw-semibold fs-5 d-inline-flex align-items-center justify-content-center flex-shrink-0"
                  style="width: 3rem; height: 3rem" aria-hidden="true"><?= $escape($account['initial']) ?></span>
            <div style="min-width: 0">
                <div class="fw-semibold text-truncate"><?= $escape($account['name']) ?></div>
                <?php if ($account['email'] !== ''): ?>
                    <div class="small text-secondary text-truncate"><?= $escape($account['email']) ?></div>
                <?php endif; ?>
                <?php if ($account['role'] !== ''): ?>
                    <span class="badge rounded-pill text-bg-primary mt-1"><?= $escape($account['role']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($accountPending !== []): ?>
            <div class="py-2 border-bottom">
                <h6 class="dropdown-header">Pendientes</h6>
                <?php foreach ($accountPending as $item): ?>
                    <a class="dropdown-item py-2 d-flex align-items-center" href="<?= $escape($item['href']) ?>">
                        <i class="bi <?= $escape($item['icon']) ?> me-2" aria-hidden="true"></i><?= $escape($item['label']) ?>
                        <span class="badge rounded-pill text-bg-danger ms-auto ps-2"><?= (int) $item['badge'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php foreach ($accountGroups as $groupIndex => $group): ?>
            <?php if ($group['items'] === []) { continue; } ?>
            <div class="py-2<?= $groupIndex > 0 ? ' border-top' : '' ?>">
                <h6 class="dropdown-header"><?= $escape($group['title']) ?></h6>
                <?php foreach ($group['items'] as $item): ?>
                    <?php $active = (bool) ($item['active'] ?? false); $newTab = (bool) ($item['newTab'] ?? false); ?>
                    <a class="dropdown-item py-2 d-flex align-items-center<?= $active ? ' active' : '' ?>" href="<?= $escape($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?><?= $newTab ? ' target="_blank" rel="noopener"' : '' ?>>
                        <i class="bi <?= $escape($item['icon']) ?> me-2" aria-hidden="true"></i><?= $escape($item['label']) ?>
                        <?php if ($newTab): ?>
                            <i class="bi bi-box-arrow-up-right ms-auto small text-secondary" aria-hidden="true"></i>
                            <span class="visually-hidden">(se abre en una pestaña nueva)</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="border-top p-2">
            <form method="post" action="<?= $escape($logoutAction) ?>">
                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>">
                <button class="dropdown-item rounded py-2 text-danger d-flex align-items-center" type="submit">
                    <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Cerrar sesión
                </button>
            </form>
            <p class="small text-secondary px-3 pt-1 mb-0">La sesión se cierra tras 30 minutos sin actividad.</p>
        </div>
    </div>
</li>
