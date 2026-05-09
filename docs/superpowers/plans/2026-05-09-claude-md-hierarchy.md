# `CLAUDE.md` Hierarchy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produire la hiérarchie complète de fichiers `CLAUDE.md` + `docs/` qui transformera l'agent en dev senior Pterodactyl/Minecraft pour le projet `pterodactyl-mc-plugins` (Blueprint extension).

**Architecture:** Hiérarchie modulaire à 3 niveaux : (1) `CLAUDE.md` racine toujours chargé qui pose l'identité, le routage subagents/MCPs, le workflow et les antipatterns ; (2) sous-`CLAUDE.md` par couche (`backend/`, `providers/`, `frontend/`) chargés à la demande ; (3) `docs/*.md` descriptifs lus à la demande pour les détails (architecture, primers, conventions).

**Tech Stack:** Markdown pur. Cibles documentaires : Pterodactyl Panel 1.11+ / Pelican 1.0+, Blueprint, Laravel 10/11, PHP 8.2+, Vue 2.7, Livewire 3, Modrinth & CurseForge APIs.

**Spec source:** `docs/superpowers/specs/2026-05-09-pterodactyl-mc-plugins-claude-md-design.md`

---

## File Map (récap du spec § 3.1)

| Path | Rôle | Lignes cible |
|---|---|---|
| `CLAUDE.md` | Racine, toujours chargé | ~300 |
| `LICENSE` | MIT | ~21 |
| `conf.yml` | Manifest Blueprint (stub minimal) | ~30 |
| `.gitignore` | Standard PHP/Node | ~30 |
| `README.md` | Public-facing | ~150 |
| `src/backend/CLAUDE.md` | Conventions backend | ~120 |
| `src/providers/CLAUDE.md` | Interface provider, rate-limit, cache | ~150 |
| `src/frontend/CLAUDE.md` | Vue 2 vs Livewire | ~100 |
| `docs/CONTEXT.md` | Personas, glossaire MC | ~150 |
| `docs/ARCHITECTURE.md` | Diagrammes mermaid, data flow | ~250 |
| `docs/CONVENTIONS.md` | PHP/Vue/Livewire/SQL | ~200 |
| `docs/PROVIDERS.md` | Spec interface, Modrinth, CurseForge | ~200 |
| `docs/PTERODACTYL-PRIMER.md` | Blueprint, Wings, perms | ~250 |
| `docs/MINECRAFT-PRIMER.md` | Formats, paths, loaders | ~200 |
| `docs/TESTING.md` | Pest + VCR + Playwright | ~150 |
| `docs/ROADMAP.md` | Features hors v1 | ~100 |

**Total** : ~16 fichiers, ~2400 lignes.

---

## Task 1 : Scaffolding (squelette projet + git hygiene)

**Files:**
- Create: `.gitignore`
- Create: `LICENSE`
- Create: `conf.yml`
- Create: `src/backend/.gitkeep`, `src/providers/.gitkeep`, `src/frontend/.gitkeep`, `src/shared/i18n/.gitkeep`
- Create: `tests/Unit/.gitkeep`, `tests/Integration/.gitkeep`, `tests/E2E/.gitkeep`
- Create: `.github/workflows/.gitkeep`

- [ ] **Step 1.1 : Créer `.gitignore`**

```gitignore
# PHP / Composer
/vendor/
composer.lock

# Node / npm
/node_modules/
package-lock.json
yarn.lock

# Laravel
.env
.env.*
!.env.example
/storage/*.key
/storage/logs/*

# Tests
.phpunit.result.cache
/coverage/
.pest/

# Build
/dist/
*.blueprint

# IDE / OS
.idea/
.vscode/
*.swp
.DS_Store
Thumbs.db

# Project-specific
/local-test-panel/
```

- [ ] **Step 1.2 : Créer `LICENSE` (MIT)**

```text
MIT License

Copyright (c) 2026 pterodactyl-mc-plugins contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

- [ ] **Step 1.3 : Créer `conf.yml` (manifest Blueprint stub)**

> Avant d'écrire ce fichier, **invoquer le MCP context7** pour vérifier le format `conf.yml` Blueprint actuel : `resolve-library-id "blueprint"` puis `get-library-docs` sur la section "Manifest" / "conf.yml". Ajuster la version du schéma si la doc l'exige.

```yaml
info:
  name: "Minecraft Plugins/Addons Manager"
  identifier: "pterodactyl-mc-plugins"
  description: "Browse, install, update, and manage Minecraft plugins/mods/addons from Modrinth and CurseForge directly inside Pterodactyl Panel and Pelican Panel."
  flags: ""
  version: "0.1.0-dev"
  target: "1.11.5"
  author: "TBD"
  icon: ""
  website: ""

admin:
  view: ""
  controller: ""
  css: ""
  wrapper: ""

dashboard:
  components: ""
  css: ""
```

> Note : ce stub est volontairement minimal. Les hooks réels (admin, dashboard, routes) seront ajoutés au fil des features dans le plan d'implémentation du module lui-même.

- [ ] **Step 1.4 : Créer les dossiers vides avec `.gitkeep`**

Run:
```bash
mkdir -p src/backend src/providers src/frontend src/shared/i18n
mkdir -p tests/Unit tests/Integration tests/E2E
mkdir -p .github/workflows
touch src/backend/.gitkeep src/providers/.gitkeep src/frontend/.gitkeep src/shared/i18n/.gitkeep
touch tests/Unit/.gitkeep tests/Integration/.gitkeep tests/E2E/.gitkeep
touch .github/workflows/.gitkeep
```

- [ ] **Step 1.5 : Vérifier l'arborescence**

Run: `find . -type f -not -path './.git/*' | sort`
Expected: liste contenant `.gitignore`, `LICENSE`, `conf.yml`, les `.gitkeep`, et le spec déjà présent dans `docs/superpowers/specs/`.

- [ ] **Step 1.6 : Commit**

```bash
git add .gitignore LICENSE conf.yml src/ tests/ .github/
git commit -m "chore: scaffold project structure with .gitignore, LICENSE, conf.yml stub"
```

---

## Task 2 : `CLAUDE.md` racine (le fichier le plus important)

**Files:**
- Create: `CLAUDE.md`

**Spec reference:** § 4 (plan détaillé § 4.1 à § 4.8)

- [ ] **Step 2.1 : Rédiger `CLAUDE.md` complet**

Le fichier doit contenir EXACTEMENT les 10 sections suivantes, dans cet ordre. Le contenu ci-dessous est définitif — utiliser tel quel.

```markdown
# CLAUDE.md — pterodactyl-mc-plugins

> Ce fichier est TOUJOURS chargé. Garde-le concis (~300 lignes max). Pour les détails, suis les pointeurs vers `docs/*.md` ou les sous-`CLAUDE.md`.

---

## 1. Identité & posture

Tu es un **dev senior Pterodactyl & Minecraft**. Tu connais :
- **Blueprint** à fond (manifest `conf.yml`, hooks admin/dashboard/route, cycle de release `.blueprint`)
- Le **modèle de permissions** Pterodactyl/Pelican (subusers, scopes, server-level perms)
- L'**API Wings** (filesystem, power state, backups, websocket)
- Les **hooks Laravel** (events, queues, jobs, observers, policies)
- Les contraintes de **chaque loader Minecraft** (Bukkit/Spigot/Paper, Forge/NeoForge, Fabric/Quilt, proxies, Bedrock)

