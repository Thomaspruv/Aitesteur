<?php

namespace App\Models;

use App\Support\UrlHost;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $url
 * @property string|null $username
 * @property string|null $password
 * @property Carbon|null $target_authorized_at
 * @property string|null $target_authorized_host
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'name', 'url', 'username', 'password', 'target_authorized_at', 'target_authorized_host'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'target_authorized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Whether the user has certified being authorized to test this
     * environment's CURRENT target — not just "authorized at some point in
     * the past". Bound to target_authorized_host (the host that was actually
     * confirmed) rather than trusting target_authorized_at alone: a bare
     * non-null timestamp says nothing about which host it was stamped for,
     * so if url changed underneath it (a bug elsewhere, a future code path
     * that forgets to reset it) this would otherwise silently keep
     * authorizing whatever url now says. Discovery/execution jobs refuse to
     * run without this — not just a UI nicety, since the crawler now clicks
     * things and attempts logins on whatever URL is configured here.
     */
    public function hasAuthorizedTarget(): bool
    {
        return $this->target_authorized_at !== null
            && $this->target_authorized_host !== null
            && $this->target_authorized_host === UrlHost::of($this->url);
    }
}
