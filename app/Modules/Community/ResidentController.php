<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

/** Comunidad / Residentes en el panel: listado, alta, edición, invitación, activación y eliminación. */
final class ResidentController
{
    private const PERMISSION = 'comunidad.residentes';
    private const FLASH = 'community_flash';
    private const STATUSES = ['active', 'inactive', 'all'];

    public function __construct(private readonly ResidentService $service = new ResidentService())
    {
    }

    public function index(): void
    {
        $actor = $this->require('read');
        $status = in_array($_GET['estado'] ?? null, self::STATUSES, true) ? (string) $_GET['estado'] : 'active';
        $search = trim(Http::string($_GET['q'] ?? null));
        $this->render('residents', 'Comunidad · Residentes', [
            'residents' => $this->service->list($search, $status),
            'search' => $search,
            'status' => $status,
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
            'canDelete' => Authorization::hasPermission($actor, self::PERMISSION, 3),
            'canAssign' => Authorization::hasPermission($actor, 'inmuebles.asignaciones', 2),
        ]);
    }

    public function form(): void
    {
        $actor = $this->require('write');
        $userId = Http::id($_GET['id'] ?? null);
        $resident = $userId > 0 ? $this->service->find($userId) : null;
        if ($userId > 0 && $resident === null) {
            Http::flash(self::FLASH, 'error', 'El residente no existe.');
            Http::redirect('/comunidad/residentes');
        }
        Session::start();
        $old = $_SESSION['community_old_input'] ?? null;
        unset($_SESSION['community_old_input']);

        $this->render('resident-form', $resident !== null ? 'Editar residente' : 'Nuevo residente', [
            'resident' => $resident,
            'old' => is_array($old) ? $old : null,
            'documentTypes' => ResidentService::DOCUMENT_TYPES,
            'canAssign' => Authorization::hasPermission($actor, 'inmuebles.asignaciones', 2),
        ]);
    }

    public function save(): void
    {
        $this->require('write');
        Http::requireCsrf();
        $userId = Http::id($_POST['user_id'] ?? null);
        try {
            $result = $this->service->save($userId, $_POST);
        } catch (RuntimeException $exception) {
            Session::start();
            $_SESSION['community_old_input'] = array_diff_key($_POST, ['password' => true, '_token' => true]);
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect('/comunidad/residentes/' . ($userId > 0 ? 'editar?id=' . $userId : 'nuevo'));
        }

        $message = $userId > 0 ? 'Residente actualizado.' : 'Residente creado.';
        if ($result['invited']) {
            $message .= ' Se le envió el correo para crear su contraseña (vence en 72 horas).';
        } elseif ($result['invitation_error'] !== null) {
            $message .= ' No se pudo enviar la invitación: ' . $result['invitation_error'] . ' Puedes reenviarla desde el listado.';
        }
        Http::flash(self::FLASH, $result['invitation_error'] === null ? 'success' : 'error', $message);
        Http::redirect($userId > 0 ? '/comunidad/residentes' : '/comunidad/residentes?q=' . rawurlencode((string) ($_POST['email'] ?? '')));
    }

    public function invite(): void
    {
        $this->require('write');
        Http::requireCsrf();
        try {
            $this->service->invite(Http::id($_POST['user_id'] ?? null));
            Http::flash(self::FLASH, 'success', 'Invitación enviada: el residente tiene 72 horas para crear su contraseña.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/comunidad/residentes');
    }

    public function setStatus(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        $active = ($_POST['active'] ?? '') === '1';
        try {
            $this->service->setActive(Http::id($_POST['user_id'] ?? null), $active, (int) $actor['id']);
            Http::flash(self::FLASH, 'success', $active ? 'Residente activado.' : 'Residente desactivado: ya no puede entrar al portal; su historial se conserva.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/comunidad/residentes' . ($active ? '' : '?estado=inactive'));
    }

    public function delete(): void
    {
        $this->require('total');
        Http::requireCsrf();
        try {
            $this->service->delete(Http::id($_POST['user_id'] ?? null));
            Http::flash(self::FLASH, 'success', 'Residente eliminado.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/comunidad/residentes?estado=all');
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
        View::renderAdmin('Community/' . $template, $data + [
            'title' => $title,
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
        ]);
    }
}