Tu écris du **PHP 8.2+ idiomatique**, du **Vue 2 propre** (Options API), du **Livewire 3 propre** (composants stateless quand possible).

Tu es **conservateur sur les changements de scope** : ne jamais ajouter une feature non listée dans le snapshot ci-dessous sans en discuter explicitement.

Tu es **rigoureux sur la sécurité** : uploads, path traversal, exécution arbitraire, leak de secrets, validation côté serveur systématique.

---

## 2. Project snapshot

**Pitch :** Extension Blueprint pour Pterodactyl Panel & Pelican Panel qui permet de browser, installer, mettre à jour et gérer les plugins/mods/addons Minecraft (sources : Modrinth + CurseForge) directement depuis l'onglet de chaque serveur, avec policies admin pour borner les choix.

**Plateformes MC supportées :** Bukkit/Spigot/Paper, Forge/NeoForge, Fabric/Quilt, proxies Velocity/Bungee/Waterfall, Bedrock (.mcaddon/.mcpack).

**Sources v1 :** Modrinth + CurseForge.

**Features MVP v1.0 (11) :**
1. Browse/search catalogue
2. Install 1-clic
3. Update detection + changelog
4. Scheduled auto-updates (cron) avec backup
5. Version pinning
6. Dependency resolution
7. Compatibility check (MC version + loader)
8. Backup avant change
9. Rollback 1-clic
10. Config files management (YAML/TOML/JSON)
11. Presets/modpacks réutilisables

**Hors scope v1 (cf. `docs/ROADMAP.md`) :** conflict detection, bulk ops admin, CVE scan, audit log, sources additionnelles (SpigotMC, Hangar, MCPEDL, GitHub releases, manual upload, URL install).

**UX :** onglet par serveur (utilisateurs) + page admin pour les policies (whitelist/blacklist).

---

## 3. Stack technique

| Couche | Tech | Versions |
|---|---|---|
| Panel hôte | Pterodactyl 1.11+ / Pelican 1.0+ | les deux |
| Backend | PHP / Laravel | 8.2+ / 10.x ou 11.x |
| Frontend Pterodactyl | Vue 2 (Options API) + Vuex | 2.7 |
| Frontend Pelican | Livewire + Alpine.js | 3.x / 3.x |
| DB | MariaDB / MySQL | comme le panel hôte |
| Queue | Redis | comme le panel hôte |
| Tests | Pest + Playwright | 3.x / 1.x |
| Lint/Format | PHPStan + Laravel Pint + ESLint + Prettier | latest |
| HTTP client | Guzzle (via Laravel HTTP) | builtin Laravel |
| HTTP cache | Redis (clé par hash de requête) | — |

**Toujours invoquer `context7` AVANT** d'utiliser une API Pterodactyl, Blueprint, Laravel, Vue ou Livewire dont tu n'es pas 100 % sûr de la version actuelle. Voir § 6.

---

## 4. Référentiel docs

| Tu vas toucher à... | Lis d'abord |
|---|---|
| Architecture, data flow, schéma DB | `docs/ARCHITECTURE.md` |
| Conventions de code (par langage) | `docs/CONVENTIONS.md` |
| Hooks Blueprint, Wings, perms du panel | `docs/PTERODACTYL-PRIMER.md` |
| Formats, paths, loaders, manifests MC | `docs/MINECRAFT-PRIMER.md` |
| Interface provider, Modrinth, CurseForge | `docs/PROVIDERS.md` |
| Stratégie de tests | `docs/TESTING.md` |
| Personas, glossaire | `docs/CONTEXT.md` |
| Discussion de scope futur | `docs/ROADMAP.md` |

Sous-`CLAUDE.md` (chargés automatiquement quand tu édites le dossier) :
- `src/backend/CLAUDE.md` — conventions Laravel, queues, sécurité Wings
- `src/providers/CLAUDE.md` — interface `PluginProvider`, cache, rate-limit
- `src/frontend/CLAUDE.md` — Vue 2 vs Livewire, i18n, accessibilité

---

## 5. Routage subagents

| Tâche | Subagent | Quand l'invoquer |
|---|---|---|
| Concevoir une route API ou un job de queue | `backend-architect` | Toute nouvelle endpoint ou job |
| Concevoir/migrer un schéma DB | `database-architect` | Toute migration |
| Créer un composant UI Vue/Livewire | `frontend-developer` | Tout composant non-trivial |
| Review pré-commit | `code-reviewer` | **Systématique** avant `git commit` |
| Review sécurité (upload, path, exécution shell) | `security-auditor` | Touche au filesystem Wings ou exécution |
| Comprendre un coin du code Blueprint/Pterodactyl | `code-explorer` | Avant de modifier un hook ou pattern existant |
| Plan d'archi multi-couches | `code-architect` | Avant tout chantier > 3 fichiers |
| TDD strict sur logique métier | `tdd-orchestrator` | Compat check, dep resolution, version compare, rollback, parser de manifests |
| Bug complexe avec ≥ 2 hypothèses concurrentes | `team-debugger` | Bug non-trivial |
| Doc API (OpenAPI) | `api-documenter` | Routes publiques du module |
| README, install guide, contributing | `docs-architect` | Docs longues |
| Vérification visuelle UI | `ui-visual-validator` | Après tout changement frontend |
| Recherche générique dans la base de code | `explore` | Quand le code à trouver n'est pas évident |

**Règle d'or :** si tu hésites entre deux subagents, invoque `code-architect` qui te dira lequel utiliser.

---

## 6. Routage MCPs

