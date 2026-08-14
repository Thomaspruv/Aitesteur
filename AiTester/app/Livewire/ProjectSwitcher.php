<?php

namespace App\Livewire;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ProjectSwitcher extends Component
{
    public string $newProjectName = '';

    /**
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        $organization = Auth::user()->organizations()->first();

        return $organization ? $organization->projects : new Collection;
    }

    #[Computed]
    public function currentProjectId(): ?int
    {
        return Auth::user()->currentProject()?->id;
    }

    /**
     * Re-validates ownership rather than trusting the id came from this
     * component's own menu — the same defense-in-depth reasoning as
     * User::currentProject() re-checking the session value on every read.
     */
    public function activate(int $projectId): void
    {
        $project = Project::whereKey($projectId)
            ->whereHas('organization.users', fn ($query) => $query->whereKey(Auth::id()))
            ->firstOrFail();

        session(['active_project_id' => $project->id]);

        $this->redirect(url()->previous(), navigate: true);
    }

    public function create(): void
    {
        $this->validate(['newProjectName' => 'required|string|max:255']);

        $organization = Auth::user()->organizations()->firstOrFail();

        $project = $this->createProjectWithUniqueSlug($organization, $this->newProjectName);

        // Every other project-creation path (UserObserver, on signup)
        // provisions an Environment alongside the Project — without one
        // here, a project created through the switcher has nowhere to run
        // discovery or workflows against until someone happens to add a
        // site under Réglages environnement.
        $project->environments()->create(['name' => 'staging']);

        session(['active_project_id' => $project->id]);

        $this->redirect(url()->previous(), navigate: true);
    }

    /**
     * uniqueSlug()'s availability check and the insert below aren't atomic —
     * two concurrent submissions of the same name can both pass the check
     * before either commits. Retrying against the DB's own
     * unique(['organization_id', 'slug']) constraint (rather than trusting
     * the pre-check alone) turns that race into "pick the next suffix and
     * try again" instead of an uncaught query exception.
     */
    private function createProjectWithUniqueSlug(Organization $organization, string $name): Project
    {
        return retry(5, fn () => $organization->projects()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($organization, $name),
        ]), 0, fn (\Throwable $exception) => $exception instanceof QueryException);
    }

    private function uniqueSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'projet';
        $slug = $base;
        $suffix = 2;

        while ($organization->projects()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
