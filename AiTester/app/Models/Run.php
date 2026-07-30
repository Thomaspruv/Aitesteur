<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\Verdict;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workflow_id
 * @property RunStatus $status
 * @property Verdict|null $verdict
 * @property int|null $escalation_level
 * @property string $triggered_by
 * @property bool $confirmed
 * @property bool|null $authenticated
 * @property string|null $expected_label
 * @property string|null $observed_label
 * @property string|null $diagnostic_summary
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workflow_id', 'status', 'verdict', 'escalation_level', 'triggered_by', 'confirmed', 'authenticated', 'expected_label', 'observed_label', 'diagnostic_summary', 'error', 'started_at', 'finished_at'])]
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'verdict' => Verdict::class,
            'confirmed' => 'boolean',
            'authenticated' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return HasMany<RunStep, $this>
     */
    public function runSteps(): HasMany
    {
        return $this->hasMany(RunStep::class)->orderBy('position');
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [RunStatus::Queued, RunStatus::Running], true);
    }
}