| Besoin | MCP | Exemple concret |
|---|---|---|
| Doc à jour Pterodactyl/Blueprint/Laravel/Vue/Livewire | **`context7`** | `resolve-library-id "blueprint"` puis `get-library-docs` AVANT tout code utilisant un hook |
| Issues / PRs / releases du repo et des dépendances | **`github`** | Suivre les releases Modrinth/CurseForge pour adapter les providers |
| Debug UI dans le Panel (network, perf, console) | **`chrome-devtools-mcp`** | Tracer les calls Modrinth depuis l'onglet serveur |
| Tester l'UI manuellement dans une instance locale | **`cursor-ide-browser`** | Naviguer dans une instance Pterodactyl/Pelican locale après changement frontend |
| Scaffolder rapidement un composant UI | **`21st-dev/magic`** | Modal d'install, card plugin, table de versions |
| Mémoire cross-session (décisions d'archi) | **`claude-mem`** | Rappeler les choix de design entre sessions |
| Tests cross-browser (optionnel) | **`browserstack`** | Compat double-Panel sur navigateurs cibles |

**`context7` est OBLIGATOIRE** avant d'utiliser une API que tu connais peut-être mais dont la version a pu changer. Ne jamais halluciner une API Blueprint ou Pterodactyl.

---

## 7. Workflow obligatoire

**TDD obligatoire** sur la logique métier critique :
- Compatibility check (MC version + loader)
- Dependency resolution
- Version compare (semver-like avec extensions MC)
- Rollback (snapshot/restore)
- Parsers de manifests : `plugin.yml` (Bukkit), `mcmod.info`/`fabric.mod.json`/`META-INF/neoforge.mods.toml` (mods Java), `manifest.json` (Bedrock)

**Pragmatique** sur :
- UI components (mais avec snapshot tests + accessibility checks)
- Glue code (controllers, simple services)
- Intégrations API externes (mais avec **VCR** pour rejouer les réponses HTTP en test)

**Code review systématique** : invoquer `code-reviewer` AVANT chaque `git commit`. Pas d'exception.

**Commits** : Conventional Commits.
- Préfixes : `feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`, `perf:`, `style:`
- Scope optionnel : `feat(providers): add Modrinth search endpoint`
- Body explique le **pourquoi**, pas le quoi.

**Branches** : `feat/<short-description>`, `fix/<short-description>`, `chore/<short-description>`, `docs/<short-description>`.

**Avant tout chantier > 1 fichier** : invoquer la skill `writing-plans` pour produire un plan d'exécution. Pas de coding cowboy.

---

## 8. Antipatterns critiques (à NE JAMAIS faire)

1. **Filesystem direct** : ne jamais écrire dans le filesystem du serveur de jeu. **Toujours via l'API Wings** (qui valide les chemins, gère les perms et les volumes Docker).
2. **Path traversal** : tout chemin venant d'un user (URL, body, config) doit être normalisé ET ancré au volume du serveur. Refuser tout `..`, `~`, chemins absolus, symlinks suspects.
3. **Trust aveugle des sources externes** : les métadonnées Modrinth/CurseForge brutes ne sont JAMAIS persistées telles quelles. Toujours valider/sanitiser AVANT insert DB. Mapper vers le DTO `NormalizedPlugin`.
4. **Exécution shell sur les `.jar`** : aucun `Process::run`, aucun `shell_exec`, aucun `proc_open` côté Panel pour lancer un binaire MC. C'est le boulot de Wings (qui orchestre Docker).
5. **Rate limits ignorés** : Modrinth = 300 req/min (token bucket Redis obligatoire). CurseForge = clé API + quotas (lire `CURSEFORGE_API_KEY` depuis `.env`, jamais en dur). Degradation gracieuse si quota dépassé (afficher un warning UI, ne pas crasher).
6. **Compat cassée entre Panels** : ne jamais introduire un changement frontend qui casse Vue 2 (Pterodactyl) en travaillant sur Pelican (Livewire), et inversement. Toujours tester les deux.
7. **Paths Minecraft hardcodés** : la cible diffère entre Java (`/plugins/`, `/mods/`, `/config/`, `/world/datapacks/`) et Bedrock (`/worlds/<world>/behavior_packs/`, `/worlds/<world>/resource_packs/`). Toujours passer par un service `PathResolver` qui prend en argument le loader.
8. **Reload/restart oublié** : selon le loader/plugin, l'install nécessite un restart serveur, un reload, ou rien. Documenter par plugin et avertir l'user.
9. **Leak de secrets** : la clé API CurseForge ne doit JAMAIS atteindre le frontend. Tous les calls partent du backend uniquement.
10. **Confusion des versions** : version Minecraft (1.20.4) ≠ version loader (Forge 49.0.x) ≠ version plugin/mod. Le DTO `NormalizedPlugin` distingue les trois.
11. **Cache invalidation oubliée** : si tu changes le mapping `NormalizedPlugin` ou le format en DB, prévoir une commande artisan `php artisan pmcp:cache:flush`.
12. **i18n oubliée** : aucune string UI hardcodée. Tout passe par i18n. FR + EN minimum.

---

## 9. Quick wins / commandes utiles

```bash
# Tests
composer test                # Pest unit + integration
composer test:e2e            # Playwright E2E (nécessite instance locale)
composer test:coverage       # Avec coverage HTML

# Lint / format
composer lint                # PHPStan + Pint dry-run
composer format              # Pint apply
npm run lint                 # ESLint frontend
npm run format               # Prettier frontend

# Blueprint
blueprint -i pterodactyl-mc-plugins   # Install dans une instance locale
blueprint -r pterodactyl-mc-plugins   # Remove
blueprint -f pterodactyl-mc-plugins   # Reinstall (force)

# Cache (artisan custom)
php artisan pmcp:cache:flush          # Vider le cache des providers
php artisan pmcp:scheduled-updates    # Forcer la passe scheduled (debug)

# Build
npm run build                # Build frontend assets
./scripts/package.sh         # Génère .blueprint pour release
```

---

## 10. Maintenance de ce fichier

Mets à jour ce `CLAUDE.md` (racine OU sous-fichier approprié) **dans le même commit** quand tu :
- Ajoutes une feature majeure → § 2 Project snapshot
- Changes la stack ou des versions → § 3
- Ajoutes/retires un subagent ou MCP → § 5 ou § 6
- Découvres un antipattern qui t'a fait perdre du temps → § 8
- Ajoutes/refactorises un dossier → File Map et § 4

Si une règle dans `CLAUDE.md` devient fausse, ne la laisse PAS pourrir : édite-la ou retire-la. **Une règle obsolète est pire que pas de règle.**
```

- [ ] **Step 2.2 : Vérifier le compte de lignes**

Run: `wc -l CLAUDE.md`
Expected: entre 270 et 330 lignes (cible ~300).

- [ ] **Step 2.3 : Vérifier la cohérence avec le spec**

Vérifications mentales :
- Les 10 sections du spec § 4.1 sont présentes dans le bon ordre
- Les 13 lignes de la matrice subagents (spec § 4.4) sont présentes
- Les 7 lignes de la matrice MCPs (spec § 4.5) sont présentes
- Les 10+ antipatterns (spec § 4.7) sont présents (ici : 12)
- Le tableau stack (spec § 4.3) est présent

- [ ] **Step 2.4 : Commit**

```bash
git add CLAUDE.md
git commit -m "docs: add root CLAUDE.md with identity, routing matrices, and antipatterns"
```

---

## Task 3 : `src/backend/CLAUDE.md`

**Files:**
- Create: `src/backend/CLAUDE.md`

**Spec reference:** § 5.1

- [ ] **Step 3.1 : Rédiger le sous-`CLAUDE.md` backend (~120 lignes)**

```markdown
# CLAUDE.md — src/backend/

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
```

- [ ] **Step 3.2 : Vérifier le compte de lignes**

Run: `wc -l src/backend/CLAUDE.md`
Expected: entre 100 et 140 lignes (cible ~120).

- [ ] **Step 3.3 : Commit**

```bash
git add src/backend/CLAUDE.md
git commit -m "docs(backend): add sub-CLAUDE.md with Laravel/queue/Wings/migrations conventions"
```

---

## Task 4 : `src/providers/CLAUDE.md`

**Files:**
- Create: `src/providers/CLAUDE.md`

**Spec reference:** § 5.2

- [ ] **Step 4.1 : Rédiger le sous-`CLAUDE.md` providers (~150 lignes)**

```markdown
# CLAUDE.md — src/providers/

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

Toujours envoyer un User-Agent identifiant : `pterodactyl-mc-plugins/<version> (+https://github.com/<org>/pterodactyl-mc-plugins)`.

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
```

- [ ] **Step 4.2 : Vérifier le compte de lignes**

Run: `wc -l src/providers/CLAUDE.md`
Expected: entre 130 et 170 lignes (cible ~150).

- [ ] **Step 4.3 : Commit**

```bash
git add src/providers/CLAUDE.md
git commit -m "docs(providers): add sub-CLAUDE.md with PluginProvider contract, cache, rate-limit"
```

---

## Task 5 : `src/frontend/CLAUDE.md`

**Files:**
- Create: `src/frontend/CLAUDE.md`

**Spec reference:** § 5.3

- [ ] **Step 5.1 : Rédiger le sous-`CLAUDE.md` frontend (~100 lignes)**

```markdown
# CLAUDE.md — src/frontend/

> Sous-`CLAUDE.md` chargé automatiquement quand tu édites un fichier dans `src/frontend/`. Pour les règles globales, voir `/CLAUDE.md`.

## Périmètre

UI du module pour les **deux Panels** :
- `src/frontend/pterodactyl/` → Vue 2.7 + Vuex (pour Pterodactyl Panel original)
- `src/frontend/pelican/` → Livewire 3 + Alpine.js (pour Pelican Panel)
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

- **Options API obligatoire** (Vue 2.7 max — Composition API API présente mais on s'aligne avec Pterodactyl qui utilise Options).
- Pas de TypeScript dans `pterodactyl/` (le panel est en JS).
- `data()` retourne un objet, `computed` pour le dérivé, `methods` pour les actions, `watch` pour les effets.
- Vuex pour le state global (catalogue paginé, server context, policies de l'admin).
- Composables → impossible en Options API, factoriser en mixins ou helpers JS purs.
- Tests : Vitest + `@vue/test-utils@1` (compat Vue 2).

## Conventions Livewire

- **Sérialisation** : seuls les types primitifs + Eloquent Model + `Livewire\Wireable` peuvent être propriétés publiques. Pas d'objets PHP arbitraires.
- **Alpine.js** pour les interactions purement front (modales, tooltips, transitions). Pas de fetch dans Alpine — laisse Livewire piloter.
- **Wire methods** suffixe `()` dans le DOM : `wire:click="install"` (pas `install()`).
- **Loading states** : `wire:loading.attr="disabled"` ou `wire:loading.class="opacity-50"`.
- **Validation** : règles dans `protected $rules`, validation lazy avec `$this->validateOnly($propertyName)`.
- Tests : Pest + `livewire/livewire` test helpers.

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
```

- [ ] **Step 5.2 : Vérifier le compte de lignes**

Run: `wc -l src/frontend/CLAUDE.md`
Expected: entre 90 et 130 lignes (cible ~100).

- [ ] **Step 5.3 : Commit**

```bash
git add src/frontend/CLAUDE.md
git commit -m "docs(frontend): add sub-CLAUDE.md with Vue/Livewire/i18n/a11y conventions"
```

---

## Task 6 : `docs/CONTEXT.md`

**Files:**
- Create: `docs/CONTEXT.md`

**Spec reference:** § 6 (table doc) + § 1.1, 1.2, 1.6 (matière première)

- [ ] **Step 6.1 : Rédiger `docs/CONTEXT.md` (~150 lignes)**

Structure obligatoire (sections H2 dans cet ordre) :

1. `## Pitch` — repris du spec § 1.1
2. `## Personas` — détaillés (3 personas du spec § 1.2 + un 4e : intégrateur tiers d'un futur provider)
3. `## User journeys clés` — 5 scénarios end-to-end :
   - Install d'un plugin Paper depuis l'onglet serveur
   - Setup d'auto-update hebdo avec rollback automatique en cas de crash
   - Création et application d'un preset (5 plugins) sur 3 serveurs
   - Admin restreint Modrinth aux plugins « approved by category » et bloque les mods qui requièrent NMS
   - Install d'un addon Bedrock sur un serveur BDS
4. `## Glossaire Minecraft` — toutes les notions à connaître :
   - **Loader** : Bukkit, Spigot, Paper, Purpur, Forge, NeoForge, Fabric, Quilt, Velocity, BungeeCord, Waterfall, BDS (Bedrock Dedicated Server), PocketMine
   - **Plugin** vs **Mod** vs **Addon** vs **Datapack** vs **Resource Pack** vs **Behavior Pack** vs **Modpack**
   - **Manifest** : `plugin.yml`, `bungee.yml`, `velocity-plugin.json`, `mcmod.info` (legacy Forge), `META-INF/mods.toml` (Forge moderne), `fabric.mod.json`, `quilt.mod.json`, `manifest.json` (Bedrock)
   - **Version MC** : 1.20.4, 1.21, snapshot 24w14a, etc. — avec ranges (`>=1.20 <1.21`)
   - **Version loader** : ex. Forge 49.0.x ≠ MC 1.20.4
   - **NMS** (`net.minecraft.server`) : pourquoi c'est dangereux pour la compat
5. `## Use cases administrateur` — pourquoi les policies, exemples concrets
6. `## Anti-cas (ce qu'on NE fait PAS dans ce module)` — synthèse du spec § 1.5

> Pour rédiger les sections « Glossaire Minecraft » et « Loader », **invoquer `context7`** sur les libs concernées (`paper-api`, `fabric-loader`, `forge-modloader`) pour vérifier que les noms de manifests sont à jour. Pour les concepts Bedrock, fetch la page officielle Microsoft Learn « Bedrock Dedicated Server documentation ».

- [ ] **Step 6.2 : Vérifier compte de lignes**

Run: `wc -l docs/CONTEXT.md`
Expected: entre 130 et 180 lignes (cible ~150).

- [ ] **Step 6.3 : Commit**

```bash
git add docs/CONTEXT.md
git commit -m "docs: add CONTEXT.md with personas, user journeys, and Minecraft glossary"
```

---

## Task 7 : `docs/ARCHITECTURE.md`

**Files:**
- Create: `docs/ARCHITECTURE.md`

**Spec reference:** § 6 (table doc)

- [ ] **Step 7.1 : Rédiger `docs/ARCHITECTURE.md` (~250 lignes)**

Structure obligatoire :

1. `## Vue d'ensemble` — schéma des couches en mermaid `flowchart TB` :
   ```
   User browser
     └─> Panel hôte (Pterodactyl/Pelican)
           ├─> Frontend module (Vue/Livewire)
           └─> Backend module (Laravel)
                 ├─> Providers (Modrinth, CurseForge)
                 ├─> Wings API (file ops, power)
                 ├─> Queue (Redis)
                 └─> DB (MariaDB, tables pmcp_*)
   ```

2. `## Data flow : install d'un plugin` — séquence mermaid `sequenceDiagram` (User → Frontend → Controller → ProviderService → Modrinth → BackupJob → InstallJob → Wings → Notification).

3. `## Data flow : scheduled update` — séquence mermaid (Cron → ApplyScheduledUpdatesJob → loop sur InstalledPlugins → CheckPluginUpdatesJob → si update → BackupJob → UpdatePluginJob → Wings → si crash detected → RollbackPluginJob).

4. `## Data flow : rollback` — séquence mermaid.

5. `## Schéma DB` — diagramme mermaid `erDiagram` avec les 8 tables `pmcp_*` (cf. `src/backend/CLAUDE.md` § Migrations) et leurs relations.

6. `## Architecture Providers` — flowchart mermaid montrant l'abstraction (Service → PluginProvider interface → [Modrinth | CurseForge] → Cache HTTP Redis → API).

7. `## Couche d'abstraction frontend (double Panel)` — explique le pattern : services backend factorisent, frontend dupliqué.

8. `## Stratégie de déploiement` — comment le `.blueprint` est packagé, où il s'installe sur Pterodactyl/Pelican.

9. `## Décisions architecturales (ADRs courts)` — section listant les choix clés et leur justification :
   - ADR-001 : Pourquoi Blueprint plutôt que fork (≤ 5 lignes)
   - ADR-002 : Pourquoi DTO normalisé (≤ 5 lignes)
   - ADR-003 : Pourquoi pas de FK vers tables panel (≤ 5 lignes)
   - ADR-004 : Pourquoi double-Panel dès v1 (≤ 5 lignes)
   - ADR-005 : Pourquoi Redis pour cache et queue (≤ 5 lignes)

> Tous les diagrammes en **Mermaid** (rendu natif GitHub + Obsidian). Pas d'images binaires.
> Pour Mermaid, **invoquer le subagent `mermaid-expert`** sur chaque diagramme non-trivial pour validation syntaxique.

- [ ] **Step 7.2 : Vérifier compte de lignes**

Run: `wc -l docs/ARCHITECTURE.md`
Expected: entre 220 et 290 lignes (cible ~250).

- [ ] **Step 7.3 : Vérifier syntaxe Mermaid**

Run: `npx -y @mermaid-js/mermaid-cli@latest -i docs/ARCHITECTURE.md -o /tmp/test.svg --quiet 2>&1 | head -20`
Expected: pas d'erreur de syntaxe (ignorer les warnings de version).

> Si Mermaid CLI indisponible, sauter cette vérif et invoquer le subagent `mermaid-expert`.

- [ ] **Step 7.4 : Commit**

```bash
git add docs/ARCHITECTURE.md
git commit -m "docs: add ARCHITECTURE.md with mermaid diagrams (data flow, ER, providers)"
```

---

## Task 8 : `docs/PTERODACTYL-PRIMER.md`

**Files:**
- Create: `docs/PTERODACTYL-PRIMER.md`

**Spec reference:** § 6 (table doc)

- [ ] **Step 8.1 : Rédiger `docs/PTERODACTYL-PRIMER.md` (~250 lignes)**

> **Préalable obligatoire :** invoquer `context7` sur :
> - `pterodactyl/panel` → comprendre `Pterodactyl\Models\Server`, `Pterodactyl\Services\…`, modèle de perms, API Wings côté Panel
> - `pelican-panel/panel` → équivalents Pelican
> - `BlueprintFramework/framework` → liste des hooks, format `conf.yml`, lifecycle d'install/uninstall
>
> Si `context7` ne trouve pas une lib, fallback sur le subagent `code-explorer` lancé sur un clone local.

Structure obligatoire :

1. `## Blueprint en 5 minutes` — c'est quoi, pourquoi, où ça s'installe (chemins exacts dans Pterodactyl et Pelican).

2. `## Format conf.yml` — toutes les clés disponibles avec exemples : `info`, `admin`, `dashboard`, `data`, `requests`, `database`, `console`. Synthèse, pas exhaustive.

3. `## Hooks que ce module utilise`
   - `dashboard.components` → injection de l'onglet serveur
   - `admin.view` + `admin.controller` → page admin policies
   - `requests.routers.application` → routes API du module
   - `data.directory` → où on stocke l'état persistant côté disk (backups index)
   - `console.commands` → commandes artisan custom (`pmcp:*`)

4. `## API Wings utile au module`
   - `GET /api/servers/<uuid>/files/list?directory=…`
   - `POST /api/servers/<uuid>/files/upload?directory=…` (multipart)
   - `POST /api/servers/<uuid>/files/write?file=…` (raw body)
   - `POST /api/servers/<uuid>/files/delete` (body : `{"root": "…", "files": ["…"]}`)
   - `POST /api/servers/<uuid>/files/compress`
   - `POST /api/servers/<uuid>/files/decompress`
   - `POST /api/servers/<uuid>/power` (start/stop/restart)
   - WebSocket pour console output (utile pour détecter un crash post-install)

   Pour chaque endpoint : **method, path, body schema, response schema**, en se référant à `context7` (lib `pterodactyl/wings`).

5. `## Modèle de permissions`
   - **Pterodactyl** : permission strings (`server.files.read`, `server.files.write`, …). Le module définit ses propres : `pmcp.plugins.read`, `pmcp.plugins.install`, `pmcp.plugins.update`, `pmcp.plugins.uninstall`, `pmcp.presets.manage`, `pmcp.policies.manage` (admin only).
   - **Pelican** : équivalent Filament policies. Mapping 1-to-1 avec les permissions Pterodactyl quand possible.
   - Subusers : héritent les permissions de leur invitation. Le module respecte cela.

6. `## Cycle de vie d'un serveur` — les états (installing, suspended, running, stopped) et l'impact sur les opérations du module (ex : interdire l'install pendant `installing`).

7. `## Quand demander un restart` — règles métier :
   - Java plugin (Bukkit/Spigot/Paper) → `reload` ou `plugman load` (déconseillé) OU restart (recommandé)
   - Java mod (Forge/Fabric) → restart obligatoire
   - Bedrock addon → reload du monde (commande BDS)
   - Datapack → `/reload` console côté server

8. `## Différences Pterodactyl vs Pelican qui nous touchent`
   - Frontend : Vue 2 vs Livewire 3
   - Filament admin (Pelican) ≠ Blade admin (Pterodactyl)
   - Endpoints API du panel : la plupart compat, mais quelques renommages → check `context7`
   - Versions de PHP / Laravel supportées (cf. `CLAUDE.md` § 3)

- [ ] **Step 8.2 : Vérifier compte de lignes**

Run: `wc -l docs/PTERODACTYL-PRIMER.md`
Expected: entre 220 et 290 lignes (cible ~250).

- [ ] **Step 8.3 : Commit**

```bash
git add docs/PTERODACTYL-PRIMER.md
git commit -m "docs: add PTERODACTYL-PRIMER.md with Blueprint hooks, Wings API, perms model"
```

---

## Task 9 : `docs/MINECRAFT-PRIMER.md`

**Files:**
- Create: `docs/MINECRAFT-PRIMER.md`

**Spec reference:** § 6 (table doc)

- [ ] **Step 9.1 : Rédiger `docs/MINECRAFT-PRIMER.md` (~200 lignes)**

> **Préalable** : invoquer `context7` sur `paper-api`, `fabric-loader`, `forge-modloader`, `velocityctl`. Pour Bedrock, fetch « Microsoft Learn / Bedrock Dedicated Server » via WebFetch si nécessaire.

Structure obligatoire :

1. `## Loaders Java (table récap)` — colonnes : Loader, Path d'install (relatif à `/`), Format manifest, Compat MC, Particularités.
   ```
   | Loader   | Path        | Manifest               | Notes                                           |
   |----------|-------------|------------------------|-------------------------------------------------|
   | Bukkit   | /plugins/   | plugin.yml             | Quasi mort, base pour Spigot/Paper             |
   | Spigot   | /plugins/   | plugin.yml             | Fork Bukkit, performances                       |
   | Paper    | /plugins/   | plugin.yml + paper-plugin.yml? | Fork Spigot, async events, API moderne |
   | Purpur   | /plugins/   | plugin.yml             | Fork Paper, options gameplay                    |
   | Velocity | /plugins/   | velocity-plugin.json   | Proxy moderne, cross-version                    |
   | BungeeCord| /plugins/  | bungee.yml             | Proxy legacy                                    |
   | Waterfall| /plugins/   | bungee.yml             | Fork BungeeCord                                 |
   | Forge    | /mods/      | META-INF/mods.toml (1.13+) ou mcmod.info (legacy) | Coremods possibles |
   | NeoForge | /mods/      | META-INF/neoforge.mods.toml | Fork Forge depuis 1.20.x                |
   | Fabric   | /mods/      | fabric.mod.json        | Léger, intrusif via mixins                      |
   | Quilt    | /mods/      | quilt.mod.json + fabric.mod.json | Fork Fabric                          |
   ```

2. `## Bedrock` — Behavior packs vs Resource packs :
   - Path : `/worlds/<world_name>/behavior_packs/<uuid_version>/manifest.json` et `.../resource_packs/...`
   - Format `manifest.json` : `format_version: 2`, `header.uuid`, `header.version` (semver array), `modules`, `dependencies`
   - Activation : modifier `world_behavior_packs.json` et `world_resource_packs.json` à la racine du monde
   - `.mcaddon` = ZIP contenant un ou plusieurs `.mcpack` (BP + RP combinés)
   - `.mcpack` = ZIP avec `manifest.json` à la racine

3. `## PocketMine-MP (Bedrock alternatif)` — `/plugins/`, format `.phar`, `plugin.yml` PocketMine-spec.

4. `## Datapacks vanilla` — `/world/datapacks/<name>/data/...`, `pack.mcmeta` à la racine.

5. `## Versions Minecraft` — semver-like mais avec quirks :
   - Format : `<major>.<minor>[.<patch>]` ex. `1.20.4`
   - Snapshots : `<year>w<week><letter>` ex. `24w14a`
   - Pre-releases : `1.21-pre1`
   - Release candidates : `1.21-rc1`
   - Comparaison ≠ semver standard (parser custom requis dans `src/backend/Services/McVersion.php`)

6. `## Compat plugin/mod ↔ MC version` — règles :
   - Bukkit/Spigot/Paper : déclaré dans `plugin.yml` via `api-version: "1.20"` (déclaration de l'API supportée, peut tourner sur versions ultérieures sauf NMS break).
   - Forge : déclaré dans `META-INF/mods.toml` via `loaderVersion` + `dependencies.minecraft.versionRange`.
   - Fabric : `fabric.mod.json` → `depends.minecraft`.
   - **Range matching** : la lib backend doit gérer `[1.20,1.21)` (interval notation Maven).

7. `## Chargement / déchargement à chaud`
   - Bukkit/Paper : `reload confirm` est dangereux (memory leaks, plugins corrompus). On préconise restart.
   - Paper : il existe `paper-plugin.yml` qui supporte un loading lifecycle plus propre. Vérifier si le plugin l'utilise.
   - Forge / Fabric : impossible. Restart obligatoire.
   - Bedrock : reload du monde (commande `/reload` en console BDS).
   - Datapacks : `/reload` ingame.

8. `## Antipatterns spécifiques au parsing`
   - Ne pas trust le `name` du plugin pour identifier (les forks réutilisent le nom). Utiliser `(source, external_id)`.
   - `plugin.yml` peut être absent ou malformé : prévoir fallback.
   - Forge `mods.toml` est en TOML mais pas en TOML strict (Forge a son parser custom).
   - Fabric peut avoir des dépendances soft (`recommends`) à ne pas auto-installer.
   - Bedrock `manifest.json` `dependencies` réfèrent par UUID — pas par nom.

- [ ] **Step 9.2 : Vérifier compte de lignes**

Run: `wc -l docs/MINECRAFT-PRIMER.md`
Expected: entre 180 et 230 lignes (cible ~200).

- [ ] **Step 9.3 : Commit**

```bash
git add docs/MINECRAFT-PRIMER.md
git commit -m "docs: add MINECRAFT-PRIMER.md with loaders, paths, manifests, and version semantics"
```

---

## Task 10 : `docs/CONVENTIONS.md`

**Files:**
- Create: `docs/CONVENTIONS.md`

- [ ] **Step 10.1 : Rédiger `docs/CONVENTIONS.md` (~200 lignes)**

> Recouper avec `src/*/CLAUDE.md` : ce fichier consolide et détaille, les sous-`CLAUDE.md` listent les règles courtes.

Structure obligatoire :

1. `## PHP / Laravel`
   - Style : Laravel Pint preset `laravel`, indentation 4 spaces, double-quotes, trailing commas multiline
   - Naming : Classes PascalCase, methods camelCase, constants SCREAMING_SNAKE_CASE
   - PHPStan level 8 minimum, level 9 cible
   - Docblocks uniquement quand le type-hint ne suffit pas (génériques de collections, exceptions)
   - Exceptions : nom finissant par `Exception`, dans `src/backend/Exceptions/`
   - Enums PHP 8.1+ pour les choix finis (`Loader`, `PluginSource`, `InstallationStatus`)

2. `## SQL / Eloquent`
   - Tables snake_case, préfixe `pmcp_`
   - Columns snake_case
   - Indexes : nom explicite `idx_pmcp_<table>_<columns>`
   - FK virtuelles (cf. `src/backend/CLAUDE.md` Migrations) : indexer manuellement
   - Toujours TIMESTAMPS sauf raison documentée
   - Soft deletes là où l'audit/rollback en dépend

3. `## JavaScript / Vue 2`
   - ESLint preset `eslint:recommended` + `plugin:vue/recommended` (vue 2)
   - Prettier, single-quote, semi: false, trailing comma "all"
   - Component naming : PascalCase, fichier = nom du composant
   - Props : objet avec `type`, `required`, `default`, `validator` quand utile
   - Pas d'`any` en JSDoc — types précis ou rien

4. `## Livewire 3 / Alpine`
   - Components : namespacé sous `App\Livewire\Pmcp\…`
   - Pas plus de 5 propriétés publiques par composant — au-delà, factoriser en `Wireable` DTOs
   - Alpine : pas plus de 30 lignes inline ; au-delà, externaliser dans un `x-data` global
   - Tailwind utility-first (preset par défaut Pelican)

5. `## CSS / SCSS`
   - Tailwind utility-first par défaut
   - SCSS uniquement pour des animations complexes ou tokens design réutilisés
   - Variables CSS pour les couleurs / espacements (override possible par le panel hôte)

6. `## Tests`
   - Pest > PHPUnit (sauf si conflit avec une lib Laravel qui exige PHPUnit)
   - Convention de nom : `it('does X', fn () => …)` au lieu de `function test_does_x()`
   - Fixtures dans `tests/Fixtures/`, factories dans `database/factories/`
   - Coverage cible : 80 % global, 95 % sur `src/backend/Services/` (logique métier)

7. `## Git`
   - Branches : `main` (stable), `develop` (intégration), `feat/*`, `fix/*`, `chore/*`, `docs/*`
   - PR : 1 PR = 1 thème ; description avec **Why**, **What**, **How tested**, **Screenshots** (si UI)
   - Commit message : Conventional Commits (cf. `CLAUDE.md` § 7)
   - Squash sur merge dans `main`, merge commits dans `develop` OK

8. `## Logging`
   - Niveau par défaut : `info`. `debug` réservé au dev local.
   - Toujours du contexte structuré : `Log::info('plugin.installed', ['server_id' => …, 'plugin_id' => …, 'version' => …])`
   - Ne JAMAIS log des secrets (clé API CurseForge, tokens user, JWT)

9. `## Documentation inline`
   - Pas de comments narratifs (« cette fonction fait X »)
   - OK pour : ADR refs, gotchas, links vers issues, TODO daté avec auteur

- [ ] **Step 10.2 : Commit**

```bash
git add docs/CONVENTIONS.md
git commit -m "docs: add CONVENTIONS.md (PHP/SQL/JS/Vue/Livewire/CSS/tests/git/logging)"
```

---

## Task 11 : `docs/PROVIDERS.md`

**Files:**
- Create: `docs/PROVIDERS.md`

- [ ] **Step 11.1 : Rédiger `docs/PROVIDERS.md` (~200 lignes)**

Structure obligatoire :

1. `## Spec interface PluginProvider` — reprendre `src/providers/CLAUDE.md` § Interface mais détailler chaque méthode avec contrat précis (préconditions, postconditions, erreurs jetables).

2. `## DTOs normalisés` — schéma exhaustif :
   - `SearchQuery` : `query: string`, `loaders: Loader[]`, `mc_versions: string[]`, `categories: string[]`, `page: int`, `per_page: int`, `sort: enum`
   - `SearchResult` : `items: NormalizedPlugin[]`, `total: int`, `page: int`, `per_page: int`
   - `NormalizedPlugin` : `id: string` (composite `<source>:<external_id>`), `source: PluginSource`, `slug`, `name`, `summary`, `description_html`, `icon_url`, `categories: string[]`, `loaders: Loader[]`, `mc_versions: string[]`, `downloads: int`, `followers: int`, `created_at`, `updated_at`, `license`, `links: { source, issues, wiki, discord }`
   - `Version` : `id: string`, `plugin_id: string`, `version: string`, `mc_versions: string[]`, `loaders: Loader[]`, `dependencies: Dependency[]`, `download_url: string`, `filename: string`, `size_bytes: int`, `sha512: string|null`, `published_at`, `changelog_html`
   - `Dependency` : `kind: enum(required|optional|incompatible|embedded)`, `plugin_id: string|null`, `version_range: string|null`

3. `## Modrinth — particularités`
   - Base URL : `https://api.modrinth.com/v2`
   - Auth : optionnelle (recommandée pour rate limit + features). Header `Authorization: <PAT>`.
   - User-Agent obligatoire (cf. `src/providers/CLAUDE.md`).
   - Endpoints utilisés : `GET /search`, `GET /project/{id|slug}`, `GET /project/{id}/version`, `GET /version/{id}`.
   - Loader → mapping Modrinth : `bukkit`, `spigot`, `paper`, `purpur`, `forge`, `neoforge`, `fabric`, `quilt`, `velocity`, `bungeecord`, `waterfall`, `datapack`. Pas de Bedrock.
   - Hash : sha1 et sha512 fournis pour chaque fichier.

4. `## CurseForge — particularités`
   - Base URL : `https://api.curseforge.com/v1`
   - Auth obligatoire : header `x-api-key: $CURSEFORGE_API_KEY`
   - Game ID Minecraft : 432
   - Class IDs intéressants :
     - Bukkit Plugins : 5
     - Mods : 6
     - Modpacks : 4471
     - Resource Packs : 12
     - Worlds : 17
     - Customization (incl. Bedrock) : 4546
   - Endpoints utilisés : `GET /mods/search`, `GET /mods/{modId}`, `GET /mods/{modId}/files`, `GET /mods/files/{fileId}`.
   - Loader → mapping CurseForge : retours via `gameVersionTypeId` ou `modLoaderType` (à mapper).
   - Hash : `hashes` array avec algo (1 = sha1, 2 = md5).

5. `## Cache HTTP — détails`
   - Implémentation : middleware Guzzle custom (`PMCP\Providers\Http\CacheMiddleware`) qui se branche sur Redis.
   - Stale-while-revalidate : si TTL expiré et upstream down, servir avec header `X-PMCP-Cache: stale`.
   - Bypass via header `X-PMCP-Cache-Bypass: 1` (debug only, désactivé en prod).

6. `## Rate limit — détails`
   - Implémentation : Lua script Redis pour atomicité du token bucket.
   - Clé : `pmcp:rl:<provider>`.
   - Si bucket vide : jeter `RateLimitExceededException` avec `retry_after` calculé.

7. `## Procédure d'ajout d'un nouveau provider`
   - 9 étapes nommées (Class, Mapper, DTOs si extension, config, cassettes, tests, doc, registration, release notes).

> Pour cette doc, **invoquer `context7`** sur `modrinth-api` (s'il existe) et fetcher la doc officielle CurseForge Core API pour s'assurer que les endpoints/IDs sont à jour.

- [ ] **Step 11.2 : Commit**

```bash
git add docs/PROVIDERS.md
git commit -m "docs: add PROVIDERS.md with PluginProvider spec, DTOs, Modrinth/CurseForge specifics"
```

---

## Task 12 : `docs/TESTING.md`

**Files:**
- Create: `docs/TESTING.md`

- [ ] **Step 12.1 : Rédiger `docs/TESTING.md` (~150 lignes)**

Structure :

1. `## Pyramide` — ratio cible : 70 % unit / 20 % integration / 10 % E2E.
2. `## Unit (tests/Unit/)` — Pest, pas de DB, pas de réseau. Cibles : services purs, parsers de manifests, `McVersion`, mappers providers.
3. `## Integration (tests/Integration/)` — DB sqlite in-memory, VCR pour HTTP. Cibles : repositories, providers, jobs.
4. `## E2E (tests/E2E/)` — Playwright contre instance Pterodactyl/Pelican locale (via Docker compose dans `local-test-panel/`). Cibles : flows utilisateur critiques (install, update, rollback).
5. `## VCR setup` — installation `php-vcr` + adapter Guzzle, où sont les cassettes, comment ré-enregistrer.
6. `## Snapshot tests` — pour les mappers, plugin Pest `pest-plugin-snapshots`. Snapshots commités.
7. `## Coverage` — Xdebug + `composer test:coverage`. Cibles : 80 % global, 95 % sur `Services/`.
8. `## CI` — GitHub Actions workflow (matrix Pterodactyl 1.11 + Pelican 1.0, PHP 8.2 + 8.3). Voir `.github/workflows/ci.yml` (à créer dans le plan d'impl module).
9. `## Comment lancer une instance locale` — guide pas-à-pas Docker compose pour Pterodactyl et pour Pelican, avec `blueprint -i` et hot reload.

- [ ] **Step 12.2 : Commit**

```bash
git add docs/TESTING.md
git commit -m "docs: add TESTING.md with Pest/VCR/Playwright strategy and CI matrix"
```

---

## Task 13 : `docs/ROADMAP.md`

**Files:**
- Create: `docs/ROADMAP.md`

- [ ] **Step 13.1 : Rédiger `docs/ROADMAP.md` (~100 lignes)**

Structure :

1. `## v1.0 (MVP)` — rappel des 11 features (cf. spec § 1.4).
2. `## v1.1 (Quick wins post-MVP)` — features hors v1 priorisées :
   - Manual upload (.jar/.mcaddon/.mcpack/.phar)
   - URL install (download depuis URL directe)
   - Conflict detection (analyse des manifests)
3. `## v1.2 (Audit & sécurité)`
   - Audit log
   - Vulnerability/CVE scan (intégration OSV.dev, GitHub Advisory)
4. `## v1.3 (Sources additionnelles)`
   - SpigotMC via Spiget API
   - Hangar (PaperMC officiel)
   - MCPEDL (scraping respectueux pour Bedrock)
5. `## v1.4 (Ops admin)`
   - Bulk operations (admin pousse N plugins sur M serveurs)
   - Templates de policies
6. `## v2.0 (Big features)`
   - GitHub Releases provider générique
   - Marketplace de presets partagés entre admins (opt-in)
7. `## Hors roadmap (rejeté)`
   - Édition de configs avec syntaxe avancée (LSP) — trop coûteux pour le ROI

Chaque item : **objectif** (1 phrase), **dépendances** (autres features), **complexité estimée** (S/M/L/XL).

- [ ] **Step 13.2 : Commit**

```bash
git add docs/ROADMAP.md
git commit -m "docs: add ROADMAP.md (v1.1 → v2.0 with priorities and complexity)"
```

---

## Task 14 : `README.md` (public-facing)

**Files:**
- Create: `README.md`

- [ ] **Step 14.1 : Rédiger `README.md` (~150 lignes)**

Structure :

1. **Header** : nom + badges (CI, license MIT, version, panels supportés)
2. **Pitch en 2 phrases**
3. **Screenshot/GIF placeholder** (à remplacer plus tard)
4. **Features** (liste à puces des 11 features)
5. **Compatibilité**
   - Pterodactyl Panel 1.11+
   - Pelican Panel 1.0+
   - Blueprint <version supportée>
   - PHP 8.2+
6. **Installation** (3 commandes)
   ```bash
   # 1. Télécharger le .blueprint depuis Releases
   wget https://github.com/<org>/pterodactyl-mc-plugins/releases/latest/download/pterodactyl-mc-plugins.blueprint

   # 2. Placer dans le dossier panel
   sudo mv pterodactyl-mc-plugins.blueprint /var/www/pterodactyl/

   # 3. Installer via Blueprint
   sudo blueprint -i pterodactyl-mc-plugins
   ```
7. **Configuration** : variables `.env` (notamment `CURSEFORGE_API_KEY`)
8. **Quick tour** : flèche sur les 3 actions principales (browse, install, update)
9. **FAQ** (5-8 questions courantes) :
   - Pourquoi MIT ?
   - Comment ajouter une source ?
   - Mes plugins sont-ils sauvegardés avant update ?
   - Que se passe-t-il si Modrinth est down ?
   - Compat Bedrock vraiment supportée ?
10. **Contributing** : pointe vers `docs/CONVENTIONS.md`, `CLAUDE.md`, et `docs/TESTING.md`
11. **License** : MIT
12. **Acknowledgements** : Pterodactyl, Pelican, Blueprint, Modrinth, CurseForge

> Le README est public-facing : ton accessible, pas trop technique. Il complète `CLAUDE.md` qui est interne.

- [ ] **Step 14.2 : Commit**

```bash
git add README.md
git commit -m "docs: add public-facing README.md with install, features, and FAQ"
```

---

## Task 15 : Vérification finale & cohérence inter-fichiers

**Files:**
- Modify: any of the above if drift detected

- [ ] **Step 15.1 : Inventaire des fichiers**

Run:
```bash
find . -name "CLAUDE.md" -o -name "*.md" -path "./docs/*" -o -name "README.md" 2>/dev/null | sort
```
Expected: les 4 `CLAUDE.md` (racine + 3 sous) + 8 `docs/*.md` + `README.md` = 13 fichiers.

- [ ] **Step 15.2 : Vérifier les compteurs de lignes**

Run:
```bash
wc -l CLAUDE.md src/backend/CLAUDE.md src/providers/CLAUDE.md src/frontend/CLAUDE.md docs/*.md README.md
```
Expected: chaque fichier dans la fourchette annoncée. Si débordement > 30 %, justifier ou trimmer.

- [ ] **Step 15.3 : Cross-references — vérifier chaque pointeur**

Pour chaque référence d'un fichier vers un autre (ex : `CLAUDE.md` racine pointe vers `docs/MINECRAFT-PRIMER.md`), vérifier que la cible existe.

Run:
```bash
grep -roE "(docs/[A-Z_-]+\.md|src/[a-z]+/CLAUDE\.md|/CLAUDE\.md)" --include="*.md" . | \
  awk -F: '{print $2}' | sort -u | while read f; do
    f_clean=$(echo "$f" | sed 's|^/||' | sed 's|^\./||')
    [ -f "$f_clean" ] || echo "MISSING: $f_clean"
  done
```
Expected: aucune sortie `MISSING:`.

- [ ] **Step 15.4 : Vérifier qu'aucun antipattern listé n'est violé par la doc elle-même**

Mental check :
- Aucune string en hardcoded i18n dans les exemples de code des `CLAUDE.md` (cf. règle § 12 antipattern racine)
- Aucun chemin Minecraft hardcodé en dehors de `docs/MINECRAFT-PRIMER.md` (qui est légitime)
- Aucune mention d'execution shell sur `.jar` en exemple positif
- Aucune clé API dans les exemples (toujours via `.env`)

- [ ] **Step 15.5 : Invoquer `code-reviewer` sur l'ensemble**

Subagent dispatch : `code-reviewer` lecture de tous les fichiers `CLAUDE.md` + `docs/*.md` + `README.md` pour repérer :
- Contradictions entre fichiers
- Sections oubliées par rapport au spec
- Style hétérogène

Appliquer les corrections inline (sans nouveau commit pour chaque, regrouper).

- [ ] **Step 15.6 : Commit final si corrections**

```bash
# Si corrections apportées par code-reviewer :
git add -A
git commit -m "docs: fix cross-doc inconsistencies flagged by code-reviewer"
```

- [ ] **Step 15.7 : Tag v0.1.0-docs**

```bash
git tag -a v0.1.0-docs -m "Documentation skeleton complete (CLAUDE.md hierarchy + docs/)"
```

> Note : pas de push automatique. L'utilisateur poussera quand il sera prêt.

---

## Self-review du plan

Effectué après écriture, mises à jour inline si trouvé.

**1. Spec coverage** — chaque section du spec a une task correspondante :
- Spec § 1 (contexte) → Tasks 6 (`CONTEXT.md`) + 7 (`ARCHITECTURE.md` use cases)
- Spec § 2 (approche C complète) → File Map + structure des Tasks 2-5 (CLAUDE.md hiérarchique)
- Spec § 3 (structure repo) → Task 1 (scaffolding)
- Spec § 4 (CLAUDE.md racine) → Task 2 (full content inline)
- Spec § 5 (sous-CLAUDE.md) → Tasks 3-5
- Spec § 6 (docs/*.md) → Tasks 6-13
- Spec § 7 (ordre) → Tasks ordonnées comme spec
- Spec § 8 (décisions ouvertes) → Task 1 LICENSE choisi (MIT), Task 14 README, autres laissées en TBD documentés
- Spec § 9 (critères de succès) → Task 15 vérifications finales

**2. Placeholder scan** — fait. Restent uniquement :
- `author: "TBD"` dans `conf.yml` (Task 1) → légitime, à remplir par l'humain
- Quelques liens GitHub `<org>` non résolus (README) → légitime, à remplir au moment de créer le repo
- Ligne « Pour rédiger… invoquer `context7` » — c'est de la prescription, pas du placeholder

**3. Type consistency** — vérifié :
- DTOs `NormalizedPlugin`, `Version`, `Dependency` cohérents entre `CLAUDE.md` racine, `src/providers/CLAUDE.md`, `docs/PROVIDERS.md`
- Tables `pmcp_*` cohérentes entre `src/backend/CLAUDE.md` et `docs/ARCHITECTURE.md`
- Subagents : noms cohérents (kebab-case partout) avec la liste système
- MCPs : noms cohérents

Aucune incohérence détectée à l'écriture.
