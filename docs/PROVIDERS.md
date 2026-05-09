# Providers — contrat `PluginProvider` et sources Modrinth / CurseForge

Relire `src/providers/CLAUDE.md` pour règles cache & rate limit opérationnelles.

## Interface `PluginProvider`

Fichier prévu : `src/providers/Contracts/PluginProvider.php`.

Méthodes (signatures indicatives) :

| Méthode | Préconditions | Postconditions / Erreurs |
|---------|---------------|-------------------------|
| `id(): string` | — | Retour stable snake (`modrinth`, `curseforge`) |
| `search(SearchQuery $q): SearchResult` | `$q->query` UTF-8 sanitized longueur bornée | Agrégation paginée normalisée ou `RateLimitExceededException` |
| `getDetails(string $externalId): NormalizedPlugin` | ID connu source | `PluginNotFoundException` 404 logique |
| `getVersions(...): array` | plugin existe | tableau `Version[]` tri décroissant publication option |
| `download(string $versionId): StreamInterface` | version existe & policy allow checksum | erreurs réseau => `TransferException` wrappées |
| `getDependencies(string $versionId): array` | pareil | tableau `Dependency[]` kinds distincts |

Aucune persistance automatique depuis provider : **service agrégateur** backend décide synchro caches DB.

DTOs vivent sous `src/providers/DTOs/`.

### `SearchQuery`

Champs projet :

```
query: string                      // recherche utilisateur sanitizée
loaders: LoaderEnum[]             // filtres loaders normalisés internes
mc_versions: string[]             // filtres jeu (peut être vide = any)
categories: string[]              // taxonomy normalisée post mapping
page: positive-int
per_page: positive-int capped 50
sort: SearchSortEnum               // relevance downloads updated_at …
```

### `SearchResult`

```
items: NormalizedPlugin[]
total: int
page: int
per_page: int
```

Pagination **stable** même si dédup cross sources en couche aggregator.

### `NormalizedPlugin`

Champs projet (non exhaustifs impl fine tuning futur) :

| Champ | Type | Notes |
|-------|------|-------|
| `id` | string | composite `sourceCode:externalProjectId` |
| `source` | enum | garantit pas ambigu forge vs plugin bucket |
| `slug` | string | lisible recherche utilisateur humain |
| `name` | string | titre marketing officiel projet |
| `summary` | string | courte description |
| `description_html_safe` | string | sanitizer backend anti XSS avant render |
| `icon_url` | string nullable | CDN distant |
| `categories` | string[] | liste normalisées |
| `loaders_supported` | LoaderEnum[] | intersection fichier principal |
| `mc_versions_sparse` | string[] | peut être vide ⇒ unknown |
| `downloads_total_estimate` | int | ordre grandeur marketing |
| `followers_star_count` | int | si métrique équivalent existe |
| `created_at_upstream` | `DateTimeImmutable` | métadonnée si dispo sinon null |
| `updated_at_upstream` | idem |
| `spdx_license_id` | string nullable | ex. MIT, LGPL-3.0-only |
| `links` | structure | `homepage`, `issues`, `discord_invite`, `source_repository` urls validées HTTPS |

Nom exact propriétés figera lors scaffold code + tests snapshot.

### `Version`

```
id_internal: string            // composite version file unique source
normalized_plugin_ref: string  // lien id NormalizedPlugin
semver_or_coerced: string      // etiquette humaine téléchargement
mc_version_constraints: string[] 
loaders: LoaderEnum[]
dependencies: Dependency[]
download_http_url_backend_only: string  // réécrit avant front jamais fuite direct
filename_suggested: string
size_bytes: int
checksum_sha512_hex: ?string   // facultatif curse si md5 fallback policy
changelog_html_safe: ?string
published_at: ?DateTimeImmutable
```

Checksum policy : rejeter persistance fichier si aucun hash vérifiable & policy sécurité stricte hébergeur.

