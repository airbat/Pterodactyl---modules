# pterodactyl-mc-plugins

![License](https://img.shields.io/badge/license-MIT-green)
![Panels](https://img.shields.io/badge/panels-Pterodactyl%20%7C%20Pelican-blue)
![Status](https://img.shields.io/badge/status-extension%20bootstrap-yellow)

> **État actuel** : hiérarchie `CLAUDE.md`, documentation, `conf.yml` Blueprint, première route client `/health`, vue admin minimale, migration `pmcp_plugins` de base, script `scripts/package-blueprint.sh`. Les providers Modrinth/CurseForge et l’UI dashboard arrivent ensuite.

> **Identifiant Blueprint** : `pteromcplugins` (lettres minuscules uniquement, contrainte Blueprint). Le nom du dépôt Git peut rester `pterodactyl-mc-plugins`.

## Pitch

**pterodactyl-mc-plugins** est une extension [**Blueprint**](https://blueprint.zip/) pour **Pterodactyl Panel** et **Pelican Panel**. Elle permet aux joueurs et administrateurs de parcourir **Modrinth** et **CurseForge**, d’installer ou mettre à jour des plugins Java, mods et addons Bedrock, avec backups, rollback, épinglage de version et policies côté administrateur — le tout sans quitter le panel, en s’appuyant sur l’**API Wings**.

## Aperçu visuel

> _Placeholder_ : ajouter ici un screenshot ou une courte démo GIF une fois l’UI disponible.

## Fonctionnalités prévues (MVP)

- Parcourir et rechercher le catalogue Modrinth + CurseForge
- Installation en un clic (téléchargement → dépôt via Wings dans le bon dossier)
- Détection des mises à jour et journal des changements
- Mises à jour planifiées (cron) avec sauvegarde préalable
- Épinglage de version
- Résolution de dépendances
- Contrôle de compatibilité version Minecraft + loader
- Sauvegarde automatique avant toute modification
- Rollback en un clic
- Gestion édition des fichiers de configuration courants (YAML / TOML / JSON)
- Presets réutilisables (listes cohérentes de plugins pour plusieurs serveurs)

Pour **build** l’archive depuis ce dépôt (hors CI) :

```bash
./scripts/package-blueprint.sh
# produit dist/pteromcplugins.blueprint (ignoré par git : *.blueprint)
```

## Compatibilité cible

| Composant | Version indicative |
|-----------|-------------------|
| Pterodactyl Panel | 1.11+ |
| Pelican Panel | 1.x (valider contre ton instance) |
| Blueprint | même `target` que `conf.yml` (voir fichier) |
| PHP | ≥ 8.2 |

Les versions exactes seront gelées lorsque le packaging `.blueprint` sera publié.

## Installation (une fois les releases disponibles)

```bash
# 1. Télécharger le .blueprint depuis Releases
wget https://github.com/VOTRE_ORG/pterodactyl-mc-plugins/releases/latest/download/pteromcplugins.blueprint

# 2. Placer dans le répertoire du panel
sudo mv pteromcplugins.blueprint /var/www/pterodactyl/

# 3. Installer via Blueprint (identifiant = pteromcplugins)
cd /var/www/pterodactyl
sudo blueprint -i pteromcplugins
```

Remplace `VOTRE_ORG` par l’organisation GitHub réelle lorsque le dépôt est public.

## Configuration

Ajouter dans l’`.env` du panel (valeurs exemple) :

```
CURSEFORGE_API_KEY=xxxxxxxx
# Optionnel futur pour Modrinth (meilleurs quotas / stats)
MODRINTH_PERSONAL_ACCESS_TOKEN=
```

Ne jamais exposer ces clés côté navigateur ; les appels restent backend.

## Prise en main rapide (futur)

1. **Parcourir** le catalogue depuis l’onglet serveur Minecraft.
2. **Installer** un plugin/mod — le module demandera un redémarrage si nécessaire.
3. **Mettre à jour** ou **annuler** (rollback) après un backup automatique.

## FAQ

### Pourquoi l’identifiant Blueprint (`pteromcplugins`) diffère du nom du dépôt Git ?

[Blueprint](https://blueprint.zip/docs/configs/confyml) impose `info.identifier` en **lettres minuscules `a-z` uniquement**. Le dépôt Git peut garder des tirets (`pterodactyl-mc-plugins`), les commandes CLI utilisent `pteromcplugins`.

### Pourquoi licence MIT ?

Pour maximiser l’adoption par les hébergeurs et permettre adaptations internes tant que la licence reste accolée aux distributions dérivées.

### Comment ajouter une nouvelle source (ex. Hangar) ?

Implémenter l’interface `PluginProvider`, ajouter mapping DTO et tests — voir `docs/PROVIDERS.md` et `src/providers/CLAUDE.md`.

### Les fichiers sont-ils sauvegardés avant mise à jour ?

Oui dans le périmètre MVP : métadonnées dans `pmcp_backups`, contenu réel via opérations Wings (compress/décompress) — cf. `docs/ARCHITECTURE.md`.

### Que se passe-t-il si Modrinth est indisponible ?

Les résultats CurseForge continuent si autorisés par policies ; un bandeau avertit l’utilisateur (stratégie cache SWR décrite dans `src/providers/CLAUDE.md`).

### Le Bedrock est-il vraiment supporté dans le MVP ?

Oui sur le papier fonctionnel avec CurseForge (couverture partielle). Une source MCPEDL dédiée est **roadmap** (`docs/ROADMAP.md`).

### Puis-je utiliser uniquement Pelican ?

Le projet vise une **double compatibilité** ; choisis Pelican comme panel hôte et suis les sections Livewire des guides.

## Contributing

Merci de lire avant toute PR :

1. `CLAUDE.md` — comportement attendu de l’agent / équipe dev
2. `docs/CONVENTIONS.md` — style PHP, tests, logs
3. `docs/TESTING.md` — stratégie Pest / Playwright / VCR

Ouvre une issue pour les grosses évolutions fonctionnelles afin de valider le périmètre (`docs/ROADMAP.md`).

## Licence

MIT — voir le fichier [`LICENSE`](LICENSE).

## Remerciements

- Communautés [Pterodactyl](https://pterodactyl.io/), [Pelican](https://pelican.dev/), Blueprint, [Modrinth](https://modrinth.com/), [CurseForge](https://www.curseforge.com/)
- Minecrafters maintenant quelques clic de moins avant le prochain rollback propre 😉
