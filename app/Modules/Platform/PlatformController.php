<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Http;
use App\Core\Authorization;
use App\Core\ModuleCatalog;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\View;
use RuntimeException;

/**
 * Consola del Superadmin en el dominio principal: crea los conjuntos residenciales (clientes),
 * habilita sus módulos y asigna su administrador.
 */
final class PlatformController
{
    private const FLASH = 'platform_flash';
    public function __construct(private readonly TenantService $tenants = new TenantService())
    {
    }

    public function index(): void
    {
        $this->requireSuperadmin();
        $tenants = $this->tenants->all();
        foreach ($tenants as &$tenant) {
            $tenant['url'] = Tenant::absoluteUrl('/', (string) $tenant['slug']);
        }
        unset($tenant);

        $this->render('index', 'Conjuntos residenciales', ['tenants' => $tenants]);
    }

    public function createForm(): void
    {
        $this->requireSuperadmin();
        $this->render('create', 'Nuevo conjunto', [
            'modules' => ModuleCatalog::MODULES,
            'baseDomain' => Tenant::baseDomain(),
            'old' => $this->takeOldInput(),
        ]);
    }

    public function store(): void
    {
        $this->requireSuperadmin();
        Http::requireCsrf();
        $name = $this->text('name');
        $slug = strtolower($this->text('slug'));
        $modules = $this->selectedModules();

        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 160) {
            $this->failWithInput('El nombre del conjunto debe tener entre 3 y 160 caracteres.', '/clientes/nuevo');
        }
        if ($slug === '') {
            $slug = TenantService::suggestSlug($name);
        }

        try {
            $tenantId = $this->tenants->create(
                $name,
                $slug,
                $modules,
                $this->text('admin_username'),
                $this->text('admin_email'),
                is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : ''
            );
        } catch (RuntimeException $exception) {
            $this->failWithInput($exception->getMessage(), '/clientes/nuevo');
        }

