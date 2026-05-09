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
blueprint -i pteromcplugins           # Install (identifiant Blueprint = a–z, cf. conf.yml)
blueprint -r pteromcplugins           # Remove
blueprint -f pteromcplugins           # Reinstall (force)

# Cache (artisan custom)
php artisan pmcp:cache:flush          # Vider le cache des providers
php artisan pmcp:scheduled-updates    # Forcer la passe scheduled (debug)

# Build
npm run build                # Build frontend assets
./scripts/package-blueprint.sh   # Génère dist/pteromcplugins.blueprint pour release
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
