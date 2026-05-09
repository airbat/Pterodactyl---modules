# Design Spec — `CLAUDE.md` pour `pterodactyl-mc-plugins`

> **Statut** : design validé en brainstorming, à transformer en plan d'implémentation via `writing-plans`.
> **Date** : 2026-05-09
> **Auteur** : brainstorming session entre l'utilisateur et l'agent.
> **Scope** : ce document décrit **le contenu et la structure du `CLAUDE.md`** (et sa hiérarchie) pour le projet Pterodactyl Module *Minecraft Plugins/Addons Manager*. Il ne décrit **pas** l'implémentation du module lui-même — celle-ci viendra dans des specs ultérieurs.

---

## 1. Contexte projet

### 1.1 Pitch

Extension Blueprint pour **Pterodactyl Panel** & **Pelican Panel** qui transforme le panel en gestionnaire de plugins/mods/addons Minecraft. Les utilisateurs naviguent dans **Modrinth + CurseForge** depuis l'onglet de leur serveur, installent en 1 clic, et bénéficient de mises à jour automatiques planifiées avec backup/rollback. Les administrateurs définissent des **policies** (whitelist/blacklist) bornant ce que les utilisateurs peuvent installer.

### 1.2 Personas

| Persona | Besoin | Usage |
|---|---|---|
| **Admin Pterodactyl** (héberge des serveurs MC pour des clients) | Garder le contrôle, éviter les plugins malveillants ou cassés | Définit policies, whitelist sources, audit (roadmap) |
| **Utilisateur final** (loue/possède un serveur MC) | Trouver et installer des plugins sans SSH/FTP | Browse, install, update, configure depuis l'onglet serveur |
| **Power user / sysadmin Minecraft** | Reproduire la même stack sur N serveurs | Crée un preset, l'applique à plusieurs serveurs |

### 1.3 Plateformes & sources couvertes (v1)

| Côté Minecraft | Sources |
|---|---|
| **Java** : Bukkit/Spigot/Paper plugins, Forge/NeoForge mods, Fabric/Quilt mods, proxies Velocity/Bungee | **Modrinth** (primaire) + **CurseForge** (mods) |
| **Bedrock** : Behavior packs, Resource packs (`.mcaddon`/`.mcpack`) | **CurseForge** (couverture partielle) — gap connu : MCPEDL en v2 |

### 1.4 Fonctionnalités MVP v1.0

11 features confirmées :

1. Browse/search catalogue Modrinth + CurseForge
2. Installation 1-clic (download + dépôt via Wings dans le bon dossier)
3. Détection des mises à jour disponibles + bouton update + changelog
4. Mises à jour automatiques planifiées (cron) avec backup avant
5. Pinning de versions (rester sur une version spécifique)
6. Résolution automatique des dépendances
7. Vérification de compatibilité (version MC + loader)
8. Backup automatique avant install/update/uninstall
9. Rollback 1-clic vers la version précédente
10. Gestion des fichiers de config (édition YAML/TOML/JSON)
11. Presets / modpacks personnalisés réutilisables

### 1.5 Hors scope v1 (roadmap)

Conflict detection, bulk ops admin, vulnerability/CVE scan, audit log compliance, sources additionnelles (SpigotMC, Hangar, MCPEDL, GitHub releases, manual upload, URL install).

### 1.6 Positionnement UX

**Onglet par serveur** (chaque utilisateur gère SES serveurs) **+ page admin pour les policies** (whitelist/blacklist sources, plugins autorisés). Le module respecte le modèle de permissions du panel hôte.

### 1.7 Cibles techniques

- **Pterodactyl Panel** 1.11+ (PHP 8.0+, Vue.js 2.7)
- **Pelican Panel** 1.0+ (PHP 8.2+, Livewire 3 + Alpine.js)
- **Blueprint** dernière version stable
- Compatibilité **double Panel** dès la v1 (couche d'abstraction frontend)

---

## 2. Approche retenue : Hiérarchie modulaire complète

Trois approches ont été évaluées :

- **A) Monolithique** (~800-1200 lignes dans un seul `CLAUDE.md`) → rejeté, trop coûteux en tokens, devient un dépotoir.
- **B) Minimaliste + docs séparés** → rejeté, latence des `Read` supplémentaires + risque de rater un doc.
- **C) Hiérarchique modulaire avec sous-`CLAUDE.md`** ✅ **retenu**.

