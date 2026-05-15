# CLAUDE.md — src/frontend/

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

> Sous-`CLAUDE.md` chargé automatiquement quand tu édites un fichier dans `src/frontend/`. Pour les règles globales, voir `/CLAUDE.md`.

## Périmètre

UI du module pour les **deux Panels** :
- **`ext/dashboard/components/`** → [**Blueprint `Components.yml`**](https://blueprint.zip/docs/configs/componentsyml) — React dans le même bundle que le panel Pterodactyl client (`conf.yml → dashboard.components`). C’est là que vit la vue **Mods & plugins** (`sections/McPluginsDashboard.tsx`), y compris le bouton **sonde version MC** (`GET …/server/probe-mc-version`) documenté dans `docs/PTERODACTYL-PRIMER.md`.
- `src/frontend/pterodactyl/` → doc historique Vue 2 côté core ; pour une extension Blueprint, l’UI client prioritaire reste **`ext/dashboard/components/`**.
- `src/frontend/pelican/` → Livewire 3 + Alpine.js (Pelican Panel — hors stack Blueprint Components).
- `src/frontend/shared/` → CSS/SCSS, i18n, assets, **strictement pas de logique JS/PHP**

## Quand Vue 2 vs Livewire ?

| Tu travailles sur... | Stack |
|---|---|
| Onglet serveur dans Pterodactyl Panel original | Vue 2.7 (Options API) + Vuex |
| Onglet serveur dans Pelican Panel | Livewire 3 + Alpine.js |
| Page admin Policies dans Pterodactyl | Vue 2.7 (admin SPA legacy) ou Blade + jQuery selon la version |
| Page admin Policies dans Pelican | Livewire 3 |
| Strings i18n, CSS, icônes, illustrations | `shared/` (les deux Panels y puisent) |

**Règle :** un changement de logique métier doit être implémenté DEUX FOIS si l'UI est dans les deux Panels. Pas de raccourci. Le service backend factorise, l'UI est dupliquée par contrainte.

## Conventions Vue 2

- **Options API obligatoire** (Vue 2.7 max — Composition API présente mais on s'aligne avec Pterodactyl qui utilise Options).
- Pas de TypeScript dans `pterodactyl/` (le panel est en JS).
- `data()` retourne un objet, `computed` pour le dérivé, `methods` pour les actions, `watch` pour les effets.
- Vuex pour le state global (catalogue paginé, server context, policies de l'admin).
- Composables → impossible en Options API, factoriser en mixins ou helpers JS purs.
- Tests : Vitest + `@vue/test-utils@1` (compat Vue 2).

## Conventions Livewire

- **Sérialisation** : seuls les types primitifs + Eloquent Model + `Livewire\Wireable` peuvent être propriétés publiques. Pas d'objets PHP arbitraires.
- **Alpine.js** pour les interactions purement front (modales, tooltips, transitions). Pas de fetch dans Alpine — laisse Livewire piloter.
- **Wire methods** suffixe dans le DOM : `wire:click="install"` (pas `install()`).
- **Loading states** : `wire:loading.attr="disabled"` ou `wire:loading.class="opacity-50"`.
- **Validation** : règles dans `protected $rules`, validation lazy avec `$this->validateOnly($propertyName)`.
- Tests : Pest + helpers `livewire/livewire`.

## i18n

- **Toutes** les strings UI passent par i18n. **Aucune** string hardcodée.
- Langues v1 : **FR** + **EN**. Fichiers : `src/shared/i18n/fr.json`, `src/shared/i18n/en.json`.
- Côté Vue : import du JSON via `vue-i18n@8` (compat Vue 2).
- Côté Livewire : Laravel `__()` natif → `resources/lang/<locale>/pmcp.php`.
- Lint check : ajouter un script `scripts/check-i18n.sh` qui grep des patterns suspects (strings entre quotes dans templates Vue/Blade) et fail la CI si trouvé.

## Accessibilité

- Cible : **WCAG 2.2 AA** minimum.
- **Navigation clavier** sur tous les éléments interactifs (test : naviguer le module entier au Tab + Enter).
- **Roles ARIA** corrects : `dialog` pour les modales, `tablist`/`tab`/`tabpanel` pour les onglets, etc.
- **Focus traps** dans les modales (libs : `focus-trap` côté Vue, Alpine `x-trap` côté Livewire).
- Contraste de couleurs : ratio ≥ 4.5:1 sur les textes normaux, ≥ 3:1 sur les gros textes.
- Toujours invoquer `ui-visual-validator` (subagent) après un changement frontend non-trivial.
- Pour les audits poussés, utiliser la skill `scan-and-fix-accessibility` (BrowserStack MCP).

## Quand utiliser `21st-dev/magic` ?

✅ **Oui** :
- Scaffolding initial d'un composant non-trivial (modal d'install, card plugin, table de versions)
- Recherche d'inspiration pour un layout

❌ **Jamais** :
- Pour des composants qui touchent aux APIs internes du panel hôte (Vuex stores Pterodactyl, hooks Pelican)
- Pour la logique métier (compat check, etc.)

Toujours **adapter** le code généré aux conventions ci-dessus avant commit.
