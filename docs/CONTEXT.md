# CONTEXT — périmètre produit et lexique Minecraft

## Pitch

Ce module Blueprint étend **Pterodactyl Panel** et **Pelican Panel** pour centraliser par **serveur** la découverte, l’installation, les mises à jour planifiées, le rollback et la gestion de presets de plugins / mods / addons Minecraft, depuis **Modrinth** et **CurseForge**, tout en donnant aux **admins panel** les **policies** (whitelist/blacklist) qui encadrent ce que peuvent faire les utilisateurs finaux sur leurs serveurs.

Voir aussi `CLAUDE.md` § 2 pour le snapshot technique.

## Personas

### Admin hébergeur Panel

Possède l’installation Pterodactyl ou Pelican, crée les nœuds, assigne les users. Ses objectifs avec ce module :

- éviter installations arbitraires de binaires inconnus ;
- limiter les sources ou catégories (ex : pas de mods NMS cassants) ;
- garder une cohérence multi-clients avec des presets réutilisables.

### Utilisateur avec accès à un ou plusieurs serveurs

Veut éviter FTP/SSH pour poser les `.jar` ou packs Bedrock ; veut des mises à jour contrôlées et un historique permettant le rollback après une mise à jour foireuse.

### Power-user / équipe technique cliente

Construit une « pile » de plugins commune (Paper + Fabric mods, proxies, etc.), la sauvegarde en preset, la ré-applique sur plusieurs serveurs (même jeu, environnements différents).

### Intégrateur source (future extension)

Représente un futur développeur qui ajouterait une **nouvelle implémentation** de `PluginProvider` (voir `docs/PROVIDERS.md`, `src/providers/CLAUDE.md`). Son besoin principal : une spec claire du DTO normalisé et du cycle cache / rate-limit, sans changer le métier métier Panel.

## User journeys clés

### 1 — Plugin Paper depuis l’onglet serveur

Sur un egg Paper configuré avec la bonne version MC, l’utilisateur ouvre le module, cherche « LuckPerms », filtre loaders Paper/Spigot, installe. Le backend passe par Wing pour écrire dans `plugins/` (path résolu par loader), vérifie la policy admin, enqueue un backup optionnel puis l’installation. L’UI indique si un **restart** est requis.

### 2 — Auto-update hebdo avec rollback automatique après crash

L’admin active les mises à jour planifiées par serveur. Un job quotidien compare les versions (Modrinth/CurseForge) aux plugins installés, respecte les **pins**. Avant mise à jour, backup automatique du dossier plugins/mods. Si le serveur ne repasse pas en « running » ou si la console signale une erreur fatale post-start, **rollback** vers le snapshot précédent (voir `docs/ARCHITECTURE.md`).

### 3 — Preset de 5 plugins sur 3 serveurs

Création d’un preset utilisateur (« stack survival »). Application serveur-par-serveur : le backend vérifie compat MC + loader + dependencies pour chaque cible avant d’enqueue les jobs ; les échecs partiels sont remontés par serveur avec détail.

### 4 — Policies admin (Modrinth only + blocage NMS)

L’admin restreint la source à Modrinth, ajoute une règle de catégories autorisées, et bloque tout artefact étiqueté ou dépendant de pratiques NMS non supportées dans la fenêtre MC annoncée. Les requêtes panel refusées retournent `PolicyViolationException` (HTTP 422/403 selon stratégie du panel).

### 5 — Addon Bedrock sur BDS

Sur un egg Bedrock Dedicated Server, après résolution monde par défaut, le téléchargement `.mcpack` / `.mcaddon` est envoyé sous le dossier comportement/resource packs prévu dans `docs/MINECRAFT-PRIMER.md`; activation via métadonnées monde (liste packs). Restart ou reload monde selon règle documentée.

## Glossaire Minecraft

### Loader

**Loader** désigne la stack d’exécution qui charge le code tiers : exemples Paper, Forge, NeoForge, Fabric, Quilt, Velocity, BungeeCord, Waterfall, **BDS** (Bedrock Dedicated Server), PocketMine — chacun a des chemins et manifests différents (table dans `docs/MINECRAFT-PRIMER.md`).

### Termes de contenu

- **Plugin** : typiquement code Java sous **Bukkit/Spigot/Paper** dans `plugins/`.
- **Mod** : artefacts pour **Forge/Fabric/Quilt** dans `mods/`.
- **Addon** : terme dominant côté **Bedrock** (behavior + resource packs).
- **Datapack** : logique monde vanilla sous `world/datapacks/`.
- **Resource pack / Behavior pack** : assets Bedrock (voir `manifest.json`).
- **Modpack** : ensemble versionné distribué (souvent CurseForge) ; ici représenté par **presets** du module plus catalogue externe.

### Manifests (références)

Représentatifs :

- Plugins Java : `plugin.yml`; proxies `bungee.yml`, `velocity-plugin.json`.
- Mods Forge/NeoForge : `META-INF/mods.toml`, `META-INF/neoforge.mods.toml`; legacy Forge `mcmod.info`.
- Fabric / Quilt : `fabric.mod.json`, parfois `quilt.mod.json`.
- Bedrock : `manifest.json` à la racine du pack.

Toujours valider contre la doc upstream (Paper, Fabric Loader, Forge, Microsoft Learn pour BDS) via **context7** quand tu implémentes un parser.

### Version MC vs version loader vs version projet

**Version Minecraft** : ex. `1.20.4`, snapshots `24w14a`.

**Range** : exemple notation intervalle `[1.20,1.21)` (style Maven utilisé dans `mods.toml` / depends).

**Version loader** : ex. Forge sans lien 1‑à‑1 avec la version jeu affichée côté user — le DTO `NormalizedPlugin` et `McVersion` (service prévu) doivent garder trois axes distincts : jeu, loader, version artefact.

### NMS (`net.minecraft.server`)

Accès directs au serveur refactoré Mojang ; change à chaque version mineure. Plugins qui en dépendent cassent vite. À signaler comme risque dans l’ UI et configurable en policy (« interdits » ou warning).

## Use cases administrateur

Sans policies, tous les artefacts Modrinth/CurseForge seraient téléchargeables : risque légal / technique / réputationnel pour l’hébergeur.

Use cases :

- **Whitelist de sources** : Modrinth seul ou CurseForge seul ou les deux ;
- **Blacklist par slug projet** ou par catégorie ;
- **Règle de loader** par serveur (interdire Fabric sur un monde Paper-only egg) ;
- **Presets imposés** : « nouveau serveur survie Paper » doit au minimum avoir X plugins.

Les policies vivent dans `pmcp_policies` (voir `docs/ARCHITECTURE.md`). `pmcp.policies.manage` est réservé au rôle admin panel.

## Anti-cas

En **v1.0**, le module **ne** couvre pas (voir `docs/ROADMAP.md`) :

- détection automatique fine de conflits entre mods deux-à-deux avant install ;
- opérations bulk cross-serveurs en un clic depuis l’UI admin globale ;
- scan CVE/OSV automatique systématique ;
- audit log conformité niveau SOC2/HIPAA ;

et **sources supplémentaires** (SpigotMC/Hangar/MCPEDL/GitHub Releases, uploads manuel / URL brute) hors MVP.
