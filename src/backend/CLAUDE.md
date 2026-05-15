# CLAUDE.md — src/backend/

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

> Sous-`CLAUDE.md` chargé automatiquement quand tu édites un fichier dans `src/backend/`. Pour les règles globales du projet, voir `/CLAUDE.md`.

## Périmètre

Tout le code Laravel côté Panel : controllers, models, services, jobs, migrations, exceptions, policies, observers. **PAS** le code provider (`src/providers/`) ni le frontend (`src/frontend/`).

## Conventions Laravel

- **Single Action Controllers** où possible (1 controller = 1 action `__invoke`)
- **Services** dans `Services/` pour la logique métier non-triviale
- **Models anémiques** : pas de logique métier dans les Eloquent models, sauf scopes et accessors purs
- **Repositories** pour les requêtes DB lourdes / réutilisables
- `declare(strict_types=1);` en tête de **chaque** fichier PHP
- Type-hint **systématique** sur les params et le return (PHP 8.2+)
- Readonly properties + constructor promotion pour les DTOs
- Final classes par défaut, sauf si héritage explicitement attendu

## Queue & Jobs

Toute opération réseau ou potentiellement longue (download d'un `.jar`, check des updates, backup, install, scheduled passes) **passe par un job de queue Redis**. Jamais en sync.

- **Idempotence obligatoire** : un job rejoué doit produire le même résultat (ne pas double-installer)
- **Backoff exponentiel** sur les jobs réseau : `tries=5`, `backoff=[10,30,60,300,900]` secondes
- Tagger les jobs avec le contexte (`server_id`, `user_id`) pour Horizon dashboard
- `failOnTimeout=true` pour les jobs réseau (sinon ils traînent)

Jobs prévus (à créer dans `src/backend/Jobs/`) :
- `CheckPluginUpdatesJob` — quotidien, par serveur
- `InstallPluginJob` — déclenché par l'user, idempotent par `(server_id, plugin_id, version)`
- `UpdatePluginJob` — comme install mais avec backup en pré-requis
- `BackupServerPluginsJob` — snapshot du dossier `/plugins` ou `/mods`
- `ApplyScheduledUpdatesJob` — orchestrateur cron-driven
- `RollbackPluginJob` — restore depuis le dernier backup

## Sécurité Wings API

- **Toujours** utiliser le client Wings officiel exposé par le panel hôte (Pterodactyl ou Pelican). Ne jamais réimplémenter.
- **Whitelist d'extensions** acceptées en upload : `.jar`, `.mcaddon`, `.mcpack`, `.phar`, `.zip` (et seulement pour les datapacks/resourcepacks).
- Tout chemin destination est résolu via le service `PathResolver` (cf. `src/backend/Services/PathResolver.php`) qui prend `(server, loader, kind)` et retourne le chemin **dans le volume du serveur**, jamais hors.
- Refuser tout `..`, chemin absolu, symlink, ou nom de fichier avec caractères de contrôle.

## Lecture de logs serveur

Pour toute lecture de log de démarrage MC, **toujours** passer par `\Pterodactyl\Repositories\Wings\DaemonFileRepository::getContent($path, $maxBytes)`. Ne JAMAIS hardcoder un path utilisateur — utiliser un chemin fixe (ex. `/logs/latest.log`). Pour des lectures plus larges, passer par `PmcpWorkspacePath::sanitizeFilePath()` qui valide l'absence de traversal.

## Error handling

- Exceptions métier dans `src/backend/Exceptions/` :
  - `PluginNotFoundException`
  - `PluginIncompatibleException`
  - `DependencyResolutionException`
  - `WingsTransferException`
  - `RateLimitExceededException`
  - `PolicyViolationException`
- Translater en réponses HTTP propres dans `Handler` (404, 409, 422, 502, 429).
- Logger **systématiquement** avec contexte : `Log::error('msg', ['server_id' => …, 'user_id' => …, 'plugin_id' => …, 'exception' => $e])`.
- Ne jamais swallow une exception. Si tu catch, tu logges OU tu re-throw.

## Migrations

- **Préfixe `pmcp_`** sur toutes les tables (Plugin Manager Pterodactyl) pour éviter collisions avec le panel hôte.
- **PAS de FK** vers les tables du panel (`servers`, `users`, etc.) : couplage faible. Stocker l'ID en `unsignedBigInteger` indexé.
- **Soft deletes** sur les `pmcp_installed_plugins` (pour rollback et audit).
- Toujours ajouter `created_at` + `updated_at` sauf raison documentée.
- Indexes composites quand on filtre sur ≥ 2 colonnes ensemble.

Tables prévues (à créer dans `src/backend/database/migrations/`) :
- `pmcp_plugins` — cache catalogue normalisé (id, source, slug, name, summary, …)
- `pmcp_plugin_versions` — versions d'un plugin (id, plugin_id, version, mc_versions, loaders, deps, sha512)
- `pmcp_installed_plugins` — qui a installé quoi (id, server_id, plugin_id, version_id, pinned, scheduled_update)
- `pmcp_presets` — modpacks utilisateur (id, user_id, name, description)
- `pmcp_preset_items` — items d'un preset (preset_id, plugin_id, version_id_or_null)
- `pmcp_policies` — règles admin (id, scope, source_whitelist, plugin_blacklist, …)
- `pmcp_backups` — métadonnées des backups (id, server_id, snapshot_path, created_at)
- `pmcp_audit_log` — (roadmap v2, table prévue mais pas peuplée en v1)

## Tests

- Pest unit dans `tests/Unit/` (pas de DB, pas de réseau)
- Pest integration dans `tests/Integration/` (DB sqlite in-memory + VCR pour HTTP)
- Avant tout commit : `composer test` doit passer
