# CLAUDE.md — src/providers/

> Sous-`CLAUDE.md` chargé automatiquement quand tu édites un fichier dans `src/providers/`. Pour les règles globales, voir `/CLAUDE.md`. Pour la spec produit complète, voir `docs/PROVIDERS.md`.

## Périmètre

Code qui parle aux sources externes : Modrinth, CurseForge (et providers futurs). Adapters HTTP, mapping vers le DTO normalisé, cache, rate-limit.

**HORS périmètre :** logique de business (compat check, dep resolution) — ça vit dans `src/backend/Services/`. Les providers ne renvoient QUE des données normalisées, ils ne décident pas.

## Interface PluginProvider

Tout provider implémente `src/providers/Contracts/PluginProvider.php` :

```php
interface PluginProvider
{
    public function id(): string;                    // 'modrinth', 'curseforge'
    public function search(SearchQuery $query): SearchResult;
    public function getDetails(string $externalId): NormalizedPlugin;
    public function getVersions(string $externalId, ?string $mcVersion = null, ?Loader $loader = null): array;  // Version[]
    public function download(string $versionId): StreamInterface;
    public function getDependencies(string $versionId): array;  // Dependency[]
}
```

Les DTOs (`SearchQuery`, `SearchResult`, `NormalizedPlugin`, `Version`, `Dependency`) sont définis dans `src/providers/DTOs/`. Ils sont **stables** : un changement nécessite une migration de cache.

## Schéma normalisé

**Règle absolue :** on ne renvoie JAMAIS le format brut Modrinth ou CurseForge au reste du code. Toujours mapper vers `NormalizedPlugin`.

Le frontend ne sait pas d'où vient un plugin (sauf affichage de la source/badge). Cela rend l'ajout de futurs providers transparent.

Le mapping est testé par snapshot tests dans `tests/Unit/Providers/Modrinth/MapperTest.php` (et CurseForge).

## Cache HTTP

- Tous les `GET` vers Modrinth/CurseForge sont cachés en Redis.
- Clé de cache : `pmcp:provider:<provider>:<sha256(method+url+sorted_query)>`.
- TTL :
  - **Search** : 1 heure
  - **Details / Versions** : 24 heures
  - **Download URL** (signée temporairement) : pas de cache
- Invalidation manuelle : `php artisan pmcp:cache:flush --provider=modrinth`
- Cache stale-while-revalidate : si l'API distante throw, servir le cache stale avec un warning header.

## Rate limits

**Modrinth** : 300 req/min, identifié par User-Agent.
- Token bucket en Redis : clé `pmcp:rl:modrinth`, capacité 300, refill 5/sec.
- Si épuisé, jeter `RateLimitExceededException` (caught par le job, retry avec backoff).

**CurseForge** : clé API obligatoire (`CURSEFORGE_API_KEY` dans `.env`), quotas par contrat.
- Lire la clé depuis le service container (config/services.php), jamais hardcodée.
- Si quota dépassé, jeter `RateLimitExceededException`.

**Degradation gracieuse** : si un provider est down ou rate-limited, le frontend affiche les résultats des autres providers + un warning « <provider> momentanément indisponible ».

## User-Agent

Toujours envoyer un User-Agent identifiant : `pteromcplugins/<version> (+https://github.com/<org>/pterodactyl-mc-plugins)` (slug dépôt Git ≠ identifiant Blueprint).

C'est explicitement demandé par Modrinth (cf. https://docs.modrinth.com/api/) et requis par CurseForge.

## Tests

- **php-vcr** (`php-vcr/php-vcr` + adapter Guzzle) pour rejouer les vraies réponses HTTP
- Cassettes commitées dans `tests/Integration/Providers/<provider>/cassettes/`
- Re-record avec `VCR_MODE=none` (default = playback)
- **Snapshot tests** sur le mapping → `NormalizedPlugin` (Pest snapshot plugin)

## Antipatterns providers

1. **Ne pas merger les résultats Modrinth + CurseForge naïvement** : dédoublonner par `project_url` ou par `(name, author)` quand `project_url` indisponible.
2. **Ne pas faire de ranking « maison »** côté backend pour la recherche : laisser la source ranker (chacun a son algo testé). Côté frontend, on peut filtrer/trier sur des critères neutres (date, downloads).
3. **Ne pas trust un `download_url`** sans vérification du checksum (sha512 si fourni). Modrinth fournit le hash, CurseForge fournit fileLength + fingerprint.
4. **Ne pas retry agressif** sur 4xx : c'est un bug côté nous, pas une transient error.
5. **Ne pas exposer la clé CurseForge** dans les logs : redaction via Laravel `Log::withoutContext()` ou middleware custom.

## Ajouter un futur provider

1. Créer `src/providers/<NewProvider>/Provider.php` qui implémente `PluginProvider`.
2. Créer `Mapper.php` qui transforme la réponse en `NormalizedPlugin`.
3. Enregistrer dans `config/pmcp.php` → `providers` array.
4. Ajouter cassettes VCR + tests.
5. Documenter dans `docs/PROVIDERS.md`.

Voir `docs/PROVIDERS.md` pour la spec détaillée et les particularités de chaque source.
