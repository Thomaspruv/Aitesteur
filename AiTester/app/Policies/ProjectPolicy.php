<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): bool
    {
        // Admins can inspect any project's data from the admin panel (cost
        // dashboards, and — via WorkflowPolicy/RunPolicy, which both delegate
        // here — the runs/workflows those dashboards link into), regardless
        // of organization membership.
        return $user->is_admin || $user->organizations()->whereKey($project->organization_id)->exists();
    }
}
