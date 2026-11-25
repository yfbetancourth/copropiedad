<?php

declare(strict_types=1);

namespace App\Modules\Privacy;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

/**
 * Panel: Configuración / Privacidad y datos. Edición y publicación versionada de la política de tratamiento,
 * la autorización del formulario y la política de cookies; datos del responsable; registro de autorizaciones.
 */
final class PrivacyAdminController
{
    private const PERMISSION = 'privacidad';
    private const FLASH = 'privacy_admin_flash';
    private const OLD_INPUT = 'privacy_admin_old';
    public const TABS = ['data_policy', 'authorization', 'cookie_policy', 'responsable'];

    public function __construct(
        private readonly PrivacyService $privacy = new PrivacyService(),
        private readonly ConsentService $consents = new ConsentService(),
    ) {
    }

    public function index(): void
    {
        $actor = $this->require('read');
        $tab = Http::string($_GET['documento'] ?? null);
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'data_policy';
        }

        Session::start();
        $old = $_SESSION[self::OLD_INPUT] ?? null;
        unset($_SESSION[self::OLD_INPUT]);
        $old = is_array($old) && ($old['document'] ?? null) === $tab ? $old : null;

        $documents = [];
        foreach (PrivacyService::DOCUMENTS as $key => $definition) {
            $documents[$key] = $definition + ['current' => $this->privacy->current($key)];
        }
        $isDocument = PrivacyService::isDocument($tab);
        $useTemplate = $isDocument && ($_GET['plantilla'] ?? null) === '1';

        View::renderAdmin('Privacy/index', [
            'title' => 'Configuración · Privacidad y datos',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'tab' => $tab,
            'documents' => $documents,
            'settings' => $this->privacy->settings(),
            'formAvailable' => $this->privacy->contactFormAvailable(),
            'editorText' => $isDocument
                ? (string) ($old['content'] ?? ($useTemplate ? $this->privacy->template($tab) : $this->privacy->editorText($tab)))
                : '',
            'changeSummary' => (string) ($old['change_summary'] ?? ($documents[$tab]['current'] ?? null ? '' : 'Versión inicial')),
            'usingTemplate' => $isDocument && ($useTemplate || $documents[$tab]['current'] === null),
            'history' => $isDocument ? $this->privacy->history($tab) : [],
            'consentCounts' => $this->consents->counts(),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function publish(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        $document = Http::string($_POST['document'] ?? null);
        $content = Http::string($_POST['content'] ?? null);
        $summary = Http::string($_POST['change_summary'] ?? null);
        if (!PrivacyService::isDocument($document)) {
            Http::flash(self::FLASH, 'error', 'El documento seleccionado no existe.');
            Http::redirect('/configuracion/privacidad');
        }
        if (($_POST['confirm_review'] ?? '') !== '1') {
            $this->failWithInput($document, $content, $summary, 'Confirma que revisaste el texto antes de publicarlo.');
        }

        try {
            $version = $this->privacy->publish($document, $content, $summary, (int) $actor['id']);
            Http::flash(self::FLASH, 'success', sprintf(
                '%s publicada como versión %d. Desde ahora es la que ve el público y la que aceptan los nuevos titulares.',
                PrivacyService::DOCUMENTS[$document]['label'],
                $version
            ));
        } catch (RuntimeException $exception) {
            $this->failWithInput($document, $content, $summary, $exception->getMessage());
        }
        Http::redirect('/configuracion/privacidad?documento=' . $document);
    }

    public function saveSettings(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        try {
            $this->privacy->saveSettings(
                Http::string($_POST['contact_email'] ?? null),
                Http::string($_POST['contact_phone'] ?? null),
                Http::string($_POST['responsible_area'] ?? null),
                ($_POST['contact_form_enabled'] ?? '') === '1',
                (int) $actor['id']
            );
            Http::flash(self::FLASH, 'success', 'Datos del responsable guardados. Si cambiaron el correo o el teléfono, publica una versión nueva de la política y de la autorización para que los muestren.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/configuracion/privacidad?documento=responsable');
    }

    /** Texto exacto de una versión publicada (lo que aceptó un titular). */
    public function version(): void
    {
        $this->require('read');
        $versionId = Http::integer($_GET['id'] ?? null);
        $version = is_int($versionId) ? $this->privacy->version($versionId) : null;
        if ($version === null) {
            Http::flash(self::FLASH, 'error', 'La versión solicitada no existe.');
            Http::redirect('/configuracion/privacidad');
        }

        View::renderAdmin('Privacy/version', [
            'title' => 'Privacidad · Versión ' . $version['version'],
            'version' => $version,
            'label' => PrivacyService::DOCUMENTS[$version['document']]['label'],
        ]);
    }

    public function consentsIndex(): void
    {
        $actor = $this->require('read');
        [$status, $query, $page] = $this->consentFilters($_GET);

        Session::start();
        View::renderAdmin('Privacy/consents', [
            'title' => 'Privacidad · Registro de autorizaciones',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'status' => $status,
            'query' => $query,
            'result' => $this->consents->search($status, $query, $page),
            'counts' => $this->consents->counts(),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function exportConsents(): void
    {
        $this->require('read');
        [$status, $query] = $this->consentFilters($_GET);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="autorizaciones-datos-' . date('Ymd-His') . '.csv"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        $this->consents->exportCsv($status, $query, $output);
        fclose($output);
    }

    public function revoke(): void
    {
        $actor = $this->require('write');
        Http::requireCsrf();
        try {
            $this->consents->revoke(Http::id($_POST['consent_id'] ?? null), Http::string($_POST['note'] ?? null), (int) $actor['id']);
            Http::flash(self::FLASH, 'success', 'Revocación registrada. Si el titular pidió suprimir sus datos, elimina también su mensaje en Comunicación > Contacto del sitio web.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        [$status, $query, $page] = $this->consentFilters($_POST);
        Http::redirect('/configuracion/privacidad/autorizaciones?' . http_build_query(['estado' => $status, 'q' => $query, 'pagina' => $page]));
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function consentFilters(array $source): array
    {
        $status = Http::string($source['estado'] ?? null);
        if (!in_array($status, ['todas', 'vigentes', 'revocadas'], true)) {
            $status = 'todas';
        }
        $page = Http::integer($source['pagina'] ?? null);

        return [$status, trim(Http::string($source['q'] ?? null)), is_int($page) && $page > 0 ? $page : 1];
    }

    private function failWithInput(string $document, string $content, string $summary, string $message): never
    {
        Session::start();
        $_SESSION[self::OLD_INPUT] = ['document' => $document, 'content' => $content, 'change_summary' => $summary];
        Http::flash(self::FLASH, 'error', $message);
        Http::redirect('/configuracion/privacidad?documento=' . $document);
    }

    private function require(string $level): array
    {
        ModuleCatalog::requireEnabled('privacidad');
        $actor = Authorization::requirePermission(self::PERMISSION, $level);
        if ($actor === null) {
            exit;
        }

        return $actor;
    }
}
