<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Authorization;
use App\Core\Session;
use App\Core\Url;
use App\Core\View;
use RuntimeException;

final class MessagingController
{
    public function index(): void
    {
        $user = Authorization::requirePermission('mensajes', 'read');
        if ($user === null) {
            return;
        }

        View::render('Messaging/index', [
            'title' => 'Mensajes',
            'actor' => $user,
            'users' => (new MessagingService())->activeUsers(),
            'sentMessages' => (new MessagingService())->sentBy((int) $user['id']),
            'canWrite' => Authorization::hasPermission($user, 'mensajes', 2),
            'csrfToken' => Session::csrfToken(),
            'flash' => $_SESSION['messaging_flash'] ?? null,
        ]);
        unset($_SESSION['messaging_flash']);
    }

    public function send(): void
    {
        $actor = Authorization::requirePermission('mensajes', 'write');
        if ($actor === null) {
            return;
        }
        $this->requireCsrf();

        $subject = trim($this->stringInput($_POST['subject'] ?? null));
        $body = trim($this->stringInput($_POST['body'] ?? null));
        $recipient = $this->stringInput($_POST['recipient'] ?? null);
        $recipientId = $recipient === 'all' ? null : filter_var($recipient, FILTER_VALIDATE_INT);

        if (mb_strlen($subject, 'UTF-8') < 1 || mb_strlen($subject, 'UTF-8') > 160
            || mb_strlen($body, 'UTF-8') < 1 || mb_strlen($body, 'UTF-8') > 5000
        ) {
            $this->flash('error', 'El asunto es obligatorio (máximo 160 caracteres) y el mensaje debe tener hasta 5000 caracteres.');
            $this->redirect('/mensajes');
        }
        if ($recipient !== 'all' && ($recipientId === false || $recipientId < 1)) {
            $this->flash('error', 'Selecciona un destinatario válido.');
            $this->redirect('/mensajes');
        }

        try {
            $count = (new MessagingService())->send(
                (int) $actor['id'],
                $subject,
                $body,
                $recipient === 'all' ? null : (int) $recipientId
            );
            $this->flash('success', sprintf('Mensaje enviado a %d usuario(s).', $count));
        } catch (RuntimeException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        $this->redirect('/mensajes');
    }

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_token'] ?? null)) {
            http_response_code(419);
            echo 'La sesión del formulario venció. Recarga la página e intenta nuevamente.';
            exit;
        }
    }

    private function stringInput(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function flash(string $type, string $message): void
    {
        Session::start();
        $_SESSION['messaging_flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Url::to($path), true, 303);
        exit;
    }
}