        Http::flash(self::FLASH, 'success', sprintf(
            'Conjunto «%s» creado. Su administrador ya puede entrar en %s',
            $name,
            Tenant::absoluteUrl('/login', $slug)
        ));
        Http::redirect('/clientes/editar?id=' . $tenantId);
    }

    public function edit(): void
    {
        $this->requireSuperadmin();
        $tenant = $this->tenantFromInput($_GET['id'] ?? null);
        $tenant['url'] = Tenant::absoluteUrl('/', (string) $tenant['slug']);
        $tenant['admin_url'] = Tenant::absoluteUrl('/admin', (string) $tenant['slug']);

        $this->render('edit', $tenant['name'], [
            'tenant' => $tenant,
            'resetSummary' => (new TenantResetService())->summary($tenant['id']),
            'modules' => ModuleCatalog::MODULES,
            'baseDomain' => Tenant::baseDomain(),
        ]);
    }

    public function update(): void
    {
        $this->requireSuperadmin();
        Http::requireCsrf();
        $tenant = $this->tenantFromInput($_POST['tenant_id'] ?? null);
        $name = $this->text('name');
        $slug = strtolower($this->text('slug'));

        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 160) {
            Http::flash(self::FLASH, 'error', 'El nombre del conjunto debe tener entre 3 y 160 caracteres.');
            Http::redirect('/clientes/editar?id=' . $tenant['id']);
        }

        try {
            $this->tenants->update($tenant['id'], $name, $slug);
            Http::flash(self::FLASH, 'success', $slug !== $tenant['slug']
                ? 'Datos guardados. El conjunto ahora se abre en ' . Tenant::absoluteUrl('/', $slug) . '; la dirección anterior deja de funcionar.'
                : 'Datos del conjunto guardados.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/clientes/editar?id=' . $tenant['id']);
    }

    public function saveModules(): void
    {
        $this->requireSuperadmin();
        Http::requireCsrf();
        $tenant = $this->tenantFromInput($_POST['tenant_id'] ?? null);
        $this->tenants->setModules($tenant['id'], $this->selectedModules());
        Http::flash(self::FLASH, 'success', 'Módulos del conjunto actualizados. Los cambios aplican de inmediato.');
        Http::redirect('/clientes/editar?id=' . $tenant['id'] . '#modulos');
    }

    public function setStatus(): void
    {
        $this->requireSuperadmin();
        Http::requireCsrf();
        $tenant = $this->tenantFromInput($_POST['tenant_id'] ?? null);
        $status = $this->text('status');
        try {
            $this->tenants->setStatus($tenant['id'], $status);
            Http::flash(self::FLASH, 'success', $status === 'suspended'
                ? 'Conjunto suspendido: su sitio público y sus cuentas quedan sin acceso hasta que lo reactives.'
                : 'Conjunto reactivado.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/clientes/editar?id=' . $tenant['id']);
    }

    public function addAdmin(): void
    {
        $this->requireSuperadmin();
        Http::requireCsrf();
        $tenant = $this->tenantFromInput($_POST['tenant_id'] ?? null);
        try {
            $this->tenants->addAdmin(
                $tenant['id'],
                $this->text('admin_username'),
                $this->text('admin_email'),
                is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : ''
            );
            Http::flash(self::FLASH, 'success', 'Administrador asignado al conjunto.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/clientes/editar?id=' . $tenant['id'] . '#administradores');
    }

    public function setAdminStatus(): void
    {
        $this->requireSuperadmin();
        Http::requireCsrf();
        $tenant = $this->tenantFromInput($_POST['tenant_id'] ?? null);
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if (!is_int($userId) || $userId < 1) {
                throw new RuntimeException('Selecciona un administrador válido.');
            }
            $active = ($_POST['active'] ?? '') === '1';
            $this->tenants->setAdminActive($tenant['id'], $userId, $active);
            Http::flash(self::FLASH, 'success', $active ? 'Administrador activado.' : 'Administrador desactivado; su sesión se cerró.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/clientes/editar?id=' . $tenant['id'] . '#administradores');
    }

    public function resetAdminPassword(): void
    {
        $this->requireSuperadmin();
        Http::requireCsrf();
        $tenant = $this->tenantFromInput($_POST['tenant_id'] ?? null);
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if (!is_int($userId) || $userId < 1) {
                throw new RuntimeException('Selecciona un administrador válido.');
            }
            $this->tenants->resetAdminPassword(
                $tenant['id'],
                $userId,
                is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : ''
            );
            Http::flash(self::FLASH, 'success', 'Contraseña del administrador actualizada; sus sesiones abiertas se cerraron.');
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage());
        }
        Http::redirect('/clientes/editar?id=' . $tenant['id'] . '#administradores');
    }

    /**
     * Reinicia el conjunto desde cero. Exige escribir el subdominio del conjunto y la contraseña del Superadmin.
     */
    public function reset(): void
    {
        $superadmin = $this->requireSuperadmin();
        Http::requireCsrf();
        $tenant = $this->tenantFromInput($_POST['tenant_id'] ?? null);
        $back = '/clientes/editar?id=' . $tenant['id'] . '#reiniciar';

        if (strtolower($this->text('confirm_slug')) !== (string) $tenant['slug']) {
            Http::flash(self::FLASH, 'error', 'Para reiniciar escribe exactamente el subdominio del conjunto: ' . $tenant['slug'] . '.');
            Http::redirect($back);
        }
        $password = is_string($_POST['superadmin_password'] ?? null) ? $_POST['superadmin_password'] : '';
        if (!$this->tenants->superadminPasswordMatches((int) $superadmin['id'], $password)) {
            Http::flash(self::FLASH, 'error', 'La contraseña del Superadmin no es correcta. No se borró nada.');
            Http::redirect($back);
        }

        $requested = is_array($_POST['categories'] ?? null)
            ? array_values(array_filter($_POST['categories'], 'is_string'))
            : [];
        try {
            $categories = TenantResetService::validateSelection($requested);
        } catch (RuntimeException $exception) {
            Http::flash(self::FLASH, 'error', $exception->getMessage() . ' No se borró nada.');
            Http::redirect($back);
        }

        try {
            $files = (new TenantResetService())->reset($tenant['id'], (string) $tenant['name'], $categories);
        } catch (\Throwable $exception) {
            error_log('Falló el reinicio del conjunto ' . $tenant['id'] . ': ' . $exception->getMessage());
            Http::flash(self::FLASH, 'error', 'No se pudo reiniciar el conjunto; no se borró nada. Revisa el registro de errores.');
            Http::redirect($back);
        }
        error_log(sprintf(
            'Superadmin %d reinició el conjunto %d (%s): %s; %d archivo(s) eliminados.',
            (int) $superadmin['id'],
            $tenant['id'],
            $tenant['slug'],
            implode(', ', $categories),
            $files
        ));
        $labels = array_map(static fn (string $key): string => TenantResetService::CATEGORIES[$key]['label'], $categories);
        $all = count($categories) === count(TenantResetService::CATEGORIES);
        Http::flash(self::FLASH, 'success', $all
            ? sprintf('El conjunto «%s» quedó en blanco: sin cuentas, caracterización, cartera ni archivos. Asigna su nuevo administrador.', $tenant['name'])
            : sprintf('Se reinició en «%s»: %s.', $tenant['name'], implode(', ', $labels))
                . (in_array('accounts', $categories, true) ? ' Asigna su nuevo administrador.' : ''));
        Http::redirect('/clientes/editar?id=' . $tenant['id'] . (in_array('accounts', $categories, true) ? '#administradores' : '#reiniciar'));
    }

    private function requireSuperadmin(): array
    {
        $user = Authorization::currentUser();
        if ($user === null) {
            Http::redirect('/login');
        }
        if (!Authorization::isSuperadmin($user)) {
            http_response_code(403);
            echo 'Solo el Superadmin puede administrar los conjuntos residenciales.';
            exit;
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function tenantFromInput(mixed $value): array
    {
        $tenantId = filter_var($value, FILTER_VALIDATE_INT);
        $tenant = is_int($tenantId) && $tenantId > 0 ? $this->tenants->find($tenantId) : null;
        if ($tenant === null) {
            Http::flash(self::FLASH, 'error', 'El conjunto seleccionado no existe.');
            Http::redirect('/clientes');
        }

        return $tenant;
    }

    /** @return list<string> */
    private function selectedModules(): array
    {
        $submitted = $_POST['modules'] ?? [];
        if (!is_array($submitted)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $submitted),
            static fn (string $module): bool => ModuleCatalog::exists($module)
        ));
    }

    private function text(string $key): string
    {
        return trim(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
    }

    /** Conserva lo escrito (sin la contraseña) para no obligar a llenar de nuevo el formulario. */
    private function failWithInput(string $message, string $path): never
    {
        Session::start();
        $_SESSION['platform_old_input'] = [
            'name' => $this->text('name'),
            'slug' => $this->text('slug'),
            'admin_username' => $this->text('admin_username'),
            'admin_email' => $this->text('admin_email'),
            'modules' => $this->selectedModules(),
        ];
        Http::flash(self::FLASH, 'error', $message);
        Http::redirect($path);
    }

    /** @return array<string, mixed>|null */
    private function takeOldInput(): ?array
    {
        Session::start();
        $old = $_SESSION['platform_old_input'] ?? null;
        unset($_SESSION['platform_old_input']);

        return is_array($old) ? $old : null;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, string $title, array $data = []): void
    {
        Session::start();
        $data['title'] = $title;
        $data['csrfToken'] = Session::csrfToken();
        $data['flash'] = Http::pullFlash(self::FLASH);
        View::renderAdmin('Platform/' . $template, $data);
    }

}
