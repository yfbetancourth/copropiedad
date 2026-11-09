<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Authorization;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use PDOException;
use RuntimeException;
use Throwable;

final class ConfigurationController
{
    public function __construct(
        private readonly TeamService $team = new TeamService(),
        private readonly RoleService $roles = new RoleService()
    ) {
    }

    public function team(): void
    {
        $actor = Authorization::requirePermission('configuracion.equipo', 'read');
        if ($actor === null) {
            return;
        }

        $user = null;
        $userId = $this->integerInput($_GET['editar'] ?? null);
        if ($userId !== false && $userId !== null) {
            $user = $this->team->find((int) $userId);
            if ($user === null) {
                $this->flash('error', 'El usuario seleccionado no existe.');
                $this->redirect('/configuracion/equipo');
            }
            if ((bool) $user['is_system']) {
                $this->flash('error', 'La cuenta Superadmin del sistema esta protegida.');
                $this->redirect('/configuracion/equipo');
            }
        }

        $this->render('team', 'Configuracion / Equipo', [
            'actor' => $actor,
            'users' => $this->team->all(),
            'roles' => $this->roles->assignableRoles(),
            'editingUser' => $user,
        ]);
    }

    public function saveUser(): void
    {
        $actor = $this->requireAction('configuracion.equipo', 'write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        $userId = $this->integerInput($_POST['user_id'] ?? null);
        $username = trim($this->stringInput($_POST['username'] ?? null));
        $email = trim($this->stringInput($_POST['email'] ?? null));
        $password = $this->stringInput($_POST['password'] ?? null);
        $roleId = $this->integerInput($_POST['role_id'] ?? null);

        if (strlen($username) < 3 || strlen($username) > 64
            || !preg_match('/^[\p{L}\p{N}_.@-]+$/uD', $username)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $roleId === false || $roleId === null
        ) {
            $this->flash('error', 'Verifica el nombre de usuario, correo y rol seleccionado.');
            $this->redirect('/configuracion/equipo');
        }

        if ($userId === false || ($userId !== null && $userId < 1)) {
            $this->flash('error', 'El usuario seleccionado no es valido.');
            $this->redirect('/configuracion/equipo');
        }

        $editing = $userId !== null;
        if ((!$editing && strlen($password) < 12)
            || ($password !== '' && strlen($password) < 12)
        ) {
            $this->flash('error', 'La contrasena debe tener al menos 12 caracteres.');
            $this->redirect('/configuracion/equipo');
        }

        $role = $this->roles->find((int) $roleId);
        if ($role === null || (bool) $role['is_system']) {
            $this->flash('error', 'Selecciona un rol personalizado valido.');
            $this->redirect('/configuracion/equipo');
        }
        if (!$actor['is_system']) {
            foreach ($role['permissions'] as $moduleKey => $level) {
                if (!Authorization::canGrant($level, $actor['permissions'][$moduleKey] ?? 'none')) {
                    $this->flash('error', 'No puedes asignar permisos que superen tu propio nivel de acceso.');
                    $this->redirect('/configuracion/equipo');
                }
            }
        }

        try {
            if ($editing) {
                $this->team->update((int) $userId, $username, $email, (int) $roleId, $password === '' ? null : $password);
                $message = 'Usuario actualizado correctamente.';
            } else {
                $this->team->create($username, $email, $password, (int) $roleId);
                $message = 'Usuario creado correctamente.';
            }
            $this->flash('success', $message);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $this->flash('error', 'El nombre de usuario o el correo ya esta registrado.');
            } else {
                throw $exception;
            }
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        $this->redirect('/configuracion/equipo');
    }

    public function setUserStatus(): void
    {
        $actor = $this->requireAction('configuracion.equipo', 'total');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        $userId = $this->integerInput($_POST['user_id'] ?? null);
        $activeInput = $_POST['active'] ?? null;
        $active = $activeInput === '1';
        if ($userId === false || $userId === null || $userId < 1
            || !in_array($activeInput, ['0', '1'], true)
        ) {
            $this->flash('error', 'El usuario seleccionado no es valido.');
            $this->redirect('/configuracion/equipo');
        }

        try {
            $this->team->setActive((int) $userId, $active, $actor['id']);
            $this->flash('success', $active ? 'Usuario activado.' : 'Usuario desactivado.');
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }
        $this->redirect('/configuracion/equipo');
    }

    public function roles(): void
    {
        $actor = Authorization::requirePermission('configuracion.roles', 'read');
        if ($actor === null) {
            return;
        }

        $role = null;
        $roleId = $this->integerInput($_GET['editar'] ?? null);
        if ($roleId !== false && $roleId !== null) {
            $role = $this->roles->find((int) $roleId);
            if ($role === null) {
                $this->flash('error', 'El rol seleccionado no existe.');
                $this->redirect('/configuracion/roles');
            }
        }

        $this->render('roles', 'Configuracion / Roles y permisos', [
            'actor' => $actor,
            'roles' => $this->roles->all(),
            'editingRole' => $role,
            'modules' => RoleService::MODULES,
            'levels' => RoleService::LEVELS,
        ]);
    }

    public function saveRole(): void
    {
        $actor = $this->requireAction('configuracion.roles', 'write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        $roleId = $this->integerInput($_POST['role_id'] ?? null);
        $name = trim($this->stringInput($_POST['name'] ?? null));
        $description = trim($this->stringInput($_POST['description'] ?? null));
        $submitted = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [];

        if (strlen($name) < 2 || strlen($name) > 64
            || !preg_match('/^[\p{L}\p{N} _.-]+$/uD', $name)
            || strlen($description) > 255
        ) {
            $this->flash('error', 'Verifica el nombre y la descripcion del rol.');
            $this->redirect('/configuracion/roles');
        }

        $permissions = [];
        foreach (RoleService::MODULES as $moduleKey => $_label) {
            $level = $submitted[$moduleKey] ?? 'none';
            if (!is_string($level) || !array_key_exists($level, RoleService::LEVELS)) {
                $this->flash('error', 'Uno de los niveles de permiso no es valido.');
                $this->redirect('/configuracion/roles');
            }
            if (!$actor['is_system'] && !Authorization::canGrant($level, $actor['permissions'][$moduleKey] ?? 'none')) {
                $this->flash('error', 'No puedes conceder permisos que superen tu propio nivel de acceso.');
                $this->redirect('/configuracion/roles');
            }
            $permissions[$moduleKey] = $level;
        }

        if ($roleId === false || ($roleId !== null && $roleId < 1)) {
            $this->flash('error', 'El rol seleccionado no es valido.');
            $this->redirect('/configuracion/roles');
        }

        $editing = $roleId !== null;
        if ($editing) {
            $existing = $this->roles->find((int) $roleId);
            if ($existing === null || (bool) $existing['is_system']) {
                $this->flash('error', 'El rol del sistema no se puede modificar.');
                $this->redirect('/configuracion/roles');
            }
        }

        try {
            $this->roles->save($editing ? (int) $roleId : null, $name, $description, $permissions);
            $this->flash('success', 'Rol y permisos guardados correctamente.');
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $this->flash('error', 'Ya existe un rol con ese nombre.');
            } else {
                throw $exception;
            }
        }
        $this->redirect('/configuracion/roles');
    }

    public function deleteRole(): void
    {
        $actor = $this->requireAction('configuracion.roles', 'total');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        $roleId = $this->integerInput($_POST['role_id'] ?? null);
        if ($roleId === false || $roleId === null || $roleId < 1) {
            $this->flash('error', 'El rol seleccionado no es valido.');
            $this->redirect('/configuracion/roles');
        }

        try {
            if (!$this->roles->delete((int) $roleId)) {
                $this->flash('error', 'El rol no existe o es un rol protegido del sistema.');
            } else {
                $this->flash('success', 'Rol eliminado.');
            }
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $this->flash('error', 'No se puede eliminar un rol que tiene usuarios asignados.');
            } else {
                throw $exception;
            }
        }
        $this->redirect('/configuracion/roles');
    }

