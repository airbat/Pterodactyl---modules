# Primer — Pterodactyl / Pelican / Blueprint / Wings

Utilise **context7** pour la documentation exacte des versions installées avant d’encoder des signatures d’API. Ce document oriente ; il ne remplace pas le code source upstream.

## Blueprint en cinq minutes

**Blueprint** permet d’étendre le Panel sous forme de paquet **`blueprint` / `.blueprint`** avec un fichier `conf.yml`, sans forcément maintenir un fork du dépôt core.

Points clés :

- hooks pour routes Laravel, fichiers frontend, zone admin ou dashboard utilisateur ;
- dossier **`data`** optionnel persisté hors dépôt ;
- packaging release en archive zip renommée conventionnellement `.blueprint` ;

Flux typique après installation Panel :

```
cp dist/pteromcplugins.blueprint /var/www/pterodactyl/
cd /var/www/pterodactyl
blueprint -i pteromcplugins
```

Les chemins exacts peuvent différer (Pelican utilise souvent arborescence analogue). Ajuster SELinux/permissions systemd selon distro.

Voir `docs/ARCHITECTURE.md` pour stratégie build.

## Format `conf.yml` (zones)

Bloc | Rôle
---|---
`info` | Identification : `identifier`, version module, compat `target`
`admin` | Vues Laravel / Blade / SPA admin + wrappers CSS si besoin
`dashboard` | Composants côté client (Vue patch panel Pterodactyl / assets Livewire côté Pelican)
`data` | Répertoire disque données additionnel hors git
`requests` | Enregistrer routers HTTP (web/api/service) avec middleware officiel Panel
`database` | Déclarer migrations Laravel packagées
`console` | Commandes Artisan custom (`pmcp:*`)

Référencé par le stub `conf.yml` racine projet.

## Hooks prévus pour ce module

Priorité mise en oeuvre lors des phases développement application réelle :

1. Injection **onglet serveur** utilisateur Minecraft (liste plugins installés / catalogue).
2. Page **Policies** niveau administrateur blueprint (whitelist source, blacklist slugs patterns).
3. Routes API REST internes Laravel consommées par front (browse, install enqueue, presets CRUD léger hors core panel resources).
4. Commandes Artisan maintenance cache providers & dry-run schedules.

Syntaxe précise ligne par ligne dépend blueprint version — inspecter scaffold blueprint template récent lors bootstrap code.

## API Wings utilisée conceptuellement

Toutes ces actions transitent depuis le Panel vers **Wings** via client HTTP Laravel fourni upstream (signature HMAC ou token node selon version). **Ne reconstruire pas ton client depuis zéro** sauf POC isolé hors prod.

Familles :

### Filesystem sandbox serveur jeu

Endpoints types (patterns, à valider contre source Wings utilisée par ton Panel) :

- `GET …/files/list?directory=` — listing relatif monde conteneur
- `POST …/files/write?file=` — écriture brute (chunks parfois requis très gros binaires)
- `POST …/files/upload` — multipart téléversement fichier unique ou multiple
- `POST …/files/delete` — suppression chemins relatifs tableau JSON
- `POST …/files/compress` / `POST …/files/decompress` — archives zip/tar utilisées snapshots rollback

Tu passes toujours l’ **`uuid`** interne Wings du serveur (visible model `Server`).

### Lifecycle conteneur

- `POST …/power` avec signal `start` `stop` `restart` pour orchestrer après install mod lourd

Après restart, suivre websocket console pour anomalies classiques classpath Forge.

### Streams diagnostic

Pour heuristiques crash post mise à jour, capter derniers évènements lignes erreur Forge « Mixin » ou Paper « Plugin … failed».

## Contexte serveur et version Minecraft

L’UI catalogue consomme **GET** `/api/client/extensions/pteromcplugins/server/context?server={uuid}` (détail du payload dans `docs/ARCHITECTURE.md` § « Contexte Minecraft »). Le handler délègue à `ServerMcContextBuilder`, qui déduit `minecraft_versions_hint` depuis les variables d’œuf fusionnées et le startup (placeholders `{{ … }}` résolus ou non).

### Détection runtime (probe via logs)

Quand `ServerMcContextBuilder` ne déduit pas de version depuis les variables d'œuf (œuf custom, placeholders non résolus, etc.), le module expose une route **synchrone** qui lit un fichier de log côté Wings :