### `Dependency`

```
kind: enum(required|optional|incompatible|embedded)
target_project_ref: ?string           // slug ou id upstream si résolvable sinon null textual name only
semver_range_raw: ?string             // expression brute non normalisée (affichage troubleshooting)
confidence: enum(high|medium|low)    // heuristic mapping quality
```

## Modrinth

Base API publique HTTPS : `https://api.modrinth.com/v2`

Auth option PAT header `Authorization: <token>` améliore quotas / évite throttling communautaire trop agressif.

Headers obligatoires module : `User-Agent` identité projet stable (cf. racine sous-providers Claude).

Endpoints principaux projet :

```
GET /search
GET /project/{id|slug}
GET /project/{id}/version
GET /version/{id}
```

Loaders projet mapping interne tableau `docs/MINECRAFT-PRIMER.md` liste modrinth slugs officiels connus dynamiquement évolutifs.

Has fichiers :

- fichiers peuvent avoir `hashes.sha512`, `sha1` ...

**Pas Bedrock officiel comme catégorie Java** : attention filtres combinés recherche addons — cross sources curse customization class.

## CurseForge (Core API v1 panel commercial key)

Endpoint base **`https://api.curseforge.com/v1`** (valider évolutions doc Postman officiel).

Headers :

```
x-api-key: ${CURSEFORGE_API_KEY}
Accept: application/json
```

GAME ID minecraft constant documenté officiel **`432`** (revalider annuel révisions).

Classes utiles projet (valeurs officielles sujettes MAJ quarterly) :

| classId | domaine approximatif |
|---------|-----------------------|
| 5 | Plugins Bukkit / Spicompat |
| 6 | Mods Java |
| 4471 | Modpacks |
| 12 | Resource packs vanilla |
| 17 | Saves / monde |
| 4546 | Customization / divers Bedrock addons possibles |

Endpoints patterns :

```
GET /mods/search
GET /mods/{modId}
GET /mods/{modId}/files
GET /mods/files/{fileId}/download-url   // auth + expiration URL temporaires
```

Loaders curse parfois via `modLoaderType` enums numériques : table mapping code interne évolutive versionée JSON config `config/pmcpcursemap.php`.

Hashes retour possibles tableau `{ algorithm: Sha1|MurmurHash|Mdz5 ??? }` — documenter précisément via capture cassette VCR exemple réel fichier mod populaire.

## Cache HTTP (détails)

Middleware Guzzle Laravel custom `PmcpProvidersHttpCacheMiddleware` :

- clé Redis : `sha256(method|url_sorted_querystring)` préfix bucket provider ;
- entête sortie **`X-Pmcp-Cache-Hit`** bool debug staging seulement.

Stale policy : TTL search 3600 sec; metadata projet 86400 sec; download URL curse **non cached** hors court circuit 60 sec anti stampede même URL signée courte durée vie.

Bypass debug header `X-Pmcp-Cache-Bypass: 1` accepté uniquement si `APP_DEBUG=true`.

## Rate limit token bucket Redis

Pseudo code atomicité LUA (implémenter plus tard module) :

1. incr token avec refill rate modrinth bucket 300/min => refill 5/s arrondis
2. si dépassement : exception `RateLimitExceededException(retry_after_seconds)`

Pour curse : respect headers `Retry-After` si renvoyés.

## Ajouter un nouveau provider (checklist projet)

1. Création dossier provider + classe impl contrat interface.
2. Mapper JSON → tests snapshot (`tests/Unit/Providers/NewX/MapperTest.php`).
3. Config `config/pmcp.providers` array enable flag + credentials env keys.
4. Ajout cassettes VCR environnement sandbox record mode pipeline locale isolée CI secret OFF.
5. Documentation user limitations + matrix compat loaders.
6. PR release notes semver module + changelog blueprint `CHANGELOG.md`.

Valider légal scraping interdit hors ToS officielle source.