    public function parametrization(): void
    {
        $actor = Authorization::requirePermission('configuracion.parametrizacion', 'read');
        if ($actor === null) {
            return;
        }

        $this->render('parametrization', 'Configuracion / Parametrizacion', [
            'actor' => $actor,
            'settings' => (new SettingsService())->current(),
        ]);
    }

    public function saveParametrization(): void
    {
        $actor = Authorization::requirePermission('configuracion.parametrizacion', 'read');
        if ($actor === null) {
            return;
        }
        if (!(bool) ($actor['is_system'] ?? false)) {
            http_response_code(403);
            echo 'Solo el usuario Superadmin puede modificar la parametrizacion.';
            return;
        }
        $this->requireCsrf();

        $name = trim($this->stringInput($_POST['property_name'] ?? null));
        $address = trim($this->stringInput($_POST['address'] ?? null));
        $taxId = trim($this->stringInput($_POST['tax_id'] ?? null));
        $accountHolder = trim($this->stringInput($_POST['account_holder'] ?? null));
        $bankName = trim($this->stringInput($_POST['bank_name'] ?? null));
        $accountType = $this->stringInput($_POST['account_type'] ?? null);
        $accountNumber = trim($this->stringInput($_POST['account_number'] ?? null));

        if ($name === '' || strlen($name) > 160 || strlen($address) > 255
            || strlen($taxId) > 32 || strlen($accountHolder) > 160
            || strlen($bankName) > 120 || strlen($accountNumber) > 34
            || ($accountNumber !== '' && !preg_match('/^\d+$/D', $accountNumber))
            || !in_array($accountType, ['', 'corriente', 'ahorros'], true)
            || (($accountHolder === '') !== ($bankName === ''))
            || (($accountHolder === '') !== ($accountType === ''))
            || (($accountHolder === '') !== ($accountNumber === ''))
        ) {
            $this->flash('error', 'Verifica los datos de la copropiedad y completa juntos el titular, banco, tipo y numero de cuenta.');
            $this->redirect('/configuracion/parametrizacion');
        }

        (new SettingsService())->saveParametrization(
            $name,
            $address,
            $taxId,
            $accountHolder,
            $bankName,
            $accountType,
            $accountNumber
        );
        $this->flash('success', 'Los datos de parametrizacion se guardaron correctamente.');
        $this->redirect('/configuracion/parametrizacion');
    }

