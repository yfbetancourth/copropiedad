<?php

declare(strict_types=1);

namespace App\Modules\Home;

use App\Core\View;
use App\Modules\Classifieds\ClassifiedsService;
use App\Modules\Configuration\PropertyGalleryService;
use App\Modules\Configuration\SettingsService;

final class HomeController
{
    public function index(): void
    {
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
            'listings' => (new ClassifiedsService())->publicListings(3),
        ]);
    }

    private function canonical(string $path): string
    {
        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');

        return $baseUrl . ($path === '/' ? '/' : $path);
    }
}
