<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Core\Database;
use App\Core\Tenant;
use App\Modules\Auth\AccessLinkService;
use App\Modules\Configuration\CompositionService;
use App\Modules\Configuration\TeamService;
use App\Modules\PropertyAssignment\RelationshipHistory;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Residentes del conjunto (propietarios, inquilinos, residentes): su cuenta del portal y su ficha. La cuenta usa
 * las reglas comunes de TeamService (tipo «resident»); los inmuebles se vinculan en Inmuebles / Asignación.
 */
final class ResidentService
{
    public const DOCUMENT_TYPES = [
        'CC' => 'Cédula de ciudadanía',
        'CE' => 'Cédula de extranjería',
        'TI' => 'Tarjeta de identidad',
        'PP' => 'Pasaporte',
        'PPT' => 'Permiso de protección temporal',
        'NIT' => 'NIT',
        'OTRO' => 'Otro',
    ];

    private readonly TeamService $accounts;

    public function __construct()
    {
        $this->accounts = new TeamService('resident');
    }

    /**
     * Residentes con su ficha e inmuebles. $search busca en nombre, usuario, correo, documento, teléfono e inmueble.
     *
     * @param 'active'|'inactive'|'all' $status
     */
    public function list(string $search = '', string $status = 'active'): array
    {
        $statusSql = match ($status) {
            'inactive' => ' AND users.is_active = 0',
            'all' => '',
            default => ' AND users.is_active = 1',
        };
        $query = Database::connection()->prepare(
            "SELECT users.id, users.username, users.email, users.is_active, users.created_at,
                    profiles.full_name, profiles.document_type, profiles.document_number, profiles.phone
             FROM admin_users AS users
             JOIN admin_roles AS roles ON roles.id = users.role_id AND roles.kind = 'resident'
             LEFT JOIN resident_profiles AS profiles ON profiles.user_id = users.id
             WHERE users.tenant_id = ?{$statusSql}
             ORDER BY COALESCE(profiles.full_name, users.username)"
        );
        $query->execute([Tenant::id()]);
        $residents = $query->fetchAll();
        $units = $this->unitsByUser();

        $needle = mb_strtolower(trim($search), 'UTF-8');
        $result = [];
        foreach ($residents as $resident) {
            $resident['units'] = $units[(int) $resident['id']] ?? [];
            $haystack = mb_strtolower(implode(' ', [
                $resident['full_name'], $resident['username'], $resident['email'], $resident['document_number'], $resident['phone'],
                ...array_column($resident['units'], 'name'),
            ]), 'UTF-8');
            if ($needle === '' || str_contains($haystack, $needle)) {
                $result[] = $resident;
            }
        }

        return $result;
    }

    public function find(int $userId): ?array
    {
        $account = $this->accounts->find($userId);
        if ($account === null) {
            return null;
        }
        $query = Database::connection()->prepare('SELECT * FROM resident_profiles WHERE user_id = ? AND tenant_id = ?');
        $query->execute([$userId, Tenant::id()]);
        $profile = $query->fetch();

        return $account + (is_array($profile) ? $profile : ['full_name' => '', 'document_type' => null, 'document_number' => null, 'phone' => null, 'notes' => ''])
            + ['units' => $this->unitsByUser()[$userId] ?? []];
    }

