<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Mail\ForgotPasswordLinkMail;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'must_change_password',
        'phone',
        'cin',
        'gender',
        'has_handicap',
        'status',
        'program_status',
        'formation_id',
        'image',
        'resume',
        'cover', // add cover here
        'about', // short bio
        'speciality',
        'socials', // social links JSON
        'promo',
        'remember_token',
        'email_verified_at',
        'created_at',
        'updated_at',
        'last_online',
        'invite_source',
        'expo_push_token', // Expo push notification token
        'apns_voip_token', // iOS PushKit VoIP token for CallKit cold-start
        // 'xp'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'activation_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_online' => 'datetime',
            'activation_token_expires_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'has_handicap' => 'boolean',
            'role' => 'array',
            'socials' => 'array',
        ];
    }

    public const ACTIVATION_TTL_HOURS = 24;

    /**
     * Issue a new activation credential. Returns the plaintext token for the
     * signed email URL only — the database stores a hash.
     */
    public function issueActivationToken(): string
    {
        $plain = bin2hex(random_bytes(32));

        $this->forceFill([
            'activation_token' => hash('sha256', $plain),
            'activation_token_expires_at' => now()->addHours(self::ACTIVATION_TTL_HOURS),
        ])->save();

        return $plain;
    }

    public function hasPendingActivation(): bool
    {
        return filled($this->getRawOriginal('activation_token') ?? $this->activation_token);
    }

    public function consumeActivationToken(): void
    {
        $this->forceFill([
            'activation_token' => null,
            'activation_token_expires_at' => null,
        ])->save();
    }

    public static function findByActivationToken(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        $hashed = hash('sha256', $plain);

        $user = static::query()
            ->where('activation_token', $hashed)
            ->orWhere('activation_token', $plain)
            ->first();

        if (! $user) {
            return null;
        }

        $expiresAt = $user->activation_token_expires_at;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            return null;
        }

        return $user;
    }

    /**
     * Normalize role values from DB/casts (JSON arrays, comma lists, quoted strings).
     *
     * @return list<string>
     */
    public function normalizedRoles(): array
    {
        $cast = $this->role;
        if (is_array($cast) && $cast !== []) {
            return self::normalizeRolesValue($cast);
        }

        $raw = $this->getRawOriginal('role');
        if ($raw === null || $raw === '') {
            return [];
        }

        return self::normalizeRolesValue($raw);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    public static function normalizeRolesValue($value): array
    {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $list = $decoded;
            } else {
                $list = array_map('trim', explode(',', $value));
            }
        } else {
            return [];
        }

        $normalized = [];
        foreach ($list as $role) {
            if ($role === null || $role === '') {
                continue;
            }
            $role = strtolower(trim((string) $role));
            $role = trim($role, "'\"");
            if ($role !== '') {
                $normalized[] = $role;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Catalog of users.role values that may be written through staff endpoints.
     * Arbitrary strings are not accepted.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = [
        'student',
        'coach',
        'admin',
        'super_admin',
        'moderateur',
        'studio_responsable',
        'responsable_studio',
        'coworker',
        'pro',
        'recruiter',
    ];

    /**
     * Roles that mark an actor as allowed to grant ADMIN_ONLY_GRANT_ROLES.
     * Do not expand this list — mayAssignPrivilegedRoles() means "actor may
     * grant elevated roles", not "these roles are blocked from assignment".
     *
     * @var list<string>
     */
    public const PRIVILEGED_ROLES = [
        'admin',
        'super_admin',
    ];

    /**
     * Roles that only admin / super_admin may grant. Non-admin staff may only
     * assign student and coworker.
     *
     * @var list<string>
     */
    public const ADMIN_ONLY_GRANT_ROLES = [
        'admin',
        'super_admin',
        'coach',
        'moderateur',
        'studio_responsable',
        'responsable_studio',
        'pro',
        'recruiter',
    ];

    /**
     * Roles non-admin staff may assign when they can edit others.
     *
     * @var list<string>
     */
    public const STAFF_GRANTABLE_ROLES = [
        'student',
        'coworker',
    ];

    public function mayAssignPrivilegedRoles(): bool
    {
        return (bool) array_intersect($this->normalizedRoles(), self::PRIVILEGED_ROLES);
    }

    /**
     * Abort 403 if $roles includes any ADMIN_ONLY_GRANT_ROLES and this actor
     * is not admin or super_admin. Call immediately before persisting users.role.
     *
     * @param  list<mixed>  $roles
     */
    public function assertMayAssignRoles(array $roles): void
    {
        $requested = self::normalizeRolesValue($roles);
        $blocked = array_values(array_intersect($requested, self::ADMIN_ONLY_GRANT_ROLES));

        if ($blocked === []) {
            return;
        }

        if (! $this->mayAssignPrivilegedRoles()) {
            abort(403, 'You are not allowed to assign this role.');
        }
    }

    /**
     * Matches mobile userHasAdminRole: the `admin` role only.
     */
    public function isEventsAdmin(): bool
    {
        return in_array('admin', $this->normalizedRoles(), true);
    }

    /**
     * Matches mobile userCanAccessScan: admin role or access_scan grant.
     * Reads the authenticated user record only — never request body flags.
     */
    public function canAccessEventsScan(): bool
    {
        if ($this->isEventsAdmin()) {
            return true;
        }

        $flag = $this->access_scan;

        return $flag === 1 || $flag === true || $flag === '1';
    }

    /** Private disk — never serve resumes via /storage symlink. */
    public const RESUME_DISK = 'documents';

    public const RESUME_DIRECTORY = 'resumes';

    /**
     * LionsGEEK program lifecycle. Independent of the `status` column, which tracks
     * life situation (Working, Studying…) and must never be derived from these.
     *
     *   active         — currently following the training
     *   certified      — finished and received a certificate
     *   not_certified  — finished but received no certificate
     *   left           — did not finish; set by hand by an admin or coach
     */
    public const PROGRAM_STATUS_ACTIVE = 'active';

    public const PROGRAM_STATUS_CERTIFIED = 'certified';

    public const PROGRAM_STATUS_NOT_CERTIFIED = 'not_certified';

    public const PROGRAM_STATUS_LEFT = 'left';

    /** @var list<string> */
    public const PROGRAM_STATUSES = [
        self::PROGRAM_STATUS_ACTIVE,
        self::PROGRAM_STATUS_CERTIFIED,
        self::PROGRAM_STATUS_NOT_CERTIFIED,
        self::PROGRAM_STATUS_LEFT,
    ];

    /** Human-readable labels, mirroring resources/js/components/helpers/userDemographics.js */
    public const PROGRAM_STATUS_LABELS = [
        self::PROGRAM_STATUS_ACTIVE => 'Active',
        self::PROGRAM_STATUS_CERTIFIED => 'Certificate',
        self::PROGRAM_STATUS_NOT_CERTIFIED => 'Not Certificate',
        self::PROGRAM_STATUS_LEFT => 'Left',
    ];

    /** Relative path on the private disk, e.g. resumes/abc.pdf */
    public function resumeStoragePath(): ?string
    {
        $name = $this->resume;
        if (! is_string($name) || $name === '') {
            return null;
        }

        $basename = basename($name);

        return $basename !== '' ? self::RESUME_DIRECTORY.'/'.$basename : null;
    }

    /**
     * Resumes are private — never expose a public /storage URL.
     * Clients must use resume_view_url (gated) instead.
     */
    public function resumePublicUrl(): ?string
    {
        return null;
    }

    public function resolveResumeAbsolutePath(): ?string
    {
        $relative = $this->resumeStoragePath();
        if ($relative && Storage::disk(self::RESUME_DISK)->exists($relative)) {
            return Storage::disk(self::RESUME_DISK)->path($relative);
        }

        // Legacy public-disk copies until resumes:migrate-private has been run.
        if ($relative && Storage::disk('public')->exists($relative)) {
            return Storage::disk('public')->path($relative);
        }

        $basename = basename((string) $this->resume);
        if ($basename === '') {
            return null;
        }

        foreach (['storage/resumes', 'storage/resume'] as $legacyDir) {
            $legacy = public_path($legacyDir.'/'.$basename);
            if (is_file($legacy)) {
                return $legacy;
            }
        }

        return null;
    }

    public function resumeViewUrl(): ?string
    {
        if (! $this->resume) {
            return null;
        }

        return route('users.resume.view', $this);
    }

    public function readStoredResumeContents(): ?string
    {
        $path = $this->resolveResumeAbsolutePath();
        if (! $path || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents !== false ? $contents : null;
    }

    public function deleteStoredResume(): void
    {
        $relative = $this->resumeStoragePath();
        if ($relative) {
            Storage::disk(self::RESUME_DISK)->delete($relative);
            Storage::disk('public')->delete($relative);
        }

        $basename = basename((string) $this->resume);
        if ($basename === '') {
            return;
        }

        foreach (['storage/resumes', 'storage/resume'] as $legacyDir) {
            $legacy = public_path($legacyDir.'/'.$basename);
            if (is_file($legacy)) {
                @unlink($legacy);
            }
        }
    }

    public function storeResumeFromUpload(UploadedFile $file): string
    {
        $this->deleteStoredResume();
        $filename = $file->hashName();
        $file->storeAs(self::RESUME_DIRECTORY, $filename, self::RESUME_DISK);

        return $filename;
    }

    public function access(): HasOne
    {
        return $this->hasOne(Access::class);
    }
    public function formation()
    {
        return $this->belongsTo(Formation::class, 'formation_id');
    }

    /**
     * All formation IDs this user belongs to (column, pivot, or promo match).
     */
    public function resolvedFormationIds(): array
    {
        $ids = [];

        if (! empty($this->formation_id)) {
            $ids[] = (int) $this->formation_id;
        }

        if (Schema::hasTable('formation_user')) {
            $pivotIds = DB::table('formation_user')
                ->where('user_id', $this->id)
                ->pluck('formation_id')
                ->map(fn($id) => (int) $id)
                ->all();
            $ids = array_merge($ids, $pivotIds);
        }

        if ($this->promo !== null && $this->promo !== '') {
            $promo = $this->promo;
            $byPromo = Formation::query()
                ->where(function ($q) use ($promo) {
                    $q->where('promo', $promo)
                        ->orWhere('promo', (string) $promo);
                })
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
            $ids = array_merge($ids, $byPromo);
        }

        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
    }

    public function primaryFormationId(): ?int
    {
        $ids = $this->resolvedFormationIds();

        return $ids[0] ?? null;
    }

    public function isEnrolledInFormation(int $formationId): bool
    {
        return in_array($formationId, $this->resolvedFormationIds(), true);
    }

    /**
     * User projects
     */
    // public function projects()
    // {
    //     return $this->hasMany(UserProject::class, 'user_id');
    // }

    public function studentProjects(): HasMany
    {
        return $this->hasMany(StudentProject::class, 'user_id');
    }

    /**
     * Projects this user approved
     */
    public function approvedProjects()
    {
        return $this->hasMany(StudentProject::class, 'approved_by');
    }


    /**
     * Get Geekos created by this user.
     */
    public function createdGeekos()
    {
        return $this->hasMany(Geeko::class, 'created_by');
    }

    /**
     * Get Geeko sessions started by this user.
     */
    public function startedSessions()
    {
        return $this->hasMany(GeekoSession::class, 'started_by');
    }

    /**
     * Get Geeko participations for this user.
     */
    public function geekoParticipations()
    {
        return $this->hasMany(GeekoParticipant::class, 'user_id');
    }
    public function scopeActive($query)
    {
        return $query->where('account_state', 0);
    }

    /**
     * User has many reservations as creator
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'user_id');
    }

    /**
     * User can be in many reservation teams (Many-to-Many)
     */
    public function reservationTeams()
    {
        return $this->belongsToMany(Reservation::class, 'reservation_teams', 'user_id', 'reservation_id')->withTimestamps();
    }
    public function badges()
    {
        return $this->belongsToMany(Badge::class)->withTimestamps();
    }
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
    public function likes()
    {
        return $this->hasMany(Like::class);
    }
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function savedPosts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_saves', 'user_id', 'post_id')->withTimestamps();
    }

    public function repostedPosts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'reposts_posts', 'user_id', 'post_id')
            ->withPivot(['description'])
            ->withTimestamps();
    }

    /**
     * Get conversations where this user is user_one
     */
    public function conversationsAsUserOne()
    {
        return $this->hasMany(Conversation::class, 'user_one_id');
    }

    /**
     * Get conversations where this user is user_two
     */
    public function conversationsAsUserTwo()
    {
        return $this->hasMany(Conversation::class, 'user_two_id');
    }

    /**
     * Get all conversations for this user
     */
    public function conversations(): \Illuminate\Database\Eloquent\Builder
    {
        // Use explicit operator/value signature to satisfy analyzers and avoid ambiguity.
        return Conversation::query()
            ->where('user_one_id', '=', $this->id)
            ->orWhere('user_two_id', '=', $this->id);
    }

    /**
     * Get all messages sent by this user
     */
    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Send the password reset notification using our custom mailable and layout.
     */
    public function sendPasswordResetNotification($token)
    {
        $resetUrl = url(route('password.reset', ['token' => $token, 'email' => $this->email], false));

        Mail::to($this->email)->send(new ForgotPasswordLinkMail($this, $resetUrl));
    }
    //! Followers relationship
    public function followers()
    {
        return $this->belongsToMany(
            User::class,
            'followers',
            'followed_id',
            'follower_id'
        )->withTimestamps();
    }

    // People I follow
    public function following()
    {
        return $this->belongsToMany(
            User::class,
            'followers',
            'follower_id',
            'followed_id'
        )->withTimestamps();
    }

    public function blockedUsers()
    {
        return $this->belongsToMany(
            User::class,
            'user_blocks',
            'blocker_id',
            'blocked_id'
        )->withTimestamps();
    }

    /**
     * @return array<int>
     */
    public function blockedUserIds(): array
    {
        if (! Schema::hasTable('user_blocks')) {
            return [];
        }

        return $this->blockedUsers()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Authors hidden from this user's social surfaces (I blocked them, or they blocked me).
     *
     * @return array<int>
     */
    public function excludedAuthorIds(): array
    {
        if (! Schema::hasTable('user_blocks')) {
            return [];
        }

        $blockedByMe = $this->blockedUserIds();
        $blockedMe = UserBlock::query()
            ->where('blocked_id', $this->id)
            ->pluck('blocker_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($blockedByMe, $blockedMe)));
    }

    public function experiences()
    {
        return $this->belongsToMany(Experience::class)->withTimestamps();
    }
    public function educations()
    {
        return $this->belongsToMany(Education::class)->withTimestamps();
    }

    public function socialLinks()
    {
        return $this->hasMany(UserSocialLink::class);
    }

    public function faceEnrollment(): HasOne
    {
        return $this->hasOne(FaceEnrollment::class);
    }

    /** Organisation this user logs in as (the company account). */
    public function organisationAccount(): HasOne
    {
        return $this->hasOne(Organization::class, 'account_user_id');
    }

    /** Organisations this user belongs to as an invited employer. */
    public function employerOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot(['member_role', 'invited_by'])
            ->withTimestamps();
    }

    public function isRecruiter(): bool
    {
        $roles = is_array($this->role) ? $this->role : [$this->role];

        return in_array('recruiter', $roles, true);
    }

    public function isOrganisationAccount(): bool
    {
        if ($this->relationLoaded('organisationAccount')) {
            return $this->organisationAccount !== null;
        }

        return Organization::query()->where('account_user_id', '=', $this->id)->exists();
    }

    public function organizationForRecruiting(): ?Organization
    {
        if ($this->relationLoaded('organisationAccount') && $this->organisationAccount) {
            return $this->organisationAccount;
        }

        $asAccount = Organization::query()->where('account_user_id', '=', $this->id)->first();
        if ($asAccount) {
            return $asAccount;
        }

        if ($this->relationLoaded('employerOrganizations')) {
            return $this->employerOrganizations->first();
        }

        return $this->employerOrganizations()->first();
    }

    public function organizationIdForRecruiting(): ?int
    {
        return $this->organizationForRecruiting()?->id;
    }

    public function canCreateJobsForOrganisation(): bool
    {
        if (! $this->isRecruiter()) {
            return false;
        }

        if ($this->isOrganisationAccount()) {
            return true;
        }

        $organizationId = $this->organizationIdForRecruiting();
        if (! $organizationId) {
            return false;
        }

        return $this->employerOrganizations()
            ->where('organizations.id', '=', $organizationId, 'and')
            ->whereIn('organization_user.member_role', ['employer', 'admin'], 'and', false)
            ->exists();
    }

    public function canManageOrganisationMembers(): bool
    {
        return $this->isOrganisationAccount();
    }

    /**
     * Shared Inertia context for recruiter UI (org account vs team member).
     *
     * @return array{
     *   organization_id: int,
     *   organization_name: string,
     *   membership_type: 'organisation_account'|'employer',
     *   membership_label: string,
     *   member_role: string,
     *   can_manage_team: bool,
     *   can_create_jobs: bool
     * }|null
     */
    public function recruitingContext(): ?array
    {
        if (! $this->isRecruiter()) {
            return null;
        }

        $organization = $this->organizationForRecruiting();
        if (! $organization) {
            return null;
        }

        $isOrgAccount = $this->isOrganisationAccount();
        $memberRole = 'owner';

        if (! $isOrgAccount) {
            if ($this->relationLoaded('employerOrganizations')) {
                $pivotOrg = $this->employerOrganizations->firstWhere('id', $organization->id);
            } else {
                $pivotOrg = $this->employerOrganizations()
                    ->where('organizations.id', $organization->id)
                    ->first();
            }
            $memberRole = $pivotOrg?->pivot?->member_role ?? 'employer';
        }

        $membershipType = $isOrgAccount ? 'organisation_account' : 'employer';
        $membershipLabel = $isOrgAccount ? __('Organisation owner') : __('Team member');

        return [
            'organization_id' => (int) $organization->id,
            'organization_name' => $organization->displayName(),
            'membership_type' => $membershipType,
            'membership_label' => $membershipLabel,
            'member_role' => $memberRole,
            'can_manage_team' => $this->canManageOrganisationMembers(),
            'can_create_jobs' => $this->canCreateJobsForOrganisation(),
        ];
    }
    public function announcements()
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }
}
