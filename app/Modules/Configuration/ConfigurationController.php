<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Http;
use App\Core\Authorization;
use App\Core\Database;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\View;
use App\Modules\Auth\SmtpMailer;
use App\Modules\Billing\BoldPaymentService;
use App\Modules\Billing\BoldTerminalService;
use App\Modules\Billing\WompiPaymentService;
use PDOException;
use RuntimeException;
use Throwable;

final class ConfigurationController
{
    private const FLASH = 'configuration_flash';
    /** Categorías sugeridas al editar la estructura; se puede escribir otra. */
    public const UNIT_CATEGORIES = ['Apartamento', 'Casa', 'Inmueble comercial', 'Parqueadero', 'Bodega', 'Depósito', 'Oficina'];

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
        $userId = Http::integer($_GET['editar'] ?? null);
        if ($userId !== false && $userId !== null) {
            $user = $this->team->find((int) $userId);
            if ($user === null) {
                Http::flash(self::FLASH, 'error', 'El usuario seleccionado no existe.');
                Http::redirect('/configuracion/equipo');
            }
            if ((bool) $user['is_system']) {
                Http::flash(self::FLASH, 'error', 'La cuenta del administrador principal está protegida; la gestiona el Superadmin.');
                Http::redirect('/configuracion/equipo');
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
        Http::requireCsrf();

        $userId = Http::integer($_POST['user_id'] ?? null);
        $username = trim(Http::string($_POST['username'] ?? null));
        $email = trim(Http::string($_POST['email'] ?? null));
        $password = Http::string($_POST['password'] ?? null);
        $roleId = Http::integer($_POST['role_id'] ?? null);

        if (strlen($username) < 3 || strlen($username) > 64
            || !preg_match('/^[\p{L}\p{N}_.@-]+$/uD', $username)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $roleId === false || $roleId === null
        ) {
            Http::flash(self::FLASH, 'error', 'Verifica el nombre de usuario, correo y rol seleccionado.');
            Http::redirect('/configuracion/equipo');
        }

        if ($userId === false || ($userId !== null && $userId < 1)) {
            Http::flash(self::FLASH, 'error', 'El usuario seleccionado no es valido.');
            Http::redirect('/configuracion/equipo');
        }

        $editing = $userId !== null;
        if ((!$editing && strlen($password) < 12)
            || ($password !== '' && strlen($password) < 12)
        ) {
            Http::flash(self::FLASH, 'error', 'La contrasena debe tener al menos 12 caracteres.');
            Http::redirect('/configuracion/equipo');
        }

        $role = $this->roles->find((int) $roleId);
        if ($role === null || (bool) $role['is_system']) {
            Http::flash(self::FLASH, 'error', 'Selecciona un rol personalizado valido.');
            Http::redirect('/configuracion/equipo');
        }
        if (!$actor['is_system']) {
            foreach ($role['permissions'] as $moduleKey => $level) {
                if (!Authorization::canGrant($level, $actor['permissions'][$moduleKey] ?? 'none')) {
                    Http::flash(self::FLASH, 'error', 'No puedes asignar permisos que superen tu propio nivel de acceso.');
                    Http::redirect('/configuracion/equipo');
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
            Http::flash(self::FLASH, 'success', $message);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                Http::flash(self::FLASH, 'error', 'El nombre de usuario o el correo ya esta registrado.');
            } else {
                throw $exception;
            }
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }

        Http::redirect('/configuracion/equipo');
    }

    public function setUserStatus(): void
    {
        $actor = $this->requireAction('configuracion.equipo', 'total');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $userId = Http::integer($_POST['user_id'] ?? null);
        $activeInput = $_POST['active'] ?? null;
        $active = $activeInput === '1';
        if ($userId === false || $userId === null || $userId < 1
            || !in_array($activeInput, ['0', '1'], true)
        ) {
            Http::flash(self::FLASH, 'error', 'El usuario seleccionado no es valido.');
            Http::redirect('/configuracion/equipo');
        }

        try {
            $this->team->setActive((int) $userId, $active, $actor['id']);
            Http::flash(self::FLASH, 'success', $active ? 'Usuario activado.' : 'Usuario desactivado.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/equipo');
    }

    public function roles(): void
    {
        $actor = Authorization::requirePermission('configuracion.roles', 'read');
        if ($actor === null) {
            return;
        }

        $role = null;
        $roleId = Http::integer($_GET['editar'] ?? null);
        if ($roleId !== false && $roleId !== null) {
            $role = $this->roles->find((int) $roleId);
            if ($role === null) {
                Http::flash(self::FLASH, 'error', 'El rol seleccionado no existe.');
                Http::redirect('/configuracion/roles');
            }
        }

        $this->render('roles', 'Configuracion / Roles y permisos', [
            'actor' => $actor,
            'roles' => $this->roles->all(),
            'editingRole' => $role,
            // Los permisos de módulos que el Superadmin deshabilitó no se muestran en este conjunto.
            'modules' => array_filter(
                RoleService::MODULES,
                static fn (string $moduleKey): bool => \App\Core\ModuleCatalog::permissionAvailable($moduleKey),
                ARRAY_FILTER_USE_KEY
            ),
            'levels' => RoleService::LEVELS,
        ]);
    }

    public function saveRole(): void
    {
        $actor = $this->requireAction('configuracion.roles', 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $roleId = Http::integer($_POST['role_id'] ?? null);
        $name = trim(Http::string($_POST['name'] ?? null));
        $description = trim(Http::string($_POST['description'] ?? null));
        $submitted = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [];

        if (strlen($name) < 2 || strlen($name) > 64
            || !preg_match('/^[\p{L}\p{N} _.-]+$/uD', $name)
            || strlen($description) > 255
        ) {
            Http::flash(self::FLASH, 'error', 'Verifica el nombre y la descripcion del rol.');
            Http::redirect('/configuracion/roles');
        }

        $permissions = [];
        $existingPermissions = $roleId !== null && $roleId !== false && $roleId > 0
            ? ($this->roles->find((int) $roleId)['permissions'] ?? [])
            : [];
        foreach (RoleService::MODULES as $moduleKey => $_label) {
            // Un módulo deshabilitado no está en el formulario: se conserva lo que tenía el rol.
            if (!\App\Core\ModuleCatalog::permissionAvailable($moduleKey)) {
                $permissions[$moduleKey] = $existingPermissions[$moduleKey] ?? 'none';
                continue;
            }
            $level = $submitted[$moduleKey] ?? 'none';
            if (!is_string($level) || !array_key_exists($level, RoleService::LEVELS)) {
                Http::flash(self::FLASH, 'error', 'Uno de los niveles de permiso no es valido.');
                Http::redirect('/configuracion/roles');
            }
            if (!$actor['is_system'] && !Authorization::canGrant($level, $actor['permissions'][$moduleKey] ?? 'none')) {
                Http::flash(self::FLASH, 'error', 'No puedes conceder permisos que superen tu propio nivel de acceso.');
                Http::redirect('/configuracion/roles');
            }
            $permissions[$moduleKey] = $level;
        }

        if ($roleId === false || ($roleId !== null && $roleId < 1)) {
            Http::flash(self::FLASH, 'error', 'El rol seleccionado no es valido.');
            Http::redirect('/configuracion/roles');
        }

        $editing = $roleId !== null;
        if ($editing) {
            $existing = $this->roles->find((int) $roleId);
            if ($existing === null || (bool) $existing['is_system']) {
                Http::flash(self::FLASH, 'error', 'El rol del sistema no se puede modificar.');
                Http::redirect('/configuracion/roles');
            }
        }

        try {
            $this->roles->save($editing ? (int) $roleId : null, $name, $description, $permissions);
            Http::flash(self::FLASH, 'success', 'Rol y permisos guardados correctamente.');
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                Http::flash(self::FLASH, 'error', 'Ya existe un rol con ese nombre.');
            } else {
                throw $exception;
            }
        }
        Http::redirect('/configuracion/roles');
    }

    public function deleteRole(): void
    {
        $actor = $this->requireAction('configuracion.roles', 'total');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $roleId = Http::integer($_POST['role_id'] ?? null);
        if ($roleId === false || $roleId === null || $roleId < 1) {
            Http::flash(self::FLASH, 'error', 'El rol seleccionado no es valido.');
            Http::redirect('/configuracion/roles');
        }

        try {
            if (!$this->roles->delete((int) $roleId)) {
                Http::flash(self::FLASH, 'error', 'El rol no existe o es un rol protegido del sistema.');
            } else {
                Http::flash(self::FLASH, 'success', 'Rol eliminado.');
            }
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                Http::flash(self::FLASH, 'error', 'No se puede eliminar un rol que tiene usuarios asignados.');
            } else {
                throw $exception;
            }
        }
        Http::redirect('/configuracion/roles');
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
        Http::requireCsrf();

        $name = trim(Http::string($_POST['property_name'] ?? null));
        $address = trim(Http::string($_POST['address'] ?? null));
        $taxId = trim(Http::string($_POST['tax_id'] ?? null));
        $accountHolder = trim(Http::string($_POST['account_holder'] ?? null));
        $bankName = trim(Http::string($_POST['bank_name'] ?? null));
        $accountType = Http::string($_POST['account_type'] ?? null);
        $accountNumber = trim(Http::string($_POST['account_number'] ?? null));
        $earlyPaymentDay = Http::integer($_POST['early_payment_day'] ?? null);
        $paymentDueDay = Http::integer($_POST['payment_due_day'] ?? null);

        if ($name === '' || strlen($name) > 160 || strlen($address) > 255
            || strlen($taxId) > 32 || strlen($accountHolder) > 160
            || strlen($bankName) > 120 || strlen($accountNumber) > 34
            || ($accountNumber !== '' && !preg_match('/^\d+$/D', $accountNumber))
            || !in_array($accountType, ['', 'corriente', 'ahorros'], true)
            || (($accountHolder === '') !== ($bankName === ''))
            || (($accountHolder === '') !== ($accountType === ''))
            || (($accountHolder === '') !== ($accountNumber === ''))
            || $earlyPaymentDay === false || $paymentDueDay === false
            // 0 = el conjunto no aplica descuento por pronto pago.
            || ($earlyPaymentDay !== null && ($earlyPaymentDay < 0 || $earlyPaymentDay > 28))
            || ($paymentDueDay !== null && ($paymentDueDay < 1 || $paymentDueDay > 28))
            || (($earlyPaymentDay === null) !== ($paymentDueDay === null))
            || ($earlyPaymentDay !== null && $paymentDueDay !== null && $earlyPaymentDay > $paymentDueDay)
        ) {
            Http::flash(self::FLASH, 'error', 'Verifica los datos de la copropiedad, completa juntos los datos bancarios y revisa los dias de pronto pago (0 a 28; 0 = sin descuento) y vencimiento (1 a 28).');
            Http::redirect('/configuracion/parametrizacion');
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
        Http::flash(self::FLASH, 'success', 'Los datos de parametrizacion se guardaron correctamente.');
        Http::redirect('/configuracion/parametrizacion');
    }

    public function propertyGallery(): void
    {
        $actor = Authorization::requirePermission('configuracion.parametrizacion', 'read');
        if ($actor === null) {
            return;
        }

        $this->render('property-gallery', 'Configuracion / Galeria publica', [
            'actor' => $actor,
            'images' => (new PropertyGalleryService())->images(),
        ]);
    }

    public function addPropertyGalleryImages(): void
    {
        if ($this->requireAction('configuracion.parametrizacion', 'write') === null) {
            return;
        }
        Http::requireCsrf();

        try {
            $count = (new PropertyGalleryService())->upload($_FILES['images'] ?? []);
            Http::flash(self::FLASH, 'success', sprintf('Se agregaron %d %s a la galería pública.', $count, $count === 1 ? 'imagen' : 'imágenes'));
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }

        Http::redirect('/configuracion/galeria-publica');
    }

    public function deletePropertyGalleryImage(): void
    {
        if ($this->requireAction('configuracion.parametrizacion', 'write') === null) {
            return;
        }
        Http::requireCsrf();
        $name = Http::string($_POST['image'] ?? null);

        if ((new PropertyGalleryService())->delete($name)) {
            Http::flash(self::FLASH, 'success', 'La imagen se retiró de la galería pública.');
        } else {
            Http::flash(self::FLASH, 'error', 'No se encontró la imagen seleccionada.');
        }

        Http::redirect('/configuracion/galeria-publica');
    }

    /** Personalización / Sitio público: textos de la portada con borrador, vista previa y publicación. */
    public function publicSite(): void
    {
        $actor = Authorization::requirePermission('configuracion.parametrizacion', 'read');
        if ($actor === null) {
            return;
        }

        Session::start();
        $errors = $_SESSION['public_site_errors'] ?? [];
        $old = $_SESSION['public_site_old'] ?? null;
        unset($_SESSION['public_site_errors'], $_SESSION['public_site_old']);
        $service = new PublicSiteService();
        $this->render('public-site', 'Configuracion / Sitio publico', [
            'actor' => $actor,
            'values' => is_array($old) ? $old : $service->draftOverrides(),
            'status' => $service->status(),
            'errors' => is_array($errors) ? $errors : [],
            'galleryCount' => count((new PropertyGalleryService())->images()),
        ]);
    }

    public function savePublicSite(): void
    {
        $actor = $this->requireAction('configuracion.parametrizacion', 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $input = $_POST['site'] ?? [];
        $input = is_array($input) ? $input : [];
        try {
            $errors = (new PublicSiteService())->saveDraft($input, (int) $actor['id']);
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect('/configuracion/sitio-publico');
        }
        if ($errors !== []) {
            Session::start();
            $_SESSION['public_site_errors'] = $errors;
            $_SESSION['public_site_old'] = array_map(
                static fn (mixed $value): string => is_string($value) ? $value : '',
                array_intersect_key($input, PublicSiteService::fields())
            );
            Http::flash(self::FLASH, 'error', 'Revisa los campos marcados; el borrador no se guardó.');
            Http::redirect('/configuracion/sitio-publico');
        }

        if (Http::string($_POST['after'] ?? null) === 'preview') {
            Http::redirect('/configuracion/sitio-publico/vista-previa');
        }
        Http::flash(self::FLASH, 'success', 'Borrador guardado. Revísalo en la vista previa y publícalo cuando esté listo.');
        Http::redirect('/configuracion/sitio-publico');
    }

    public function previewPublicSite(): void
    {
        if (Authorization::requirePermission('configuracion.parametrizacion', 'read') === null) {
            return;
        }

        (new \App\Modules\Home\HomeController())->render((new PublicSiteService())->draft(), true);
    }

    public function publishPublicSite(): void
    {
        $actor = $this->requireAction('configuracion.parametrizacion', 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        try {
            $published = (new PublicSiteService())->publish((int) $actor['id']);
            Http::flash(self::FLASH, $published ? 'success' : 'error', $published
                ? 'Los cambios ya se ven en el sitio público.'
                : 'No hay un borrador para publicar.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/sitio-publico');
    }

    public function discardPublicSite(): void
    {
        if ($this->requireAction('configuracion.parametrizacion', 'write') === null) {
            return;
        }
        Http::requireCsrf();

        try {
            $discarded = (new PublicSiteService())->discardDraft();
            Http::flash(self::FLASH, $discarded ? 'success' : 'error', $discarded
                ? 'Se descartó el borrador; el formulario muestra de nuevo lo publicado.'
                : 'No hay un borrador para descartar.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/sitio-publico');
    }

    public function financial(): void
    {
        $actor = Authorization::requirePermission('configuracion.financiera', 'read');
        if ($actor === null) {
            return;
        }

        // Inmuebles y suma de coeficientes de la conformación: la vista muestra cuánto se recauda con cada opción.
        $composition = (new CompositionService())->current();
        $coefficients = [];
        foreach (is_array($composition['units'] ?? null) ? $composition['units'] : [] as $unit) {
            if (is_array($unit) && is_numeric($unit['coefficient'] ?? null)) {
                $coefficients[] = (float) $unit['coefficient'];
            }
        }
        $this->render('financial', 'Configuracion / Financiera', [
            'actor' => $actor,
            'settings' => (new SettingsService())->current(),
            'unitCount' => is_array($composition['units'] ?? null) ? count($composition['units']) : 0,
            'coefficientSum' => round(array_sum($coefficients), 4),
        ]);
    }

    public function saveFinancial(): void
    {
        $actor = Authorization::requirePermission('configuracion.financiera', 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $administrationFee = $this->moneyInput($_POST['administration_fee'] ?? null);
        $effectiveDate = Http::string($_POST['effective_date'] ?? null);
        $discount = $this->moneyInput($_POST['early_payment_discount'] ?? null);
        $lateFeeType = Http::string($_POST['late_fee_type'] ?? null);
        $lateFeeValue = $this->moneyInput($_POST['late_fee_value'] ?? null);
        $lateFeeDays = Http::integer($_POST['late_fee_days'] ?? null);
        $fineAmount = $this->moneyInput($_POST['fine_amount'] ?? null);
        $extraordinaryFeeInput = $_POST['extraordinary_fee'] ?? null;
        $extraordinaryFee = $extraordinaryFeeInput === null || $extraordinaryFeeInput === ''
            ? null
            : $this->moneyInput($extraordinaryFeeInput);
        $extraordinaryFeeStartDate = trim(Http::string($_POST['extraordinary_fee_start_date'] ?? null));
        $extraordinaryFeeEndDate = trim(Http::string($_POST['extraordinary_fee_end_date'] ?? null));
        $validDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        $extraordinaryStartDate = $extraordinaryFeeStartDate === ''
            ? null
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $extraordinaryFeeStartDate);
        $extraordinaryStartErrors = \DateTimeImmutable::getLastErrors();
        $extraordinaryEndDate = $extraordinaryFeeEndDate === ''
            ? null
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $extraordinaryFeeEndDate);
        $extraordinaryEndErrors = \DateTimeImmutable::getLastErrors();
        $extraordinaryFeeConfigured = $extraordinaryFee !== null
            && $extraordinaryFeeStartDate !== ''
            && $extraordinaryFeeEndDate !== '';
        $extraordinaryFeeCleared = $extraordinaryFee === null
            && $extraordinaryFeeStartDate === ''
            && $extraordinaryFeeEndDate === '';

        if ($administrationFee === false || $discount === false || $lateFeeValue === false
            || $fineAmount === false || !in_array($lateFeeType, ['percentage', 'fixed'], true)
            || $lateFeeDays === false || $lateFeeDays === null || $lateFeeDays < 0 || $lateFeeDays > 365
            || $effectiveDate === '' || $validDate === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || ($lateFeeType === 'percentage' && $this->decimalGreaterThan($lateFeeValue, '100'))
            || (!$extraordinaryFeeConfigured && !$extraordinaryFeeCleared)
            || ($extraordinaryFeeConfigured && (
                $extraordinaryFee === false
                || $extraordinaryStartDate === false
                || $extraordinaryEndDate === false
                || $extraordinaryStartDate->format('Y-m-d') !== $extraordinaryFeeStartDate
                || $extraordinaryEndDate->format('Y-m-d') !== $extraordinaryFeeEndDate
                || ($extraordinaryStartErrors !== false && (
                    $extraordinaryStartErrors['warning_count'] > 0 || $extraordinaryStartErrors['error_count'] > 0
                ))
                || ($extraordinaryEndErrors !== false && (
                    $extraordinaryEndErrors['warning_count'] > 0 || $extraordinaryEndErrors['error_count'] > 0
                ))
                || $extraordinaryFeeStartDate > $extraordinaryFeeEndDate
            ))
        ) {
            Http::flash(self::FLASH, 'error', 'Revisa los valores financieros, las fechas de vigencia y el porcentaje de mora entre 0 y 100.');
            Http::redirect('/configuracion/financiera');
        }

        $feeBasis = Http::string($_POST['fee_basis'] ?? 'base');
        try {
            (new SettingsService())->saveFinancial(
                $administrationFee,
                $effectiveDate,
                $discount,
                $lateFeeType,
                $lateFeeValue,
                $lateFeeDays,
                $fineAmount,
                $extraordinaryFeeConfigured ? $extraordinaryFee : null,
                $extraordinaryFeeConfigured ? $extraordinaryFeeStartDate : null,
                $extraordinaryFeeConfigured ? $extraordinaryFeeEndDate : null,
                $feeBasis
            );
        } catch (\RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect('/configuracion/financiera');
        }
        Http::flash(self::FLASH, 'success', 'Los parametros financieros se guardaron correctamente.');
        Http::redirect('/configuracion/financiera');
    }

    public function mail(): void
    {
        $actor = Authorization::requirePermission('configuracion.correo', 'read');
        if ($actor === null) {
            return;
        }

        $service = new MailSettingsService();
        $this->render('mail', 'Configuracion / Correo', [
            'actor' => $actor,
            'settings' => $service->current(),
            'connectedAccount' => $service->connectedAccount(),
            'oauthProviders' => MailOAuthService::availableProviders(),
            'oauthResult' => MailOAuthController::resultFlash(),
        ]);
    }

    public function saveMail(): void
    {
        if ($this->requireAction('configuracion.correo', 'write') === null) {
            return;
        }
        Http::requireCsrf();

        ['host' => $host, 'port' => $port, 'username' => $username, 'password' => $password,
         'encryption' => $encryption, 'from_address' => $fromAddress, 'from_name' => $fromName] = $this->smtpInput('/configuracion/correo');

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
            Http::flash(self::FLASH, 'success', 'La configuración de correo se guardó de forma segura.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }

        Http::redirect('/configuracion/correo');
    }

    public function testMail(): void
    {
        $actor = $this->requireAction('configuracion.correo', 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

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
            Http::flash(self::FLASH, 'success', 'Se envió el correo de prueba a ' . $actor['email'] . '.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            error_log('Fallo la prueba SMTP: ' . $exception->getMessage());
            Http::flash(self::FLASH, 'error', 'No se pudo enviar el correo. Verifica los datos del proveedor y su conexión SMTP.');
        }

        Http::redirect('/configuracion/correo');
    }

    /**
     * Datos SMTP del formulario (cuenta general o correo personal). Si no son válidos, vuelve a $returnPath
     * con el aviso.
     *
     * @return array{host: string, port: int, username: string, password: string, encryption: string, from_address: string, from_name: string}
     */
    private function smtpInput(string $returnPath): array
    {
        $input = [
            'host' => trim(Http::string($_POST['host'] ?? null)),
            'port' => Http::integer($_POST['port'] ?? null),
            'username' => trim(Http::string($_POST['username'] ?? null)),
            'password' => Http::string($_POST['password'] ?? null),
            'encryption' => Http::string($_POST['encryption'] ?? null),
            'from_address' => trim(Http::string($_POST['from_address'] ?? null)),
            'from_name' => trim(Http::string($_POST['from_name'] ?? null)),
        ];
        $port = $input['port'];
        if (preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D', $input['host']) !== 1
            || !is_int($port) || $port < 1 || $port > 65535
            || $input['username'] === '' || strlen($input['username']) > 254 || strlen($input['password']) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $input['username']) === 1
            || !in_array($input['encryption'], ['tls', 'ssl'], true)
            || !filter_var($input['from_address'], FILTER_VALIDATE_EMAIL)
            || strlen($input['from_name']) > 160 || preg_match('/[\x00-\x1F\x7F]/', $input['from_name']) === 1
        ) {
            Http::flash(self::FLASH, 'error', 'Revisa el servidor, puerto, cifrado, usuario y datos del remitente.');
            Http::redirect($returnPath);
        }

        return $input;
    }

    /* ---------- Correo personal de envío (p. ej. correo certificado) de cada administrador ---------- */

    private const PERSONAL_MAIL_PERMISSION = 'cartera.cobros';

    public function myMail(): void
    {
        $actor = Authorization::requirePermission(self::PERSONAL_MAIL_PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        $general = (new MailSettingsService())->current();
        $personal = new PersonalMailService();
        $this->render('my-mail', 'Mi correo de envío', [
            'actor' => $actor,
            'personal' => $personal->current((int) $actor['id']),
            'generalAddress' => (new MailSettingsService())->credentials()['from_address'] ?? $general['from_address'],
            'connectedAccount' => $personal->connectedAccount((int) $actor['id']),
            'oauthProviders' => MailOAuthService::availableProviders(),
            'oauthResult' => MailOAuthController::resultFlash(),
        ]);
    }

    public function saveMyMail(): void
    {
        $actor = Authorization::requirePermission(self::PERSONAL_MAIL_PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        ['host' => $host, 'port' => $port, 'username' => $username, 'password' => $password,
         'encryption' => $encryption, 'from_address' => $fromAddress, 'from_name' => $fromName] = $this->smtpInput('/configuracion/mi-correo');

        try {
            $service = new PersonalMailService();
            $service->save((int) $actor['id'], $host, $port, $username, $password, $encryption, $fromAddress, $fromName);
            $active = $service->current((int) $actor['id'])['active'] ?? false;
            Http::flash(self::FLASH, 'success', $active
                ? 'Se guardaron los cambios; tu correo personal sigue activo.'
                : 'Datos guardados. Ahora envía el correo de prueba: si llega, tu cuenta quedará activa para tus envíos.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/mi-correo');
    }

    public function testMyMail(): void
    {
        $actor = Authorization::requirePermission(self::PERSONAL_MAIL_PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $service = new PersonalMailService();
        try {
            // La cuenta conectada con Google o Microsoft tiene prioridad; si no hay, se prueba el SMTP personal.
            $connected = (new MailOAuthService())->sendingSettings('personal', (int) $actor['id'], '');
            $settings = $connected ?? $service->credentials((int) $actor['id']);
            if ($settings === null) {
                throw new RuntimeException('Primero conecta tu cuenta o guarda los datos de tu servidor de correo.');
            }
            $name = htmlspecialchars($actor['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $from = htmlspecialchars($settings['from_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            (new SmtpMailer())->send(
                $settings,
                $actor['email'],
                $actor['username'],
                'Prueba de tu correo de envío · ' . (new SettingsService())->propertyName(),
                '<p>Hola ' . $name . ',</p><p>Tu cuenta <strong>' . $from . '</strong> quedó lista para enviar las notificaciones de cobro que tú emitas.</p>'
                    . '<p>Si tu proveedor certifica los envíos, deberías recibir también su constancia.</p>',
                'Tu cuenta ' . $settings['from_address'] . ' quedo lista para enviar las notificaciones de cobro que tu emitas.'
            );
            if ($connected === null) {
                $service->markVerified((int) $actor['id']);
            }
            Http::flash(self::FLASH, 'success', 'La prueba se envió a ' . $actor['email'] . ' y tu correo personal quedó ACTIVO: las notas que emitas o reenvíes saldrán desde ' . $settings['from_address'] . '.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            error_log('Fallo la prueba SMTP personal: ' . $exception->getMessage());
            Http::flash(self::FLASH, 'error', 'El proveedor rechazó el envío. Revisa servidor, puerto, cifrado, usuario y contraseña (algunos proveedores exigen una contraseña de aplicación). Tu cuenta sigue inactiva.');
        }
        Http::redirect('/configuracion/mi-correo');
    }

    public function setMyMailStatus(): void
    {
        $actor = Authorization::requirePermission(self::PERSONAL_MAIL_PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        $active = ($_POST['active'] ?? '') === '1';
        try {
            (new PersonalMailService())->setActive((int) $actor['id'], $active);
            Http::flash(self::FLASH, 'success', $active
                ? 'Tu correo personal está activo para tus envíos.'
                : 'Tu correo personal quedó en pausa: tus envíos saldrán por la cuenta general de la copropiedad.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/mi-correo');
    }

    public function deleteMyMail(): void
    {
        $actor = Authorization::requirePermission(self::PERSONAL_MAIL_PERMISSION, 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();
        (new PersonalMailService())->delete((int) $actor['id']);
        Http::flash(self::FLASH, 'success', 'Se eliminó tu correo personal de envío y su contraseña. Tus envíos saldrán por la cuenta general.');
        Http::redirect('/configuracion/mi-correo');
    }

    public function paymentMethods(): void
    {
        $actor = Authorization::requirePermission('configuracion.pagos', 'read');
        if ($actor === null) {
            return;
        }

        $boldEnabled = ModuleCatalog::enabled('pagos_en_linea');
        $service = new BoldPaymentService();
        $settings = $service->settings();
        $availableMethods = [];
        $providerError = null;
        if ($boldEnabled && $settings['api_key_configured']) {
            try {
                $availableMethods = $service->availableMethods();
            } catch (RuntimeException $exception) {
                $providerError = $exception->getMessage();
            }
        }
        $terminalService = new BoldTerminalService();
        $terminalSettings = $terminalService->settings();
        $availableTerminals = [];
        $availableTerminalMethods = [];
        $terminalProviderError = null;
        if ($boldEnabled && $terminalSettings['api_key_configured']) {
            try {
                $availableTerminals = $terminalService->terminals();
                $availableTerminalMethods = $terminalService->paymentMethods();
            } catch (RuntimeException $exception) {
                $terminalProviderError = $exception->getMessage();
            }
        }

        $this->render('payment-methods', 'Configuracion / Metodos de pago', [
            'actor' => $actor,
            'settings' => $settings,
            'availableMethods' => $availableMethods,
            'providerError' => $providerError,
            'terminalSettings' => $terminalSettings,
            'availableTerminals' => $availableTerminals,
            'availableTerminalMethods' => $availableTerminalMethods,
            'terminalProviderError' => $terminalProviderError,
            'boldEnabled' => $boldEnabled,
            'webhookUrl' => $service->webhookUrl(),
            'wompiEnabled' => ModuleCatalog::enabled(WompiPaymentService::MODULE),
            'wompiSettings' => (new WompiPaymentService())->settings(),
            'wompiEventsUrl' => (new WompiPaymentService())->webhookUrl(),
        ]);
    }

    public function savePaymentMethods(): void
    {
        if (Authorization::requirePermission('configuracion.pagos', 'write') === null) {
            return;
        }
        Http::requireCsrf();

        $methods = $_POST['payment_methods'] ?? [];
        try {
            if (!is_array($methods) || !array_is_list($methods)) {
                throw new RuntimeException('Selecciona métodos de pago válidos.');
            }
            $unavailableMethods = (new BoldPaymentService())->saveSettings(
                Http::string($_POST['environment'] ?? null),
                Http::string($_POST['api_key'] ?? null),
                $methods
            );
            $message = 'La configuración de Bold se guardó y la conexión fue verificada.';
            if ($unavailableMethods !== []) {
                $message .= ' Métodos no habilitados en Bold: ' . implode(', ', $unavailableMethods) . '.';
            }
            Http::flash(self::FLASH, 'success', $message);
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/metodos-pago');
    }

    public function saveWompiSettings(): void
    {
        if (Authorization::requirePermission('configuracion.pagos', 'write') === null) {
            return;
        }
        Http::requireCsrf();
        ModuleCatalog::requireEnabled(WompiPaymentService::MODULE);
        try {
            (new WompiPaymentService())->saveSettings(
                Http::string($_POST['wompi_environment'] ?? null),
                Http::string($_POST['wompi_public_key'] ?? null),
                Http::string($_POST['wompi_private_key'] ?? null),
                Http::string($_POST['wompi_events_secret'] ?? null),
                Http::string($_POST['wompi_integrity_secret'] ?? null)
            );
            Http::flash(self::FLASH, 'success', 'Wompi quedó configurado y reconoció la llave pública del comercio.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/metodos-pago#wompi');
    }

    public function saveBoldTerminalSettings(): void
    {
        if (Authorization::requirePermission('configuracion.pagos', 'write') === null) {
            return;
        }
        Http::requireCsrf();
        try {
            $terminalParts = explode('|', Http::string($_POST['terminal'] ?? null), 2);
            (new BoldTerminalService())->saveSettings(
                Http::string($_POST['terminal_environment'] ?? null),
                Http::string($_POST['terminal_api_key'] ?? null),
                Http::string($_POST['terminal_webhook_secret'] ?? null),
                $terminalParts[0] ?? '',
                $terminalParts[1] ?? '',
                Http::string($_POST['terminal_payment_method'] ?? null)
            );
            Http::flash(self::FLASH, 'success', 'API Integrations quedó configurada y Bold validó el método y el datáfono.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/metodos-pago');
    }

    public function characterization(): void
    {
        $actor = Authorization::requirePermission('configuracion.caracterizacion', 'read');
        if ($actor === null) {
            return;
        }

        $settings = (new SettingsService())->current();
        $compositionService = new CompositionService();
        $composition = $compositionService->current();
        $housingType = in_array($settings['housing_type'] ?? null, ['casa', 'torres_apartamento', 'mixto'], true)
            ? (string) $settings['housing_type']
            : '';
        $this->render('characterization', 'Conformación / Caracterización', [
            'actor' => $actor,
            'settings' => $settings,
            'hasComposition' => $composition !== null
                && ($composition['answers'] !== [] || $composition['units'] !== null),
            'impact' => [
                'units' => is_array($composition['units'] ?? null) ? count($composition['units']) : 0,
                'answers' => count($composition['answers'] ?? []),
                'assignments' => $compositionService->assignmentCount(),
            ],
            'billingLocked' => $compositionService->unitIdsWithBillingHistory() !== [],
            'conformation' => $compositionService->progress($housingType),
        ]);
    }

    public function saveCharacterization(): void
    {
        $actor = Authorization::requirePermission('configuracion.caracterizacion', 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $housingType = Http::string($_POST['housing_type'] ?? null);
        if (!in_array($housingType, ['casa', 'torres_apartamento', 'mixto'], true)) {
            Http::flash(self::FLASH, 'error', 'Selecciona una caracterizacion valida.');
            Http::redirect('/configuracion/caracterizacion');
        }

        $settings = (new SettingsService())->current();
        $previousType = $settings['housing_type'] ?? '';
        $previousNomenclature = $settings['tower_nomenclature'] ?? 'numeric';
        $previousApartmentNomenclature = UnitNomenclature::isApartmentFormat($settings['apartment_nomenclature'] ?? null)
            ? $settings['apartment_nomenclature']
            : 'piso_numero';
        $hasTowers = in_array($housingType, ['torres_apartamento', 'mixto'], true);
        $towerNomenclature = $hasTowers
            ? Http::string($_POST['tower_nomenclature'] ?? null)
            : $previousNomenclature;
        if (!UnitNomenclature::isTowerFormat($towerNomenclature)) {
            Http::flash(self::FLASH, 'error', 'Selecciona un tipo de nomenclatura valido para las torres.');
            Http::redirect('/configuracion/caracterizacion');
        }
        $apartmentNomenclature = $hasTowers
            ? Http::string($_POST['apartment_nomenclature'] ?? null)
            : $previousApartmentNomenclature;
        if (!UnitNomenclature::isApartmentFormat($apartmentNomenclature)) {
            Http::flash(self::FLASH, 'error', 'Selecciona un tipo de nomenclatura valido para los apartamentos.');
            Http::redirect('/configuracion/caracterizacion');
        }

        $compositionService = new CompositionService();
        $composition = $compositionService->current();
        $hasComposition = is_array($composition)
            && ($composition['answers'] !== [] || $composition['units'] !== null);
        $confirmedChange = $this->confirmedReset($_POST['confirm_text'] ?? null);
        $nomenclatureChanged = $hasTowers
            && ($towerNomenclature !== $previousNomenclature || $apartmentNomenclature !== $previousApartmentNomenclature);
        $resetsStructure = ($housingType !== $previousType || $nomenclatureChanged) && $hasComposition;

        if ($resetsStructure && !Authorization::isSuperadmin($actor)) {
            Http::flash(self::FLASH, 
                'error',
                'Solo el perfil Superadmin puede reiniciar el proceso de conformación. La caracterización no se cambió.'
            );
            Http::redirect('/configuracion/caracterizacion');
        }
        if ($resetsStructure && $compositionService->unitIdsWithBillingHistory() !== []) {
            Http::flash(self::FLASH, 
                'error',
                'No se puede cambiar la caracterización: los inmuebles actuales ya tienen cartera (notas de cobro, cargos o referencias de pago) y su historial de cartera quedaría desconectado. Corrige nombres o coeficientes desde Conformación de la copropiedad.'
            );
            Http::redirect('/configuracion/caracterizacion');
        }
        if ($resetsStructure && !$confirmedChange) {
            Http::flash(self::FLASH, 
                'error',
                'Para cambiar la caracterización escribe REINICIAR en la confirmación. Se eliminarán la estructura de inmuebles y sus asignaciones.'
            );
            Http::redirect('/configuracion/caracterizacion');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            (new SettingsService())->saveCharacterization($housingType, $towerNomenclature, $apartmentNomenclature);
            if ($resetsStructure) {
                $compositionService->clear();
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        if ($resetsStructure || $previousType === '') {
            // El siguiente paso natural es definir la estructura con la nueva caracterización.
            Http::flash(self::FLASH, 'success', 'Caracterización guardada. Ahora define cuántos inmuebles conforman la copropiedad.');
            Http::redirect('/configuracion/conformacion');
        }
        Http::flash(self::FLASH, 'success', 'La caracterización se guardó correctamente.');
        Http::redirect('/configuracion/caracterizacion');
    }

    /** Las acciones que borran la estructura exigen escribir REINICIAR, no solo pulsar «Aceptar». */
    private function confirmedReset(mixed $value): bool
    {
        return is_string($value) && mb_strtoupper(trim($value), 'UTF-8') === 'REINICIAR';
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
            Http::flash(self::FLASH, 'error', 'Primero define la caracterizacion de la copropiedad.');
            Http::redirect('/configuracion/caracterizacion');
        }

        $towerNomenclature = $settings['tower_nomenclature'] ?? 'numeric';
        $apartmentNomenclature = $settings['apartment_nomenclature'] ?? 'piso_numero';
        if (!UnitNomenclature::isTowerFormat($towerNomenclature) || !UnitNomenclature::isApartmentFormat($apartmentNomenclature)) {
            throw new RuntimeException('El tipo de nomenclatura guardado no es valido.');
        }

        $compositionService = new CompositionService();
        $composition = $compositionService->current();
        if (!is_array($composition) || $composition['housing_type'] !== $housingType) {
            $composition = null;
        }
        $answers = $composition['answers'] ?? [];
        $steps = $this->compositionSteps($housingType);
        $firstUnanswered = $this->firstUnansweredCompositionStep($steps, $answers);
        $allAnswered = $firstUnanswered === count($steps);
        $requestedStep = Http::integer($_GET['paso'] ?? null);
        $stepIndex = $requestedStep === false || $requestedStep === null
            ? $firstUnanswered
            : min(max($requestedStep, 0), $firstUnanswered);

        $structureExists = ($composition['units'] ?? null) !== null;
        $editingAnswer = $structureExists && $requestedStep !== null && $requestedStep !== false
            && Authorization::isSuperadmin($actor);
        if ($editingAnswer) {
            // «Cambiar» una cantidad con la estructura ya generada: se muestra el paso con la advertencia de regeneración.
            $mode = 'wizard';
        } elseif ($structureExists) {
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
                $category = trim(Http::string($unit['category'] ?? null));
                $category = $category !== '' ? $category : 'Sin categoria';
                $unitCountsByCategory[$category] = ($unitCountsByCategory[$category] ?? 0) + 1;
            }
            ksort($unitCountsByCategory, SORT_NATURAL | SORT_FLAG_CASE);
        }

        // Si el servidor rechazó la estructura, se devuelve exactamente lo que el usuario había escrito.
        Session::start();
        $draftUnits = null;
        if (is_string($_SESSION['composition_draft'] ?? null)) {
            $decodedDraft = json_decode($_SESSION['composition_draft'], true);
            $draftUnits = is_array($decodedDraft) && array_is_list($decodedDraft) ? $decodedDraft : null;
        }
        unset($_SESSION['composition_draft']);
        if ($mode === 'structure' && $draftUnits !== null) {
            $units = array_map(static fn (mixed $unit): array => is_array($unit) ? [
                'id' => is_string($unit['id'] ?? null) ? $unit['id'] : '',
                'category' => is_string($unit['category'] ?? null) ? $unit['category'] : '',
                'name' => is_string($unit['name'] ?? null) ? $unit['name'] : '',
                'coefficient' => is_scalar($unit['coefficient'] ?? null) ? (string) $unit['coefficient'] : '',
            ] : ['id' => '', 'category' => '', 'name' => '', 'coefficient' => ''], $draftUnits);
        }

        $this->render('composition', 'Conformación de la copropiedad', [
            'actor' => $actor,
            'structureExists' => $structureExists,
            'restoredDraft' => $mode === 'structure' && $draftUnits !== null,
            'billingUnitIds' => $compositionService->unitIdsWithBillingHistory(),
            'assignmentCount' => $compositionService->assignmentCount(),
            'conformation' => $compositionService->progress($housingType),
            'housingType' => $housingType,
            'towerNomenclature' => $towerNomenclature,
            'apartmentNomenclature' => $apartmentNomenclature,
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
            // Para estimar la cuota mensual que representa cada categoría en el desglose de coeficientes.
            'feeSettings' => [
                'amount' => is_numeric($settings['administration_fee'] ?? null) ? (string) $settings['administration_fee'] : null,
                'basis' => ($settings['fee_basis'] ?? 'base') === 'budget' ? 'budget' : 'base',
            ],
        ]);
    }

    public function saveCompositionStep(): void
    {
        if ($this->requireAction('configuracion.conformacion', 'write') === null) {
            return;
        }
        Http::requireCsrf();

        $housingType = $this->configuredHousingType();
        $steps = $this->compositionSteps($housingType);
        $field = Http::string($_POST['field'] ?? null);
        $value = Http::integer($_POST['value'] ?? null);
        $fieldIndex = array_search($field, array_column($steps, 'key'), true);
        $minimum = $fieldIndex === false ? 1 : $steps[$fieldIndex]['min'];

        if ($fieldIndex === false || $value === false || $value === null || $value < $minimum || $value > 5000) {
            Http::flash(self::FLASH, 'error', sprintf('Ingresa una cantidad como numero entero, entre %d y 5.000.', $minimum));
            Http::redirect('/configuracion/conformacion');
        }

        $composition = (new CompositionService())->current();
        $answers = is_array($composition) && $composition['housing_type'] === $housingType
            ? $composition['answers']
            : [];
        $answers[$field] = $value;
        $error = $this->compositionAnswersError($housingType, $answers, false);
        if ($error !== null) {
            Http::flash(self::FLASH, 'error', $error);
            Http::redirect('/configuracion/conformacion?paso=' . $fieldIndex);
        }

        $changed = !is_array($composition) || $composition['housing_type'] !== $housingType
            || $answers !== $composition['answers'];
        $structureExists = is_array($composition) && $composition['housing_type'] === $housingType
            && $composition['units'] !== null;
        if ($changed && $structureExists) {
            // Cambiar una cantidad regenera todos los inmuebles: se pierden coeficientes, ediciones y asignaciones.
            if (!Authorization::isSuperadmin(Authorization::currentUser())) {
                Http::flash(self::FLASH, 'error', 'Solo el perfil Superadmin puede reiniciar el proceso de conformación. La cantidad no se cambió.');
                Http::redirect('/configuracion/conformacion');
            }
            if ((new CompositionService())->unitIdsWithBillingHistory() !== []) {
                Http::flash(self::FLASH, 'error', 'No se puede regenerar la estructura: estos inmuebles ya tienen cartera (notas de cobro, cargos o referencias de pago). Agrega o corrige inmuebles directamente en la lista.');
                Http::redirect('/configuracion/conformacion');
            }
            if (!$this->confirmedReset($_POST['confirm_text'] ?? null)) {
                Http::flash(self::FLASH, 'error', 'La cantidad no se cambió. Para regenerar la estructura escribe REINICIAR en la confirmación.');
                Http::redirect('/configuracion/conformacion?paso=' . $fieldIndex);
            }
        }
        if ($changed) {
            (new CompositionService())->saveAnswers($answers);
        } elseif ($structureExists) {
            Http::flash(self::FLASH, 'success', 'La cantidad no cambió; la estructura de inmuebles se conserva.');
        }
        Http::redirect('/configuracion/conformacion');
    }

    /**
     * Borra la estructura (respuestas del asistente, inmuebles, coeficientes y vínculos vigentes)
     * para volver a conformar desde la primera pregunta. La caracterización se conserva.
     */
    public function resetComposition(): void
    {
        $actor = $this->requireAction('configuracion.conformacion', 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $compositionService = new CompositionService();
        if (!Authorization::isSuperadmin($actor)) {
            Http::flash(self::FLASH, 'error', 'Solo el perfil Superadmin puede reiniciar la conformación. No se borró nada.');
            Http::redirect('/configuracion/conformacion');
        }
        if ($compositionService->current() === null) {
            Http::flash(self::FLASH, 'error', 'No hay una conformación guardada para reiniciar.');
            Http::redirect('/configuracion/conformacion');
        }
        if ($compositionService->unitIdsWithBillingHistory() !== []) {
            Http::flash(self::FLASH, 'error', 'No se puede reiniciar: estos inmuebles ya tienen cartera (notas de cobro, cargos o referencias de pago) y quedaría desconectada. Corrige o agrega inmuebles directamente en la lista.');
            Http::redirect('/configuracion/conformacion');
        }
        if (!$this->confirmedReset($_POST['confirm_text'] ?? null)) {
            Http::flash(self::FLASH, 'error', 'No se reinició. Para confirmar escribe REINICIAR.');
            Http::redirect('/configuracion/conformacion');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $compositionService->clear();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        Session::start();
        unset($_SESSION['composition_draft']);
        Http::flash(self::FLASH, 'success', 'Conformación reiniciada. Responde de nuevo las cantidades para generar los inmuebles; el historial de vínculos anteriores se conserva en Trazabilidad.');
        Http::redirect('/configuracion/conformacion');
    }

    public function generateComposition(): void
    {
        if ($this->requireAction('configuracion.conformacion', 'write') === null) {
            return;
        }
        Http::requireCsrf();

        $housingType = $this->configuredHousingType();
        $settings = (new SettingsService())->current();
        $towerNomenclature = $settings['tower_nomenclature'] ?? 'numeric';
        $apartmentNomenclature = $settings['apartment_nomenclature'] ?? 'piso_numero';
        $composition = (new CompositionService())->current();
        $answers = is_array($composition) && $composition['housing_type'] === $housingType
            ? $composition['answers']
            : [];
        $error = $this->compositionAnswersError($housingType, $answers, true);
        if ($error !== null) {
            Http::flash(self::FLASH, 'error', $error);
            Http::redirect('/configuracion/conformacion');
        }

        $units = [];
        if ($housingType === 'casa') {
            for ($house = 1; $house <= $answers['houses']; $house++) {
                $units[] = ['category' => 'Casa', 'name' => 'Casa ' . $house];
            }
        } else {
            for ($tower = 1; $tower <= $answers['towers']; $tower++) {
                $towerName = UnitNomenclature::towerName($tower, $towerNomenclature);
                for ($floor = 1; $floor <= $answers['floors_per_tower']; $floor++) {
                    for ($unit = 1; $unit <= $answers['units_per_floor']; $unit++) {
                        $units[] = [
                            'category' => 'Apartamento',
                            'name' => UnitNomenclature::apartmentName(
                                $towerName,
                                $floor,
                                $unit,
                                $answers['units_per_floor'],
                                $apartmentNomenclature
                            ),
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
        for ($parking = 1; $parking <= ($answers['parking_spots'] ?? 0); $parking++) {
            $units[] = ['category' => 'Parqueadero', 'name' => 'Parqueadero ' . $parking];
        }
        for ($storage = 1; $storage <= ($answers['storage_units'] ?? 0); $storage++) {
            $units[] = ['category' => 'Bodega', 'name' => 'Bodega ' . $storage];
        }

        (new CompositionService())->saveUnits($units, false);
        Http::redirect('/configuracion/conformacion');
    }

    public function finishComposition(): void
    {
        if ($this->requireAction('configuracion.conformacion', 'write') === null) {
            return;
        }
        Http::requireCsrf();

        $housingType = $this->configuredHousingType();
        $compositionService = new CompositionService();
        $composition = $compositionService->current();
        if (!is_array($composition) || $composition['housing_type'] !== $housingType
            || $composition['units'] === null
        ) {
            Http::flash(self::FLASH, 'error', 'Genera la estructura antes de guardarla.');
            Http::redirect('/configuracion/conformacion');
        }

        $submittedJson = $_POST['units_json'] ?? null;
        if (!is_string($submittedJson)) {
            Http::flash(self::FLASH, 'error', 'No se recibio la lista de inmuebles. Recarga la pagina e intenta nuevamente.');
            Http::redirect('/configuracion/conformacion');
        }
        try {
            $submitted = json_decode($submittedJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Http::flash(self::FLASH, 'error', 'La lista de inmuebles tiene un formato invalido. Revisa los datos e intenta nuevamente.');
            Http::redirect('/configuracion/conformacion');
        }
        if (!is_array($submitted) || !array_is_list($submitted) || count($submitted) < 1 || count($submitted) > 5000) {
            Http::flash(self::FLASH, 'error', 'La estructura debe tener entre 1 y 5.000 inmuebles.');
            Http::redirect('/configuracion/conformacion');
        }

        $existingIds = [];
        $existingNames = [];
        foreach ($composition['units'] as $existingUnit) {
            if (is_array($existingUnit) && is_string($existingUnit['id'] ?? null)) {
                $existingIds[$existingUnit['id']] = true;
                $existingNames[$existingUnit['id']] = (string) ($existingUnit['name'] ?? '');
            }
        }
        $billingIds = $compositionService->unitIdsWithBillingHistory();
        $submittedIds = [];
        $seenNames = [];
        $units = [];
        $errors = [];
        $totalTenThousandths = 0;
        foreach ($submitted as $index => $unit) {
            $row = 'Fila ' . ($index + 1);
            if (!is_array($unit)) {
                $errors[] = $row . ': el inmueble no tiene un formato válido.';
                continue;
            }
            $name = preg_replace('/\s+/u', ' ', trim(Http::string($unit['name'] ?? null))) ?? '';
            $category = $this->normalizedCategory(Http::string($unit['category'] ?? null));
            if ($name === '' || mb_strlen($name) > 160) {
                $errors[] = $row . ': escribe el nombre del inmueble (máximo 160 caracteres).';
            }
            if ($category === '' || mb_strlen($category) > 80) {
                $errors[] = $row . ': elige o escribe una categoría (máximo 80 caracteres).';
            }
            $nameKey = mb_strtolower($name, 'UTF-8');
            if ($name !== '' && isset($seenNames[$nameKey])) {
                $errors[] = $row . ': «' . $name . '» está repetido (también en la fila ' . $seenNames[$nameKey] . ').';
            } elseif ($name !== '') {
                $seenNames[$nameKey] = $index + 1;
            }
            if ($category === 'Apartamento' && $name !== '' && !UnitNomenclature::isValidApartmentName($name)) {
                $errors[] = $row . ': los apartamentos deben terminar en «Apartamento» seguido de su número (ej. Apartamento 101 o Apartamento 01).';
            }
            $coefficient = $this->coefficientInput($unit['coefficient'] ?? null);
            if ($coefficient === false) {
                $errors[] = $row . ': el coeficiente debe estar entre 0 y 100, con máximo 4 decimales (ej. 1.2500).';
            } else {
                [$whole, $fraction] = explode('.', $coefficient);
                $totalTenThousandths += (int) $whole * 10000 + (int) $fraction;
            }
            $id = Http::string($unit['id'] ?? null);
            if ($id !== '' && (!isset($existingIds[$id]) || isset($submittedIds[$id]))) {
                Http::flash(self::FLASH, 'error', 'La lista contiene un identificador de inmueble inválido. Recarga la página e intenta nuevamente.');
                Http::redirect('/configuracion/conformacion');
            }
            if ($id !== '') {
                $submittedIds[$id] = true;
            }
            $units[] = ['id' => $id, 'category' => $category, 'name' => $name, 'coefficient' => $coefficient === false ? '' : $coefficient];
        }

        // Inmuebles con cartera no se pueden quitar: sus notas, cargos o referencia de pago quedarían sin inmueble.
        foreach (array_diff_key($billingIds, $submittedIds) as $removedId => $_) {
            if (isset($existingIds[$removedId])) {
                $errors[] = '«' . ($existingNames[$removedId] ?? 'Un inmueble') . '» tiene cartera (notas de cobro, cargos o referencia de pago) y no se puede quitar.';
            }
        }

        // Ley 675 de 2001: los coeficientes de copropiedad suman 100 %. Se tolera ±0,01 por redondeo del reglamento.
        if ($errors === [] && abs($totalTenThousandths - 1000000) > 100) {
            $errors[] = sprintf(
                'Los coeficientes suman %s %% y deben sumar 100 %% (se admite ±0,01 por redondeo). La cuota de administración se reparte con estos valores.',
                number_format($totalTenThousandths / 10000, 4, ',', '.')
            );
        }

        if ($errors !== []) {
            Session::start();
            $_SESSION['composition_draft'] = $submittedJson;
            $shown = array_slice($errors, 0, 6);
            $extra = count($errors) - count($shown);
            Http::flash(self::FLASH, 'error', 'No se guardó la estructura. Tus cambios siguen en pantalla; corrige: ' . implode(' · ', $shown)
                . ($extra > 0 ? ' · y ' . $extra . ' error(es) más.' : ''));
            Http::redirect('/configuracion/conformacion');
        }

        try {
            $compositionService->saveUnits($units, true);
        } catch (RuntimeException $exception) {
            // Lo que la base rechaza y la validación anterior no detecta (p. ej. nombres que solo difieren en tildes).
            Session::start();
            $_SESSION['composition_draft'] = $submittedJson;
            Http::flash(self::FLASH, 'error', 'No se guardó la estructura. Tus cambios siguen en pantalla; corrige: ' . $exception->getMessage());
            Http::redirect('/configuracion/conformacion');
        }
        Http::flash(self::FLASH, 'success', 'La estructura de ' . count($units) . ' inmuebles se guardó correctamente. Siguiente paso: asigna los inmuebles a sus usuarios.');
        Http::redirect('/configuracion/conformacion');
    }

    /** Unifica mayúsculas y espacios de las categorías conocidas para evitar «apartamento» vs «Apartamento». */
    private function normalizedCategory(string $category): string
    {
        $category = preg_replace('/\s+/u', ' ', trim($category)) ?? '';
        foreach (self::UNIT_CATEGORIES as $known) {
            if (mb_strtolower($category, 'UTF-8') === mb_strtolower($known, 'UTF-8')) {
                return $known;
            }
        }

        return $category;
    }

    /**
     * @return list<array{key: string, label: string, help: string, min: int}>
     */
    private function compositionSteps(string $housingType): array
    {
        if ($housingType === 'casa') {
            $steps = [[
                'key' => 'houses',
                'label' => '¿Cuantas casas conforman la copropiedad?',
                'help' => 'Cuenta todas las casas que hacen parte de la copropiedad.',
                'min' => 1,
            ]];
        } else {
            $steps = [
                [
                    'key' => 'towers',
                    'label' => '¿Cuantas torres de apartamentos hay?',
                    'help' => 'Ingresa el numero total de torres.',
                    'min' => 1,
                ],
                [
                    'key' => 'floors_per_tower',
                    'label' => '¿Cuantos pisos tiene cada torre?',
                    'help' => 'Si las torres tienen alturas distintas, ingresa el mismo numero para todas por ahora; podras corregir los inmuebles al revisar.',
                    'min' => 1,
                ],
                [
                    'key' => 'units_per_floor',
                    'label' => '¿Cuantos apartamentos hay en cada piso?',
                    'help' => 'Ingresa la cantidad de apartamentos por piso.',
                    'min' => 1,
                ],
            ];
            if ($housingType === 'mixto') {
                $steps[] = [
                    'key' => 'commercial_properties',
                    'label' => '¿Cuantos inmuebles comerciales hay?',
                    'help' => 'Cuenta locales, oficinas u otros inmuebles comerciales por separado de los apartamentos.',
                    'min' => 1,
                ];
            }
        }

        // Parqueaderos y bodegas privados con folio propio: son opcionales (0 si no hay o si son comunales).
        $steps[] = [
            'key' => 'parking_spots',
            'label' => '¿Cuantos parqueaderos privados hay?',
            'help' => 'Cuenta solo los parqueaderos que son inmuebles independientes (con coeficiente propio). Escribe 0 si no hay o si son comunales.',
            'min' => 0,
        ];
        $steps[] = [
            'key' => 'storage_units',
            'label' => '¿Cuantas bodegas o depositos privados hay?',
            'help' => 'Cuenta solo las bodegas que son inmuebles independientes (con coeficiente propio). Escribe 0 si no hay.',
            'min' => 0,
        ];

        return $steps;
    }

    private function configuredHousingType(): string
    {
        $housingType = (new SettingsService())->current()['housing_type'] ?? '';
        if (!in_array($housingType, ['casa', 'torres_apartamento', 'mixto'], true)) {
            Http::flash(self::FLASH, 'error', 'Primero define la caracterizacion de la copropiedad.');
            Http::redirect('/configuracion/caracterizacion');
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
            if (!is_int($value) || $value < $step['min'] || $value > 5000) {
                return 'Hay una cantidad faltante o invalida. Usa numeros enteros de hasta 5.000.';
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

    private function compositionUnitCount(string $housingType, array $answers): int
    {
        $extras = ($answers['parking_spots'] ?? 0) + ($answers['storage_units'] ?? 0);
        if ($housingType === 'casa') {
            return $answers['houses'] + $extras;
        }

        $apartments = $answers['towers'] * $answers['floors_per_tower'] * $answers['units_per_floor'];

        return $apartments + ($housingType === 'mixto' ? $answers['commercial_properties'] : 0) + $extras;
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

    private function coefficientInput(mixed $value): string|false
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/D', $value, $matches)) {
            return false;
        }
        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 4, '0');
        $scaled = $whole * 10000 + $fraction;
        if ($scaled < 0 || $scaled > 1000000) { // de 0 % (no paga cuota) a 100 %
            return false;
        }
        return $whole . '.' . str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);
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

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $template, string $title, array $data = []): void
    {
        Session::start();
        $data['title'] = $title;
        $data['csrfToken'] = Session::csrfToken();
        $data['flash'] = Http::pullFlash(self::FLASH);
        View::renderAdmin('Configuration/' . $template, $data);
    }

}
