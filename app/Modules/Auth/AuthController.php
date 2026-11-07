<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Session;
use App\Core\Authorization;
use App\Core\Url;
use App\Core\View;
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
            $this->redirect('/admin');
        }

        $this->render('login', 'Acceso administrativo');
    }

    public function login(): void
    {
        $this->requireCsrf();
        $identity = trim((string) ($_POST['identity'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($identity === '' || $password === '') {
            $this->render('login', 'Acceso administrativo', 'Ingresa tu usuario o correo y contrasena.');
            return;
        }

        $user = $this->auth->authenticate($identity, $password);

        if ($user === null) {
            $this->render('login', 'Acceso administrativo', 'Credenciales invalidas o cuenta temporalmente bloqueada.');
            return;
        }

        Session::regenerate();
        $_SESSION['admin_user_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_auth_version'] = $user['auth_version'];
        $_SESSION['last_activity'] = time();
        $this->redirect('/admin');
    }

    public function logout(): void
    {
        $this->requireCsrf();
        Session::destroy();
        $this->redirect('/login');
    }

    public function forgotPasswordForm(): void
    {
        $this->render('forgot-password', 'Recuperar contrasena');
    }

    public function requestPasswordReset(): void
    {
        $this->requireCsrf();
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->render('forgot-password', 'Recuperar contrasena', 'Ingresa un correo valido.');
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
            'Recuperar contrasena',
            null,
            'Si la cuenta esta registrada, recibiras instrucciones para recuperar el acceso.'
        );
    }

    public function resetPasswordForm(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $this->render('reset-password', 'Nueva contrasena', null, null, $token);
    }

    public function resetPassword(): void
    {
        $this->requireCsrf();
        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $this->render('reset-password', 'Nueva contrasena', 'El enlace no es valido o ha vencido.', null, '');
            return;
        }

        if (strlen($password) < 12) {
            $this->render('reset-password', 'Nueva contrasena', 'La contrasena debe tener al menos 12 caracteres.', null, $token);
            return;
        }

        if (!hash_equals($password, $confirmation)) {
            $this->render('reset-password', 'Nueva contrasena', 'Las contrasenas no coinciden.', null, $token);
            return;
        }

        if (!$this->auth->resetPassword(hash('sha256', $token), $password)) {
            $this->render('reset-password', 'Nueva contrasena', 'El enlace no es valido o ha vencido.', null, '');
            return;
        }

        $this->render('login', 'Acceso administrativo', null, 'Contrasena actualizada. Ya puedes iniciar sesion.');
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

        View::render('Auth/admin', [
            'title' => 'Panel administrativo',
            'username' => $user['username'],
            'csrfToken' => Session::csrfToken(),
        ]);
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

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(419);
            echo 'La sesion del formulario vencio. Recarga la pagina e intenta nuevamente.';
            exit;
        }
    }

    private function render(
        string $template,
        string $title,
        ?string $error = null,
        ?string $notice = null,
        string $token = ''
    ): void {
        header('Cache-Control: no-store, private');
        View::render('Auth/' . $template, [
            'title' => $title,
            'csrfToken' => Session::csrfToken(),
            'error' => $error,
            'notice' => $notice,
            'token' => $token,
        ]);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::to($path), true, 303);
        exit;
    }
}
