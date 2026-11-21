<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Session;
use App\Core\Authorization;
use App\Core\Database;
use App\Core\ModuleCatalog;
use App\Core\Tenant;
use App\Core\Url;
use App\Core\View;
use App\Modules\Billing\BoldPaymentService;
use App\Modules\Billing\BoldTerminalService;
use App\Modules\Classifieds\ClassifiedsService;
use PDOException;
use RuntimeException;
use Throwable;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly PasswordResetMailer $mailer = new PasswordResetMailer()
    ) {
    }

    public function loginForm(): void
    {
        if ($this->isAuthenticated()) {
            $user = Authorization::currentUser();
            if ($user !== null) {
                $destination = $this->loginDestination($user, $_GET['destino'] ?? null);
                if ($destination !== '/login') {
                    $this->redirect($destination);
                }
            }
        }

        $this->render('login', 'Acceso', null, null, '', $this->requestedDestination($_GET['destino'] ?? null));
    }

    public function login(): void
    {
        $this->requireCsrf();
        $identity = trim((string) ($_POST['identity'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($identity === '' || $password === '') {
            $this->render('login', 'Acceso', 'Ingresa tu usuario o correo y contraseña.', null, '', $this->requestedDestination($_POST['destination'] ?? null));
            return;
        }

        $user = $this->auth->authenticate($identity, $password);

        if ($user === null) {
            $this->render('login', 'Acceso', 'Credenciales inválidas o cuenta temporalmente bloqueada.', null, '', $this->requestedDestination($_POST['destination'] ?? null));
            return;
        }

        Session::regenerate();
        $_SESSION['admin_user_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_auth_version'] = $user['auth_version'];
        $_SESSION['tenant_scope'] = Tenant::scopeId() ?? 0;
        $_SESSION['last_activity'] = time();
        $authenticatedUser = Authorization::currentUser();
        if ($authenticatedUser === null) {
            $this->redirect('/login');
        }

        if (Tenant::isPlatform()) {
            $this->redirect('/admin'); // En la plataforma solo existe la consola del Superadmin.
        }

        $requestedDestination = $this->requestedDestination($_POST['destination'] ?? null);
        $canAccessPortal = Authorization::hasPermission($authenticatedUser, 'usuario.portal', 1);
        $canAccessAdmin = Authorization::hasPermission($authenticatedUser, 'panel', 1);
        if ($requestedDestination === 'usuario' && !$canAccessPortal) {
            Session::destroy();
            $this->render('login', 'Acceso', 'Esta cuenta no tiene acceso al panel de Usuario.', null, '', 'usuario');
            return;
        }
        if ($requestedDestination === 'admin' && !$canAccessAdmin && !$canAccessPortal) {
            Session::destroy();
            $this->render('login', 'Acceso', 'Esta cuenta no tiene acceso a un panel habilitado.', null, '', 'admin');
            return;
        }
        $this->redirect($requestedDestination === 'usuario' || !$canAccessAdmin ? '/usuario' : '/admin');
    }

    public function logout(): void
    {
        $this->requireCsrf();
        Session::destroy();
        $this->redirect('/login');
    }

    public function forgotPasswordForm(): void
    {
        $this->render('forgot-password', 'Recuperar contraseña');
    }

    public function requestPasswordReset(): void
    {
        $this->requireCsrf();
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->render('forgot-password', 'Recuperar contraseña', 'Ingresa un correo válido.');
            return;
        }

        $user = $this->auth->findActiveUserByEmail($email);
        if ($user !== null && $this->auth->canRequestReset((int) $user['id'])) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $this->auth->createResetToken((int) $user['id'], $tokenHash);

            try {
                $this->mailer->send($user['email'], $user['username'], $token);
            } catch (Throwable $exception) {
                $this->auth->invalidateResetToken($tokenHash);
                error_log('No se pudo enviar el correo de recuperacion: ' . $exception->getMessage());
            }
        }

        $this->render(
            'forgot-password',
            'Recuperar contraseña',
            null,
            'Si la cuenta está registrada, recibirás instrucciones para recuperar el acceso.'
        );
    }

    public function resetPasswordForm(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $this->render('reset-password', 'Nueva contraseña', null, null, $token);
    }

    public function resetPassword(): void
    {
        $this->requireCsrf();
        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $this->render('reset-password', 'Nueva contraseña', 'El enlace no es válido o ha vencido.', null, '');
            return;
        }

        if (strlen($password) < 12) {
            $this->render('reset-password', 'Nueva contraseña', 'La contraseña debe tener al menos 12 caracteres.', null, $token);
            return;
        }

        if (!hash_equals($password, $confirmation)) {
            $this->render('reset-password', 'Nueva contraseña', 'Las contraseñas no coinciden.', null, $token);
            return;
        }

        if (!$this->auth->resetPassword(hash('sha256', $token), $password)) {
            $this->render('reset-password', 'Nueva contraseña', 'El enlace no es válido o ha vencido.', null, '');
            return;
        }

        $this->render('login', 'Acceso', null, 'Contraseña actualizada. Ya puedes iniciar sesión.');
    }

    public function userPanel(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null) {
            return;
        }

        $paymentResult = $_GET['pago'] ?? null;
        if (is_string($paymentResult) && isset([
            'approved' => true,
            'pending' => true,
            'rejected' => true,
        ][$paymentResult]) && !isset($_SESSION['user_profile_flash'])) {
            $messages = [
                'approved' => ['success', 'Bold confirmó el pago y se actualizó el saldo de la nota.'],
                'pending' => ['error', 'El pago aún está pendiente de confirmación de Bold. Actualiza el panel en unos minutos.'],
                'rejected' => ['error', 'Bold no aprobó el pago. Puedes intentar nuevamente desde la nota.'],
            ];
            $_SESSION['user_profile_flash'] = [
                'type' => $messages[$paymentResult][0],
                'message' => $messages[$paymentResult][1],
            ];
        }

        $compositionService = new \App\Modules\Configuration\CompositionService();
        $composition = $compositionService->current();
        $unitsById = [];
        if (is_array($composition) && $composition['completed'] && is_array($composition['units'])) {
            $composition = $compositionService->ensureUnitIds();
            foreach ($composition['units'] ?? [] as $unit) {
                if (is_array($unit) && is_string($unit['id'] ?? null)) {
                    $unitsById[$unit['id']] = $unit;
                }
            }
        }

        $statement = Database::connection()->prepare(
            'SELECT unit_id FROM property_unit_assignments WHERE tenant_id = ? AND user_id = ? ORDER BY unit_id'
        );
        $statement->execute([Tenant::id(), $user['id']]);
        $assignedUnitIds = [];
        foreach ($statement->fetchAll() as $assignment) {
            $assignedUnitIds[(string) $assignment['unit_id']] = true;
        }
        $assignedUnits = [];
        foreach ($unitsById as $unitId => $unit) {
            if (isset($assignedUnitIds[$unitId])) {
                $assignedUnits[] = $unit;
            }
        }

        $billingEnabled = ModuleCatalog::enabled('cartera');
        $onlinePaymentsEnabled = ModuleCatalog::enabled('pagos_en_linea');
        $paymentService = new BoldPaymentService();
        $paymentSettings = $onlinePaymentsEnabled
            ? $paymentService->settings()
            : ['api_key_configured' => false, 'payment_methods' => []];
        Session::start();
        View::renderPortal('Portal/index', [
            'title' => 'Bienvenida',
            'user' => $user,
            'units' => $assignedUnits,
            'billingDocuments' => $billingEnabled ? $paymentService->payableStatements((int) $user['id']) : [],
            'onlinePaymentsReady' => $onlinePaymentsEnabled
                && $paymentSettings['api_key_configured']
                && $paymentSettings['payment_methods'] !== []
                && $paymentService->webhookUrl() !== '',
            'terminalPaymentsReady' => $onlinePaymentsEnabled
                && (new BoldTerminalService())->settings()['configured']
                && (new BoldTerminalService())->webhookUrl() !== '',
            'notifications' => (new PortalNotificationService())->unreadForUser((int) $user['id']),
            'classifieds' => ModuleCatalog::enabled('clasificados')
                ? (new ClassifiedsService())->listingsForUser((int) $user['id'])
                : [],
            'settings' => (new \App\Modules\Configuration\SettingsService())->current(),
            'flash' => $_SESSION['user_profile_flash'] ?? null,
            'csrfToken' => Session::csrfToken(),
        ]);
        unset($_SESSION['user_profile_flash']);
    }

    public function userStartPayment(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null || !$this->requireModule('pagos_en_linea')) {
            return;
        }
        $this->requireCsrf();

        $statementId = filter_var($_POST['statement_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($statementId) || $statementId < 1) {
            $this->profileFlash('error', 'Selecciona una nota de cobro válida.');
            $this->redirect('/usuario/pagos');
        }

        try {
            $checkoutUrl = (new BoldPaymentService())->createPaymentLink($statementId, (int) $user['id']);
            header('Location: ' . $checkoutUrl, true, 303);
            exit;
        } catch (RuntimeException $exception) {
            $this->profileFlash('error', $exception->getMessage());
            $this->redirect('/usuario/pagos');
        }
    }

    public function userStartTerminalPayment(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null || !$this->requireModule('pagos_en_linea')) return;
        $this->requireCsrf();
        $statementId = filter_var($_POST['statement_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($statementId) || $statementId < 1) {
            $this->profileFlash('error', 'Selecciona una nota de cobro válida.');
            $this->redirect('/usuario/pagos');
        }
        try {
            (new BoldTerminalService())->startPayment($statementId, (int) $user['id'], (string) ($user['email'] ?? ''));
            $this->profileFlash('success', 'Solicitud enviada al datáfono Bold. Completa el pago en el terminal; el saldo se actualizará cuando Bold confirme la transacción.');
        } catch (RuntimeException $exception) {
            $this->profileFlash('error', $exception->getMessage());
        }
        $this->redirect('/usuario/pagos');
    }

    public function userPayments(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null || !$this->requireModule('cartera')) {
            return;
        }

        $paymentResult = $_GET['pago'] ?? null;
        if (is_string($paymentResult) && isset([
            'approved' => true,
            'pending' => true,
            'rejected' => true,
        ][$paymentResult]) && !isset($_SESSION['user_profile_flash'])) {
            $messages = [
                'approved' => ['success', 'Bold confirmó el pago y se actualizó el saldo de la nota.'],
                'pending' => ['error', 'El pago aún está pendiente de confirmación de Bold. Actualiza la sección en unos minutos.'],
                'rejected' => ['error', 'Bold no aprobó el pago. Puedes intentarlo nuevamente desde la nota.'],
            ];
            $_SESSION['user_profile_flash'] = [
                'type' => $messages[$paymentResult][0],
                'message' => $messages[$paymentResult][1],
            ];
        }

        $onlinePaymentsEnabled = ModuleCatalog::enabled('pagos_en_linea');
        $paymentService = new BoldPaymentService();
        $paymentSettings = $onlinePaymentsEnabled
            ? $paymentService->settings()
            : ['api_key_configured' => false, 'payment_methods' => []];
        Session::start();
        View::renderPortal('Portal/payments', [
            'title' => 'Pagos',
            'user' => $user,
            'billingDocuments' => $paymentService->payableStatements((int) $user['id']),
            'paymentMethods' => $onlinePaymentsEnabled ? $paymentService->configuredMethods() : [],
            'onlinePaymentsReady' => $onlinePaymentsEnabled
                && $paymentSettings['api_key_configured']
                && $paymentSettings['payment_methods'] !== []
                && $paymentService->webhookUrl() !== '',
            'terminalPaymentsReady' => $onlinePaymentsEnabled
                && (new BoldTerminalService())->settings()['configured']
                && (new BoldTerminalService())->webhookUrl() !== '',
            'notifications' => (new PortalNotificationService())->unreadForUser((int) $user['id']),
            'flash' => $_SESSION['user_profile_flash'] ?? null,
            'csrfToken' => Session::csrfToken(),
        ]);
        unset($_SESSION['user_profile_flash']);
    }

    public function userMessages(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null || !$this->requireModule('mensajes')) {
            return;
        }

        View::renderPortal('Portal/messages', [
            'title' => 'Mis mensajes',
            'user' => $user,
            'notifications' => (new PortalNotificationService())->unreadForUser((int) $user['id']),
            'messages' => (new PortalNotificationService())->messagesForUser((int) $user['id']),
            'csrfToken' => Session::csrfToken(),
        ]);
    }

    public function markBillingNotificationRead(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null) {
            return;
        }
        $this->requireCsrf();

        $statementId = filter_var($_POST['statement_id'] ?? null, FILTER_VALIDATE_INT);
        if ($statementId === false || $statementId < 1) {
            http_response_code(400);
            echo 'La notificación seleccionada no es válida.';
            return;
        }

        try {
            (new PortalNotificationService())->markBillingRead($statementId, (int) $user['id']);
        } catch (RuntimeException) {
            http_response_code(404);
            echo 'La notificación no existe o no está disponible.';
            return;
        }
        $this->redirect('/usuario#notificaciones');
    }

    public function markMessageRead(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null) {
            return;
        }
        $this->requireCsrf();

        $messageId = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT);
        if ($messageId === false || $messageId < 1) {
            http_response_code(400);
            echo 'El mensaje seleccionado no es válido.';
            return;
        }

        try {
            (new PortalNotificationService())->markMessageRead($messageId, (int) $user['id']);
        } catch (RuntimeException) {
            http_response_code(404);
            echo 'El mensaje no existe o no está disponible.';
            return;
        }
        $this->redirect('/usuario/mensajes');
    }

    public function updateOwnProfile(): void
    {
        $user = Authorization::requirePermission('usuario.portal', 'read');
        if ($user === null) {
            return;
        }
        $this->requireCsrf();

        $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $passwordConfirmation = is_string($_POST['password_confirmation'] ?? null)
            ? $_POST['password_confirmation']
            : '';

        if (strlen($username) < 3 || strlen($username) > 64
            || !preg_match('/^[\p{L}\p{N}_.@-]+$/uD', $username)
            || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $currentPassword === ''
        ) {
            $this->profileFlash('error', 'Revisa tu nombre de usuario, correo y contraseña actual.');
            $this->redirect('/usuario');
        }
        if ($newPassword !== '' && strlen($newPassword) < 12) {
            $this->profileFlash('error', 'La nueva contraseña debe tener al menos 12 caracteres.');
            $this->redirect('/usuario');
        }
        if ($newPassword !== '' && !hash_equals($newPassword, $passwordConfirmation)) {
            $this->profileFlash('error', 'La nueva contraseña y su confirmación no coinciden.');
            $this->redirect('/usuario');
        }

        try {
            $authVersion = $this->auth->updateOwnCredentials(
                (int) $user['id'],
                $currentPassword,
                $username,
                $email,
                $newPassword === '' ? null : $newPassword
            );
            $_SESSION['admin_auth_version'] = $authVersion;
            $_SESSION['admin_username'] = $username;
            $this->profileFlash('success', 'Los datos de tu cuenta se actualizaron correctamente.');
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $this->profileFlash('error', 'Ese nombre de usuario o correo ya está asociado a otra cuenta.');
            } else {
                throw $exception;
            }
        } catch (RuntimeException $exception) {
            $this->profileFlash('error', $exception->getMessage());
        }

        $this->redirect('/usuario');
    }

    public function adminPanel(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('/login');
        }

        $user = Authorization::requirePermission('panel', 'read');
        if ($user === null) {
            return;
        }

        $dashboard = null;
        if (Authorization::hasPermission($user, 'cartera.cobros', 1)) {
            try {
                $dashboard = (new \App\Modules\Billing\ReportService())->dashboard();
            } catch (\Throwable $exception) {
                // El panel debe abrir aunque falte una migración o configuración de cartera.
                error_log('No se pudieron cargar los indicadores del panel: ' . $exception->getMessage());
            }
        }

        header('Cache-Control: no-store, private');
        View::renderAdmin('Auth/admin', [
            'title' => 'Bienvenida',
            'username' => $user['username'],
            'dashboard' => $dashboard,
        ]);
    }

    /** Responde 404 cuando el Superadmin deshabilitó el módulo en este conjunto. */
    private function requireModule(string $module): bool
    {
        if (ModuleCatalog::enabled($module)) {
            return true;
        }
        http_response_code(404);
        echo 'Esta opción no está habilitada en tu conjunto.';

        return false;
    }

    private function isAuthenticated(): bool
    {
        Session::start();
        $userId = $_SESSION['admin_user_id'] ?? null;
        $authVersion = $_SESSION['admin_auth_version'] ?? null;
        $lastActivity = $_SESSION['last_activity'] ?? 0;

        if (!is_int($userId) || !is_int($authVersion) || !is_int($lastActivity)
            || time() - $lastActivity > 1800
            || !$this->auth->isCurrentSession($userId, $authVersion)
        ) {
            if (isset($_SESSION['admin_user_id'])) {
                Session::destroy();
            }

            return false;
        }

        $_SESSION['last_activity'] = time();

        return true;
    }

    private function requestedDestination(mixed $destination): string
    {
        return $destination === 'usuario' ? 'usuario' : 'admin';
    }

    private function loginDestination(array $user, mixed $destination): string
    {
        if ($this->requestedDestination($destination) === 'usuario'
            && Authorization::hasPermission($user, 'usuario.portal', 1)
        ) {
            return '/usuario';
        }

        if (Authorization::hasPermission($user, 'panel', 1)) {
            return '/admin';
        }

        return Authorization::hasPermission($user, 'usuario.portal', 1) ? '/usuario' : '/login';
    }

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(419);
            echo 'La sesión del formulario venció. Recarga la página e intenta nuevamente.';
            exit;
        }
    }

    private function render(
        string $template,
        string $title,
        ?string $error = null,
        ?string $notice = null,
        string $token = '',
        string $destination = 'admin'
    ): void {
        header('Cache-Control: no-store, private');
        try {
            $coverImage = (new \App\Modules\Configuration\PropertyGalleryService())->images()[0]['url'] ?? null;
            $address = trim((string) ((new \App\Modules\Configuration\SettingsService())->current()['address'] ?? ''));
        } catch (\Throwable) {
            // La pantalla de acceso debe abrir aunque falle la galería o la configuración.
            $coverImage = null;
            $address = '';
        }
        View::renderAuth('Auth/' . $template, [
            'title' => $title,
            'csrfToken' => Session::csrfToken(),
            'error' => $error,
            'notice' => $notice,
            'token' => $token,
            'destination' => $destination,
            'coverImage' => $coverImage,
            'address' => $address,
        ]);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::to($path), true, 303);
        exit;
    }

    private function profileFlash(string $type, string $message): void
    {
        Session::start();
        $_SESSION['user_profile_flash'] = ['type' => $type, 'message' => $message];
    }
}
