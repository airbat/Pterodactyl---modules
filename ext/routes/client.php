<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Routes étend `/api/client/extensions/{identifier}` (voir blueprint.zip/docs/concepts/routing).
 * Middleware / auth sont appliqués par le Panel hôte lors du chargement de ce fichier.
 *
 * La recherche catalogue est définie ici (closure) plutôt que dans une classe dédiée : certaines
 * installations ne résolvent pas les contrôleurs d’extension via le conteneur Laravel
 * (BindingResolutionException sur CatalogController) si l’autoload Composer du module Blueprint
 * n’est pas encore pris en compte.
 */
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'extension' => '{identifier}',
    'version' => '{version}',
    'blueprint_target' => '{target}',
    'blueprint_matches_target' => '{is_target}',
    'blueprint_engine' => '{engine}',
]));

Route::get('/catalog/search', static function (Request $request): JsonResponse {
    $modrinthApi = 'https://api.modrinth.com/v2';
    $userAgent = 'pteromcplugins/{version} (+https://blueprint.zip)';

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
        $response = Http::timeout(20)
            ->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => $userAgent,
            ])
            ->get($modrinthApi . '/search', [
                'query' => $query,
                'limit' => $limit,
                'offset' => $offset,
            ]);
    } catch (Throwable $e) {
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
