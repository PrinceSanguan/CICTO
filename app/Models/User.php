<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Role $role
 * @property int|null $office_id
 * @property string|null $position
 * @property string|null $phone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * RoutesNotifications rather than Notifiable.
     *
     * Notifiable also pulls in HasDatabaseNotifications, whose morphMany points
     * at a `notifications` table with notifiable_type/notifiable_id columns.
     * Ours is a hand-written table with real foreign keys, so that relation
     * would fatal on first access. RoutesNotifications keeps notify() working
     * for Fortify's password reset.
     */
    use RoutesNotifications;

    /**
     * Mirror the database defaults onto new instances.
     *
     * Without this, a freshly created user has `role` and `is_active` in the
     * database (via column defaults) but NOT on the in-memory model -- so
     * $user->role is null for the rest of the request. That breaks the
     * post-registration redirect and every capability check, and it only shows
     * up on the signup path, which is the worst place to find it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'user',
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * `role` and `office_id` are deliberately absent from #[Fillable] above.
     * That attribute is a whitelist, so they are excluded by construction --
     * which is exactly the property being protected. Privilege is assigned only
     * through App\Actions\Users\AssignUserRole, behind a Gate check.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return HasMany<Document, $this> */
    public function submittedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'created_by_id');
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest('id');
    }

    /** @return HasMany<DocumentMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(DocumentMovement::class, 'actor_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function atLeast(Role $role): bool
    {
        return $this->role->atLeast($role);
    }

    /**
     * Whether this user acts on behalf of the given office.
     *
     * A Super Admin acts for every office; everyone else only for their own.
     * This answers DocumentPolicy's "may you decide on this leg" question.
     */
    public function actsForOffice(?int $officeId): bool
    {
        if ($officeId === null) {
            return false;
        }

        return $this->isSuperAdmin() || $this->office_id === $officeId;
    }

    /**
     * A user with no office cannot receive routed work. Self-registered
     * accounts sit here until an Admin assigns them one.
     */
    public function isQuarantined(): bool
    {
        return $this->office_id === null && ! $this->isSuperAdmin();
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('users.is_active', true);
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function inOffice(Builder $query, int $officeId): void
    {
        $query->where('users.office_id', $officeId);
    }
}