    /**
     * Crea ($userId = 0) o actualiza un residente. Sin contraseña al crear, la cuenta nace con una clave aleatoria
     * y se envía la invitación para que el residente cree la suya.
     *
     * @return array{id: int, invited: bool, invitation_error: ?string}
     */
    public function save(int $userId, array $input): array
    {
        $data = $this->validate($input, $userId === 0);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($userId === 0) {
                $password = $data['password'] !== '' ? $data['password'] : bin2hex(random_bytes(24));
                $userId = $this->accounts->create($data['username'], $data['email'], $password, $this->residentRoleId());
            } else {
                $current = $this->accounts->find($userId);
                if ($current === null) {
                    throw new RuntimeException('El residente no existe.');
                }
                $this->accounts->update($userId, $data['username'], $data['email'], (int) $current['role_id'], $data['password'] !== '' ? $data['password'] : null);
            }
            // INSERT o UPDATE explícitos: un «ON DUPLICATE KEY» se activaría también con el documento repetido de
            // otro residente y sobrescribiría su ficha en lugar de rechazar el dato.
            $profileExists = $pdo->prepare('SELECT 1 FROM resident_profiles WHERE user_id = ?');
            $profileExists->execute([$userId]);
            $profile = [$data['full_name'], $data['document_type'], $data['document_number'], $data['phone'], $data['notes']];
            if ($profileExists->fetchColumn() === false) {
                $pdo->prepare(
                    'INSERT INTO resident_profiles (full_name, document_type, document_number, phone, notes, user_id, tenant_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([...$profile, $userId, Tenant::id()]);
            } else {
                $pdo->prepare(
                    'UPDATE resident_profiles SET full_name = ?, document_type = ?, document_number = ?, phone = ?, notes = ?
                     WHERE user_id = ? AND tenant_id = ?'
                )->execute([...$profile, $userId, Tenant::id()]);
            }
            $pdo->commit();
        } catch (PDOException $exception) {
            $this->rollBack();
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException(str_contains($exception->getMessage(), 'document')
                    ? 'Ya hay un residente con ese documento.'
                    : 'Ese usuario o correo ya está registrado en el conjunto.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        $invitationError = null;
        $invited = false;
        if ($data['send_invitation']) {
            try {
                $this->invite($userId);
                $invited = true;
            } catch (RuntimeException $exception) {
                $invitationError = $exception->getMessage();
            }
        }

        return ['id' => $userId, 'invited' => $invited, 'invitation_error' => $invitationError];
    }

    /** Envía (o reenvía) el correo para que el residente cree su contraseña. */
    public function invite(int $userId): void
    {
        $resident = $this->accounts->find($userId);
        if ($resident === null || !(bool) $resident['is_active']) {
            throw new RuntimeException('Solo se puede invitar a un residente activo.');
        }
        (new AccessLinkService())->send($resident, true);
    }

    public function setActive(int $userId, bool $active, int $actorId): void
    {
        $this->accounts->setActive($userId, $active, $actorId);
    }

    /**
     * Elimina la cuenta y su ficha. Sus vínculos con inmuebles, reservas y clasificados se borran con ella; el
     * historial de inmuebles conserva su nombre. Si tiene movimientos de cartera, no se puede: se desactiva.
     */
    public function delete(int $userId): void
    {
        if ($this->accounts->find($userId) === null) {
            throw new RuntimeException('El residente no existe.');
        }
        try {
            Database::connection()->prepare('DELETE FROM admin_users WHERE id = ? AND tenant_id = ?')->execute([$userId, Tenant::id()]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('El residente tiene notas de cobro, pagos o mensajes registrados: desactívalo para conservar ese historial.');
            }
            throw $exception;
        }
    }

    /** @return array<int, list<array{name: string, relation: string}>> inmuebles vigentes de cada cuenta */
    private function unitsByUser(): array
    {
        $names = [];
        $composition = (new CompositionService())->current();
        foreach ((is_array($composition) && is_array($composition['units'] ?? null)) ? $composition['units'] : [] as $unit) {
            if (is_array($unit) && is_string($unit['id'] ?? null)) {
                $names[$unit['id']] = (string) ($unit['name'] ?? '');
            }
        }
        $query = Database::connection()->prepare(
            'SELECT user_id, unit_id, relation_type, resides FROM property_unit_assignments WHERE tenant_id = ?'
        );
        $query->execute([Tenant::id()]);
        $units = [];
        foreach ($query->fetchAll() as $row) {
            if (isset($names[(string) $row['unit_id']])) {
                $units[(int) $row['user_id']][] = [
                    'name' => $names[(string) $row['unit_id']],
                    'relation' => RelationshipHistory::label((string) $row['relation_type'], (bool) $row['resides']),
                ];
            }
        }

        return $units;
    }

    private function residentRoleId(): int
    {
        $query = Database::connection()->prepare("SELECT id FROM admin_roles WHERE tenant_id = ? AND kind = 'resident' ORDER BY id LIMIT 1");
        $query->execute([Tenant::id()]);
        $roleId = $query->fetchColumn();
        if ($roleId === false) {
            throw new RuntimeException('El conjunto no tiene el rol «Residente». Aplica la migración 029.');
        }

        return (int) $roleId;
    }

    /** @return array<string, mixed> datos normalizados */
    private function validate(array $input, bool $creating): array
    {
        $text = static fn (string $key): string => trim(is_string($input[$key] ?? null) ? $input[$key] : '');
        $data = [
            'full_name' => $text('full_name'),
            'email' => strtolower($text('email')),
            'username' => $text('username'),
            'document_type' => $text('document_type'),
            'document_number' => strtoupper(str_replace(['.', ' '], '', $text('document_number'))),
            'phone' => $text('phone'),
            'notes' => $text('notes'),
            'password' => is_string($input['password'] ?? null) ? $input['password'] : '',
            'send_invitation' => ($input['send_invitation'] ?? '') === '1',
        ];
        if (mb_strlen($data['full_name'], 'UTF-8') < 3 || mb_strlen($data['full_name'], 'UTF-8') > 160) {
            throw new RuntimeException('Escribe el nombre completo del residente (entre 3 y 160 caracteres).');
        }
        if (strlen($data['email']) > 254 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ingresa un correo válido: con él el residente recibe sus cobros y su acceso.');
        }
        if ($data['username'] === '') {
            $data['username'] = $this->suggestUsername($data['email']);
        }
        if (strlen($data['username']) < 3 || strlen($data['username']) > 64 || !preg_match('/^[\p{L}\p{N}_.@-]+$/uD', $data['username'])) {
            throw new RuntimeException('El usuario debe tener entre 3 y 64 caracteres, sin espacios.');
        }
        if ($data['document_type'] === '' xor $data['document_number'] === '') {
            throw new RuntimeException('Indica el tipo y el número del documento, o deja ambos vacíos.');
        }
        if ($data['document_type'] !== '' && (!isset(self::DOCUMENT_TYPES[$data['document_type']]) || !preg_match('/^[A-Z0-9-]{3,30}$/D', $data['document_number']))) {
            throw new RuntimeException('Revisa el tipo y el número del documento (letras, números o guion).');
        }
        if ($data['phone'] !== '' && !preg_match('/^[0-9+().\-\s]{7,30}$/D', $data['phone'])) {
            throw new RuntimeException('El teléfono no es válido.');
        }
        if (mb_strlen($data['notes'], 'UTF-8') > 500) {
            throw new RuntimeException('Las notas internas admiten hasta 500 caracteres.');
        }
        if ($data['password'] !== '' && strlen($data['password']) < 12) {
            throw new RuntimeException('La contraseña debe tener al menos 12 caracteres, o déjala vacía para enviar la invitación.');
        }
        if ($creating && $data['password'] === '') {
            $data['send_invitation'] = true; // Sin contraseña conocida, la invitación es la única forma de entrar.
        }
        foreach (['document_type', 'document_number', 'phone'] as $optional) {
            $data[$optional] = $data[$optional] === '' ? null : $data[$optional];
        }

        return $data;
    }

    /** Usuario a partir del correo (parte antes de @), sin repetir uno existente en el conjunto. */
    private function suggestUsername(string $email): string
    {
        $base = substr((string) preg_replace('/[^a-z0-9._-]/', '', strtolower(strstr($email, '@', true) ?: $email)), 0, 56);
        $base = strlen($base) >= 3 ? $base : 'residente';
        $query = Database::connection()->prepare('SELECT COUNT(*) FROM admin_users WHERE tenant_id = ? AND username = ?');
        for ($suffix = 0; $suffix < 100; $suffix++) {
            $candidate = $suffix === 0 ? $base : $base . $suffix;
            $query->execute([Tenant::id(), $candidate]);
            if ((int) $query->fetchColumn() === 0) {
                return $candidate;
            }
        }

        return $base . bin2hex(random_bytes(3));
    }

    private function rollBack(): void
    {
        if (Database::connection()->inTransaction()) {
            Database::connection()->rollBack();
        }
    }
}