L'utilisateur a choisi l'**Option C complète** : tous les sous-`CLAUDE.md` créés dès le départ (squelettes vides au minimum), pas de démarrage progressif.

---

## 3. Structure du repo & hiérarchie `CLAUDE.md`

### 3.1 Arborescence

```
pterodactyl-mc-plugins/
├── CLAUDE.md                              ← racine, ~300 lignes, TOUJOURS chargé
├── README.md                              ← humains : install, screenshots, links
├── LICENSE                                ← MIT par défaut (à confirmer)
├── conf.yml                               ← manifest Blueprint (versions, hooks)
│
├── docs/                                  ← docs longues, chargées à la demande
│   ├── CONTEXT.md                         ← personas, scope, glossaire MC
│   ├── ARCHITECTURE.md                    ← diagrammes mermaid, couches, data flow
│   ├── CONVENTIONS.md                     ← PHP/Laravel/Vue/Livewire/SQL conventions
│   ├── PROVIDERS.md                       ← spec interface Provider + Modrinth + CurseForge
│   ├── PTERODACTYL-PRIMER.md              ← Blueprint hooks, Wings API, modèle de perms
│   ├── MINECRAFT-PRIMER.md                ← formats .jar/.mcaddon, paths, loaders, versions
│   ├── TESTING.md                         ← stratégie tests (unit/integration/E2E)
│   └── ROADMAP.md                         ← features hors scope v1, ordre prévu
│
├── src/
│   ├── backend/
│   │   ├── CLAUDE.md                      ← règles backend (Laravel, queues, jobs)
│   │   ├── Http/Controllers/
│   │   ├── Models/                        ← Plugin, InstalledPlugin, Preset, Policy
│   │   ├── Services/
│   │   ├── Jobs/                          ← UpdateCheckJob, ScheduledUpdateJob, BackupJob
│   │   └── database/migrations/
│   │
│   ├── providers/
│   │   ├── CLAUDE.md                      ← règles provider (interface, rate-limit, cache)
│   │   ├── Contracts/PluginProvider.php   ← interface commune
│   │   ├── Modrinth/
│   │   └── CurseForge/
│   │
│   ├── frontend/
│   │   ├── CLAUDE.md                      ← règles frontend (Vue 2 / Livewire selon Panel)
│   │   ├── pterodactyl/                   ← Vue 2 components pour Panel original
│   │   └── pelican/                       ← Livewire/Alpine components pour Pelican
│   │
│   └── shared/
│       └── i18n/                          ← FR + EN minimum
│
├── tests/
│   ├── Unit/
│   ├── Integration/                       ← contre Modrinth/CurseForge avec VCR
│   └── E2E/                               ← Pest + Playwright pour onglet serveur
│
└── .github/workflows/
    ├── ci.yml                             ← phpstan, pint, pest, eslint
    └── release.yml                        ← package .blueprint sur tag
```

### 3.2 Rôles des `CLAUDE.md`

| Fichier | Quand chargé | Contenu (synthèse) | Lignes cible |
|---|---|---|---|
| **`CLAUDE.md`** racine | Toujours | Identité, stack, matrice subagents/MCPs, workflow, antipatterns critiques | ~300 |
| **`src/backend/CLAUDE.md`** | Édition fichiers backend | Conventions Laravel, queues Redis, sécurité Wings API, error handling | ~120 |
| **`src/providers/CLAUDE.md`** | Édition fichiers provider | Interface `PluginProvider`, rate-limit, cache HTTP, mapping schéma normalisé | ~150 |
| **`src/frontend/CLAUDE.md`** | Édition fichiers frontend | Quand Vue 2 vs Livewire, conventions UI, i18n, accessibilité | ~100 |

### 3.3 Règles anti-duplication

- La racine **ne duplique pas** les sous-`CLAUDE.md`. Elle **pointe** vers eux.
- Les `docs/*.md` sont **descriptifs** (le projet expliqué) ; les `CLAUDE.md` sont **prescriptifs** (comment travailler).
- Si une info change, on l'édite à un seul endroit. Toute info dupliquée est un bug à corriger.

---

## 4. Contenu du `CLAUDE.md` racine

### 4.1 Plan détaillé (~300 lignes)

