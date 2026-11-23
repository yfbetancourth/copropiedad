<?php

declare(strict_types=1);

namespace App\Modules\CommonAreas;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\View;
use App\Modules\Auth\PortalNotificationService;
use DateTimeImmutable;
use RuntimeException;

/** Portal del residente: agenda de las zonas comunes, solicitud de reserva y cancelación. */
final class CommonAreaPortalController
{
    private const FLASH = 'user_profile_flash';
    private const AGENDA_DAYS = 14;

    public function __construct(private readonly CommonAreaService $service = new CommonAreaService())
    {
    }

    public function index(): void
    {
        $user = $this->requireResident();
        $areas = $this->service->areas(true);
        $areaId = Http::id($_GET['zona'] ?? null);
        $selected = null;
        foreach ($areas as $area) {
            if ((int) $area['id'] === $areaId || ($areaId === 0 && $selected === null)) {
                $selected = $area;
            }
        }
        $today = new DateTimeImmutable('today');
        $from = CommonAreaAdminController::fromDate(Http::string($_GET['desde'] ?? null));
        $from = $from < $today ? $today : $from;

        Session::start();
        View::renderPortal('CommonAreas/index', [
            'title' => 'Zonas comunes',
            'user' => $user,
            'areas' => $areas,
            'selected' => $selected,
            'from' => $from,
            'agendaDays' => self::AGENDA_DAYS,
            'agenda' => $selected !== null ? $this->service->agenda((int) $selected['id'], $from, self::AGENDA_DAYS) : [],
            'bookings' => $this->service->forUser((int) $user['id']),
            'notifications' => (new PortalNotificationService())->unreadForUser((int) $user['id']),
            'flash' => Http::pullFlash(self::FLASH),
            'csrfToken' => Session::csrfToken(),
        ]);
    }

    public function request(): void
    {
        $user = $this->requireResident();
        Http::requireCsrf();
        $areaId = Http::id($_POST['area_id'] ?? null);
        $date = Http::string($_POST['date'] ?? null);
        try {
            $this->service->request(
                (int) $user['id'],
                $areaId,
                $date,
                Http::string($_POST['start'] ?? null),
                Http::string($_POST['end'] ?? null),
                Http::string($_POST['guests'] ?? null),
                Http::string($_POST['notes'] ?? null)
            );
            Http::flash(self::FLASH, 'success', 'Solicitud enviada. La administración la revisará y te avisaremos aquí y por correo.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/usuario/zonas-comunes?zona=' . $areaId . ($date !== '' ? '&desde=' . rawurlencode($date) : '') . '#agenda');
    }

    public function cancel(): void
    {
        $user = $this->requireResident();
        Http::requireCsrf();
        try {
            $this->service->cancel(Http::id($_POST['booking_id'] ?? null), (int) $user['id']);
            Http::flash(self::FLASH, 'success', 'Reserva cancelada; el horario quedó libre.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/usuario/zonas-comunes#mis-reservas');
    }

    private function requireResident(): array
    {
        $user = Authorization::requireResident();
        ModuleCatalog::requireEnabled('zonas_comunes');

        return $user;
    }
}
