# Primer — Minecraft loaders, packs et versioning

Voir `docs/CONTEXT.md` pour le glossaire produit ; ce fichier cible implémentation (**paths relatifs sandbox serveur**) et précautions parsers.

Référencé par `CLAUDE.md` § 4 et `docs/ARCHITECTURE.md`.

## Loaders Java (récap installation)

Chemins relatifs usuels à partir de la racine serveur jeu (PAS hardcoder en dur dans le front : utiliser **`PathResolver` backend** décrit dans `src/backend/CLAUDE.md`).

| Loader | Répertoire cible typique | Manifest principal | Notes |
|--------|--------------------------|--------------------|-------|
| Bukkit | `plugins/` | `plugin.yml` | Legacy très limité moderne |
| Spigot | `plugins/` | `plugin.yml` | Performances meilleures CraftBukkit |
| Paper | `plugins/` | `plugin.yml`, parfois `paper-plugin.yml` | API étendues async évènements |
| Purpur | `plugins/` | `plugin.yml` | Fork Paper tuning gameplay serveur |
| Velocity | `plugins/` | `velocity-plugin.json` | Proxy moderne |
| BungeeCord | `plugins/` | `bungee.yml` | Ancien mais encore déployé |
| Waterfall | `plugins/` | `bungee.yml` compat | Fork patches performance |
| Forge | `mods/` | `META-INF/mods.toml` (>=1.13), `mcmod.info` ancien | Coremods modulaires complexes |
| NeoForge | `mods/` | `META-INF/neoforge.mods.toml` | Divergence après fork Forge communautaire |
| Fabric | `mods/` | `fabric.mod.json` | Mixins, dépendances `depends.minecraft` |
| Quilt | `mods/` | `quilt.mod.json` + compat `fabric.mod.json` souvent présent | Ecosysteme encore parfois bridgé Fabric |

Les proxys gèrent des plugins distincts serveur jeu : ne jamais mélanger un `.jar` proxy dans dossier monde Paper par erreur de mapping egg.

## Bedrock — Behaviour vs Resource packs

Structures typiques monde `world_name` :

- Behavior packs répertoire : `worlds/<world>/behavior_packs/<uuid_ou_nom>/manifest.json`
- Resource packs analogue sous `worlds/<world>/resource_packs/…`

Activation : fichiers liste JSON racine monde (`world_behavior_packs.json`, `world_resource_packs.json`) — précise implémentation BDS officielle via doc Microsoft Learn (chercher *Bedrock Dedicated Server*).

Empaquetages :

| Extension | Contenu ZIP |
|-----------|-------------|
| `.mcpack` | `manifest.json` racine (+ assets) |
| `.mcaddon` | Souvent pack combinant comportement + resources (nested `.mcpack`) |

Extractions téléchargées doivent conserver dossier UUID version pour év collisions multi versions test.

Le coverage catalogue **Bedrock correct** depuis CurseForge seul sera partiel hors MVP enrichissements MCPEDL (voir `docs/ROADMAP.md`).

## PocketMine-MP (alternatif PHP Bedrock)

- Répertoire plugins : `/plugins/` (`.phar` plugin typique PocketMine YAML)

Parser manifest aligné quasi plugin Bukkit YAML variantes — garder classe parser isolée **`PocketMineManifestParser`** future.

## Datapacks vanilla

Chemin jeu : `/world/datapacks/<slug_pack>/`

Fichier racine `pack.mcmeta` JSON définit `pack.pack_format`.

Rechargements fréquent endommagent TPS joueurs : notifier admin production.

## Sémantique versions Minecraft (nonsemver pur)

Familles :

| Type | Pattern typique |
|------|-----------------|
| Release stable | `1.20`, `1.20.4` |
| Snapshot | `24w14a` année/semaine/lettre |
| Pre-release | `1.21-pre3` |
| Release candidate | `1.21-rc1` |

Comparaison doit être encapsulée `McVersion.php` :

- Parsing snapshot vs release canonical ordering Mojang évolutif ⇒ tests unit Pest obligatoires (cf. plan module).
- Ranges tiers utiliser notation intervalle Maven Forge (`[1.20,1.21)`), pas strict semver NPM.

Tester cas limites :

- pré vs rc vs release même mineure
- version absente snapshot sur panel stable production

## Compatibilité déclarations upstream

### Paper/Bukkit famille

Champ **`api-version`** dans `plugin.yml` indique famille API Bukkit Paper ciblée mais ne garantit absence NMS usages plugin — afficher disclaimers utilisateur.

### Forge / NeoForge

`mods.toml` section :

```
[[dependencies.minecraft]]
mandatory=true
versionRange="[1.20.4]"
```

NeoForge fichier équivalent évolutif : valider contre modèle officiel lors impl parse TOML (attention parser Forge non strict 100 pourcent spec TOML standard).

### Fabric / Quilt

`depends` map key `minecraft` avec range version string OU tableau contraintes.

Ne pas installer auto dépendances `recommends` ou `suggests`.

## Hot reload / lifecycle

| Loader | Guidance module |
|--------|----------------|
| Paper plugins | Prefer restart durable |
| Forge/Fabric mods | Restart obligatoire |
| Proxies légers Velocity | Restart proxy si aucun subsystem swap live |
| BDS behaviour packs ajout | Reload monde si supporté sinon restart BDS |
| Datapack | `/reload` risque pics TPS ⇒ fenêtre hors pointe utilisateurs |

Lister raisons dans changelog UI après action install/update.

## Antipatterns parsing / identification

Slugs projet **ne suffisent pas** : toujours clé `(source_external_id[, file_id])`.

`plugin.yml` absent plugin mal packagé : fallback lecture nom fichier jar + WARN policy.

Forge TOML : ne pas splitter naïvement lignes — utiliser bibliothèque parse robust.

Bedrock UUID dependencies : suivre liaison via map uuid → dossier physique copié.

Ne jamais exécuter binaire téléchargé côté Panel pour « analyser » : introspection doit être lecture statique fichiers hors process.

## Liens lectures complémentaires (à consulter via context7 lors dev)

Paper dev docs loader plugin ; Fabric loom metadata ; Forge MDK sample `mods.toml` ; Microsoft docs BDS.