- **Route** : `GET /api/client/extensions/pteromcplugins/server/probe-mc-version?server={uuid}`
- **Handler** : `ext/routes/client.php` → `PteroMcPlugins\Services\PmcpRuntimeVersionProbe::probe($server)`
- **Lecture Wings** : `DaemonFileRepository::getContent($path, 512_000)` (≤ 512 Ko du début du fichier).
- **Chemins tentés** (liste fermée, ordre fixe ; pas d’input utilisateur → pas de path traversal) : `/logs/latest.log`, `/Logs/latest.log`, `logs/latest.log`, `Logs/latest.log`. Si Wings renvoie **404** pour un chemin, le suivant est essayé. Tous vides ou absents → **404** avec `tried_paths` dans le JSON.
- **Parser** : `PmcpVersionLogParser::parse()` — pur, sans I/O. Avant les regex : retrait **BOM UTF-8** et **séquences ANSI** (CSI / OSC). Loaders reconnus : **paper**, **spigot** (CraftBukkit), **neoforge**, **forge**, **quilt**, **fabric**, **bedrock**, **vanilla**. Paper / Spigot : motif tolérant du texte (y compris `(Implementing API …)`) entre le marqueur `Paper version` / `CraftBukkit version` et la première occurrence **`(MC: …)`**. Bedrock : présence de **`Starting Server`** puis une ligne du type **`[…] Version: x.y.z.w`** (timestamp étendu).
- **Réponse JSON succès** : `{ mc_version, loader, source_line, source: "latest_log" }`.
- **Permission** : `ACTION_FILE_READ` (message 403 explicite si refusé).
- **Erreurs HTTP** (champ `message` + éventuellement `detail` si `APP_DEBUG`, `wings_status` pour les erreurs Wings mappées) :
  - **404** : aucun fichier candidat utilisable, ou chemin inexistant côté daemon après les essais.
  - **403** : Wings refuse la lecture (politique nœud / daemon).
  - **422** : fichier lu mais aucun banner reconnu, ou fichier trop volumineux pour la limite (512 Ko).
  - **502** : erreur **5xx** Wings lors de la lecture, ou nœud injoignable sans code HTTP exploitable (message générique dans ce dernier cas).
- **UI** : bouton dans `ext/dashboard/components/sections/McPluginsDashboard.tsx` (bloc sans `minecraft_versions_hint`) ; appelle la route via `fetchJson` et met à jour le filtre catalogue (`minecraft_versions_hint` équivalent côté filtre).
- **Limites** : le serveur doit avoir produit au moins une fois un `latest.log` dans l’un des emplacements ci-dessus ; pas de fallback WebSocket ni commande `version` en v1.0 (voir `docs/superpowers/plans/2026-05-15-runtime-mc-version-probe.md`).

## Permissions Panel proposées

Chaînes `pmcp.*` :

- `pmcp.plugins.read`
- `pmcp.plugins.install`
- `pmcp.plugins.update`
- `pmcp.plugins.uninstall`
- `pmcp.presets.manage`
- `pmcp.policies.manage`

Mapper vers policies Filament côté Pelican (gates + policies resource) équivalent niveau granularité UX.

Contrôleurs Laravel doivent appeler :

```php
$this->authorize('view', $server); // exemple existant fichiers Panel
```

Puis vérifications custom `Gate::authorize('has-permission-for-pmcp', …)` suivant abstraction panel.

## États Panel impact module

Exemple états :

- **`installing`** / **`restoring`** : bloquer téléchargements binaires lourds
- **`offline`** autorise upload fichiers hors runtime mais attention lock si backup simultanée
- **`running`** téléchargements mod Forge sensibles ⇒ planifier fenêtre maintenance utilisateur avec message clair avant restart forcé Wings

Ajouter métadonne `maintenance_scheduled_at` facultatif futur hors MVP.

## Quand notifier restart utilisateur final

Voir table détaillée `docs/MINECRAFT-PRIMER.md`. Règle générale UI :

- Badge **Forge/Fabric mods** ⇒ message fort « redémarrage obligatoire »
- Plugins Paper légers ⇒ « redémarrage recommandé — reload serveur désapprouvé production »
- Ajouts behaviour pack ⇒ « rechargement monde BDS peut suffire sous conditions » mais documenter reboot complet si incertain

Éviter promesses automatiques contradictoires Wings power API limites SLA hébergeur.

## Différences Pterodactyl versus Pelican (implémentation)

| Zone | Détail projet |
|---|---|
| Frontend | Duplication contrôlée (Vue vs Livewire) |
| Routing names | Préfixes namespaces différents : adapter tests E2E |
| Auth guards | Harmoniser enums permissions string vs int codes internes |
| Admin Blueprint (`admin.view`) | `ext/views/admin/extension.blade.php` utilise `@extends('layouts.admin')` **Pterodactyl-classique** — vérifier / factoriser équivalent sous Pelican lors du port officiel |

Avant merges majeurs blueprint : passer **phpstan niveau projet module** après merge Panel core minor bump.
