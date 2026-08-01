# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Airtight ("AiTester") is an AI-driven QA testing platform: point it at a staging URL, it crawls the app to propose candidate test **Workflows**, and then replays those workflows by having an LLM drive a real headless browser step-by-step, producing a pass/fail **Verdict** per run. It's a Laravel 13 + Livewire 4 (Flux UI) app with a Node/Playwright crawler subsystem invoked as a child process. All user-facing UI strings are French (no `resources/lang` files — the French text is written literally inside `__()` calls).

This repo already has an `AGENTS.md` (Laravel Boost guidelines, auto-loaded by Cursor) covering framework-level conventions: run `vendor/bin/pint --dirty --format agent` after editing PHP, use `search-docs` (Boost MCP) before Laravel/Livewire/Pest changes, PHP 8 constructor promotion, explicit return types, etc. Follow those; they aren't repeated here.

## Commands

The site itself is served persistently by Laravel Herd/Valet at the `APP_URL` `.test` domain — **never run a command to "start the server."** Only the queue worker and Vite need starting for local dev:

```bash
./start-all                 # queue:listen + npm run dev, concurrently (checks Valet is up first)
# or
composer run dev            # php artisan dev (same idea, via Laravel's built-in dev orchestrator)
```

Setup from scratch: `composer run setup` (installs deps, copies `.env`, generates key, migrates, builds assets).

**Tests** (Pest, all in `tests/Feature/` — this project has no meaningful `tests/Unit/`; SQLite in-memory DB, `RefreshDatabase` per test):

```bash
php artisan test                                  # full suite
php artisan test --compact                        # quieter output (preferred)
php artisan test --filter=RunDiscoveryCrawlTest    # one test file
php artisan test --filter="a run against an unauthorized target"  # one test by name
composer run test                                 # config:clear + lint:check + types:check + full test suite (what CI runs)
```

There is **no automated test suite for the `crawler/` JS code** (no `package.json` test script, no `*.test.mjs` files). Changes there can only be verified by reading the code carefully and/or a manual smoke test against a real headless Chromium (`node --check <file>` for syntax at minimum).

**Lint / static analysis:**

```bash
vendor/bin/pint --dirty --format agent   # format only changed PHP files (do this after any PHP edit)
composer run lint:check                  # pint --test, whole repo
composer run types:check                 # phpstan/larastan, level 7 (phpstan.neon)
```

## Architecture

### Tenancy hierarchy and auto-provisioning

`User` → (belongsToMany) `Organization` → (hasMany) `Project` → (hasMany) `Environment` / `Workflow`. There is **no project switcher yet** — `User::currentProject()` always returns the first organization's first project (memoized via `once()`). Every new `User` (factory, seeder, or real Fortify signup) gets an Organization + Project + Environment auto-created by `App\Observers\UserObserver::created()` (registered in `AppServiceProvider::boot()`), so `currentProject()`/`primaryEnvironment()` are never null in practice — but the auto-created `Environment` intentionally has `url`/`target_authorized_at`/`target_authorized_host` all null (see authorization below).

### The two-phase test lifecycle

1. **Discovery** (`resources/views/pages/app/⚡discovery.blade.php` → `App\Jobs\RunDiscoveryCrawl` → `crawler/crawl.mjs`): breadth-first same-origin crawl of a target URL (links, clicks on non-form UI, popups, infinite scroll — never submits a form except a best-effort login/register attempt). Produces an app graph (`AppGraphNode`/`AppGraphEdge`) and candidate `Workflow` rows (`status = candidate`, `origin = discovered`).
2. **Verification/execution** (`App\Jobs\RunWorkflow` → `crawler/execute.mjs`): replays a `Workflow`'s ordered natural-language `steps` in a real browser — an LLM (`App\Contracts\AiClient`) decides what to click/fill at each step from a compact list of currently-visible interactive elements (never invents data; unable-to-proceed steps become `cant_find`). One self-heal retry is allowed per step (escalation level 0-1 only; higher levels are out of scope per the script's own docblock). Produces `Run`/`RunStep` rows and a `Verdict` (`PASS`/`PASS_HEALED`/`CHANGED`/`BROKEN`/`SKIPPED`).

