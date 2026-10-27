<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property string $login
 * @property string $display_name
 * @property string|null $last_name
 * @property string|null $first_name
 * @property string|null $middle_name
 * @property string|null $email
 * @property string|null $phone
 * @property UserRole $role
 * @property string $password
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $registered_at
 * @property \Illuminate\Support\Carbon|null $access_revoked_at
 * @property array<int, string> $access_rights
 *
 * @property-read Organization $organization
 */
final class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $guarded = ['id'];

    /**
     * @var array<int, string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password'          => 'hashed',
            'active'            => 'boolean',
            'registered_at'     => 'immutable_datetime',
            'access_revoked_at' => 'immutable_datetime',
            'access_rights'     => 'array',
            'role'              => UserRole::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->uuid === null || $user->uuid === '') {
                $user->uuid = (string) Str::uuid();
            }

            $user->registered_at ??= now();

            if ($user->access_rights === null || $user->access_rights === []) {
                $user->access_rights = match ($user->role) {
                    UserRole::Manager => [
                        'requests.view',
                        'requests.manage',
                        'catalogs.manage',
                        'users.manage',
                    ],
                    UserRole::Station => ['requests.view', 'requests.station'],
                    default => [
                        'requests.view',
                        'requests.create',
                        'catalogs.vehicles',
                        'catalogs.people',
                    ],
                };
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasValidRole(): bool
    {
        return $this->organization !== null
            && $this->organization->kind === $this->role->organizationKind();
    }

    public function hasPlatformAccess(): bool
    {
        return $this->active
            && $this->access_revoked_at === null
            && $this->hasValidRole();
    }

    public function fullName(): string
    {
        $name = collect([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ])->filter(static fn (?string $part): bool => filled($part))->implode(' ');

        return $name !== '' ? $name : $this->display_name;
    }

    public function tenantId(): int
    {
        return (int) $this->organization->tenant_id;
    }

    public function roleLabel(): string
    {
        return $this->role->label();
    }

    /**
     * @return array<string, mixed>
     */
    public function apiData(): array
    {
        $this->loadMissing('organization');

        return [
            'id'                   => $this->uuid,
            'systemId'             => $this->id,
            'organizationId'       => $this->organization->uuid,
            'organizationSystemId' => $this->organization_id,
            'login'                => $this->login,
            'displayName'          => $this->fullName(),
            'fullName'             => $this->fullName(),
            'email'                => $this->email,
            'phone'                => $this->phone,
            'role'                 => $this->role->value,
            'label'                => $this->roleLabel(),
            'organization'         => $this->organization->name,
            'accessRights'         => $this->access_rights ?? [],
            'permissions'          => [
                'canCreateRequest' => $this->role === UserRole::Customer
                    && $this->hasPlatformAccess(),
            ],
        ];
    }
}
