<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Routes étend `/api/client/extensions/{identifier}` (voir blueprint.zip/docs/concepts/routing).
 * Middleware / auth sont appliqués par le Panel hôte lors du chargement de ce fichier.
 */
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'extension' => '{identifier}',
    'version' => '{version}',
    'blueprint_target' => '{target}',
    'blueprint_matches_target' => '{is_target}',
    'blueprint_engine' => '{engine}',
]));
