<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Core\Authorization;
use App\Core\Http;
use App\Core\Session;
use App\Core\View;
use PDOException;
use RuntimeException;

/** Cartera / Importar extracto: cargar, revisar la propuesta y confirmar. */
final class BankImportController
{
    private const FLASH = 'bank_import_flash';
    private const PERMISSION = 'cartera.cobros';

    public function __construct(private readonly BankImportService $imports = new BankImportService())
    {
    }

    public function index(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        Session::start();
        View::renderAdmin('Billing/bank-import', [
            'title' => 'Cartera · Importar extracto',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'imports' => $this->imports->recentImports(),
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function upload(): void
    {
        $actor = $this->requireWrite();
        try {
            $result = $this->imports->upload($_FILES['statement'] ?? [], (int) $actor['id']);
            if ($result['warnings'] !== []) {
                Http::flash(self::FLASH, 'error', implode(' ', $result['warnings']));
            }
            Http::redirect('/cartera/extractos/revisar?id=' . $result['import_id']);
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/extractos');
    }

    public function review(): void
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'read');
        if ($actor === null) {
            return;
        }
        try {
            $data = $this->imports->review(Http::id($_GET['id'] ?? null));
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
            Http::redirect('/cartera/extractos');
        }
        Session::start();
        View::renderAdmin('Billing/bank-import-review', [
            'title' => 'Cartera · Revisar extracto',
            'csrfToken' => Session::csrfToken(),
            'flash' => Http::pullFlash(self::FLASH),
            'import' => $data['import'],
            'movements' => $data['movements'],
            'canWrite' => Authorization::hasPermission($actor, self::PERMISSION, 2),
        ]);
    }

    public function confirm(): void
    {
        $actor = $this->requireWrite();
        $importId = Http::id($_POST['import_id'] ?? null);
        $actions = $_POST['action'] ?? [];
        try {
            $counts = $this->imports->confirm($importId, is_array($actions) ? array_map('strval', $actions) : [], (int) $actor['id']);
            Http::flash(self::FLASH, $counts['failed'] > 0 ? 'error' : 'success', sprintf(
                'Extracto confirmado: %d registrado(s) como pago, %d enviado(s) a por identificar, %d omitido(s)%s.',
                $counts['applied'],
                $counts['unidentified'],
                $counts['omitted'],
                $counts['failed'] > 0 ? ' y ' . $counts['failed'] . ' con error (revisa el detalle)' : ''
            ));
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/extractos/revisar?id=' . $importId);
    }

    public function discard(): void
    {
        $this->requireWrite();
        try {
            $this->imports->discard(Http::id($_POST['import_id'] ?? null));
            Http::flash(self::FLASH, 'success', 'Importación descartada; no se registró nada.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/cartera/extractos');
    }

    private function requireWrite(): array
    {
        $actor = Authorization::requirePermission(self::PERMISSION, 'write');
        if ($actor === null) {
            exit;
        }
        Http::requireCsrf();

        return $actor;
    }
}
