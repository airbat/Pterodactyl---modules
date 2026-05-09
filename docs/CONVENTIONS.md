# Conventions de code — consolidation

Résumé projet ; détails spécifiques dossiers aussi dans :

- `src/backend/CLAUDE.md`
- `src/providers/CLAUDE.md`
- `src/frontend/CLAUDE.md`

## PHP / Laravel

Style : Laravel Pint preset `laravel` (alignment PSR‑12 Laravel adapté indentation 4 espaces fichiers `.php`). Guillemets doubles majoritairement sauf divergence config Pint projet.

Naming :

| Élément | Convention |
|---------|------------|
| Classes / Enums | `StudlyCaps` |
| Méthodes / propriétés | `camelCase` |
| Constants | `SCREAMING_SNAKE_CASE` |
| fichiers classe | reflètent nom classe |

PHPStan niveau minimal **8** en CI évolutif 9 après dette résolue.

Docblocks réservées aux génériques collections non inférées ou comportements métier subtil.

Exceptions métier :

- placées sous `src/backend/Exceptions/` ;
- suffixe `Exception`;
- éviter messages utilisateur bruts depuis exception — utiliser translation keys Laravel.

Enums PHP 8.1+ pour ensembles fermés :

- `PluginSourceEnum`
- `LoaderEnum`
- `InstallationStatusEnum`
- ...

## SQL / Eloquent

Tables : préfix fixe projet `pmcp_` comme indiqué `src/backend/CLAUDE.md`.

Colonnes : `snake_case`.

Index naming : pattern `idx_pmcp_<table>_<cols_join_underscore>`.

Pas de FK physiques externes core panel : indexes simples BIGINT + composites lecture lourds (search installed par server).

Timestamps Laravel par défaut `created_at` `updated_at` sauf justification performance append-only volumétrique.

Soft deletes où rollback / désinstallation logique doit conserver ligne historique.

## JavaScript / Vue 2 (Pterodactyl)

Tooling projet (à scaffold ultérieurement) :

- ESLint recommended + plugin vue 2 (`plugin:vue/recommended` ancien monde)
- Prettier frontend : préférer `'single-quote': true`, `semi: false`, trailing commas `all`.

Composants : nom fichier PascalCase aligné nom export défaut Vue.

Props : définition objet `{ type, required, default, validator }` si valeur dérivée user.

Typage : limiter **`any`** JSDoc — préférer typedef explicites petits fichiers helpers.

Pas TypeScript dossier legacy `pterodactyl/` (alignement upstream).

## Livewire 3 / Alpine (Pelican)

Namespaces composants Laravel : `App\Livewire\Pmcp\Feature\Subfeature`.

Limiter surface publique composant : au-delà de ~5 props wireables envisager objet `Wireable` DTO imbriqué.

Alpine : éviter blobs > ~30 lignes inline — extraire `Alpine.data('pmcpInstallModal', …)` asset bundlé dédié.

Styles : Tailwind utilitaire first (preset Pelican) ; éviter surcharge globale hors scope module.

## CSS / SCSS

Tokens globaux overrides via CSS variables permettant héberger look cohérent panel.

SCSS réservé animations complexes spinner multi étapes téléchargements lourds.

## Tests (Pest)

Convention tests description style :

```php
it('rejette une version minecraft snapshot si policy stable only', fn () => ...);
```

Fixtures dossier projet `tests/Fixtures/` (à créer). Factories Laravel `database/factories/` hors migrations core.

Couvertures cibles (post tooling) :

- global ≥ **80 %** lignes pertinentes hors glue UI ;
- **≥ 95 %** sur `src/backend/Services/**/*` métier critique (compatibilité semver MC, graphe deps, rollback state machine).

## Git / Collaboration

Branches principales envisagées :

- `main` stable tags release blueprint
- éventuelle `develop` intégration continue

Feature branches préfix :

- `feat/…`
- `fix/…`
- `chore/…`
- `docs/…`

PR unique thématique : template PR devra contenir :

1. Pourquoi
2. Quoi précisément
3. Comment testé commandes réels logs
4. Captures UI si front

Squash merges sur main si petite équipe évite bruit commits intermédiaires expérimentations.

Commits : Conventional Commits (cf. racine `CLAUDE.md` § 7).

## Logging structuré

Niveaux :

| Niveau | Usage module |
|--------|----------------|
| `info` événements normal succès métier installs |
| `warning` quotas API bientôt saturation |
| `error` échecs Wings transfert |

Toujours contexte tableau associatif :

```php
Log::info('pmcp.plugin.installed', [
    'server_id' => $serverId,
    'plugin_normalized_id' => $dto->id(),
    'version_id' => $versionId,
]);
```

**JAMAIS** loguer tokens API CurseForge, secrets panel, payloads Authorization bruts erreurs utilisateur upload.

Masquage automatique prévu middleware logging custom blueprint extension.

## Commentaires inline minimalistes

Interdiction commentaires évidents du type « retourne un bool » ; acceptable :

```
// EDGE: Forge peut publier plusieurs fichiers même version curse — choisir primaryFileId officiel curse API
```

Lier issues GitHub `GH-123` quand workaround temporaire.

## Internationalisation développeurs

Specs UI : aucune phrase visible utilisateur brute dans merges ; PR review checklist `src/frontend/CLAUDE.md` i18n.

Scripts CI futur grep anti-pattern literals dans templates hors exceptions tests.
