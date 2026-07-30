<?php

namespace Database\Seeders;

use App\Enums\Criticality;
use App\Enums\Verdict;
use App\Enums\WorkflowOrigin;
use App\Enums\WorkflowStatus;
use App\Models\AppGraphNode;
use App\Models\Project;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Seeder;

class DemoWorkspaceSeeder extends Seeder
{
    /**
     * Populate the given user's auto-provisioned project with the "Facturly"
     * sample data matching the airtight design handoff.
     */
    public function run(User $user): void
    {
        $project = $user->currentProject();

        if (! $project) {
            return;
        }

        $project->update([
            'name' => 'Facturly',
            'slug' => 'facturly',
            'business_description' => 'SaaS de facturation pour indépendants',
            'avg_ai_cost_per_run' => 0.006,
            'uptime_pct' => 99.8,
        ]);

        $project->environments()->first()?->update([
            'name' => 'staging',
            'url' => 'staging.facturly.app',
        ]);

        $this->seedActiveWorkflows($project);
        $this->seedDiscoveryCandidates($project);
        $this->seedAppGraph($project);
    }

    protected function seedActiveWorkflows(Project $project): void
    {
        $connexion = Workflow::create([
            'project_id' => $project->id,
            'name' => 'Connexion',
            'criticality' => Criticality::P0,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Active,
            'score' => 96,
            'verified' => true,
            'canary' => true,
            'latest_verdict' => Verdict::Pass,
            'last_run_at' => now()->subMinutes(12),
            'steps' => [
                ['intent' => 'Ouvrir la page de connexion', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Saisir les identifiants du compte de test', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Soumettre le formulaire', 'assertions' => [['label' => 'état atteint', 'on' => true], ['label' => 'baseline visuelle', 'on' => true]]],
            ],
            'spark_data' => [10, 9, 11, 8, 10, 7, 9, 6, 8],
        ]);
        $connexion->runs()->create(['verdict' => Verdict::Pass, 'escalation_level' => 0, 'triggered_by' => 'deploy', 'created_at' => now()->subMinutes(12)]);

        $invoice = Workflow::create([
            'project_id' => $project->id,
            'name' => "Créer une facture → l'envoyer → la marquer payée",
            'criticality' => Criticality::P0,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Active,
            'score' => 88,
            'verified' => true,
            'latest_verdict' => Verdict::PassHealed,
            'last_run_at' => now()->subMinutes(34),
            'steps' => [
                ['intent' => 'Se connecter avec le compte de test standard', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Créer une facture de 100 € pour le client Dupont', 'assertions' => [['label' => 'état atteint', 'on' => true], ['label' => 'baseline visuelle', 'on' => true]]],
                ['intent' => 'Envoyer la facture', 'assertions' => [['label' => "pas d'erreur", 'on' => true]]],
                ['intent' => "Vérifier qu'elle apparaît comme 'envoyée' dans la liste", 'assertions' => [['label' => 'état atteint', 'on' => true], ['label' => 'baseline visuelle', 'on' => false]]],
            ],
            'spark_data' => [8, 12, 9, 13, 7, 10, 6, 9, 7],
        ]);
        $invoice->runs()->create(['verdict' => Verdict::PassHealed, 'escalation_level' => 1, 'triggered_by' => 'deploy', 'created_at' => now()->subMinutes(34)]);

        foreach ([
            ['version' => 9, 'change_label' => 'Création initiale (grounding run)', 'days' => 28],
            ['version' => 10, 'change_label' => 'Montant de test changé à 100€', 'days' => 15],
            ['version' => 11, 'change_label' => 'Self-healing : sélecteur bouton Envoyer', 'days' => 7],
            ['version' => 12, 'change_label' => 'Assertion visuelle ajoutée (étape 4)', 'days' => 3],
        ] as $entry) {
            $invoice->workflowVersions()->create([
                'version' => $entry['version'],
                'steps' => $invoice->steps,
                'change_label' => $entry['change_label'],
                'created_at' => now()->subDays($entry['days']),
            ]);
        }

        $checkout = Workflow::create([
            'project_id' => $project->id,
            'name' => 'Ajouter au panier → payer',
            'criticality' => Criticality::P0,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Active,
            'score' => 79,
            'verified' => true,
            'latest_verdict' => Verdict::Changed,
            'last_run_at' => now()->subHour(),
            'steps' => [
                ['intent' => 'Ouvrir la fiche du premier produit de la liste', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Ajouter le produit au panier', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Payer avec la carte de test', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
            ],
            'spark_data' => [9, 8, 10, 7, 9, 12, 8, 10, 9],
        ]);
        $checkoutRun = $checkout->runs()->create(['verdict' => Verdict::Changed, 'escalation_level' => 4, 'triggered_by' => 'deploy', 'created_at' => now()->subHour()]);
        $checkoutRun->update([
            'diagnostic_summary' => "L'étape « Envoyer » est maintenant dans un menu déroulant — parcours retrouvé par un chemin différent.",
        ]);

        $invite = Workflow::create([
            'project_id' => $project->id,
            'name' => 'Inviter un membre',
            'criticality' => Criticality::P1,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Active,
            'score' => 74,
            'verified' => true,
            'latest_verdict' => Verdict::Pass,
            'last_run_at' => now()->subHours(2),
            'steps' => [
                ['intent' => "Ouvrir les paramètres d'équipe", 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Inviter un membre par email', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Confirmer le rôle attribué', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
            ],
            'spark_data' => [11, 9, 10, 9, 8, 9, 8, 9, 8],
        ]);
        $invite->runs()->create(['verdict' => Verdict::Pass, 'escalation_level' => 0, 'triggered_by' => 'deploy', 'created_at' => now()->subHours(2)]);

        $resetPassword = Workflow::create([
            'project_id' => $project->id,
            'name' => 'Réinitialiser mot de passe',
            'criticality' => Criticality::P0,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Active,
            'score' => 91,
            'verified' => true,
            'latest_verdict' => Verdict::Broken,
            'last_run_at' => now()->subHours(3),
            'steps' => [
                ['intent' => 'Ouvrir la page de connexion', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => "Cliquer sur 'Mot de passe oublié'", 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => "Saisir l'email et soumettre", 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Ouvrir le lien reçu par email', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Vérifier le formulaire de nouveau mot de passe', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
            ],
            'spark_data' => [8, 9, 8, 10, 9, 14, 15, 16, 17],
        ]);
        $brokenRun = $resetPassword->runs()->create([
            'verdict' => Verdict::Broken,
            'escalation_level' => 3,
            'triggered_by' => 'deploy',
            'confirmed' => true,
            'expected_label' => 'baseline',
            'observed_label' => 'erreur 500',
            'diagnostic_summary' => 'La page affiche une erreur serveur — non réalisable autrement → régression confirmée.',
            'created_at' => now()->subHours(3),
        ]);
        foreach ([
            ['intent' => 'Ouvrir la page de connexion', 'state' => Verdict::Pass],
            ['intent' => "Cliquer sur 'Mot de passe oublié'", 'state' => Verdict::Pass],
            ['intent' => "Saisir l'email et soumettre", 'state' => Verdict::Pass],
            ['intent' => 'Ouvrir le lien reçu par email', 'state' => Verdict::Pass],
            ['intent' => 'Vérifier le formulaire de nouveau mot de passe', 'state' => Verdict::Broken],
        ] as $position => $step) {
            $brokenRun->runSteps()->create([
                'position' => $position,
                'intent' => $step['intent'],
                'state' => $step['state'],
            ]);
        }

        $export = Workflow::create([
            'project_id' => $project->id,
            'name' => 'Exporter les données',
            'criticality' => Criticality::P1,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Active,
            'score' => 68,
            'verified' => true,
            'latest_verdict' => Verdict::Pass,
            'last_run_at' => now()->subHours(5),
            'steps' => [
                ['intent' => 'Ouvrir les paramètres du compte', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
                ['intent' => 'Lancer un export CSV', 'assertions' => [['label' => 'état atteint', 'on' => true]]],
            ],
            'spark_data' => [10, 10, 9, 10, 9, 10, 9, 10, 9],
        ]);
        $export->runs()->create(['verdict' => Verdict::Pass, 'escalation_level' => 0, 'triggered_by' => 'deploy', 'created_at' => now()->subHours(5)]);
    }

    protected function seedDiscoveryCandidates(Project $project): void
    {
        Workflow::create([
            'project_id' => $project->id,
            'name' => 'Rechercher un client et ouvrir sa fiche',
            'criticality' => Criticality::P1,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Candidate,
            'score' => 82,
            'verified' => true,
            'steps' => [
                ['intent' => 'Ouvrir la barre de recherche', 'assertions' => []],
                ['intent' => 'Saisir "Dupont"', 'assertions' => []],
                ['intent' => 'Ouvrir le premier résultat', 'assertions' => []],
            ],
        ]);

        Workflow::create([
            'project_id' => $project->id,
            'name' => 'Exporter les factures en PDF',
            'criticality' => Criticality::P1,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Candidate,
            'score' => 68,
            'verified' => true,
            'steps' => [
                ['intent' => 'Ouvrir la liste des factures', 'assertions' => []],
                ['intent' => 'Exporter en PDF', 'assertions' => []],
            ],
        ]);

        Workflow::create([
            'project_id' => $project->id,
            'name' => "Modifier le rôle d'un membre",
            'criticality' => Criticality::P2,
            'origin' => WorkflowOrigin::Discovered,
            'status' => WorkflowStatus::Candidate,
            'score' => 52,
            'verified' => false,
            'steps' => [
                ['intent' => 'Ouvrir la fiche du membre', 'assertions' => []],
                ['intent' => 'Changer le rôle', 'assertions' => []],
                ['intent' => 'Sauvegarder', 'assertions' => []],
            ],
        ]);
    }

    protected function seedAppGraph(Project $project): void
    {
        $login = AppGraphNode::create(['project_id' => $project->id, 'key' => 'login', 'label' => 'login', 'kind' => 'current', 'x' => 40, 'y' => 30]);
        $dashboard = AppGraphNode::create(['project_id' => $project->id, 'key' => 'dashboard', 'label' => 'dashboard', 'kind' => 'neutral', 'x' => 120, 'y' => 90]);
        $invoiceOk = AppGraphNode::create(['project_id' => $project->id, 'key' => 'facture-ok', 'label' => 'facture ✓', 'kind' => 'healthy', 'x' => 200, 'y' => 60]);
        $error500 = AppGraphNode::create(['project_id' => $project->id, 'key' => 'erreur-500', 'label' => 'erreur 500', 'kind' => 'error', 'x' => 200, 'y' => 130]);
        $settings = AppGraphNode::create(['project_id' => $project->id, 'key' => 'settings', 'label' => 'paramètres', 'kind' => 'neutral', 'x' => 60, 'y' => 140]);

        foreach ([
            [$login, $dashboard],
            [$dashboard, $invoiceOk],
            [$dashboard, $error500],
            [$dashboard, $settings],
        ] as [$from, $to]) {
            $project->appGraphEdges()->create(['from_node_id' => $from->id, 'to_node_id' => $to->id]);
        }
    }
}
