<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

/** Panel administrativo: revisión de los clasificados de los residentes (publicar o no publicar). */
final class ClassifiedsAdminController
{
    private const PERMISSION = 'clasificados';
    private const FLASH = 'classifieds_admin_flash';

    public function __construct(private readonly ClassifiedsService $service = new ClassifiedsService())
    {
    }

    public function index(): void
    {
        $actor = $this->require('read');
        $filter = Http::string($_GET['estado'] ?? null);
        if ($filter !== 'todos' && !isset(ClassifiedsService::STATUS_LABELS[$filter])) {
            $filter = 'pending';
        }

        Session::start();
        View::renderAdmin('Classifieds/index', [
            'title' => 'Comunidad · Clasificados',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'filter' => $filter,
            'counts' => $this->service->countsByStatus(),
            'listings' => $this->service->forReview($filter === 'todos' ? null : $filter),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function approve(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        try {
            $this->service->approve(Http::id($_POST['listing_id'] ?? null), (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'Clasificado publicado; el residente recibió el aviso.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->back();
    }

    public function reject(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        try {
            $this->service->reject(Http::id($_POST['listing_id'] ?? null), Http::string($_POST['reason'] ?? null), (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'El clasificado no se publica; el residente recibió el motivo.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->back();
    }

    /** Vuelve a la pestaña desde la que se decidió. */
    private function back(): never
    {
        $filter = Http::string($_POST['estado'] ?? null);
        Http::redirect('/clasificados' . ($filter === 'todos' || isset(ClassifiedsService::STATUS_LABELS[$filter]) ? '?estado=' . $filter : ''));
    }

    private function require(string $level): array
    {
        ModuleCatalog::requireEnabled('clasificados');
        $actor = Authorization::requirePermission(self::PERMISSION, $level);
        if ($actor === null) {
            exit;
        }

        return $actor;
    }
}
