<?php

namespace Database\Factories;

use App\Models\AppGraphEdge;
use App\Models\AppGraphNode;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppGraphEdge>
 */
class AppGraphEdgeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'from_node_id' => fn (array $attributes) => AppGraphNode::factory()->create(['project_id' => $attributes['project_id']])->id,
            'to_node_id' => fn (array $attributes) => AppGraphNode::factory()->create(['project_id' => $attributes['project_id']])->id,
        ];
    }
}
