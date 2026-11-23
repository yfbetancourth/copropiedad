<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

/** Comunidad / Tipos de residente: catálogo editable por la administración. */
final class ResidentTypeController
{
    private const PERMISSION = 'comunidad.residentes';
    private const FLASH = 'community_types_flash';
    private const BACK = '/comunidad/tipos-residente';

    public function __construct(private readonly ResidentTypeService $service = new ResidentTypeService())
    {
    }

    public function index(): void
    {
        $actor = $this->require('read');
        Session::start();
        View::renderAdmin('Community/types', [
            'title' => 'Tipos de residente',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'types' => $this->service->all(),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
            'canDelete' => Authorization::hasPermission($actor, self::PERMISSION, 3),
        ]);
    }

    public function save(): void
    {
        $this->require('write');
        Http::requireCsrf();
        $typeId = Http::id($_POST['type_id'] ?? null);
        try {
            $this->service->save(
                $typeId,
                Http::string($_POST['name'] ?? null),
                Http::string($_POST['description'] ?? null),
                ($_POST['requires_unit_owner'] ?? '') === '1'
            );
            Http::flash(self::FLASH, 'success', $typeId > 0 ? 'Tipo de residente actualizado.' : 'Tipo de residente creado.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect(self::BACK);
    }

    public function setStatus(): void
    {
        $this->require('write');
        Http::requireCsrf();
        $active = ($_POST['active'] ?? '') === '1';
        try {
            $this->service->setActive(Http::id($_POST['type_id'] ?? null), $active);
            Http::flash(self::FLASH, 'success', $active ? 'Tipo activado.' : 'Tipo desactivado: ya no se ofrece al crear o importar residentes; quienes lo tienen lo conservan.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect(self::BACK);
    }

    public function delete(): void
    {
        $this->require('total');
        Http::requireCsrf();
        try {
            $this->service->delete(Http::id($_POST['type_id'] ?? null));
            Http::flash(self::FLASH, 'success', 'Tipo de residente eliminado.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect(self::BACK);
    }

    private function require(string $level): array
    {
        $actor = Authorization::requirePermission(self::PERMISSION, $level);
        if ($actor === null) {
            exit;
        }

        return $actor;
    }
}
