# CLAUDE.md — src/providers/

<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (90-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk vitest run          # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%)
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->

> Sous-`CLAUDE.md` chargé automatiquement quand tu édites un fichier dans `src/providers/`. Pour les règles globales, voir `/CLAUDE.md`. Pour la spec produit complète, voir `docs/PROVIDERS.md`.

## Périmètre

Code qui parle aux sources externes : Modrinth, CurseForge (et providers futurs). Adapters HTTP, mapping vers le DTO normalisé, cache, rate-limit.

**HORS périmètre :** logique de business (compat check, dep resolution) — ça vit dans `src/backend/Services/`. Les providers ne renvoient QUE des données normalisées, ils ne décident pas.

## Interface PluginProvider

Tout provider implémente `src/providers/Contracts/PluginProvider.php` :

```php
interface PluginProvider
{
    public function id(): string;                    // 'modrinth', 'curseforge'
    public function search(SearchQuery $query): SearchResult;
    public function getDetails(string $externalId): NormalizedPlugin;
    public function getVersions(string $externalId, ?string $mcVersion = null, ?Loader $loader = null): array;  // Version[]
    public function download(string $versionId): StreamInterface;
    public function getDependencies(string $versionId): array;  // Dependency[]
}
```

Les DTOs (`SearchQuery`, `SearchResult`, `NormalizedPlugin`, `Version`, `Dependency`) sont définis dans `src/providers/DTOs/`. Ils sont **stables** : un changement nécessite une migration de cache.

## Schéma normalisé

**Règle absolue :** on ne renvoie JAMAIS le format brut Modrinth ou CurseForge au reste du code. Toujours mapper vers `NormalizedPlugin`.

Le frontend ne sait pas d'où vient un plugin (sauf affichage de la source/badge). Cela rend l'ajout de futurs providers transparent.

Le mapping est testé par snapshot tests dans `tests/Unit/Providers/Modrinth/MapperTest.php` (et CurseForge).

## Cache HTTP

- Tous les `GET` vers Modrinth/CurseForge sont cachés en Redis.
- Clé de cache : `pmcp:provider:<provider>:<sha256(method+url+sorted_query)>`.
- TTL :
  - **Search** : 1 heure
  - **Details / Versions** : 24 heures
  - **Download URL** (signée temporairement) : pas de cache
- Invalidation manuelle : `php artisan pmcp:cache:flush --provider=modrinth`
- Cache stale-while-revalidate : si l'API distante throw, servir le cache stale avec un warning header.

## Rate limits

**Modrinth** : 300 req/min, identifié par User-Agent.
- Token bucket en Redis : clé `pmcp:rl:modrinth`, capacité 300, refill 5/sec.
- Si épuisé, jeter `RateLimitExceededException` (caught par le job, retry avec backoff).

**CurseForge** : clé API obligatoire (`CURSEFORGE_API_KEY` dans `.env`), quotas par contrat.
- Lire la clé depuis le service container (config/services.php), jamais hardcodée.
- Si quota dépassé, jeter `RateLimitExceededException`.

**Degradation gracieuse** : si un provider est down ou rate-limited, le frontend affiche les résultats des autres providers + un warning « <provider> momentanément indisponible ».

## User-Agent

Toujours envoyer un User-Agent identifiant : `pteromcplugins/<version> (+https://github.com/<org>/pterodactyl-mc-plugins)` (slug dépôt Git ≠ identifiant Blueprint).

C'est explicitement demandé par Modrinth (cf. https://docs.modrinth.com/api/) et requis par CurseForge.

## Tests

- **php-vcr** (`php-vcr/php-vcr` + adapter Guzzle) pour rejouer les vraies réponses HTTP
- Cassettes commitées dans `tests/Integration/Providers/<provider>/cassettes/`
- Re-record avec `VCR_MODE=none` (default = playback)
- **Snapshot tests** sur le mapping → `NormalizedPlugin` (Pest snapshot plugin)

## Antipatterns providers

1. **Ne pas merger les résultats Modrinth + CurseForge naïvement** : dédoublonner par `project_url` ou par `(name, author)` quand `project_url` indisponible.
2. **Ne pas faire de ranking « maison »** côté backend pour la recherche : laisser la source ranker (chacun a son algo testé). Côté frontend, on peut filtrer/trier sur des critères neutres (date, downloads).
3. **Ne pas trust un `download_url`** sans vérification du checksum (sha512 si fourni). Modrinth fournit le hash, CurseForge fournit fileLength + fingerprint.
4. **Ne pas retry agressif** sur 4xx : c'est un bug côté nous, pas une transient error.
5. **Ne pas exposer la clé CurseForge** dans les logs : redaction via Laravel `Log::withoutContext()` ou middleware custom.

## Ajouter un futur provider

1. Créer `src/providers/<NewProvider>/Provider.php` qui implémente `PluginProvider`.
2. Créer `Mapper.php` qui transforme la réponse en `NormalizedPlugin`.
3. Enregistrer dans `config/pmcp.php` → `providers` array.
4. Ajouter cassettes VCR + tests.
5. Documenter dans `docs/PROVIDERS.md`.

Voir `docs/PROVIDERS.md` pour la spec détaillée et les particularités de chaque source.
