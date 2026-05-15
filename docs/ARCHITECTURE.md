# Architecture — vue d’ensemble et flux

Voir `docs/PTERODACTYL-PRIMER.md` pour Blueprint/Wings/permissions et `docs/MINECRAFT-PRIMER.md` pour les chemins par loader.

## Vue d’ensemble

```mermaid
flowchart TB
  UB[Navigateur utilisateur]
  PH[Panel hôte Pterodactyl ou Pelican]
  FE[Frontend module Vue ou Livewire]
  BE[Backend module Laravel]
  PR[Providers Modrinth CurseForge]
  WG[Wings API]
  RQ[Queues Redis]
  DB[(MariaDB tables pmcp_)]

  UB --> PH
  PH --> FE
  PH --> BE
  FE --> BE
  BE --> PR
  BE --> WG
  BE --> RQ
  BE --> DB
  RQ --> BE
```

Le module ne parle **pas** au disque du conteneur jeu en contournant Wings : toute écriture passe par l’API officielle du daemon (cf. `CLAUDE.md` § 8).

## Data flow : install d’un plugin

Réponse installer : champ optionnel **`backup`** (`id`, `archive`) lorsque **`backup_before: true`** est envoyé avec un **`backup_context`** parmi `catalog` | `history` | `scheduled` : compression Wings du dernier segment du dossier cible (`.tar.gz` sur le volume), ligne en **`pmcp_backups`**.

```mermaid
sequenceDiagram
  participant U as Utilisateur
  participant F as Frontend
  participant C as Controller
  participant P as ProviderAggregate
  participant M as Modrinth API
  participant B as BackupServerPluginsJob
  participant I as InstallPluginJob
  participant W as Wings
  participant N as Notifications

  U->>F: Choisit version et installe
  F->>C: POST install
  C->>P: Resolve metadata et telechargement
  P->>M: GET fichier ou metadonnees
  M-->>P: Binaire ou URL plus hash
  C->>B: enqueue backup dossier cible
  B->>W: Backup repertoire plugins ou mods
  C->>I: enqueue install idempotent
  I->>W: Upload fichier au path resolu
  I-->>N: Succes ou erreur
  N-->>F: websocket ou poll
```

Les noms exacts seront précisés quand les contrôleurs seront scaffoldés dans le codebase.

## Data flow : scheduled update

```mermaid
sequenceDiagram
  participant CR as Cron panel
  participant A as ApplyScheduledUpdatesJob
  participant CK as CheckPluginUpdatesJob
  participant BK as BackupServerPluginsJob
  participant UP as UpdatePluginJob
  participant WG as Wings
  participant RB as RollbackPluginJob

  CR->>A: Declenche periode
  A->>CK: Pour serveur eligible
  CK-->>UP: Nouvelle version sans pin ni blocage policy
  UP->>BK: Snapshot avant MAJ
  BK->>W: Snapshot repertoire plugins ou mods
  UP->>W: Remplace fichier
  alt crash detecte post MAJ
    UP->>RB: Restaurer dernier bon etat
    RB->>W: Decompress ou copie retour
  end
```

La détection de crash sera **heuristique** à calibrer (état Wings, lignes console) lors de l’implémentation.

## Data flow : rollback

```mermaid
sequenceDiagram
  participant U as Utilisateur ou job
  participant R as RollbackPluginJob
  participant DB as Stockage backups
  participant W as Wings

  U->>R: Demande rollback
  R->>DB: Recupere snapshot pour server et artefact
  R->>W: Restaurer depuis archive
```

## Schéma DB

Pas de FK physiques vers `servers` / `users` du panel ; seulement des IDs opaques (ADR-003).

```mermaid
erDiagram
  PMCP_PLUGINS ||--o{ PMCP_PLUGIN_VERSIONS : has
  PMCP_PLUGINS ||--o{ PMCP_INSTALLED_PLUGINS : installed_as
  PMCP_PLUGIN_VERSIONS ||--o{ PMCP_INSTALLED_PLUGINS : resolves_to
  PMCP_PRESETS ||--o{ PMCP_PRESET_ITEMS : contains
  PMCP_PLUGINS ||--o{ PMCP_PRESET_ITEMS : references_plugin
  PMCP_PLUGIN_VERSIONS ||--o{ PMCP_PRESET_ITEMS : pinned_version

  PMCP_PLUGINS {
    bigint id PK
    string source_code
    string slug
    string name_normalized
    text summary
  }

  PMCP_PLUGIN_VERSIONS {
    bigint id PK
    bigint plugin_id FK
    string semver_artifact
    json mc_versions
    string sha512
  }

  PMCP_INSTALLED_PLUGINS {
    bigint id PK
    bigint server_panel_id
    bigint plugin_id
    bigint plugin_version_id
    boolean is_pinned
    boolean scheduled_autoupdate
    datetime deleted_at
  }

  PMCP_PRESETS {
    bigint id PK
    bigint owner_user_panel_id
    string preset_name
    text description_text
  }

  PMCP_PRESET_ITEMS {
    bigint id PK
    bigint preset_id FK
    bigint plugin_id FK
    bigint plugin_version_id
  }

  PMCP_POLICIES {
    bigint id PK
    string policy_scope_json_key
    json rules_body
  }

  PMCP_BACKUPS {
    bigint id PK
    bigint server_panel_id
    string wings_relative_archive_path
    datetime captured_at
  }

  PMCP_AUDIT_LOG {
    bigint id PK
    string event_code
    json details_json
  }
```

