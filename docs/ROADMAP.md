# Roadmap — au-delà du MVP v1.0

Le **MVP** couvre onze capacités listées `CLAUDE.md` § 2 (browse, install, updates planifiés, pins, deps, compat MC, backups, rollback, édition configs simples, presets). Tout ce qui suit est **hors portée initiale** mais ordonné pour arbitrage produit.

## v1.0 (MVP) — rappel

Livrable principal : extension Blueprint installable Pterodactyl & Pelican avec sources **Modrinth + CurseForge**, policies admin, double UI, jobs queue pour opérations longues, persistance tables `pmcp_*`.

Complexité globale estimée : **XL** (plusieurs mois équipe réduite).

## v1.1 — quick wins post-MVP

| Item | Objectif | Dépendances | Taille |
|------|----------|-------------|--------|
| Upload manuel `.jar` / `.phar` / packs Bedrock | Permettre artefacts non listés catalogue | validation antivirus optionnelle + PathResolver | M |
| Installation via URL directe signée / whitelisted domaine | Intégration pipelines CI utilisateur avancés | politique sécurité strict SSRF protection | L |
| Détection conflits manifestes (duplicatas classes, mixins) | Réduire tickets support crash obscurs | parse avancé bytecode / heuristique | XL |

## v1.2 — audit & sécurité

| Item | Objectif | Dépendances | Taille |
|------|----------|-------------|--------|
| Journalisation audit actions sensibles | Traçabilité qui a installé quoi quand | table `pmcp_audit_log` + UI filtres | M |
| Scan vuln (OSV.dev / GitHub Advisory API) | Signalement versions connues vulnérables | cache résultats + politique severities | L |

## v1.3 — sources additionnelles

| Item | Objectif | Dépendances | Taille |
|------|----------|-------------|--------|
| SpigotMC via Spiget | Couverture plugins absents Modrinth | rate limit + mapping DTO | L |
| Hangar PaperMC | Source officielle plugins modernes Paper | auth API si requise | M |
| MCPEDL / agrégats Bedrock respectueux | Combler trou catalogue Bedrock | conformité robots / partenariats | XL |

## v1.4 — opérations admin massives

| Item | Objectif | Dépendances | Taille |
|------|----------|-------------|--------|
| Bulk apply preset N→M serveurs filtrés | Gains temps équipes support | UI confirmation + file d’attente jobs | L |
| Templates policies réutilisables | Standardiser politiques par plan commercial | modèle export/import JSON signé | M |

## v2.0 — features majeures

| Item | Objectif | Dépendances | Taille |
|------|----------|-------------|--------|
| Provider GitHub Releases générique | Auto track projects OSS multi assets | auth PAT scopes + mapping release asset patterns | L |
| Marketplace presets partagés (opt-in) | Écosystème communautaire curaté | modération + signing packages | XL |

## Hors roadmap (rejet explicite court terme)

Éditeur configs in-game avec **LSP** complet (autocomplétion schémas mods complexes) : ROI faible vs maintenance; rester sur éditeur fichiers panel existant amélioré incremental.

Intégration paiement marketplace mods tiers : hors vision open-source actuelle.

## Comment prioriser une entrée

1. Discuter risque sécurité / charge support.
2. Estimer complexité table ci-dessus vs bande passante.
3. Ouvrir ADR mini si impacte schéma DB ou surface API publique panel.

Revue trimestrielle roadmap vs retours early adopters hébergeurs.
