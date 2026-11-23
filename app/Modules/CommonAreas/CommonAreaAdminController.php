<?php

declare(strict_types=1);

namespace App\Modules\CommonAreas;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\Session;
use App\Core\View;
use DateTimeImmutable;
use RuntimeException;

/** Panel administrativo: espacios comunes, agenda y decisión de las solicitudes de reserva. */
final class CommonAreaAdminController
{
    private const PERMISSION = 'zonas.comunes';
    private const FLASH = 'common_areas_flash';
    private const AGENDA_DAYS = 14;

    public function __construct(private readonly CommonAreaService $service = new CommonAreaService())
    {
    }

    public function requests(): void
    {
        $actor = $this->require('read');
        $areas = $this->service->areas();
        $areaId = Http::id($_GET['zona'] ?? null);
        $from = self::fromDate(Http::string($_GET['desde'] ?? null));

        $this->render('requests', 'Zonas comunes · Solicitudes', [
            'requests' => $this->service->pendingRequests(),
            'areas' => $areas,
            'areaId' => $areaId,
            'from' => $from,
            'agendaDays' => self::AGENDA_DAYS,
            'agenda' => $this->service->agenda($areaId > 0 ? $areaId : null, $from, self::AGENDA_DAYS),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function areas(): void
    {
        $actor = $this->require('read');
        $editId = Http::id($_GET['editar'] ?? null);
        $editing = null;
        if ($editId > 0) {
            try {
                $editing = $this->service->area($editId);
            } catch (RuntimeException) {
                $editing = null;
            }
        }
        $this->render('areas', 'Zonas comunes · Espacios', [
            'areas' => $this->service->areas(),
            'editing' => $editing,
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function saveArea(): void
    {
        $this->require('write');
        Http::requireCsrf();
        $areaId = Http::id($_POST['area_id'] ?? null);
        try {
            $this->service->saveArea($areaId, $_POST);
            Http::flash(self::FLASH, 'success', $areaId > 0 ? 'Espacio actualizado.' : 'Espacio creado: los residentes ya pueden solicitarlo.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect('/zonas-comunes/espacios' . ($areaId > 0 ? '?editar=' . $areaId : ''));
        }
        Http::redirect('/zonas-comunes/espacios');
    }

    public function setAreaStatus(): void
    {
        $this->require('write');
        Http::requireCsrf();
        $active = ($_POST['active'] ?? '') === '1';
        try {
            $this->service->setAreaActive(Http::id($_POST['area_id'] ?? null), $active);
            Http::flash(self::FLASH, 'success', $active ? 'Espacio activado.' : 'Espacio desactivado: ya no recibe solicitudes; las reservas existentes se conservan.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/zonas-comunes/espacios');
    }

    public function deleteArea(): void
    {
        $this->require('write');
        Http::requireCsrf();
        try {
            $this->service->deleteArea(Http::id($_POST['area_id'] ?? null));
            Http::flash(self::FLASH, 'success', 'Espacio eliminado.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/zonas-comunes/espacios');
    }

    public function approve(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        try {
            $rejected = $this->service->approve(Http::id($_POST['booking_id'] ?? null), Http::string($_POST['notes'] ?? null), (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'Reserva aprobada y residente notificado.'
                . ($rejected > 0 ? ' Se rechazaron ' . $rejected . ' solicitud(es) que se cruzaban con ese horario y se avisó a sus residentes.' : ''));
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/zonas-comunes');
    }

    public function reject(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        try {
            $this->service->reject(Http::id($_POST['booking_id'] ?? null), Http::string($_POST['reason'] ?? null), (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'Solicitud rechazada; el residente recibió el motivo.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/zonas-comunes');
    }

    /** Fecha inicial de la agenda: la pedida (hoy si no es válida), sin ir más de una semana atrás. */
    public static function fromDate(string $value): DateTimeImmutable
    {
        $today = new DateTimeImmutable('today');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            return $today;
        }

        return $date < $today->modify('-7 days') ? $today->modify('-7 days') : $date;
    }

    private function require(string $level): array
    {
        $actor = Authorization::requirePermission(self::PERMISSION, $level);
        if ($actor === null) {
            exit;
        }

        return $actor;
    }

    private function render(string $template, string $title, array $data): void
    {
        Session::start();
        View::renderAdmin('CommonAreas/' . $template, $data + [
            'title' => $title,
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
        ]);
    }
}