| § | Section | Lignes |
|---|---|---|
| 1 | Identité & posture | ~15 |
| 2 | Project snapshot (scope/features/non-scope) | ~25 |
| 3 | Stack technique (table versions) | ~30 |
| 4 | Référentiel docs (quand lire `docs/*.md`) | ~15 |
| 5 | Routage subagents (matrice tâche → subagent) | ~50 |
| 6 | Routage MCPs (matrice besoin → MCP) | ~40 |
| 7 | Workflow obligatoire (TDD, code-review, commits) | ~40 |
| 8 | Antipatterns critiques | ~50 |
| 9 | Quick wins / commandes utiles | ~20 |
| 10 | Méta : maintenance du fichier | ~10 |

### 4.2 § 1 — Identité & posture

> Tu es un **dev senior Pterodactyl & Minecraft**. Tu connais Blueprint à fond, le modèle de permissions Pterodactyl/Pelican, l'API Wings, les hooks Laravel, et les contraintes de chaque loader Minecraft. Tu écris du PHP 8.2+ idiomatique, du Vue 2 propre, du Livewire 3 propre. Tu es **conservateur sur les changements de scope** et **rigoureux sur la sécurité** (uploads, path traversal, exécution arbitraire).

### 4.3 § 3 — Stack technique

| Couche | Tech | Versions cibles |
|---|---|---|
| Panel | Pterodactyl 1.11+ / Pelican 1.0+ | les deux |
| Backend | PHP / Laravel | 8.2+ / 10.x (Pterodactyl) ou 11.x (Pelican) |
| Frontend (Pterodactyl) | Vue 2 | 2.7 |
| Frontend (Pelican) | Livewire / Alpine.js | 3.x / 3.x |
| DB | MariaDB / MySQL | comme le panel hôte |
| Queue | Redis | comme le panel hôte |
| Tests | Pest + Playwright | 3.x / 1.x |
| Lint | PHPStan + Laravel Pint + ESLint | latest |

### 4.4 § 5 — Routage subagents

| Tâche | Subagent | Quand |
|---|---|---|
| Concevoir une route API ou un job de queue | `backend-architect` | Toute nouvelle endpoint/job |
| Concevoir/migrer un schéma DB | `database-architect` | Toute migration |
| Créer un composant UI (Vue/Livewire) | `frontend-developer` | Tout composant non-trivial |
| Review pré-commit | `code-reviewer` | Systématique avant `git commit` |
| Review sécurité (upload, path, exécution shell) | `security-auditor` | Touche au filesystem Wings, exécution, fs |
| Comprendre un coin du code Blueprint/Pterodactyl | `code-explorer` | Avant de modifier un hook ou pattern existant |
| Plan d'archi multi-couches | `code-architect` | Avant tout chantier > 3 fichiers |
| TDD strict sur logique métier | `tdd-orchestrator` | Compat check, dep resolution, version compare, rollback |
| Bug complexe multi-hypothèses | `team-debugger` | Bug avec ≥ 2 hypothèses concurrentes |
| Doc API (OpenAPI) | `api-documenter` | Routes publiques du module |
| README / install guide / contributing | `docs-architect` | Docs longues |
| Vérification visuelle UI | `ui-visual-validator` | Après tout changement frontend |
| Recherche générique | `explore` | Quand le code à trouver n'est pas évident |

### 4.5 § 6 — Routage MCPs

| Besoin | MCP | Exemple concret |
|---|---|---|
| Doc à jour Pterodactyl/Blueprint/Laravel/Vue/Livewire | **context7** | `resolve-library-id "blueprint"` puis `get-library-docs` avant tout code utilisant un hook |
| Issues / PRs / releases du repo | **github** | Suivre les releases Modrinth/CurseForge dans des issues |
| Debug UI dans le Panel | **chrome-devtools-mcp** | Tracer les calls Modrinth depuis l'onglet serveur |
| Tester l'UI manuellement | **cursor-ide-browser** | Naviguer dans une instance Pterodactyl/Pelican locale |
| Scaffolder rapidement un composant UI | **21st-dev/magic** | Modal d'install, card plugin |
| Mémoire cross-session | **claude-mem** | Rappeler les décisions d'archi entre sessions |
| Tests cross-browser (optionnel) | **browserstack** | Compat Pterodactyl + Pelican sur navigateurs cibles |

### 4.6 § 7 — Workflow obligatoire