Both jobs follow the same shape: normalize the environment's URL, shell out via `Illuminate\Support\Facades\Process` to the corresponding `crawler/*.mjs` script, and parse its JSON output file. **The Node scripts always write valid JSON to `--output` and exit 0** regardless of whether the crawl/run itself succeeded — success/failure is decided by the JSON's `ok`/`error` fields, never the process exit code. Both jobs have `$tries = 1` deliberately: a stale automatic retry would silently repeat real browser actions (form submits, clicks) against the live target.

### Authorization / SSRF safety model (security-sensitive — read before touching)

Before any crawl/run is allowed to touch a URL, the user must tick an explicit "I'm authorized to test this site" checkbox in the UI (`⚡discovery.blade.php` / `⚡settings-environment.blade.php`), which stamps `Environment::target_authorized_at` + `target_authorized_host`. `Environment::hasAuthorizedTarget()` binds authorization to the **current** `url`'s host (via `App\Support\UrlHost::of()`), not just "was ever authorized" — both `RunDiscoveryCrawl`/`RunWorkflow` independently re-check this as defense in depth against the UI gate being bypassed.

Separately, `crawler/lib/shared.mjs` guards the crawler itself against reaching private/internal network ranges (including the cloud metadata IP), layered because no single Playwright interception point covers everything:
- `assertPublicHost()` — one upfront DNS check on the start URL before the browser launches.
- `installPrivateNetworkGuard()` — installed on every browser context, this is three mechanisms in one: a `context.route()` DNS check per request/hostname, a `context.routeWebSocket()` check (route() never sees WebSocket traffic), and a `context.on('response')` backstop that inspects the *actual* connected IP via `serverAddr()` and kills the whole context if it's private (this is what catches an HTTP redirect to an internal address — `context.route()` is never invoked for a redirect's target, only the first URL in a chain).

`App\Support\UrlHost::of()`/`normalize()` is the single place URL→host extraction and scheme-defaulting happens on the PHP side; it's deliberately kept in sync with how a real browser (WHATWG URL parsing) would interpret the same string (case-insensitive scheme, backslash normalized to slash) rather than trusting PHP's `parse_url()` alone, since a PHP/browser parsing disagreement here is a real bypass vector. `RunDiscoveryCrawl`/`RunWorkflow`'s `normalizeUrl()` delegate to `UrlHost::normalize()` rather than re-implementing it.

### AI provider abstraction

`App\Contracts\AiClient` (`chatJson(systemPrompt, userPrompt): array`) is the only interface application code talks to. `App\Services\Ai\DeepSeekClient` is currently the sole implementation. The provider/model/API key are configured **per-Project** (encrypted `ai_provider`/`ai_model`/`ai_api_key` columns), not via `.env` — `Project::aiClient(): ?AiClient` returns null if unconfigured, and every call site (compiling a workflow from a description, verifying a candidate, replaying steps) must handle that null case gracefully rather than assuming AI is available.

### Livewire "page" components

Full-page UI lives as Livewire 4 single-file components under `resources/views/pages/app/*.blade.php` (the `⚡` prefix is a filesystem-only naming convention, not meaningful to the framework). They're routed with the `pages::` view namespace via `Route::livewire('path', 'pages::app.name')->name(...)` in `routes/app.php`, all under an `auth`+`verified` middleware group. Each file is a `new class extends Component { ... }` block followed by its Blade template in the same file — no separate class file.

### Enums as the state-machine source of truth

`App\Enums\{DiscoveryRunStatus,RunStatus,WorkflowStatus,WorkflowOrigin,Verdict,Criticality,AiProvider}` define every valid state a `DiscoveryRun`/`Run`/`Workflow` can be in — check these before adding a new status string anywhere, and update the corresponding `match`/`switch` exhaustively (PHPStan/Larastan level 7 will catch a missed arm on a backed enum in most cases).
