<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Http;
use App\Core\Authorization;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

final class MessagingController
{
    private const FLASH = 'messaging_flash';
    public function index(): void
    {
        $user = Authorization::requirePermission('mensajes', 'read');
        if ($user === null) {
            return;
        }

        View::renderAdmin('Messaging/index', [
            'title' => 'Mensajes',
            'actor' => $user,
            'users' => (new MessagingService())->activeUsers(),
            'sentMessages' => (new MessagingService())->sentBy((int) $user['id']),
            'canWrite' => Authorization::hasPermission($user, 'mensajes', 2),
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
        ]);
    }

    public function send(): void
    {
        $actor = Authorization::requirePermission('mensajes', 'write');
        if ($actor === null) {
            return;
        }
        Http::requireCsrf();

        $subject = trim(Http::string($_POST['subject'] ?? null));
        $body = trim(Http::string($_POST['body'] ?? null));
        $recipient = Http::string($_POST['recipient'] ?? null);
        $recipientId = $recipient === 'all' ? null : filter_var($recipient, FILTER_VALIDATE_INT);

        if (mb_strlen($subject, 'UTF-8') < 1 || mb_strlen($subject, 'UTF-8') > 160
            || mb_strlen($body, 'UTF-8') < 1 || mb_strlen($body, 'UTF-8') > 5000
        ) {
            Http::flash(self::FLASH, 'error', 'El asunto es obligatorio (máximo 160 caracteres) y el mensaje debe tener hasta 5000 caracteres.');
            Http::redirect('/mensajes');
        }
        if ($recipient !== 'all' && ($recipientId === false || $recipientId < 1)) {
            Http::flash(self::FLASH, 'error', 'Selecciona un destinatario válido.');
            Http::redirect('/mensajes');
        }

        try {
            $count = (new MessagingService())->send(
                (int) $actor['id'],
                $subject,
                $body,
                $recipient === 'all' ? null : (int) $recipientId
            );
            Http::flash(self::FLASH, 'success', sprintf('Mensaje enviado a %d usuario(s).', $count));
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }

        Http::redirect('/mensajes');
    }

}
