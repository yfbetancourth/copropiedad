<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\View;
use App\Modules\Auth\PortalNotificationService;
use RuntimeException;

/** Portal del residente: escribir a la administración y seguir sus conversaciones. */
final class ConversationPortalController
{
    private const FLASH = 'user_profile_flash';

    public function __construct(private readonly ConversationService $service = new ConversationService())
    {
    }

    public function index(): void
    {
        $user = $this->requireResident();
        $this->render('Messaging/conversations', 'Escribir a la administración', $user, [
            'conversations' => $this->service->forResident((int) $user['id']),
        ]);
    }

    public function show(): void
    {
        $user = $this->requireResident();
        try {
            $conversation = $this->service->thread(Http::id($_GET['id'] ?? null), (int) $user['id']);
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect('/usuario/comunicacion');
        }
        $this->render('Messaging/conversation', $conversation['subject'], $user, ['conversation' => $conversation]);
    }

    public function open(): void
    {
        $user = $this->requireResident();
        Http::requireCsrf();
        try {
            $conversationId = $this->service->open(
                (int) $user['id'],
                Http::string($_POST['category'] ?? null),
                Http::string($_POST['subject'] ?? null),
                Http::string($_POST['body'] ?? null)
            );
            Http::flash(self::FLASH, 'success', 'Tu mensaje llegó a la administración. Te avisaremos aquí y por correo cuando respondan.');
            Http::redirect('/usuario/comunicacion/ver?id=' . $conversationId);
        } catch (RuntimeException $exception) {
            Session::start();
            $_SESSION['conversation_draft'] = array_intersect_key($_POST, array_flip(['category', 'subject', 'body']));
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect('/usuario/comunicacion#nueva');
        }
    }

    public function reply(): void
    {
        $user = $this->requireResident();
        Http::requireCsrf();
        $conversationId = Http::id($_POST['conversation_id'] ?? null);
        try {
            $this->service->reply($conversationId, (int) $user['id'], true, Http::string($_POST['body'] ?? null));
            Http::flash(self::FLASH, 'success', 'Mensaje enviado a la administración.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/usuario/comunicacion/ver?id=' . $conversationId . '#responder');
    }

    private function requireResident(): array
    {
        $user = Authorization::requireResident();
        ModuleCatalog::requireEnabled('mensajes');

        return $user;
    }

    private function render(string $template, string $title, array $user, array $data): void
    {
        Session::start();
        $draft = $_SESSION['conversation_draft'] ?? null;
        unset($_SESSION['conversation_draft']);
        View::renderPortal($template, $data + [
            'title' => $title,
            'user' => $user,
            'draft' => is_array($draft) ? $draft : [],
            'notifications' => (new PortalNotificationService())->unreadForUser((int) $user['id']),
            'flash' => Http::pullFlash(self::FLASH),
            'csrfToken' => Session::csrfToken(),
        ]);
    }
}
