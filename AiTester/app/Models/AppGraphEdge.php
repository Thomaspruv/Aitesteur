<?php

namespace App\Models;

use Database\Factories\AppGraphEdgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $from_node_id
 * @property int $to_node_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'from_node_id', 'to_node_id'])]
class AppGraphEdge extends Model
{
    /** @use HasFactory<AppGraphEdgeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<AppGraphNode, $this>
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(AppGraphNode::class, 'from_node_id');
    }

    /**
     * @return BelongsTo<AppGraphNode, $this>
     */
    public function toNode(): BelongsTo
    {
        return $this->belongsTo(AppGraphNode::class, 'to_node_id');
    }
}
