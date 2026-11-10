<?php

declare(strict_types=1);

namespace App\Modules\PropertyAssignment;

use App\Core\Authorization;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use RuntimeException;

final class PropertyAssignmentController
{
    private const PERMISSION = 'inmuebles.asignaciones';

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }

        $selectedUserId = $this->integerInput($_GET['usuario'] ?? null);
        if ($selectedUserId === false || ($selectedUserId !== null && $selectedUserId < 1)) {
            $this->flash('error', 'El usuario seleccionado no es valido.');
            $this->redirect('/inmuebles/asignaciones');
        }

        $data = (new PropertyAssignmentService())->pageData($selectedUserId);
        if ($selectedUserId !== null && $data['selectedUser'] === null) {
            $this->flash('error', 'El usuario seleccionado no existe o no puede recibir inmuebles.');
            $this->redirect('/inmuebles/asignaciones');
        }

        $this->render([
            'actor' => $actor,
            ...$data,
            'selectedUserId' => $selectedUserId,
        ]);
    }

    public function save(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        $userId = $this->integerInput($_POST['user_id'] ?? null);
        $submittedJson = $_POST['unit_ids_json'] ?? null;
        if ($userId === false || $userId === null || $userId < 1 || !is_string($submittedJson)) {
            $this->flash('error', 'Selecciona un usuario y revisa la lista de inmuebles.');
            $this->redirect('/inmuebles/asignaciones');
        }

        try {
            $unitIds = json_decode($submittedJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($unitIds) || !array_is_list($unitIds)) {
                throw new RuntimeException('La seleccion de inmuebles tiene un formato invalido.');
            }
            (new PropertyAssignmentService())->assign($userId, $unitIds);
            $this->flash('success', 'Las asignaciones del usuario se guardaron correctamente.');
        } catch (\JsonException) {
            $this->flash('error', 'No se pudo leer la seleccion de inmuebles. Recarga la pagina e intenta nuevamente.');
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        $this->redirect('/inmuebles/asignaciones?usuario=' . $userId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data): void
    {
        Session::start();
        $data['title'] = 'Inmuebles / Asignacion de usuarios';
        $data['csrfToken'] = Session::csrfToken();
        $data['flash'] = $_SESSION['property_assignment_flash'] ?? null;
        unset($_SESSION['property_assignment_flash']);
        View::render('PropertyAssignment/index', $data);
    }

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(419);
            echo 'La sesion del formulario vencio. Recarga la pagina e intenta nuevamente.';
            exit;
        }
    }

    private function integerInput(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : false;
    }

    private function flash(string $type, string $message): void
    {
        Session::start();
        $_SESSION['property_assignment_flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::to($path), true, 303);
        exit;
    }
}
