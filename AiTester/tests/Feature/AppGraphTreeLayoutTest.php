<?php

use App\Models\AppGraphEdge;
use App\Models\AppGraphNode;
use App\Models\Project;
use App\Support\AppGraphTreeLayout;

test('a linear chain is laid out with increasing depth and constant x', function () {
    $project = Project::factory()->create();

    $a = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'current']);
    $b = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'neutral']);
    $c = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'neutral']);

    AppGraphEdge::factory()->create(['project_id' => $project->id, 'from_node_id' => $a->id, 'to_node_id' => $b->id]);
    AppGraphEdge::factory()->create(['project_id' => $project->id, 'from_node_id' => $b->id, 'to_node_id' => $c->id]);

    $layout = AppGraphTreeLayout::build(
        AppGraphNode::whereIn('id', [$a->id, $b->id, $c->id])->get(),
        AppGraphEdge::whereIn('from_node_id', [$a->id, $b->id])->get(),
    );

    expect($layout['nodes'][$a->id]['y'])->toBeLessThan($layout['nodes'][$b->id]['y'])
        ->and($layout['nodes'][$b->id]['y'])->toBeLessThan($layout['nodes'][$c->id]['y'])
        ->and($layout['nodes'][$a->id]['x'])->toBe($layout['nodes'][$b->id]['x'])
        ->and($layout['nodes'][$b->id]['x'])->toBe($layout['nodes'][$c->id]['x'])
        ->and($layout['edges'])->toHaveCount(2);
});

test('a root with several children is centered above them', function () {
    $project = Project::factory()->create();

    $root = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'current']);
    $child1 = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'neutral']);
    $child2 = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'neutral']);
    $child3 = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'neutral']);

    foreach ([$child1, $child2, $child3] as $child) {
        AppGraphEdge::factory()->create(['project_id' => $project->id, 'from_node_id' => $root->id, 'to_node_id' => $child->id]);
    }

    $nodes = AppGraphNode::whereIn('id', [$root->id, $child1->id, $child2->id, $child3->id])->get();
    $edges = AppGraphEdge::where('from_node_id', $root->id)->get();

    $layout = AppGraphTreeLayout::build($nodes, $edges);

    $childXs = collect([$child1->id, $child2->id, $child3->id])
        ->map(fn ($id) => $layout['nodes'][$id]['x'])
        ->sort()
        ->values();

    expect($layout['nodes'][$root->id]['x'])->toBe($childXs[1])
        ->and($childXs[1] - $childXs[0])->toBeGreaterThan(0)
        ->and($childXs[2] - $childXs[1])->toBeGreaterThan(0);
});

test('a node reachable through two different links only appears once, with a single parent', function () {
    $project = Project::factory()->create();

    $root = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'current']);
    $a = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'neutral']);
    $b = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'neutral']);
    $shared = AppGraphNode::factory()->create(['project_id' => $project->id, 'kind' => 'neutral']);

    AppGraphEdge::factory()->create(['project_id' => $project->id, 'from_node_id' => $root->id, 'to_node_id' => $a->id]);
    AppGraphEdge::factory()->create(['project_id' => $project->id, 'from_node_id' => $root->id, 'to_node_id' => $b->id]);
    // Both a and b link to the same shared page (e.g. every page links back to a "contact" link).
    AppGraphEdge::factory()->create(['project_id' => $project->id, 'from_node_id' => $a->id, 'to_node_id' => $shared->id]);
    AppGraphEdge::factory()->create(['project_id' => $project->id, 'from_node_id' => $b->id, 'to_node_id' => $shared->id]);

    $nodes = AppGraphNode::whereIn('id', [$root->id, $a->id, $b->id, $shared->id])->get();
    $edges = AppGraphEdge::where('project_id', $project->id)->get();

    $layout = AppGraphTreeLayout::build($nodes, $edges);

    expect($layout['nodes'])->toHaveCount(4);

    $edgesIntoShared = collect($layout['edges'])->filter(fn ($edge) => $edge['to']['node']->id === $shared->id);
    expect($edgesIntoShared)->toHaveCount(1);
});

test('an empty node collection returns an empty layout', function () {
    $layout = AppGraphTreeLayout::build(collect(), collect());

    expect($layout)->toBe(['nodes' => [], 'edges' => [], 'width' => 0, 'height' => 0]);
});
