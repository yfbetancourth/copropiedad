<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

/** Panel: Comunicación / Contacto del sitio web (mensajes del formulario de la página pública). */
final class ContactAdminController
{
    private const PERMISSION = 'contacto';
    private const FLASH = 'contact_admin_flash';
    private const FILTERS = ['new' => 'Nuevos', 'read' => 'Leídos', 'todos' => 'Todos'];

    public function __construct(
        private readonly ContactService $contacts = new ContactService(),
        private readonly PrivacyService $privacy = new PrivacyService(),
    ) {
    }

    public function index(): void
    {
        $actor = $this->require('read');
        $filter = Http::string($_GET['estado'] ?? null);
        if (!isset(self::FILTERS[$filter])) {
            $filter = 'new';
        }

        Session::start();
        View::renderAdmin('Privacy/contact', [
            'title' => 'Comunicación · Contacto del sitio web',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => $this->contacts->counts(),
            'messages' => $this->contacts->messages($filter === 'todos' ? null : $filter),
            'formAvailable' => $this->privacy->contactFormAvailable(),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
            'canSeeConsents' => Authorization::hasPermission($actor, 'privacidad', 1),
        ]);
    }

    public function markRead(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        try {
            $this->contacts->markRead(Http::id($_POST['message_id'] ?? null), (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'Mensaje marcado como leído.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->back();
    }

    public function delete(): void
    {
        $this->require('write');
        Http::requireCsrf();
        try {
            $this->contacts->delete(Http::id($_POST['message_id'] ?? null));
            Http::flash(self::FLASH, 'success', 'Mensaje eliminado. La autorización se conserva en el registro como prueba.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        $this->back();
    }

    private function back(): never
    {
        $filter = Http::string($_POST['estado'] ?? null);
        Http::redirect('/comunicacion/contacto' . (isset(self::FILTERS[$filter]) ? '?estado=' . $filter : ''));
    }

    private function require(string $level): array
    {
        ModuleCatalog::requireEnabled('privacidad');
        $actor = Authorization::requirePermission(self::PERMISSION, $level);
        if ($actor === null) {
            exit;
        }

        return $actor;
    }
}
