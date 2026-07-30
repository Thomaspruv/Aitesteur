<?php

namespace App\Policies;

use App\Models\Run;
use App\Models\User;

class RunPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Run $run): bool
    {
        return (new WorkflowPolicy)->view($user, $run->workflow);
    }
}
