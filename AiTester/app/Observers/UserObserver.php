<?php

namespace App\Observers;

use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

class UserObserver
{
    /**
     * Provision an empty organization/project/environment for every new user
     * so the product screens always have a workspace to render into.
     */
    public function created(User $user): void
    {
        $organization = Organization::create([
            'name' => "Organisation de {$user->name}",
            'slug' => Str::slug("org-{$user->name}-{$user->id}"),
        ]);

        $organization->users()->attach($user, ['role' => 'owner']);

        $project = $organization->projects()->create([
            'name' => 'Mon projet',
            'slug' => 'mon-projet',
        ]);

        Environment::create([
            'project_id' => $project->id,
            'name' => 'staging',
        ]);
    }
}
