<?php

namespace App\Models;

use App\Enums\Criticality;
use App\Enums\Verdict;
use App\Enums\WorkflowOrigin;
use App\Enums\WorkflowStatus;
use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property Criticality $criticality
 * @property WorkflowOrigin $origin
 * @property string|null $discovery_url
 * @property WorkflowStatus $status
 * @property int|null $score
 * @property bool $verified
 * @property bool $canary
 * @property Verdict|null $latest_verdict
 * @property Carbon|null $last_run_at
 * @property string|null $ignored_reason
 * @property array<int, array{intent: string, assertions: array<int, array{label: string, on: bool}>}> $steps
 * @property array<int, int> $spark_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'name', 'criticality', 'origin', 'discovery_url', 'status', 'score', 'verified', 'canary', 'latest_verdict', 'last_run_at', 'ignored_reason', 'steps', 'spark_data'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criticality' => Criticality::class,
            'origin' => WorkflowOrigin::class,
            'status' => WorkflowStatus::class,
            'latest_verdict' => Verdict::class,
            'last_run_at' => 'datetime',
            'verified' => 'boolean',
            'canary' => 'boolean',
            'steps' => 'array',
            'spark_data' => 'array',
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
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return HasMany<WorkflowVersion, $this>
     */
    public function workflowVersions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    /**
     * @return HasOne<Run, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->runs()->one()->latestOfMany();
    }

    /**
     * Persist the current $steps as the workflow's truth and append a version history entry.
     *
     * Locks the workflow row for the duration of the transaction so concurrent
     * edits (e.g. a double-click, or two open tabs) can't read the same
     * max(version) and collide on the workflow_versions unique constraint.
     */
    public function saveStepsAsNewVersion(string $changeLabel): void
    {
        DB::transaction(function () use ($changeLabel) {
            $workflow = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            $nextVersion = (int) ($workflow->workflowVersions()->max('version') ?? 0) + 1;

            $workflow->workflowVersions()->create([
                'version' => $nextVersion,
                'steps' => $this->steps,
                'change_label' => $changeLabel,
            ]);

            $workflow->steps = $this->steps;
            $workflow->save();
        });
    }
}
