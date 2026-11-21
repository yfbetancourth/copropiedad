<?php

declare(strict_types=1);

namespace App\Modules\Home;

use App\Core\Tenant;
use App\Core\ModuleCatalog;
use App\Core\View;
use App\Modules\Classifieds\ClassifiedsService;
use App\Modules\Configuration\PropertyGalleryService;
use App\Modules\Configuration\SettingsService;

final class HomeController
{
    public function index(): void
    {
        if (!Tenant::isActive()) {
            http_response_code(503);
            echo 'Este conjunto residencial no está disponible temporalmente.';
            return;
        }

        $settings = (new SettingsService())->current();
        $name = trim((string) ($settings['property_name'] ?? '')) ?: 'Copropiedad';
        $address = trim((string) ($settings['address'] ?? ''));

        View::renderPublic('Home/index', [
            'title' => $address !== '' ? 'Comunidad en ' . $address : 'Vivir en comunidad',
            'metaDescription' => $address !== ''
                ? $name . ' · Vida en comunidad en ' . $address . '. Conoce nuestros espacios, ubicación y clasificados de inmuebles.'
                : $name . ' · Un espacio para vivir y construir comunidad. Conoce nuestros espacios y explora los clasificados de inmuebles.',
            'canonicalUrl' => $this->canonical('/'),
            'settings' => $settings,
            'galleryImages' => (new PropertyGalleryService())->images(),
            'listings' => ModuleCatalog::enabled('clasificados') ? (new ClassifiedsService())->publicListings(3) : [],
            'classifiedsEnabled' => ModuleCatalog::enabled('clasificados'),
        ]);
    }

    private function canonical(string $path): string
    {
        $baseUrl = rtrim(Tenant::absoluteUrl('/'), '/');

        return $baseUrl . ($path === '/' ? '/' : $path);
    }
}