Population de `PMCP_AUDIT_LOG` hors portée **v1.0**.

## Architecture Providers

```mermaid
flowchart LR
  S[AggregatorService]
  I[Contrat PluginProvider]
  MOD[Adapter Modrinth]
  CFD[Adapter CurseForge]
  CACHE[(Redis cache HTTP)]
  API1["api.modrinth.com"]
  API2["api.curseforge.com"]

  S --> I
  MOD --> CACHE
  CFD --> CACHE
  I -.-> MOD
  I -.-> CFD
  CACHE --> API1
  CACHE --> API2
```

DTO stables garantissent aucun leak de JSON tiers vers le frontend.

## Double Panel frontend

Backend unique ; UI dupliquée volontairement entre `src/frontend/pterodactyl/` (Vue 2.7 Options API) et `src/frontend/pelican/` (Livewire/Alpine) — rationales dans `src/frontend/CLAUDE.md`.

## Contexte Minecraft (`server/context`)

L’endpoint client **GET** associé au module (déclaré dans les routes Blueprint `ext/routes/client.php`) renvoie un **contexte lecture seule** pour l’UI catalogue : aucun appel Wings, pas de modification disque.

Le handler délègue la construction du payload à `PteroMcPlugins\Services\ServerMcContextBuilder::build($server)` (fichier `ext/app/Services/ServerMcContextBuilder.php`, chargé via `require_once` depuis la route pour rester compatible avec les panels où l’autoload Composer de l’extension n’est pas garanti).

### Forme du payload

| Champ | Rôle |
|--------|------|
| `minecraft_versions_hint` | Liste de chaînes candidates (tri insensible à la casse) : versions `1.x`, canaux (`latest`, `snapshot`, …), adresses IPv4 détectées dans le startup analysé, extractions depuis URLs (Modrinth/…), motifs Paper/Purpur/Fabric/Quilt. Filtres pour éviter les faux positifs courts type options JVM. |
| `egg_variables` | Sous-ensemble des variables d’environnement de l’œuf / serveur pour les clés d’intérêt (`MINECRAFT_VERSION`, `MC_VERSION`, `BEDROCK_VERSION`, …). |
| `egg_name` / `nest_name` | Libellés affichage / heuristiques (ex. détection Bedrock). |
| `context_meta` | `bedrock_like_egg` : heuristique œuf/nest ou présence `BEDROCK_VERSION`. `startup_has_placeholders_left` : après expansion `{{ ENV }}` depuis l’env fusionné (valeurs serveur + défauts œuf), il reste des marqueurs `{{ … }}` non résolus (startup template). |

Les indices proviennent de : **variables fusionnées** (valeur instance + repli sur `default_value` des variables œuf), puis **startup** après substitution des placeholders, avec motifs additionnels sur la « haystack » (URLs, noms de jars).

### Sonde runtime (`server/probe-mc-version`)

Complément **opt-in** (bouton UI) lorsque les hints ci-dessus sont insuffisants :

- **Route** : **GET** `…/server/probe-mc-version?server={uuid}` (même préfixe d’extension que `server/context`, déclaré dans `ext/routes/client.php`).
- **Comportement** : lecture d’un fichier `latest.log` via Wings (`PmcpRuntimeVersionProbe`), parsing par `PmcpVersionLogParser` (bannières de démarrage Java + Bedrock). Détails (chemins candidats, codes HTTP, champs JSON) : `docs/PTERODACTYL-PRIMER.md` § « Détection runtime ».
- **Différence avec `server/context`** : la sonde **appelle Wings** et nécessite la permission lecture fichiers ; le contexte serveur reste **sans I/O Wings**.

### Tests

La logique du builder est couverte par des tests Pest standalone (`composer test`), avec un stub `Pterodactyl\Models\Server` sous `tests/stubs/` — le panel réel fournit le modèle Eloquent en production.

## Déploiement Blueprint

Publication d’un fichier `.blueprint` via `./scripts/package.sh` (référencé dans `CLAUDE.md` § 9 une fois présent dans le repo). Installation admin :

```
blueprint -i pteromcplugins
```

## ADR résumées

### ADR-001 Blueprint vs fork panel

Isolation des changements upstream ; désinstallation claire pour l’hébergeur.

### ADR-002 DTO `NormalizedPlugin`

Couplage faible APIs externes ; évolutivité multi-sources sans casser frontend.

### ADR-003 Pas de FK vers schéma core panel

Tolérence aux divergences Pterodactyl/Pelican et montée de versions indépendante.

### ADR-004 Double UI dès MVP

Répond aux attentes natives de chaque écosystème panel malgré coût de maintenance doublé.

### ADR-005 Redis cache et queues

Réutiliser stack standard panel ; clés préfixées `pmcp:*` pour TTL et buckets.