- **TDD obligatoire** sur : compat check, dep resolution, version compare, rollback, parser de manifests plugins (`plugin.yml`, `mcmod.info`, `manifest.json` Bedrock).
- **Pragmatique** sur : UI, glue code, intégrations API externes (mais avec VCR/snapshot tests).
- `code-reviewer` **systématique** avant chaque commit.
- Commits Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`).
- Branches `feat/`, `fix/`, `chore/`, `docs/`.
- Avant tout chantier > 1 fichier : invoquer `writing-plans`.

### 4.7 § 8 — Antipatterns critiques

- Ne jamais écrire directement dans le filesystem du serveur de jeu : **toujours passer par l'API Wings**.
- **Path traversal** : tout chemin venant d'un user doit être normalisé + ancré au volume du serveur.
- Ne pas trust les métadonnées Modrinth/CurseForge brutes : valider avant insert DB.
- Aucun appel d'exécution shell sur le `.jar` côté Panel : c'est Wings qui exécute le serveur de jeu.
- Respecter les **rate limits** Modrinth (300 req/min) et CurseForge (clé API + quotas).
- Ne pas casser la compat Vue 2 si on développe sur Pelican (et inversement).
- Ne pas hardcoder les paths Minecraft : ils diffèrent entre Java (`/plugins/`, `/mods/`) et Bedrock (`/worlds/<world>/behavior_packs/`).
- Ne pas oublier de **redémarrer/recharger** le serveur après install (selon plugin/mod).
- Ne pas exposer la clé API CurseForge dans le frontend.
- Ne pas confondre **version MC** (1.20.4) et **version loader** (Forge 49.0.x).

### 4.8 § 10 — Maintenance

> Mets à jour `CLAUDE.md` (racine ou sous-fichier) DANS LE MÊME COMMIT quand tu :
> - Ajoutes une feature majeure (mettre à jour Project snapshot)
> - Changes la stack (versions, lib clé)
> - Ajoutes/retires un subagent ou MCP de la matrice
> - Découvres un antipattern qui m'a fait perdre du temps (ajouter section 8)
> - Ajoutes/refactorises un dossier (mettre à jour la hiérarchie)
>
> Si une règle dans `CLAUDE.md` devient fausse, ne la laisse PAS pourrir : édite-la ou retire-la. Une règle obsolète est pire que pas de règle.

---

## 5. Contenu des sous-`CLAUDE.md`

### 5.1 `src/backend/CLAUDE.md` (~120 lignes)

Sections :

1. Périmètre du dossier
2. Conventions Laravel (Single Action Controllers, Services, Models anémiques, `declare(strict_types=1)`)
3. Queue & Jobs (idempotence, backoff exponentiel, async par défaut)
4. Sécurité Wings API (whitelist d'extensions, validation paths)
5. Error handling (exceptions métier dans `Exceptions/`, logs avec contexte)
6. Migrations (préfixe `pmcp_`, pas de FK vers tables panel, soft deletes)

### 5.2 `src/providers/CLAUDE.md` (~150 lignes)

Sections :

1. Périmètre du dossier
2. Interface `PluginProvider` :
   - `search(query, filters): SearchResult[]`
   - `getDetails(id): PluginDetails`
   - `getVersions(id, mcVersion?, loader?): Version[]`
   - `download(versionId): Stream`
   - `getDependencies(versionId): Dependency[]`
3. Schéma normalisé (DTO `NormalizedPlugin`, jamais de format brut au frontend)
4. Cache HTTP (Redis, TTL 1h search / 24h details, invalidation artisan)
5. Rate limits (token bucket Redis pour Modrinth ; quota CurseForge ; degradation gracieuse)
6. Tests (php-vcr pour replay HTTP, snapshot tests sur le mapping)
7. Antipatterns provider-spécifiques (dédoublonnage, pas de ranking maison)

### 5.3 `src/frontend/CLAUDE.md` (~100 lignes)

Sections :

1. Périmètre du dossier (les deux Panels)
2. Quand Vue 2 vs Livewire (pterodactyl/ → Vue 2.7 + Vuex ; pelican/ → Livewire 3 + Alpine ; shared/ → CSS/i18n/assets)
3. Conventions Vue 2 (Options API obligatoire, pas de TypeScript)
4. Conventions Livewire (sérialisation, Alpine pour interactions front)
5. i18n (FR + EN min, pas de string hardcodée)
6. Accessibilité (WCAG AA, navigation clavier, `ui-visual-validator`)
7. Quand utiliser `21st-dev/magic` (scaffolding initial uniquement, jamais sur APIs panel)

---

## 6. Contenu des `docs/*.md`

| Fichier | Lignes | Rôle | Quand le lire |
|---|---|---|---|
| **`docs/CONTEXT.md`** | ~150 | Personas détaillés, glossaire MC (loader, addon, behavior pack, modpack…), use cases | Découverte projet ou décision produit |
| **`docs/ARCHITECTURE.md`** | ~250 | Diagrammes mermaid : data flow, séquences install/update/rollback, schéma DB, archi providers | Avant chantier d'archi ou migration DB |
| **`docs/CONVENTIONS.md`** | ~200 | Conventions PHP/Laravel/Vue/Livewire/SQL, naming, style, error patterns | Avant 1re contribution dans une couche |
| **`docs/PROVIDERS.md`** | ~200 | Spec interface `PluginProvider`, particularités Modrinth/CurseForge, ajout futur provider | Avant de toucher `src/providers/` |
| **`docs/PTERODACTYL-PRIMER.md`** | ~250 | Blueprint hooks, modèle de permissions Pterodactyl/Pelican, API Wings, cycle de vie d'un serveur | Avant de toucher hooks Blueprint, Wings, perms |
| **`docs/MINECRAFT-PRIMER.md`** | ~200 | Formats `.jar`/`.mcaddon`/`.mcpack`, paths par loader, semver MC, manifests | Avant parsing de plugins ou compat |
| **`docs/TESTING.md`** | ~150 | Stratégie : Pest unit, VCR providers, Playwright E2E, instance locale | Avant nouvelle suite de tests |
| **`docs/ROADMAP.md`** | ~100 | Features hors scope v1 priorisées, ordre prévu, dépendances | Discussions de scope futur |

---

## 7. Stratégie de démarrage

Ordre d'écriture dans le plan d'implémentation (à détailler dans `writing-plans`) :

1. `CLAUDE.md` racine (le plus important)
2. `docs/CONTEXT.md` + `docs/ARCHITECTURE.md`
3. `docs/PTERODACTYL-PRIMER.md` + `docs/MINECRAFT-PRIMER.md` (fondations)
4. Squelettes des sous-`CLAUDE.md` (avec au moins le périmètre rempli)
5. `docs/PROVIDERS.md` + `docs/CONVENTIONS.md` + `docs/TESTING.md`
6. `docs/ROADMAP.md` + `README.md` + `LICENSE`

---

## 8. Décisions ouvertes (à trancher dans le plan ou plus tard)

- **Nom définitif du module** (provisoire : `pterodactyl-mc-plugins`)
- **Licence** (proposition : MIT)
- **Distribution** (GitHub Releases avec `.blueprint` packagé sur tag — à confirmer)
- **CI** : GitHub Actions confirmé, mais services à utiliser pour MariaDB de test ?
- **Versions exactes** Pterodactyl/Pelican à supporter (1.11+ et 1.0+ proposés, à figer dans `conf.yml`)

---

## 9. Critères de succès du `CLAUDE.md`

Le `CLAUDE.md` est jugé bon si, sans contexte additionnel :

1. À l'ouverture du projet, l'agent comprend en < 30 sec la nature du module, sa stack, et son scope.
2. Pour 90 % des tâches, l'agent sait quel subagent invoquer **sans se reposer la question**.
3. L'agent invoque **automatiquement** `context7` avant d'utiliser une API Pterodactyl/Blueprint/Laravel/Vue/Livewire.
4. L'agent **n'invente jamais** un chemin filesystem MC : il consulte `docs/MINECRAFT-PRIMER.md` ou demande.
5. L'agent **n'oublie jamais** d'invoquer `code-reviewer` avant de proposer un commit.
6. L'agent **n'écrit jamais** de TDD-required code (compat check, dep resolution, etc.) sans test d'abord.
7. Les antipatterns critiques de la § 8 racine sont **respectés à 100 %** (pas de path traversal, pas de leak de clé API, pas d'exécution shell directe sur les binaires MC, etc.).

---

## 10. Suite

Une fois ce design approuvé par l'utilisateur :

1. Invocation de `writing-plans` pour produire un plan d'implémentation détaillé fichier-par-fichier.
2. Le plan fixera : ordre, contenu de chaque fichier (avec extraits concrets), checkpoints de validation utilisateur.
3. Pas d'écriture de code/`CLAUDE.md` réel avant que le plan soit approuvé.
