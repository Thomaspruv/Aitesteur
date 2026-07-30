<?php

namespace App\Models;

use App\Enums\DiscoveryRunStatus;
use Database\Factories\DiscoveryRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $environment_id
 * @property DiscoveryRunStatus $status
 * @property int|null $pages_visited
 * @property int|null $candidates_created
 * @property bool|null $authenticated
 * @property bool|null $login_form_detected
 * @property bool|null $register_form_detected
 * @property int|null $forms_found
 * @property int|null $popups_found
 * @property int|null $modals_found
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'environment_id', 'status', 'pages_visited', 'candidates_created', 'authenticated', 'login_form_detected', 'register_form_detected', 'forms_found', 'popups_found', 'modals_found', 'error', 'started_at', 'finished_at'])]
class DiscoveryRun extends Model
{
    /** @use HasFactory<DiscoveryRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DiscoveryRunStatus::class,
            'authenticated' => 'boolean',
            'login_form_detected' => 'boolean',
            'register_form_detected' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [DiscoveryRunStatus::Queued, DiscoveryRunStatus::Running], true);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
