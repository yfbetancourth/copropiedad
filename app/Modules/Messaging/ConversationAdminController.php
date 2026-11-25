<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\View;
use App\Modules\Billing\AccountDocumentService;
use RuntimeException;

/** Panel: el equipo de la administración atiende las conversaciones que abren los residentes. */
final class ConversationAdminController
{
    private const PERMISSION = 'mensajes';
    private const FLASH = 'messaging_flash';
    private const FILTERS = ['pending', 'answered', 'closed', 'all'];

    public function __construct(private readonly ConversationService $service = new ConversationService())
    {
    }

    public function index(): void
    {
        $actor = $this->require('read');
        $filter = in_array($_GET['estado'] ?? null, self::FILTERS, true) ? (string) $_GET['estado'] : 'pending';
        $search = trim(Http::string($_GET['q'] ?? null));
        $this->render('inbox', 'Atención a residentes', [
            'conversations' => $this->service->inbox($filter, $search),
            'filter' => $filter,
            'search' => $search,
            'pendingCount' => $this->service->pendingForStaff(),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function show(): void
    {
        $actor = $this->require('read');
        try {
            $conversation = $this->service->thread(Http::id($_GET['id'] ?? null));
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect('/comunicacion/atencion');
        }
        $canAttach = $this->canAttachDocuments($actor);
        $this->render('conversation', $conversation['subject'], [
            'conversation' => $conversation,
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
            'documentGroups' => $canAttach && $conversation['status'] !== 'closed'
                ? (new AccountDocumentService())->documentsForResident((int) $conversation['resident_id'])
                : [],
        ]);
    }

    public function reply(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        $conversationId = Http::id($_POST['conversation_id'] ?? null);
        try {
            $attachment = null;
            $selected = Http::string($_POST['attachment'] ?? null);
            if ($selected !== '') {
                if (!$this->canAttachDocuments($actor)) {
                    throw new RuntimeException('Necesitas acceso a Cartera para adjuntar documentos de cobro.');
                }
                $conversation = $this->service->thread($conversationId);
                if ($conversation['status'] === 'closed') {
                    throw new RuntimeException('La conversación está cerrada: reábrela para responder.');
                }
                $attachment = (new AccountDocumentService())->resolveAttachment((int) $conversation['resident_id'], $selected, (int) $actor['id']);
            }
            $this->service->reply($conversationId, (int) $actor['id'], false, Http::string($_POST['body'] ?? null), $attachment);
            if (($_POST['close'] ?? '') === '1') {
                $this->service->setClosed($conversationId, true, (int) $actor['id']);
            }
            Http::flash(self::FLASH, 'success', 'Respuesta enviada; el residente recibió el aviso.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/comunicacion/atencion/ver?id=' . $conversationId);
    }

    public function setStatus(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        $conversationId = Http::id($_POST['conversation_id'] ?? null);
        $close = ($_POST['close'] ?? '') === '1';
        try {
            $this->service->setClosed($conversationId, $close, (int) $actor['id']);
            Http::flash(self::FLASH, 'success', $close ? 'Conversación cerrada; se avisó al residente.' : 'Conversación reabierta.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/comunicacion/atencion/ver?id=' . $conversationId);
    }

    /** Adjuntar documentos de cobro exige que Cartera esté habilitada y acceso de lectura a ella. */
    private function canAttachDocuments(array $actor): bool
    {
        return ModuleCatalog::enabled('cartera') && Authorization::hasPermission($actor, 'cartera.cobros', 1);
    }

    private function require(string $level): array
    {
        $actor = Authorization::requirePermission(self::PERMISSION, $level);
        if ($actor === null) {
            exit;
        }

        return $actor;
    }

    private function render(string $template, string $title, array $data): void
    {
        Session::start();
        View::renderAdmin('Messaging/' . $template, $data + [
            'title' => $title,
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
        ]);
    }
}