    public function financial(): void
    {
        $actor = Authorization::requirePermission('configuracion.financiera', 'read');
        if ($actor === null) {
            return;
        }

        $this->render('financial', 'Configuracion / Financiera', [
            'actor' => $actor,
            'settings' => (new SettingsService())->current(),
        ]);
    }

    public function saveFinancial(): void
    {
        $actor = Authorization::requirePermission('configuracion.financiera', 'write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        $administrationFee = $this->moneyInput($_POST['administration_fee'] ?? null);
        $effectiveDate = $this->stringInput($_POST['effective_date'] ?? null);
        $discount = $this->moneyInput($_POST['early_payment_discount'] ?? null);
        $lateFeeType = $this->stringInput($_POST['late_fee_type'] ?? null);
        $lateFeeValue = $this->moneyInput($_POST['late_fee_value'] ?? null);
        $lateFeeDays = $this->integerInput($_POST['late_fee_days'] ?? null);
        $fineAmount = $this->moneyInput($_POST['fine_amount'] ?? null);
        $validDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        $dateErrors = \DateTimeImmutable::getLastErrors();

        if ($administrationFee === false || $discount === false || $lateFeeValue === false
            || $fineAmount === false || !in_array($lateFeeType, ['percentage', 'fixed'], true)
            || $lateFeeDays === false || $lateFeeDays === null || $lateFeeDays < 0 || $lateFeeDays > 365
            || $effectiveDate === '' || $validDate === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || ($lateFeeType === 'percentage' && $this->decimalGreaterThan($lateFeeValue, '100'))
        ) {
            $this->flash('error', 'Revisa los valores: montos no negativos, fecha valida y porcentaje de mora entre 0 y 100.');
            $this->redirect('/configuracion/financiera');
        }

        (new SettingsService())->saveFinancial(
            $administrationFee,
            $effectiveDate,
            $discount,
            $lateFeeType,
            $lateFeeValue,
            $lateFeeDays,
            $fineAmount
        );
        $this->flash('success', 'Los parametros financieros se guardaron correctamente.');
        $this->redirect('/configuracion/financiera');
    }

    public function characterization(): void
    {
        $actor = Authorization::requirePermission('configuracion.caracterizacion', 'read');
        if ($actor === null) {
            return;
        }

        $this->render('characterization', 'Configuracion / Caracterizacion', [
            'actor' => $actor,
            'settings' => (new SettingsService())->current(),
        ]);
    }

    public function saveCharacterization(): void
    {
        $actor = Authorization::requirePermission('configuracion.caracterizacion', 'write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        $housingType = $this->stringInput($_POST['housing_type'] ?? null);
        if (!in_array($housingType, ['casa', 'torres_apartamento'], true)) {
            $this->flash('error', 'Selecciona un tipo de vivienda valido.');
            $this->redirect('/configuracion/caracterizacion');
        }

        (new SettingsService())->saveCharacterization($housingType);
        $this->flash('success', 'La caracterizacion se guardo correctamente.');
        $this->redirect('/configuracion/caracterizacion');
    }

    private function requireAction(string $moduleKey, string $level): ?array
    {
        return Authorization::requirePermission($moduleKey, $level);
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

    private function stringInput(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function moneyInput(mixed $value): string|false
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return false;
        }

        $value = trim((string) $value);
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
            return false;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, 2, '0');
    }

    private function decimalGreaterThan(string $value, string $limit): bool
    {
        [$whole, $fraction] = explode('.', $value, 2);
        [$limitWhole, $limitFraction] = explode('.', $limit . '.00', 2);
        $whole = ltrim($whole, '0') ?: '0';
        $limitWhole = ltrim($limitWhole, '0') ?: '0';

        if (strlen($whole) !== strlen($limitWhole)) {
            return strlen($whole) > strlen($limitWhole);
        }

        $comparison = strcmp($whole, $limitWhole);

        return $comparison > 0
            || ($comparison === 0 && str_pad($fraction, 2, '0') > $limitFraction);
    }

    private function flash(string $type, string $message): void
    {
        Session::start();
        $_SESSION['configuration_flash'] = ['type' => $type, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $template, string $title, array $data = []): void
    {
        Session::start();
        $data['title'] = $title;
        $data['csrfToken'] = Session::csrfToken();
        $data['flash'] = $_SESSION['configuration_flash'] ?? null;
        unset($_SESSION['configuration_flash']);
        View::render('Configuration/' . $template, $data);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::to($path), true, 303);
        exit;
    }
}
