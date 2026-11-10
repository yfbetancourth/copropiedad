<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Authorization;
use App\Core\Database;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use App\Modules\Auth\SmtpMailer;
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
        $actor = Authorization::requirePermission('configuracion.parametrizacion', 'write');
        if ($actor === null) {
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
        $earlyPaymentDay = $this->integerInput($_POST['early_payment_day'] ?? null);
        $paymentDueDay = $this->integerInput($_POST['payment_due_day'] ?? null);

        if ($name === '' || strlen($name) > 160 || strlen($address) > 255
            || strlen($taxId) > 32 || strlen($accountHolder) > 160
            || strlen($bankName) > 120 || strlen($accountNumber) > 34
            || ($accountNumber !== '' && !preg_match('/^\d+$/D', $accountNumber))
            || !in_array($accountType, ['', 'corriente', 'ahorros'], true)
            || (($accountHolder === '') !== ($bankName === ''))
            || (($accountHolder === '') !== ($accountType === ''))
            || (($accountHolder === '') !== ($accountNumber === ''))
            || $earlyPaymentDay === false || $paymentDueDay === false
            || ($earlyPaymentDay !== null && ($earlyPaymentDay < 1 || $earlyPaymentDay > 28))
            || ($paymentDueDay !== null && ($paymentDueDay < 1 || $paymentDueDay > 28))
            || (($earlyPaymentDay === null) !== ($paymentDueDay === null))
            || ($earlyPaymentDay !== null && $paymentDueDay !== null && $earlyPaymentDay > $paymentDueDay)
        ) {
            $this->flash('error', 'Verifica los datos de la copropiedad, completa juntos los datos bancarios y revisa los dias de pronto pago y vencimiento (1 a 28).');
            $this->redirect('/configuracion/parametrizacion');
        }

        (new SettingsService())->saveParametrization(
            $name,
            $address,
            $taxId,
            $accountHolder,
            $bankName,
            $accountType,
            $accountNumber,
            $earlyPaymentDay,
            $paymentDueDay
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

    public function mail(): void
    {
        $actor = Authorization::requirePermission('configuracion.correo', 'read');
        if ($actor === null) {
            return;
        }

        $this->render('mail', 'Configuracion / Correo', [
            'actor' => $actor,
            'settings' => (new MailSettingsService())->current(),
        ]);
    }

    public function saveMail(): void
    {
        if ($this->requireAction('configuracion.correo', 'write') === null) {
            return;
        }
        $this->requireCsrf();

        $host = trim($this->stringInput($_POST['host'] ?? null));
        $port = $this->integerInput($_POST['port'] ?? null);
        $username = trim($this->stringInput($_POST['username'] ?? null));
        $password = $this->stringInput($_POST['password'] ?? null);
        $encryption = $this->stringInput($_POST['encryption'] ?? null);
        $fromAddress = trim($this->stringInput($_POST['from_address'] ?? null));
        $fromName = trim($this->stringInput($_POST['from_name'] ?? null));

        if (preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D', $host) !== 1
            || $port === false || $port === null || $port < 1 || $port > 65535
            || $username === '' || strlen($username) > 254 || strlen($password) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $username) === 1
            || !in_array($encryption, ['tls', 'ssl'], true)
            || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
            || strlen($fromName) > 160 || preg_match('/[\x00-\x1F\x7F]/', $fromName) === 1
        ) {
            $this->flash('error', 'Revisa el servidor, puerto, cifrado, usuario y datos del remitente.');
            $this->redirect('/configuracion/correo');
        }

        try {
            (new MailSettingsService())->save(
                $host,
                $port,
                $username,
                $password,
                $encryption,
                $fromAddress,
                $fromName
            );
            $this->flash('success', 'La configuración de correo se guardó de forma segura.');
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        $this->redirect('/configuracion/correo');
    }

    public function testMail(): void
    {
        $actor = $this->requireAction('configuracion.correo', 'write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        try {
            $settings = (new MailSettingsService())->credentials();
            $username = htmlspecialchars($actor['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            (new SmtpMailer())->send(
                $settings,
                $actor['email'],
                $actor['username'],
                'Prueba de correo de la copropiedad',
                '<p>Hola ' . $username . ',</p><p>La configuración de correo funciona correctamente.</p>',
                'La configuracion de correo funciona correctamente.'
            );
            $this->flash('success', 'Se envió el correo de prueba a ' . $actor['email'] . '.');
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            error_log('Fallo la prueba SMTP: ' . $exception->getMessage());
            $this->flash('error', 'No se pudo enviar el correo. Verifica los datos del proveedor y su conexión SMTP.');
        }

        $this->redirect('/configuracion/correo');
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
            'hasComposition' => ($composition = (new CompositionService())->current()) !== null
                && ($composition['answers'] !== [] || $composition['units'] !== null),
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
        if (!in_array($housingType, ['casa', 'torres_apartamento', 'mixto'], true)) {
            $this->flash('error', 'Selecciona una caracterizacion valida.');
            $this->redirect('/configuracion/caracterizacion');
        }

        $settings = (new SettingsService())->current();
        $previousType = $settings['housing_type'] ?? '';
        $previousNomenclature = $settings['tower_nomenclature'] ?? 'numeric';
        $nomenclatureInput = $this->stringInput($_POST['tower_nomenclature'] ?? null);
        $towerNomenclature = in_array($housingType, ['torres_apartamento', 'mixto'], true)
            ? $nomenclatureInput
            : $previousNomenclature;
        if (!in_array($towerNomenclature, ['numeric', 'alphabetic'], true)) {
            $this->flash('error', 'Selecciona un tipo de nomenclatura valido para las torres.');
            $this->redirect('/configuracion/caracterizacion');
        }

        $compositionService = new CompositionService();
        $composition = $compositionService->current();
        $hasComposition = is_array($composition)
            && ($composition['answers'] !== [] || $composition['units'] !== null);
        $confirmedChange = ($_POST['confirm_housing_change'] ?? null) === '1';
        $nomenclatureChanged = in_array($housingType, ['torres_apartamento', 'mixto'], true)
            && $towerNomenclature !== $previousNomenclature;

        if (($housingType !== $previousType || $nomenclatureChanged) && $hasComposition && !$confirmedChange) {
            $this->flash(
                'error',
                'La estructura registrada puede dejar de corresponder a esta caracterizacion. Confirma el cambio para reiniciar las respuestas y los inmuebles.'
            );
            $this->redirect('/configuracion/caracterizacion');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            (new SettingsService())->saveCharacterization($housingType, $towerNomenclature);
            if (($housingType !== $previousType || $nomenclatureChanged) && $hasComposition) {
                $compositionService->clear();
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $this->flash('success', 'La caracterizacion se guardo correctamente.');
        $this->redirect('/configuracion/caracterizacion');
    }

    public function composition(): void
    {
        $actor = Authorization::requirePermission('configuracion.conformacion', 'read');
        if ($actor === null) {
            return;
        }

        $settings = (new SettingsService())->current();
        $housingType = $settings['housing_type'] ?? '';
        if (!in_array($housingType, ['casa', 'torres_apartamento', 'mixto'], true)) {
            $this->flash('error', 'Primero define la caracterizacion de la copropiedad.');
            $this->redirect('/configuracion/caracterizacion');
        }

        $towerNomenclature = $settings['tower_nomenclature'] ?? 'numeric';
        if (!in_array($towerNomenclature, ['numeric', 'alphabetic'], true)) {
            throw new RuntimeException('El tipo de nomenclatura guardado no es valido.');
        }

        $compositionService = new CompositionService();
        $composition = $compositionService->current();
        if (is_array($composition) && is_array($composition['units'])) {
            $composition = $compositionService->ensureUnitIds();
        }
        if (!is_array($composition) || $composition['housing_type'] !== $housingType) {
            $composition = null;
        }
        $answers = $composition['answers'] ?? [];
        $steps = $this->compositionSteps($housingType);
        $firstUnanswered = $this->firstUnansweredCompositionStep($steps, $answers);
        $allAnswered = $firstUnanswered === count($steps);
        $requestedStep = $this->integerInput($_GET['paso'] ?? null);
        $stepIndex = $requestedStep === false || $requestedStep === null
            ? $firstUnanswered
            : min(max($requestedStep, 0), $firstUnanswered);

        if (($composition['units'] ?? null) !== null) {
            $mode = 'structure';
        } elseif ($allAnswered) {
            $mode = 'answers_review';
        } else {
            $mode = 'wizard';
        }
        $units = $composition['units'] ?? null;
        $unitCountsByCategory = [];
        if (is_array($units)) {
            foreach ($units as $unit) {
                if (!is_array($unit)) {
                    continue;
                }
                $category = trim($this->stringInput($unit['category'] ?? null));
                $category = $category !== '' ? $category : 'Sin categoria';
                $unitCountsByCategory[$category] = ($unitCountsByCategory[$category] ?? 0) + 1;
            }
            ksort($unitCountsByCategory, SORT_NATURAL | SORT_FLAG_CASE);
        }

        $this->render('composition', 'Configuracion / Conformación de la copropiedad', [
            'actor' => $actor,
            'housingType' => $housingType,
            'towerNomenclature' => $towerNomenclature,
            'answers' => $answers,
            'steps' => $steps,
            'stepIndex' => $stepIndex,
            'progress' => $allAnswered ? 100 : (int) floor((($stepIndex + 1) / count($steps)) * 100),
            'mode' => $mode,
            'units' => $units ?? [],
            'storedUnitCount' => is_array($units) ? count($units) : null,
            'unitCountsByCategory' => $unitCountsByCategory,
            'estimatedUnitCount' => $allAnswered ? $this->compositionUnitCount($housingType, $answers) : null,
            'completed' => (bool) ($composition['completed'] ?? false),
        ]);
    }

    public function saveCompositionStep(): void
    {
        if ($this->requireAction('configuracion.conformacion', 'write') === null) {
            return;
        }
        $this->requireCsrf();

        $housingType = $this->configuredHousingType();
        $steps = $this->compositionSteps($housingType);
        $field = $this->stringInput($_POST['field'] ?? null);
        $value = $this->integerInput($_POST['value'] ?? null);
        $fieldIndex = array_search($field, array_column($steps, 'key'), true);

        if ($fieldIndex === false || $value === false || $value === null || $value < 1 || $value > 5000) {
            $this->flash('error', 'Ingresa una cantidad como numero entero positivo, entre 1 y 5.000.');
            $this->redirect('/configuracion/conformacion');
        }

        $composition = (new CompositionService())->current();
        $answers = is_array($composition) && $composition['housing_type'] === $housingType
            ? $composition['answers']
            : [];
        $answers[$field] = $value;
        $error = $this->compositionAnswersError($housingType, $answers, false);
        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect('/configuracion/conformacion?paso=' . $fieldIndex);
        }

        if (!is_array($composition) || $composition['housing_type'] !== $housingType
            || $answers !== $composition['answers']
        ) {
            (new CompositionService())->saveAnswers($housingType, $answers);
        }
        $this->redirect('/configuracion/conformacion');
    }

    public function generateComposition(): void
    {
        if ($this->requireAction('configuracion.conformacion', 'write') === null) {
            return;
        }
        $this->requireCsrf();

        $housingType = $this->configuredHousingType();
        $towerNomenclature = (new SettingsService())->current()['tower_nomenclature'] ?? 'numeric';
        $composition = (new CompositionService())->current();
        $answers = is_array($composition) && $composition['housing_type'] === $housingType
            ? $composition['answers']
            : [];
        $error = $this->compositionAnswersError($housingType, $answers, true);
        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect('/configuracion/conformacion');
        }

        $units = [];
        if ($housingType === 'casa') {
            for ($house = 1; $house <= $answers['houses']; $house++) {
                $units[] = ['category' => 'Casa', 'name' => 'Casa ' . $house];
            }
        } else {
            for ($tower = 1; $tower <= $answers['towers']; $tower++) {
                $towerName = $towerNomenclature === 'alphabetic'
                    ? $this->alphabeticTowerName($tower)
                    : (string) $tower;
                for ($floor = 1; $floor <= $answers['floors_per_tower']; $floor++) {
                    for ($unit = 1; $unit <= $answers['units_per_floor']; $unit++) {
                        $apartmentName = str_pad((string) $unit, 2, '0', STR_PAD_LEFT);
                        $units[] = [
                            'category' => 'Apartamento',
                            'name' => sprintf('Torre %s - Piso %d - Apartamento %s', $towerName, $floor, $apartmentName),
                        ];
                    }
                }
            }
            if ($housingType === 'mixto') {
                for ($commercial = 1; $commercial <= $answers['commercial_properties']; $commercial++) {
                    $units[] = [
                        'category' => 'Inmueble comercial',
                        'name' => 'Inmueble comercial ' . $commercial,
                    ];
                }
            }
        }

        (new CompositionService())->saveUnits($units, false);
        $this->redirect('/configuracion/conformacion');
    }

    public function finishComposition(): void
    {
        if ($this->requireAction('configuracion.conformacion', 'write') === null) {
            return;
        }
        $this->requireCsrf();

        $housingType = $this->configuredHousingType();
        $compositionService = new CompositionService();
        $composition = $compositionService->current();
        if (!is_array($composition) || $composition['housing_type'] !== $housingType
            || $composition['units'] === null
        ) {
            $this->flash('error', 'Genera la estructura antes de guardarla.');
            $this->redirect('/configuracion/conformacion');
        }
        $composition = $compositionService->ensureUnitIds();
        if (!is_array($composition) || !is_array($composition['units'])) {
            $this->flash('error', 'No se pudo cargar la estructura actual. Recarga la pagina e intenta nuevamente.');
            $this->redirect('/configuracion/conformacion');
        }

        $submittedJson = $_POST['units_json'] ?? null;
        if (!is_string($submittedJson)) {
            $this->flash('error', 'No se recibio la lista de inmuebles. Recarga la pagina e intenta nuevamente.');
            $this->redirect('/configuracion/conformacion');
        }
        try {
            $submitted = json_decode($submittedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->flash('error', 'La lista de inmuebles tiene un formato invalido. Revisa los datos e intenta nuevamente.');
            $this->redirect('/configuracion/conformacion');
        }
        if (!is_array($submitted) || !array_is_list($submitted) || count($submitted) < 1 || count($submitted) > 5000) {
            $this->flash('error', 'La estructura debe tener entre 1 y 5.000 inmuebles.');
            $this->redirect('/configuracion/conformacion');
        }

        $existingIds = [];
        foreach ($composition['units'] as $existingUnit) {
            if (is_array($existingUnit) && is_string($existingUnit['id'] ?? null)) {
                $existingIds[$existingUnit['id']] = true;
            }
        }
        $submittedIds = [];
        $units = [];
        foreach ($submitted as $unit) {
            if (!is_array($unit)) {
                $this->flash('error', 'Revisa los nombres y categorias de los inmuebles.');
                $this->redirect('/configuracion/conformacion');
            }
            $name = trim($this->stringInput($unit['name'] ?? null));
            $category = trim($this->stringInput($unit['category'] ?? null));
            if ($name === '' || strlen($name) > 160 || $category === '' || strlen($category) > 80) {
                $this->flash('error', 'Cada inmueble necesita un nombre y una categoria de hasta 80 caracteres.');
                $this->redirect('/configuracion/conformacion');
            }
            if ($category === 'Apartamento'
                && !$this->hasValidApartmentNomenclature($name)
            ) {
                $this->flash('error', 'Los apartamentos deben terminar con un numero de dos digitos entre 01 y 99. El identificador 00 no es valido.');
                $this->redirect('/configuracion/conformacion');
            }
            $id = $this->stringInput($unit['id'] ?? null);
            if ($id !== '' && (!isset($existingIds[$id]) || isset($submittedIds[$id]))) {
                $this->flash('error', 'La lista contiene un identificador de inmueble invalido. Recarga la pagina e intenta nuevamente.');
                $this->redirect('/configuracion/conformacion');
            }
            if ($id !== '') {
                $submittedIds[$id] = true;
            }
            $units[] = ['id' => $id, 'category' => $category, 'name' => $name];
        }

        $compositionService->saveUnits($units, true);
        $this->flash('success', 'La estructura de bienes se guardo correctamente.');
        $this->redirect('/configuracion/conformacion');
    }

    /**
     * @return list<array{key: string, label: string, help: string}>
     */
    private function compositionSteps(string $housingType): array
    {
        if ($housingType === 'casa') {
            return [[
                'key' => 'houses',
                'label' => '¿Cuantas casas conforman la copropiedad?',
                'help' => 'Cuenta todas las casas que hacen parte de la copropiedad.',
            ]];
        }

        $steps = [
            [
                'key' => 'towers',
                'label' => '¿Cuantas torres de apartamentos hay?',
                'help' => 'Ingresa el numero total de torres.',
            ],
            [
                'key' => 'floors_per_tower',
                'label' => '¿Cuantos pisos tiene cada torre?',
                'help' => 'Si las torres tienen alturas distintas, ingresa el mismo numero para todas por ahora; podras corregir los inmuebles al revisar.',
            ],
            [
                'key' => 'units_per_floor',
                'label' => '¿Cuantos apartamentos hay en cada piso?',
                'help' => 'Ingresa la cantidad de apartamentos por piso.',
            ],
        ];
        if ($housingType === 'mixto') {
            $steps[] = [
                'key' => 'commercial_properties',
                'label' => '¿Cuantos inmuebles comerciales hay?',
                'help' => 'Cuenta locales, oficinas u otros inmuebles comerciales por separado de los apartamentos.',
            ];
        }

        return $steps;
    }

    private function configuredHousingType(): string
    {
        $housingType = (new SettingsService())->current()['housing_type'] ?? '';
        if (!in_array($housingType, ['casa', 'torres_apartamento', 'mixto'], true)) {
            $this->flash('error', 'Primero define la caracterizacion de la copropiedad.');
            $this->redirect('/configuracion/caracterizacion');
        }

        return $housingType;
    }

    private function compositionAnswersError(
        string $housingType,
        array $answers,
        bool $requireAll,
    ): ?string
    {
        $steps = $this->compositionSteps($housingType);
        foreach ($steps as $step) {
            if (!array_key_exists($step['key'], $answers)) {
                if ($requireAll) {
                    return 'Completa todas las cantidades antes de generar la estructura.';
                }
                continue;
            }
            $value = $answers[$step['key']];
            if (!is_int($value) || $value < 1 || $value > 5000) {
                return 'Hay una cantidad faltante o invalida. Usa numeros enteros positivos de hasta 5.000.';
            }
        }
        foreach (array_keys($answers) as $key) {
            if (!in_array($key, array_column($steps, 'key'), true)) {
                return 'Las respuestas guardadas no corresponden a la caracterizacion actual. Vuelve a completar los pasos.';
            }
        }
        if (isset($answers['units_per_floor'])
            && $answers['units_per_floor'] > 99
        ) {
            return 'La nomenclatura de dos digitos admite hasta 99 apartamentos por piso.';
        }
        if ($requireAll && $this->firstUnansweredCompositionStep($steps, $answers) !== count($steps)) {
            return 'Completa todas las cantidades antes de generar la estructura.';
        }
        if ($requireAll && $this->compositionUnitCount($housingType, $answers) > 5000) {
            return 'La estructura supera el limite de 5.000 inmuebles. Ajusta las cantidades antes de continuar.';
        }
        if ($this->firstUnansweredCompositionStep($steps, $answers) === count($steps)
            && $this->compositionUnitCount($housingType, $answers) > 5000
        ) {
            return 'El total supera los 5.000 inmuebles. Ajusta una cantidad para continuar.';
        }

        return null;
    }

    private function alphabeticTowerName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function hasValidApartmentNomenclature(string $name): bool
    {
        return preg_match('/(?:^| - )Apartamento (?:0[1-9]|[1-9][0-9])$/D', $name) === 1;
    }

    private function compositionUnitCount(string $housingType, array $answers): int
    {
        if ($housingType === 'casa') {
            return $answers['houses'];
        }

        $apartments = $answers['towers'] * $answers['floors_per_tower'] * $answers['units_per_floor'];

        return $apartments + ($housingType === 'mixto' ? $answers['commercial_properties'] : 0);
    }

    private function firstUnansweredCompositionStep(array $steps, array $answers): int
    {
        foreach ($steps as $index => $step) {
            if (!array_key_exists($step['key'], $answers)) {
                return $index;
            }
        }

        return count($steps);
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
