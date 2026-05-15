# Stratégie de tests — Pest, VCR, Playwright

Objectif global : garder forte confiance sur **chemins critiques** téléchargements, mapping versions MC, rollback, résolution graphe dépendances — sans surcharge maintenance inutile sur glue UI encore volatile early stage.

Voir aussi `CLAUDE.md` § 7 (TDD obligatoire ciblée).

## Pyramide cible volumétrique effort

Répartition aspirationnelle lignes temps engineer :

| Couche | Part effort | Technique |
|--------|-------------|-----------|
| Unit | ~70 % | Pest pur assertions services & parsers sans IO |
| Intégration | ~20 % | SQLite `:memory:` + **php-vcr** HTTP providers fake |
| E2E | ~10 % | Playwright contre stack docker panel local blueprint installé |

Chiffres ajustés réellement après instrumentation coverage première milestone.

## Unit (`tests/Unit`)

Cibles prioritaires **avant merges logique**:

| Zone | Pourquoi |
|------|-----------|
| `PmcpVersionLogParser` + fixtures `tests/stubs/logs/*.log` | Bannières de démarrage (Java + Bedrock), normalisation ANSI/BOM ; **zéro I/O** — `tests/Unit/PmcpVersionLogParserTest.php` |
| `McVersionComparator` futur service | quirks snapshots pre/rc |
| `DependencyGraphResolver` | cycles optionnels soft fails |
| Mappers providers Modrinth/CurseForge | golden files snapshot |
| `PathResolverPolicyGuard` mocks | garantit pas traversal |

Interdictions : pas d’HTTP réseau ; pas PDO externe MariaDB CI lourd.

Mocks : faker seeds fixes tests reproductibles.

## Intégration (`tests/Integration`)

Stack :

- Migrate minimal tables blueprint extension + sqlite temporairement ;
- Laravel container réel léger bootstrap testbench blueprint (après scaffolding technique panel extension harness future).

Cas :

- Routes internes téléchargements simul Wing client fake double ;
- Jobs queue sync driver fake pour assert ordre backups avant update ;

**php-vcr** (`php-vcr/php-vcr`) :

- dossier cassette `tests/Integration/Providers/Modrinth/cassettes/*.yml`;
- variable env `PMCP_VCR_RECORD=1` lors capture locale développeur (jamais CI par défaut).

Adapter Guzzle Laravel HTTP façade pour passer à travers client instrumenté ou wrapper custom middleware test only.

Assertions checksum mapping résultats hash stable snapshot json.

## E2E (`tests/E2E`)

Playwright scénarios haut niveau (post stabilisation première UI prototype) :

1. Login utilisateur fixture panel seed docker compose ephemeral.
2. Navigation onglet module serveur jeu → recherche faux plugin léger téléchargeable petite taille OSS fixture hébergée locale statique nginx sidecar évite dépend internet CI flaky.
3. Install → fichier presence via mock Wings HTTP double (si full docker trop lourd early : marquer groupe `@slow` désactivé default pipeline PR).

Matrice Panels :

| Job CI matrix | rationale |
|---------------|-----------|
| `PTERO_TAG=xxx` blueprint target | regressions blueprint hooks |
| `PELICAN_TAG=yyy` correspondant Pelican fork | divergence Filament layouts |

Voir future `.github/workflows/ci.yml` fichier hors scope ce dépôt doc-only actuel mais référencé ici.

## Snapshot tests Pest

Ajouter pest plugin snapshots (`pestphp/pest-plugin-snapshots` ou équivalent) pour JSON canonical ordering keys mapper.

Commits snapshots approuvé review conscient (diff lisible YAML/JSON indented stable).

Renormaliser automatiquement tri clés objet PHP `ksort` profond pré export snapshot évite jitter ordre associative.

## Couverture

Commande aspirationnelle après composer tooling :

```
composer test:coverage
```

Génération HTML `coverage/index.html` local review branches sensibles Wings integration.

Gate CI : `--min=80` global ; exceptions fichiers codegen boilerplate whitelistés petite surface.

Pour services métier : seuil `--min=95` ciblé `src/backend/Services`.

## Variables environment tests

```
APP_ENV=testing
CACHE_DRIVER=array
QUEUE_CONNECTION=sync           # phases early ; basculer redis fake plus tard intégration jobs
CURSEFORGE_API_KEY=fake_but_lengthy_token_stub
MODRINTH_ACCESS_TOKEN=null      # facultatif pipelines public read only
PMCP_DISABLE_EXTERNAL_NET=1     # forcera uniquement replay VCR
```

## Développement local panel complet docker

Recommandé futur dossier `./local-test-panel/` (gitignored `.gitignore` racine projet) décrivant :

- service `mysql`/`mariadb`
- redis
- wings dev (optionnel nested VM lourd skip laptop small)
- panel php-fpm nginx reverse

Workflow dev :

```
docker compose -f local-test-panel/pterodactyl.yml up -d
composer run blueprint:publish-dev   # hypothetical script wrapping blueprint -i
npm run watch:frontenddual         # hypothetical parallel vue+livewire asset watchers
```

Document exact à écrire quand scaffolding applicatif existe : pointer depuis README section contributing.

### Hot reload développeurs

Frontend Pterodactyl : ancien monde webpack mix ⇒ possible `npm run dev` proxy.

Pelican vite / mix selon upstream : suivre conventions panel target.

Éviter recharger blueprint complet à chaque edit PHP route : liaison symlinks développeurs documentée advanced.

## Qualité transverse

- **Mutation testing** (option post maturité) sur `McVersion` & dependency resolver ;
- **Static analysis** PHPStan rule custom interdisant `exec`, `shell_exec`, `proc_open` dans namespace `Pmcp\` protections sécurité ;
- **Secret scanning** git hooks pre-commit `gitleaks` opt-in hébergeur.

## Signaux CI failure action plan

| Symptôme | Action |
|----------|--------|
| Cassette VCR mismatch | Re-record localement version API stable fixture verrouillée date |
| Snapshot mapper changed | Valider intentionnel PR description fournir diff humain |
| Playwright flake | Isoler réseau → réduire parallélisme workers temporairement |

Tester toujours **PHP version matrix** min support panel (8.2 / 8.3) pour éviter surprises typed properties readonly différences.
