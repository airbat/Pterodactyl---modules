<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

/**
 * Routes étend `/api/client/extensions/{identifier}` (voir blueprint.zip/docs/concepts/routing).
 * Middleware / auth sont appliqués par le Panel hôte lors du chargement de ce fichier.
 *
 * Closures uniquement (pas de contrôleurs dédiés) pour éviter BindingResolutionException
 * si l’autoload des classes d’extension Blueprint n’est pas résolu.
 */
$modrinthBase = 'https://api.modrinth.com/v2';
$modrinthUa = 'pteromcplugins/{version} (+https://blueprint.zip)';

/** @var callable(string, array<string, mixed> = []): \Illuminate\Http\Client\Response $modrinthGet */
$modrinthGet = static function (string $path, array $query = []) use ($modrinthBase, $modrinthUa) {
    return Http::timeout(25)
        ->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => $modrinthUa,
        ])
        ->get($modrinthBase . $path, $query);
};

$validProjectId = static function (string $id): bool {
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $id);
};

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'extension' => '{identifier}',
    'version' => '{version}',
    'blueprint_target' => '{target}',
    'blueprint_matches_target' => '{is_target}',
    'blueprint_engine' => '{engine}',
]));

// Route plus spécifique en premier
Route::get('/catalog/modrinth/project/{projectId}/versions', static function (Request $request, string $projectId) use ($modrinthGet, $validProjectId): JsonResponse {
    if (!$validProjectId($projectId)) {
        return response()->json(['message' => 'Identifiant projet invalide.'], 422);
    }

    $validator = Validator::make($request->query(), [
        'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        'offset' => ['sometimes', 'integer', 'min:0', 'max:100000'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $data = $validator->validated();
    $limit = (int) ($data['limit'] ?? 20);
    $offset = (int) ($data['offset'] ?? 0);

    try {
        $response = $modrinthGet('/project/' . rawurlencode($projectId) . '/version', [
            'limit' => $limit,
            'offset' => $offset,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Réseau indisponible vers Modrinth.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 503);
    }

    if ($response->status() === 404) {
        return response()->json(['message' => 'Projet ou versions introuvables.'], 404);
    }
    if (!$response->successful()) {
        return response()->json([
            'message' => 'Modrinth a répondu une erreur.',
            'status' => $response->status(),
        ], 502);
    }

    $list = $response->json();
    if (!is_array($list)) {
        $list = [];
    }

    $versions = [];
    foreach ($list as $v) {
        if (!is_array($v)) {
            continue;
        }
        $primary = null;
        foreach ($v['files'] ?? [] as $f) {
            if (is_array($f) && !empty($f['primary'])) {
                $primary = $f;
                break;
            }
        }
        if ($primary === null && isset($v['files'][0]) && is_array($v['files'][0])) {
            $primary = $v['files'][0];
        }

        $depList = [];
        foreach (is_array($v['dependencies'] ?? null) ? $v['dependencies'] : [] as $d) {
            if (!is_array($d)) {
                continue;
            }
            $depList[] = [
                'project_id' => isset($d['project_id']) ? (string) $d['project_id'] : null,
                'dependency_type' => isset($d['dependency_type']) ? (string) $d['dependency_type'] : null,
            ];
        }

        $versions[] = [
            'id' => isset($v['id']) ? (string) $v['id'] : '',
            'name' => isset($v['name']) ? (string) $v['name'] : '',
            'version_number' => isset($v['version_number']) ? (string) $v['version_number'] : '',
            'date_published' => $v['date_published'] ?? null,
            'version_type' => isset($v['version_type']) ? (string) $v['version_type'] : null,
            'downloads' => isset($v['downloads']) ? (int) $v['downloads'] : 0,
            'loaders' => is_array($v['loaders'] ?? null) ? $v['loaders'] : [],
            'game_versions' => is_array($v['game_versions'] ?? null) ? $v['game_versions'] : [],
            'changelog' => isset($v['changelog']) ? (string) $v['changelog'] : null,
            'primary_file' => is_array($primary) ? [
                'filename' => isset($primary['filename']) ? (string) $primary['filename'] : '',
                'size' => isset($primary['size']) ? (int) $primary['size'] : 0,
                'sha512' => isset($primary['hashes']['sha512']) ? (string) $primary['hashes']['sha512'] : null,
            ] : null,
            'dependencies' => $depList,
        ];
    }

    return response()->json([
        'provider' => 'modrinth',
        'project_id' => $projectId,
        'limit' => $limit,
        'offset' => $offset,
        'versions' => $versions,
    ]);
})->where('projectId', '[A-Za-z0-9][A-Za-z0-9_-]{0,127}');

Route::get('/catalog/modrinth/project/{projectId}', static function (string $projectId) use ($modrinthGet, $validProjectId): JsonResponse {
    if (!$validProjectId($projectId)) {
        return response()->json(['message' => 'Identifiant projet invalide.'], 422);
    }

    try {
        $response = $modrinthGet('/project/' . rawurlencode($projectId), []);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Réseau indisponible vers Modrinth.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 503);
    }

    if ($response->status() === 404) {
        return response()->json(['message' => 'Projet introuvable.'], 404);
    }
    if (!$response->successful()) {
        return response()->json([
            'message' => 'Modrinth a répondu une erreur.',
            'status' => $response->status(),
        ], 502);
    }

    $p = $response->json();
    if (!is_array($p)) {
        return response()->json(['message' => 'Réponse Modrinth invalide.'], 502);
    }

    $slug = isset($p['slug']) ? (string) $p['slug'] : '';
    $ptype = isset($p['project_type']) ? (string) $p['project_type'] : 'mod';
    $pathSegment = match ($ptype) {
        'plugin' => 'plugin',
        'modpack' => 'modpack',
        'resourcepack' => 'resourcepack',
        default => 'mod',
    };

    return response()->json([
        'provider' => 'modrinth',
        'project' => [
            'id' => isset($p['id']) ? (string) $p['id'] : $projectId,
            'slug' => $slug,
            'title' => isset($p['title']) ? (string) $p['title'] : '',
            'description' => isset($p['description']) ? (string) $p['description'] : '',
            'body' => isset($p['body']) ? (string) $p['body'] : '',
            'project_type' => $ptype,
            'icon_url' => $p['icon_url'] ?? null,
            'downloads' => isset($p['downloads']) ? (int) $p['downloads'] : null,
            'followers' => isset($p['followers'])
                ? (int) $p['followers']
                : (isset($p['follows']) ? (int) $p['follows'] : null),
            'license' => is_array($p['license'] ?? null)
                ? (isset($p['license']['id']) ? (string) $p['license']['id'] : null)
                : (isset($p['license']) ? (string) $p['license'] : null),
            'client_side' => $p['client_side'] ?? null,
            'server_side' => $p['server_side'] ?? null,
            'source_url' => $p['source_url'] ?? null,
            'issues_url' => $p['issues_url'] ?? null,
            'wiki_url' => $p['wiki_url'] ?? null,
            'discord_url' => $p['discord_url'] ?? null,
            'page_url' => $slug !== '' ? 'https://modrinth.com/' . $pathSegment . '/' . rawurlencode($slug) : null,
        ],
    ]);
})->where('projectId', '[A-Za-z0-9][A-Za-z0-9_-]{0,127}');

Route::get('/catalog/search', static function (Request $request) use ($modrinthGet): JsonResponse {
    $validator = Validator::make($request->query(), [
        'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
        'offset' => ['sometimes', 'integer', 'min:0', 'max:50000'],
        'server' => ['sometimes', 'nullable', 'string', 'max:64'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $query = isset($data['q']) ? trim((string) $data['q']) : '';
    $limit = (int) ($data['limit'] ?? 15);
    $offset = (int) ($data['offset'] ?? 0);

    try {
        $response = $modrinthGet('/search', [
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Réseau indisponible vers Modrinth.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 503);
    }

    if (!$response->successful()) {
        return response()->json([
            'message' => 'Modrinth a répondu une erreur.',
            'status' => $response->status(),
        ], 502);
    }

    $payload = $response->json();
    $hits = is_array($payload['hits'] ?? null) ? $payload['hits'] : [];

    $items = [];
    foreach ($hits as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = isset($row['slug']) ? (string) $row['slug'] : '';
        $ptype = isset($row['project_type']) ? (string) $row['project_type'] : 'mod';
        $pathSegment = match ($ptype) {
            'plugin' => 'plugin',
            'modpack' => 'modpack',
            'resourcepack' => 'resourcepack',
            default => 'mod',
        };

        $items[] = [
            'provider' => 'modrinth',
            'external_id' => isset($row['project_id']) ? (string) $row['project_id'] : '',
            'slug' => $slug,
            'title' => isset($row['title']) ? (string) $row['title'] : '',
            'summary' => isset($row['description']) ? (string) $row['description'] : '',
            'project_type' => $ptype,
            'icon_url' => isset($row['icon_url']) ? $row['icon_url'] : null,
            'downloads' => isset($row['downloads']) ? (int) $row['downloads'] : null,
            'page_url' => $slug !== '' ? 'https://modrinth.com/' . $pathSegment . '/' . rawurlencode($slug) : null,
        ];
    }

    return response()->json([
        'provider' => 'modrinth',
        'total' => isset($payload['total_hits']) ? (int) $payload['total_hits'] : count($items),
        'limit' => isset($payload['limit']) ? (int) $payload['limit'] : $limit,
        'offset' => isset($payload['offset']) ? (int) $payload['offset'] : $offset,
        'items' => $items,
    ]);
});
