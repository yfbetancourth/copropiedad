<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Http;
use App\Core\Session;
use App\Core\Authorization;
use App\Core\Database;
use App\Core\ModuleCatalog;
use App\Core\Tenant;
use App\Core\View;
use App\Modules\Billing\BoldPaymentService;
use App\Modules\Classifieds\ClassifiedsService;
use PDOException;
use RuntimeException;

final class AuthController
{
    private const FLASH = 'user_profile_flash';

    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly PasswordResetMailer $mailer = new PasswordResetMailer()
    ) {
    }

    public function loginForm(): void
    {
        $user = Authorization::currentUser();
        if ($user !== null) {
            $destination = $this->loginDestination($user);
            if ($destination !== '/login') {
                Http::redirect($destination);
            }
        }

        $this->render('login', 'Acceso', null, null, '', $this->requestedDestination($_GET['destino'] ?? null));
    }

    public function login(): void
    {
        Http::requireCsrf();
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
            Http::redirect('/login');
        }

        if (Tenant::isPlatform()) {
            Http::redirect('/admin'); // En la plataforma solo existe la consola del Superadmin.
        }

        // Cada cuenta va a su lugar: el residente al portal y el equipo de la administración al panel.
        $destination = $this->loginDestination($authenticatedUser);
        if ($destination === '/login') {
            Session::destroy();
            $this->render('login', 'Acceso', 'Esta cuenta no tiene acceso habilitado. Comunícate con la administración.', null, '', $this->requestedDestination($_POST['destination'] ?? null));
            return;
        }
        Http::redirect($destination);
    }

    public function logout(): void
    {
        Http::requireCsrf();
        Session::destroy();
        Http::redirect('/login');
    }

    public function forgotPasswordForm(): void
    {
        $this->render('forgot-password', 'Recuperar contraseña');
    }

    public function requestPasswordReset(): void
    {
        Http::requireCsrf();
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->render('forgot-password', 'Recuperar contraseña', 'Ingresa un correo válido.');
            return;
        }

        $user = $this->auth->findActiveUserByEmail($email);
        if ($user !== null && $this->auth->canRequestReset((int) $user['id'])) {
            try {
                (new AccessLinkService($this->auth, $this->mailer))->send($user, false);
            } catch (RuntimeException) {
                // No se revela al visitante si la cuenta existe ni si el correo falló (queda en el log).
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
        Http::requireCsrf();
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
        $user = Authorization::requireResident();

        $composition = (new \App\Modules\Configuration\CompositionService())->current();
        $unitsById = [];
        if (is_array($composition) && $composition['completed'] && is_array($composition['units'])) {
            foreach ($composition['units'] as $unit) {
                if (is_array($unit) && is_string($unit['id'] ?? null)) {
                    $unitsById[$unit['id']] = $unit;
                }
            }
        }

        $statement = Database::connection()->prepare(
            'SELECT unit_id, relation_type, resides, started_on
             FROM property_unit_assignments WHERE tenant_id = ? AND user_id = ? ORDER BY unit_id'
        );
        $statement->execute([Tenant::id(), $user['id']]);
        $assignedUnitIds = [];
        foreach ($statement->fetchAll() as $assignment) {
            $assignedUnitIds[(string) $assignment['unit_id']] = $assignment;
        }
        $assignedUnits = [];
        foreach ($unitsById as $unitId => $unit) {
            if (isset($assignedUnitIds[$unitId])) {
                $link = $assignedUnitIds[$unitId];
                $assignedUnits[] = $unit + [
                    'relation_label' => \App\Modules\PropertyAssignment\RelationshipHistory::label(
                        (string) $link['relation_type'],
                        (bool) $link['resides']
                    ),
                    'started_on' => (string) $link['started_on'],
                ];
            }
        }

        Session::start();
        View::renderPortal('Portal/index', [
            'title' => 'Bienvenida',
            'user' => $user,
            'units' => $assignedUnits,
            'billingSummary' => ModuleCatalog::enabled('cartera') ? $this->billingSummary((int) $user['id']) : null,
            'notifications' => (new PortalNotificationService())->unreadForUser((int) $user['id']),
            'classifieds' => ModuleCatalog::enabled('clasificados')
                ? (new ClassifiedsService())->listingsForOwner((int) $user['id'])
                : [],
            'settings' => (new \App\Modules\Configuration\SettingsService())->current(),
            // Cuota de cada inmueble del residente (no la base general ni el presupuesto completo del conjunto).
            'unitFees' => (new \App\Modules\Billing\BillingService())->monthlyFeesFor(array_map(
                static fn (array $unit): string => (string) $unit['id'],
                $assignedUnits
            )),
            'flash' => Http::pullFlash(self::FLASH),
            'csrfToken' => Session::csrfToken(),
        ]);
    }

    /**
     * Resumen de cobros para la bienvenida del portal; el detalle y los pagos están en «Pagos».
     *
     * @return array{count: int, pending: int, balance_cents: int}
     */
    private function billingSummary(int $userId): array
    {
        $documents = (new BoldPaymentService())->payableStatements($userId);
        $pending = array_filter($documents, static fn (array $document): bool => (int) $document['payable_balance'] > 0);

        return [
            'count' => count($documents),
            'pending' => count($pending),
            'balance_cents' => array_sum(array_map(static fn (array $document): int => (int) $document['payable_balance'], $pending)),
        ];
    }

    public function userMessages(): void
    {
        $user = Authorization::requireResident();
        ModuleCatalog::requireEnabled('mensajes');

        View::renderPortal('Portal/messages', [
            'title' => 'Comunicados',
            'user' => $user,
            'notifications' => (new PortalNotificationService())->unreadForUser((int) $user['id']),
            'messages' => (new PortalNotificationService())->messagesForUser((int) $user['id']),
            'csrfToken' => Session::csrfToken(),
        ]);
    }

    public function markBillingNotificationRead(): void
    {
        $user = Authorization::requireResident();
        Http::requireCsrf();

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
        Http::redirect('/usuario#notificaciones');
    }

    /** Marca un aviso como leído y lleva a su destino en el portal (comprobante, reportes, reservas…). */
    public function openNotice(): void
    {
        $user = Authorization::requireResident();
        Http::requireCsrf();
        $destination = (new PortalNotificationService())->openNotice(Http::id($_POST['notice_id'] ?? null), (int) $user['id']);
        if ($destination === null) {
            http_response_code(404);
            echo 'El aviso no existe.';
            return;
        }
        Http::redirect($destination);
    }

    public function markMessageRead(): void
    {
        $user = Authorization::requireResident();
        Http::requireCsrf();

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
        Http::redirect('/usuario/mensajes');
    }

    /** Mi cuenta del equipo de la administración (el residente la tiene en su portal). */
    public function staffAccount(): void
    {
        $user = Authorization::requirePermission('panel', 'read');
        if ($user === null) {
            return;
        }
        Session::start();
        View::renderAdmin('Auth/account', [
            'title' => 'Mi cuenta',
            'user' => $user,
            'flash' => Http::pullFlash(self::FLASH),
            'csrfToken' => Session::csrfToken(),
        ]);
    }

    public function updateOwnProfile(): void
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            Http::redirect('/login');
        }
        $back = Authorization::isResident($user) ? '/usuario#perfil' : '/mi-cuenta';
        Http::requireCsrf();

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
            Http::flash(self::FLASH, 'error', 'Revisa tu nombre de usuario, correo y contraseña actual.');
            Http::redirect($back);
        }
        if ($newPassword !== '' && strlen($newPassword) < 12) {
            Http::flash(self::FLASH, 'error', 'La nueva contraseña debe tener al menos 12 caracteres.');
            Http::redirect($back);
        }
        if ($newPassword !== '' && !hash_equals($newPassword, $passwordConfirmation)) {
            Http::flash(self::FLASH, 'error', 'La nueva contraseña y su confirmación no coinciden.');
            Http::redirect($back);
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
            Http::flash(self::FLASH, 'success', 'Los datos de tu cuenta se actualizaron correctamente.');
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                Http::flash(self::FLASH, 'error', 'Ese nombre de usuario o correo ya está asociado a otra cuenta.');
            } else {
                throw $exception;
            }
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }

        Http::redirect($back);
    }

    public function adminPanel(): void
    {
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

    private function requestedDestination(mixed $destination): string
    {
        return $destination === 'usuario' ? 'usuario' : 'admin';
    }

    /** El residente entra al portal; el equipo de la administración, al panel. */
    private function loginDestination(array $user): string
    {
        if (Authorization::isResident($user)) {
            return '/usuario';
        }

        return Authorization::hasPermission($user, 'panel', 1) ? '/admin' : '/login';
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

}
