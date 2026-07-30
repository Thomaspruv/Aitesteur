<?php

namespace App\Services\Ai;

use App\Contracts\AiClient;
use App\Exceptions\AiClientException;
use Illuminate\Support\Str;

class DiscoveryAnnotator
{
    protected const MAX_NAME_LENGTH = 60;

    protected const SYSTEM_PROMPT = <<<'PROMPT'
        Tu analyses des pages découvertes par un crawl automatique en boîte noire (sans accès au code source)
        sur une application web. Pour chaque page contenant un formulaire, déduis à partir de son URL, de son
        titre, de son titre visible et des champs du formulaire à quoi elle sert dans le métier de l'application,
        et propose un nom de workflow court, clair et différencié des autres pages.
        Réponds UNIQUEMENT avec un objet JSON de cette forme, sans texte autour :
        {
          "pages": [
            {"url": "url exacte de la page", "name": "nom métier court (<60 caractères)", "criticality": "P0" | "P1" | "P2"}
          ]
        }
        PROMPT;

    public function __construct(protected AiClient $client) {}

    /**
     * @param  array<int, array{title: string, url: string, heading: string|null, fields: array<int, string>}>  $pages
     * @return array<string, array{name: string, criticality: string}> keyed by page url
     *
     * @throws AiClientException if the AI response doesn't contain usable annotations
     */
    public function annotate(array $pages): array
    {
        if ($pages === []) {
            return [];
        }

        $description = collect($pages)
            ->map(fn (array $page) => sprintf(
                "URL: %s\nTitre HTML: %s\nTitre visible: %s\nChamps du formulaire: %s",
                $page['url'],
                $page['title'],
                $page['heading'] ?? '—',
                $page['fields'] === [] ? '—' : implode(', ', $page['fields']),
            ))
            ->implode("\n---\n");

        $result = $this->client->chatJson(self::SYSTEM_PROMPT, $description);

        $pagesResult = $result['pages'] ?? null;

        if (! is_array($pagesResult) || $pagesResult === []) {
            throw new AiClientException("La réponse de l'IA ne contient aucune annotation exploitable.");
        }

        $annotations = [];
        foreach ($pagesResult as $entry) {
            if (! is_array($entry) || ! is_string($entry['url'] ?? null)) {
                continue;
            }

            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            if ($name === '') {
                continue;
            }

            $annotations[$entry['url']] = [
                'name' => Str::limit($name, self::MAX_NAME_LENGTH, ''),
                'criticality' => in_array($entry['criticality'] ?? null, ['P0', 'P1', 'P2'], true) ? $entry['criticality'] : 'P2',
            ];
        }

        return $annotations;
    }
}
