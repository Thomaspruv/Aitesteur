<?php

namespace App\Support;

use App\Models\AppGraphEdge;
use App\Models\AppGraphNode;
use Illuminate\Support\Collection;

/**
 * Turns the crawl's raw node/edge graph (which can have cross-links — e.g. a
 * nav bar linking back home from every page) into a clean, readable
 * hierarchical sitemap: one root, each other page placed under the page that
 * first linked to it, siblings spaced out and parents centered over their
 * children.
 */
class AppGraphTreeLayout
{
    private const COL_WIDTH = 70;

    private const LEVEL_HEIGHT = 60;

    private const PADDING = 40;

    /**
     * @param  Collection<int, AppGraphNode>  $nodes
     * @param  Collection<int, AppGraphEdge>  $edges
     * @return array{nodes: array<int, array{node: AppGraphNode, x: float, y: float}>, edges: array<int, array{from: array{node: AppGraphNode, x: float, y: float}, to: array{node: AppGraphNode, x: float, y: float}}>, width: int, height: int}
     */
    public static function build(Collection $nodes, Collection $edges): array
    {
        if ($nodes->isEmpty()) {
            return ['nodes' => [], 'edges' => [], 'width' => 0, 'height' => 0];
        }

        $nodesById = $nodes->keyBy('id');

        $adjacency = [];
        foreach ($edges as $edge) {
            $adjacency[$edge->from_node_id][] = $edge->to_node_id;
        }

        $root = $nodes->firstWhere('kind', 'current') ?? $nodes->first();

        // BFS from the root, keeping only the first-discovered parent for
        // each node — this is what collapses the graph into a spanning tree.
        $depthOf = [$root->id => 0];
        $childrenOf = [];
        $visited = [$root->id => true];
        $queue = [$root->id];

        while ($queue !== []) {
            $currentId = array_shift($queue);

            foreach ($adjacency[$currentId] ?? [] as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }

                $visited[$childId] = true;
                $depthOf[$childId] = $depthOf[$currentId] + 1;
                $childrenOf[$currentId][] = $childId;
                $queue[] = $childId;
            }
        }

        // A node unreachable from the root (shouldn't normally happen — the
        // crawl always starts there) is still placed rather than dropped, so
        // the tree never silently loses a page the crawl actually found.
        foreach ($nodes as $node) {
            if (! isset($visited[$node->id]) && $node->id !== $root->id) {
                $visited[$node->id] = true;
                $depthOf[$node->id] = 1;
                $childrenOf[$root->id][] = $node->id;
            }
        }

        $slot = [];
        $cursor = 0;
        $assignSlot = function (int $id) use (&$assignSlot, &$slot, &$cursor, $childrenOf): void {
            $children = $childrenOf[$id] ?? [];

            if ($children === []) {
                $slot[$id] = $cursor++;

                return;
            }

            foreach ($children as $childId) {
                $assignSlot($childId);
            }

            $first = $slot[$children[0]];
            $last = $slot[end($children)];
            $slot[$id] = ($first + $last) / 2;
        };
        $assignSlot($root->id);

        $positions = [];
        foreach ($slot as $id => $slotValue) {
            $positions[$id] = [
                'node' => $nodesById[$id],
                'x' => self::PADDING + $slotValue * self::COL_WIDTH,
                'y' => self::PADDING + $depthOf[$id] * self::LEVEL_HEIGHT,
            ];
        }

        $treeEdges = [];
        foreach ($childrenOf as $parentId => $children) {
            foreach ($children as $childId) {
                $treeEdges[] = ['from' => $positions[$parentId], 'to' => $positions[$childId]];
            }
        }

        $leafCount = max($cursor, 1);

        return [
            'nodes' => $positions,
            'edges' => $treeEdges,
            'width' => self::PADDING * 2 + ($leafCount - 1) * self::COL_WIDTH,
            'height' => self::PADDING * 2 + max($depthOf) * self::LEVEL_HEIGHT,
        ];
    }
}
