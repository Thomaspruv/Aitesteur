<?php

namespace App\Models;

use App\Contracts\AiClient;
use App\Enums\AiProvider;
use App\Enums\WorkflowStatus;
use App\Services\Ai\DeepSeekClient;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $business_description
 * @property float|null $avg_ai_cost_per_run
 * @property float|null $uptime_pct
 * @property AiProvider|null $ai_provider
 * @property string|null $ai_model
 * @property string|null $ai_api_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'name', 'slug', 'business_description', 'avg_ai_cost_per_run', 'uptime_pct', 'ai_provider', 'ai_model', 'ai_api_key'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_provider' => AiProvider::class,
            'ai_api_key' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * @return HasMany<Workflow, $this>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /**
     * @return HasMany<AppGraphNode, $this>
     */
    public function appGraphNodes(): HasMany
    {
        return $this->hasMany(AppGraphNode::class);
    }

    /**
     * @return HasMany<AppGraphEdge, $this>
     */
    public function appGraphEdges(): HasMany
    {
        return $this->hasMany(AppGraphEdge::class);
    }

    /**
     * @return HasMany<DiscoveryRun, $this>
     */
    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class);
    }

    public function primaryEnvironment(): ?Environment
    {
        return $this->environments()->first();
    }

    public function aiClient(): ?AiClient
    {
        if (! $this->ai_provider || ! $this->ai_api_key) {
            return null;
        }

        return match ($this->ai_provider) {
            AiProvider::DeepSeek => new DeepSeekClient($this->ai_api_key, $this->ai_model ?? 'deepseek-chat'),
        };
    }

    public function latestDiscoveryRun(): ?DiscoveryRun
    {
        return $this->discoveryRuns()->latest('created_at')->first();
    }

    public function latestRun(): ?Run
    {
        return Run::query()
            ->whereHas('workflow', fn ($query) => $query->where('project_id', $this->id))
            ->latest('created_at')
            ->first();
    }

    public function latestActiveWorkflow(): ?Workflow
    {
        return $this->workflows()
            ->where('status', WorkflowStatus::Active)
            ->latest('updated_at')
            ->first();
    }
}
