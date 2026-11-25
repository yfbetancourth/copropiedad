<?php

declare(strict_types=1);

namespace App\Modules\Classifieds;

use App\Core\Http;
use App\Core\Authorization;
use App\Core\ModuleCatalog;
use App\Core\View;
use PDOException;
use RuntimeException;

final class ClassifiedsController
{
    private const FLASH = 'user_profile_flash';
    public function __construct(private readonly ClassifiedsService $service = new ClassifiedsService())
    {
    }

    public function index(): void
    {
        ModuleCatalog::requireEnabled('clasificados');
        View::renderPublic('Classifieds/index', [
            'title' => 'Clasificados de inmuebles',
            'listings' => $this->service->publicListings(),
            // El pie de página público muestra la dirección de la copropiedad.
            'settings' => (new \App\Modules\Configuration\SettingsService())->current(),
        ]);
    }

    public function save(): void
    {
        ModuleCatalog::requireEnabled('clasificados');
        $user = Authorization::requireResident();
        Http::requireCsrf();

        try {
            $this->service->createListing((int) $user['id'], $_POST, $_FILES['images'] ?? []);
            Http::flash(self::FLASH, 'success', 'Tu clasificado se envió a revisión. Te avisaremos cuando la administración lo publique.');
        } catch (PDOException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }

        Http::redirect('/usuario#clasificados');
    }

    public function update(): void
    {
        ModuleCatalog::requireEnabled('clasificados');
        $user = Authorization::requireResident();
        Http::requireCsrf();

        $listingId = filter_var($_POST['listing_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($listingId) || $listingId < 1) {
            Http::flash(self::FLASH, 'error', 'El clasificado seleccionado no es válido.');
            Http::redirect('/usuario#clasificados');
        }
        $removeImages = is_array($_POST['remove_images'] ?? null)
            ? array_values(array_filter(
                array_map(static fn (mixed $id): int|false => filter_var($id, FILTER_VALIDATE_INT), $_POST['remove_images']),
                static fn (int|false $id): bool => is_int($id) && $id > 0
            ))
            : [];

        try {
            if (!$this->service->updateListing($listingId, (int) $user['id'], $_POST, $_FILES['images'] ?? [], $removeImages)) {
                Http::flash(self::FLASH, 'error', 'No se encontró un clasificado tuyo para editar.');
            } else {
                Http::flash(self::FLASH, 'success', 'Los cambios quedaron guardados y el clasificado pasó a revisión: no se verá en la página pública hasta que la administración lo apruebe.');
            }
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }

        Http::redirect('/usuario#clasificado-' . $listingId);
    }

    public function changeStatus(): void
    {
        ModuleCatalog::requireEnabled('clasificados');
        $user = Authorization::requireResident();
        Http::requireCsrf();

        $listingId = filter_var($_POST['listing_id'] ?? null, FILTER_VALIDATE_INT);
        $action = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if (!is_int($listingId) || $listingId < 1 || !in_array($action, ['open', 'closed'], true)) {
            Http::flash(self::FLASH, 'error', 'El clasificado seleccionado no es válido.');
            Http::redirect('/usuario#clasificados');
        }

        $status = $this->service->setStatus($listingId, (int) $user['id'], $action);
        if ($status === null) {
            Http::flash(self::FLASH, 'error', 'No se encontró un clasificado tuyo que admita ese cambio.');
        } else {
            Http::flash(self::FLASH, 'success', match ($status) {
                'published' => 'El clasificado volvió a publicarse.',
                'pending' => 'El clasificado se envió de nuevo a revisión.',
                default => 'El clasificado se cerró y ya no aparece al público.',
            });
        }

        Http::redirect('/usuario#clasificados');
    }

    public function delete(): void
    {
        ModuleCatalog::requireEnabled('clasificados');
        $user = Authorization::requireResident();
        Http::requireCsrf();

        $listingId = filter_var($_POST['listing_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($listingId) || $listingId < 1) {
            Http::flash(self::FLASH, 'error', 'El clasificado seleccionado no es válido.');
            Http::redirect('/usuario#clasificados');
        }

        if (!$this->service->deleteListing($listingId, (int) $user['id'])) {
            Http::flash(self::FLASH, 'error', 'No se encontró un clasificado tuyo para eliminar.');
        } else {
            Http::flash(self::FLASH, 'success', 'El clasificado y sus imágenes se eliminaron.');
        }

        Http::redirect('/usuario#clasificados');
    }

    public function image(): void
    {
        ModuleCatalog::requireEnabled('clasificados');
        $imageId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($imageId) || $imageId < 1) {
            $this->notFound();
        }

        $image = $this->service->imageForDisplay($imageId);
        if ($image === null) {
            $this->notFound();
        }

        // Sin publicar: solo la ve su autor o quien revisa clasificados en el panel.
        if ($image['status'] !== 'published') {
            $user = Authorization::currentUser();
            if ($user === null || ((int) $user['id'] !== (int) $image['user_id']
                && !Authorization::hasPermission($user, 'clasificados', 1))
            ) {
                $this->notFound();
            }
        }

        if (!is_file($image['path']) || !is_readable($image['path'])) {
            error_log('Una imagen registrada de clasificado no está disponible en el almacenamiento.');
            $this->notFound();
        }

        header('Content-Type: ' . $image['mime_type']);
        header('Content-Length: ' . (int) $image['byte_size']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');
        header($image['status'] === 'published'
            ? 'Cache-Control: public, max-age=900'
            : 'Cache-Control: no-store, private');
        readfile($image['path']);
    }


    private function notFound(): never
    {
        http_response_code(404);
        echo 'Imagen no encontrada.';
        exit;
    }
}
