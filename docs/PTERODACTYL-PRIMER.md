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
cp dist/pterodactyl-mc-plugins.blueprint /var/www/pterodactyl/
cd /var/www/pterodactyl
blueprint -i pterodactyl-mc-plugins
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

Pour heuristiques crash post mise à jour, capter derniers évènements lignes erreur Forge « Mixin » ou Paper « Plugin … failed**.

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
| Feature flags blueprint | Tester sur deux panels CI matrix (voir `docs/TESTING.md`) |

Avant merges majeurs blueprint : passer **phpstan niveau projet module** après merge Panel core minor bump.
