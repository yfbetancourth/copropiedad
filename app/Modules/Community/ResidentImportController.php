<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\Session;
use App\Core\View;
use RuntimeException;

/** Comunidad / Residentes / Importar CSV: subir, revisar fila por fila y confirmar. */
final class ResidentImportController
{
    private const PERMISSION = 'comunidad.residentes';
    private const FLASH = 'community_flash';
    private const SESSION_KEY = 'resident_import';

    public function __construct(private readonly ResidentImportService $service = new ResidentImportService())
    {
    }

    public function form(): void
    {
        $this->require();
        Session::start();
        if (($_GET['descartar'] ?? '') === '1') {
            unset($_SESSION[self::SESSION_KEY]);
            Http::redirect('/comunidad/residentes/importar');
        }
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        View::renderAdmin('Community/import', [
            'title' => 'Importar residentes',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'review' => is_array($pending) ? $pending : null,
            'types' => (new ResidentTypeService())->all(true),
            'maxRows' => ResidentImportService::MAX_ROWS,
            'headers' => ResidentImportService::TEMPLATE_HEADERS,
        ]);
    }

    public function template(): void
    {
        $this->require();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla-residentes.csv"');
        echo $this->service->template();
    }

    public function review(): void
    {
        $this->require();
        Http::requireCsrf();
        Session::start();
        unset($_SESSION[self::SESSION_KEY]);
        $file = $_FILES['csv'] ?? null;
        try {
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Elige el archivo CSV con los residentes.');
            }
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || (int) $file['size'] > ResidentImportService::MAX_BYTES) {
                throw new RuntimeException('El archivo supera 1 MB. Divídelo en varios archivos.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new RuntimeException('No se pudo recibir el archivo. Intenta de nuevo.');
            }
            if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new RuntimeException('El archivo debe tener extensión .csv (en Excel: Guardar como → CSV UTF-8).');
            }
            $content = (string) file_get_contents((string) $file['tmp_name']);
            if (str_contains($content, "\0")) {
                throw new RuntimeException('El archivo no parece un CSV de texto. En Excel usa Guardar como → CSV UTF-8.');
            }
            $rows = $this->service->review($content);
            $_SESSION[self::SESSION_KEY] = [
                'file' => mb_substr(basename((string) $file['name']), 0, 120, 'UTF-8'),
                'rows' => $rows,
            ];
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/comunidad/residentes/importar');
    }

    public function confirm(): void
    {
        $this->require();
        Http::requireCsrf();
        Session::start();
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($pending) || !is_array($pending['rows'] ?? null)) {
            Http::flash(self::FLASH, 'error', 'La revisión expiró. Sube de nuevo el archivo.');
            Http::redirect('/comunidad/residentes/importar');
        }

        $result = $this->service->import($pending['rows'], ($_POST['send_invitations'] ?? '') === '1');
        $message = $result['created'] === 0 ? 'No se creó ningún residente.' : 'Se crearon ' . $result['created'] . ' residente(s).';
        if ($result['invited']) {
            $message .= ' Las invitaciones para crear la contraseña se están enviando por correo (vencen en 72 horas).';
        } elseif ($result['created'] > 0) {
            $message .= ' Aún no pueden entrar al portal: envíales el acceso desde el listado cuando quieras.';
        }
        if ($result['failed'] !== []) {
            $message .= ' No se pudieron crear: ' . implode('; ', array_map(
                static fn (array $f): string => 'fila ' . $f['line'] . ' (' . $f['message'] . ')',
                array_slice($result['failed'], 0, 10)
            )) . (count($result['failed']) > 10 ? '; y ' . (count($result['failed']) - 10) . ' más.' : '.');
        }
        Http::flash(self::FLASH, $result['failed'] === [] ? 'success' : 'error', $message);
        Http::redirect('/comunidad/residentes');
    }

    private function require(): array
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            exit;
        }

        return $actor;
    }
}
