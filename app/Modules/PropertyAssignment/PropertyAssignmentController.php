<?php

declare(strict_types=1);

namespace App\Modules\PropertyAssignment;

use App\Core\Http;
use App\Core\Authorization;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

final class PropertyAssignmentController
{
    private const FLASH = 'property_assignment_flash';
    private const PERMISSION = 'inmuebles.asignaciones';

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }

        $selectedUserId = Http::integer($_GET['usuario'] ?? null);
        if ($selectedUserId === false || ($selectedUserId !== null && $selectedUserId < 1)) {
            Http::flash(self::FLASH, 'error', 'El usuario seleccionado no es valido.');
            Http::redirect('/inmuebles/asignaciones');
        }

        $data = (new PropertyAssignmentService())->pageData($selectedUserId);
        if ($selectedUserId !== null && $data['selectedUser'] === null) {
            Http::flash(self::FLASH, 'error', 'El usuario seleccionado no existe o no puede recibir inmuebles.');
            Http::redirect('/inmuebles/asignaciones');
        }

        $housingType = (string) ((new \App\Modules\Configuration\SettingsService())->current()['housing_type'] ?? '');
        $this->render([
            'actor' => $actor,
            ...$data,
            'selectedUserId' => $selectedUserId,
            'conformation' => (new \App\Modules\Configuration\CompositionService())->progress(
                in_array($housingType, ['casa', 'torres_apartamento', 'mixto'], true) ? $housingType : ''
            ),
        ]);
    }

    public function save(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $userId = Http::integer($_POST['user_id'] ?? null);
        $submittedJson = $_POST['assignments_json'] ?? null;
        $effectiveDate = is_string($_POST['effective_date'] ?? null) ? trim($_POST['effective_date']) : '';
        if ($userId === false || $userId === null || $userId < 1 || !is_string($submittedJson)) {
            Http::flash(self::FLASH, 'error', 'Selecciona un usuario y revisa la lista de inmuebles.');
            Http::redirect('/inmuebles/asignaciones');
        }

        try {
            $links = json_decode($submittedJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($links) || !array_is_list($links)) {
                throw new RuntimeException('La seleccion de inmuebles tiene un formato invalido.');
            }
            (new PropertyAssignmentService())->assign($userId, $links, $effectiveDate, (int) $actor['id']);
            Http::flash(self::FLASH, 'success', count($links) === 0
                ? 'Se guardó: el usuario quedó sin inmuebles vinculados. El historial conserva sus vínculos anteriores.'
                : 'Se guardaron ' . count($links) . ' vínculo(s) para este usuario.');
        } catch (\JsonException) {
            Http::flash(self::FLASH, 'error', 'No se pudo leer la seleccion de inmuebles. Recarga la pagina e intenta nuevamente.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }

        Http::redirect('/inmuebles/asignaciones?usuario=' . $userId);
    }

    public function history(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }

        $unitId = $_GET['unidad'] ?? null;
        if ($unitId !== null && (!is_string($unitId) || preg_match('/^[a-f0-9]{32}$/D', $unitId) !== 1)) {
            Http::flash(self::FLASH, 'error', 'El inmueble seleccionado no es valido.');
            Http::redirect('/inmuebles/historial');
        }

        $data = (new PropertyAssignmentService())->historyPageData($unitId);
        if ($unitId !== null && $data['selectedUnit'] === null) {
            Http::flash(self::FLASH, 'error', 'El inmueble seleccionado no existe.');
            Http::redirect('/inmuebles/historial');
        }

        Session::start();
        $flash = Http::pullFlash(self::FLASH);
        View::renderAdmin('PropertyAssignment/history', [
            'title' => 'Trazabilidad de inmuebles',
            'actor' => $actor,
            'flash' => $flash,
            ...$data,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): void
    {
        Session::start();
        $data['title'] = 'Asignación de inmuebles';
        $data['csrfToken'] = Session::csrfToken();
        $data['flash'] = Http::pullFlash(self::FLASH);
        View::renderAdmin('PropertyAssignment/index', $data);
    }

}
