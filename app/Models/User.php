<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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
 * @property-read Office|null $office Nullable: office_id is, and a
 *   user can exist before being assigned to one. Declared explicitly because
 *   larastan otherwise infers a non-null Office from the relation's generics.
 * @property string|null $position
 * @property string|null $phone
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property array<string, mixed>|null $preferences Cast from a nullable json column.
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
/*
 * MustVerifyEmail is declared, not merely inherited.
 *
 * The `MustVerifyEmail` *trait* on Illuminate\Foundation\Auth\User already
 * gave this model `hasVerifiedEmail()` and `sendEmailVerificationNotification()`,
 * so the methods were always there and the class looked verified-capable. But
 * both of the framework pieces that matter test for the CONTRACT, not the
 * methods: Illuminate\Auth\Listeners\SendEmailVerificationNotification and the
 * `verified` middleware each do `instanceof MustVerifyEmail`. Without this
 * `implements`, registration sent no verification mail and the `verified`
 * middleware on all six protected route groups (routes/web.php:72,
 * settings.php:15, documents.php:23 and :101, panels.php:25 and :38) was a
 * silent no-op -- a self-registered account walked straight past a gate that
 * reads, in the routes file, as if it were closed.
 *
 * That was survivable only while MAIL_MAILER=log made verification impossible
 * anyway. It is not survivable now that mail works, which is why it is declared
 * here. No existing account is affected: all five were already verified when
 * this changed.
 */
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
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
            'preferences' => 'array',
        ];
    }

    /**
     * One display preference, or the fallback when it has never been set.
     *
     * Reads through the cast rather than exposing the column, so a caller can
     * never accidentally treat a missing preferences row as an empty array of
     * settings and write the whole thing away.
     */
    public function preference(string $key, mixed $default = null): mixed
    {
        // ?? on the whole property, not just the offset: `preferences` is
        // null for every account created before this column existed, which is
        // all of them at the point this shipped.
        return ($this->preferences ?? [])[$key] ?? $default;
    }

    /**
     * Merge preferences without clobbering keys this caller does not know
     * about.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergePreferences(array $values): void
    {
        $this->preferences = [...$this->preferences ?? [], ...$values];
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

    /**
     * A lowercased LIKE term for the user search, with the metacharacters
     * neutralised, for use with `escape '!'`.
     *
     * THE ESCAPE CHARACTER IS '!' AND NOT A BACKSLASH, ON PURPOSE.
     * `"... escape '\'"` in a PHP double-quoted string is not an escape
     * sequence, so the two characters survive and the emitted SQL literal is
     * `'\'`. PostgreSQL and SQLite read that as a one-character string holding
     * a backslash and accept it; MySQL treats a backslash inside a string
     * literal as an escape by default, so the literal never closes and the
     * statement dies with a 1064 syntax error. That is a 500 on the user-search
     * box for every MySQL/MariaDB deployment. `!` needs no escaping in any of
     * the three, so the same SQL is valid everywhere.
     *
     * Both controllers that search users share this so the escaping and the
     * ESCAPE clause can never drift apart -- they already had two copies of the
     * broken version.
     */
    public static function likeTerm(string $search): string
    {
        // The escape character itself must be escaped first, or escaping the
        // wildcards would then re-escape the marks this line just added.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));

        return '%'.$escaped.'%';
    }
}
