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
