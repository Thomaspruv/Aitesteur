<?php

namespace App\Livewire;

use App\Models\Environment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EnvironmentSwitcher extends Component
{
    /**
     * @return Collection<int, Environment>
     */
    #[Computed]
    public function environments(): Collection
    {
        $project = Auth::user()->currentProject();

        return $project ? $project->environments : new Collection;
    }

    #[Computed]
    public function activeEnvironmentId(): ?int
    {
        return Auth::user()->currentProject()?->activeEnvironment()?->id;
    }

    public function activate(int $environmentId): void
    {
        $project = Auth::user()->currentProject();

        abort_unless($project?->environments()->whereKey($environmentId)->exists(), 403);

        session(['active_environment_id' => $environmentId]);

        $this->redirect(url()->previous(), navigate: true);
    }
}
