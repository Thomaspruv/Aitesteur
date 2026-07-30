<?php

namespace App\Models;

use Database\Factories\AppGraphNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $key
 * @property string $label
 * @property string|null $url
 * @property string|null $screenshot_path
 * @property string $kind
 * @property int $x
 * @property int $y
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'key', 'label', 'url', 'screenshot_path', 'kind', 'x', 'y'])]
class AppGraphNode extends Model
{
    /** @use HasFactory<AppGraphNodeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
