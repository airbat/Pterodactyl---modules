# pterodactyl-mc-plugins

![License](https://img.shields.io/badge/license-MIT-green)
![Panels](https://img.shields.io/badge/panels-Pterodactyl%20%7C%20Pelican-blue)
![Status](https://img.shields.io/badge/status-documentation%20skeleton-orange)

> **État actuel** : ce dépôt contient la hiérarchie `CLAUDE.md`, la documentation d’architecture/conventions et un stub Blueprint (`conf.yml`). Le code applicatif (routes, jobs, providers HTTP, UI) arrive dans des itérations suivantes.

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
wget https://github.com/VOTRE_ORG/pterodactyl-mc-plugins/releases/latest/download/pterodactyl-mc-plugins.blueprint

# 2. Placer dans le répertoire du panel
sudo mv pterodactyl-mc-plugins.blueprint /var/www/pterodactyl/

# 3. Installer via Blueprint
cd /var/www/pterodactyl
sudo blueprint -i pterodactyl-mc-plugins
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
