<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

require_once dirname(__DIR__) . '/app/Services/ServerMcContextBuilder.php';
require_once dirname(__DIR__) . '/app/Services/PmcpInstallCompressPaths.php';
require_once dirname(__DIR__) . '/app/Services/PmcpInstallDirectoryBackup.php';
require_once dirname(__DIR__) . '/app/Services/PmcpHttpException.php';
require_once dirname(__DIR__) . '/app/Services/PmcpArtifactInstall.php';
require_once dirname(__DIR__) . '/app/Services/PmcpCheckUpdatesBatch.php';
require_once dirname(__DIR__) . '/app/Services/PmcpWorkspacePath.php';
require_once dirname(__DIR__) . '/app/Services/PmcpInstallBackupRestore.php';
require_once dirname(__DIR__) . '/app/Services/PmcpPresetItems.php';

/**
 * Routes étend `/api/client/extensions/{identifier}` (voir blueprint.zip/docs/concepts/routing).
 * Middleware / auth sont appliqués par le Panel hôte lors du chargement de ce fichier.
 *
 * Classe métier incluse avec `require_once` (`ext/app/Services`) pour éviter
 * BindingResolutionException si l'autoload Composer de l’extension n’est pas enregistré.
 */

/*
 * Aligné sur conf.yml info.version ; Blueprint doit remplacer la valeur au build.
 * Si le placeholder {version} reste (dev / chargement direct), on force un UA stable :
 * CurseForge / Cloudflare rejettent souvent un User-Agent avec des accolades ou ambigu.
 */
$pmcpExtensionVersion = '{version}';
if (! is_string($pmcpExtensionVersion) || $pmcpExtensionVersion === '' || $pmcpExtensionVersion === '{version}') {
    $pmcpExtensionVersion = '0.7.3-dev';
}

$modrinthBase = 'https://api.modrinth.com/v2';
$modrinthUa = 'pteromcplugins/' . $pmcpExtensionVersion . ' (+https://blueprint.zip)';

/** @var callable(string, array<string, mixed> = []): \Illuminate\Http\Client\Response $modrinthGet */
$modrinthGet = static function (string $path, array $query = []) use ($modrinthBase, $modrinthUa) {
    return Http::timeout(25)
        ->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => $modrinthUa,
        ])
        ->get($modrinthBase . $path, $query);
};

$curseForgeBase = 'https://api.curseforge.com/v1';
$curseForgeGameIdMc = 432;
$curseForgeUa = 'pteromcplugins/' . $pmcpExtensionVersion . ' (+https://blueprint.zip) curseforge-proxy';

/** @var callable(string): ?string */
$curseForgePickEnvRaw = static function (string $envKey): ?string {
    $candidates = [
        isset($_ENV[$envKey]) && is_string($_ENV[$envKey]) ? $_ENV[$envKey] : null,
        isset($_SERVER[$envKey]) && is_string($_SERVER[$envKey]) ? $_SERVER[$envKey] : null,
    ];
    $fromGetenv = getenv($envKey);
    if (is_string($fromGetenv) && $fromGetenv !== '') {
        $candidates[] = $fromGetenv;
    }
    $fromEnv = env($envKey);
    if (is_string($fromEnv) && $fromEnv !== '') {
        $candidates[] = $fromEnv;
    }

    foreach ($candidates as $raw) {
        if (! is_string($raw)) {
            continue;
        }
        $t = trim($raw);
        if ($t === '') {
            continue;
        }
        /* Guillemets résiduels si .env copié à la main. */
        $t = trim($t, " \t\n\r\0\x0B\"'");

        return $t !== '' ? $t : null;
    }

    return null;
};

/** @var callable(): ?string $curseForgeApiKey */
$curseForgeApiKey = static function () use ($curseForgePickEnvRaw): ?string {
    foreach (['CURSEFORGE_API_KEY', 'CF_API_KEY'] as $envKey) {
        $v = $curseForgePickEnvRaw($envKey);
        if ($v !== null) {
            return $v;
        }
    }

    return null;
};

/**
 * @param  array<string, mixed>  $query
 * @return (\Illuminate\Http\Client\Response)|false false si aucune clé API
 */
$curseForgeGet = static function (string $path, array $query = []) use ($curseForgeBase, $curseForgeUa, $curseForgeApiKey) {
    $key = $curseForgeApiKey();
    if ($key === null) {
        return false;
    }

    return Http::timeout(30)
        ->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => $curseForgeUa,
            'x-api-key' => $key,
        ])
        ->get($curseForgeBase . $path, $query);
};

/**
 * Lorque CURSEFORGE_API_KEY est définie mais refusée par l’API (401/403), on évite
 * de répondre 502 générique comme pour une panne upstream.
 *
 * @return non-empty-string|null
 */
$curseForgeAuthFailureMessage = static function (\Illuminate\Http\Client\Response $r): ?string {
    $c = $r->status();
    if ($c === 401 || $c === 403) {
        return 'CurseForge : accès refusé par l’API (HTTP ' . $c . '). Vérifiez une clé créée sur https://console.curseforge.com/ dans le .env du panel (CURSEFORGE_API_KEY ou CF_API_KEY), sans guillemets ni espaces parasites ; après toute modification du .env exécutez `php artisan config:clear`. Si la clé est sure, un plafond de débit ou un filtrage IP (403 côté CDN) est aussi possible.';
    }

    return null;
};

/** @return JsonResponse|null */
$curseForgeAuthFailureHttp = static function (\Illuminate\Http\Client\Response $r) use ($curseForgeAuthFailureMessage): ?JsonResponse {
    $msg = $curseForgeAuthFailureMessage($r);
    if ($msg === null) {
        return null;
    }

    return response()->json([
        'message' => $msg,
        'status' => $r->status(),
    ], 503);
};

/** classId officiel Minecraft Java CurseForge — cf. docs/PROVIDERS.md */
$curseForgeClassToProjectType = static function (?int $classId): string {
    return match ($classId) {
        5 => 'plugin',
        6 => 'mod',
        4471 => 'modpack',
        12 => 'resourcepack',
        default => 'mod',
    };
};

$curseForgePageUrl = static function (int $classId, string $slug): ?string {
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }

    $segment = match ($classId) {
        5 => 'bukkit-plugins',
        4471 => 'modpacks',
        12 => 'texture-packs',
        default => 'mc-mods',
    };

    return 'https://www.curseforge.com/minecraft/' . $segment . '/' . rawurlencode($slug);
};

$curseForgeCfResponseData = static function (mixed $json): ?array {
    if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
        return null;
    }

    return $json['data'];
};

$validCurseForgeModId = static function (string $id): bool {
    if (! ctype_digit($id)) {
        return false;
    }

    $n = (int) $id;

    return $n > 0 && $n <= 2147483646;
};

$validProjectId = static function (string $id): bool {
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $id);
};

/** Filtre version MC transmis à Modrinth/CurseForge (conservateur). */
$validMcVersionFilter = static function (string $raw): bool {
    $v = trim($raw);

    return $v !== ''
        && (bool) preg_match('/^[A-Za-z0-9.+_\-]{1,48}$/', $v);
};

/** Résumé changelog / notes pour l’UI (vérif MAJ). */
$pmcpTruncatePlain = static function (string $text, int $maxLen = 680): string {
    $t = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($t === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($t) > $maxLen) {
        return mb_substr($t, 0, $maxLen) . '…';
    }

    return strlen($t) > $maxLen ? substr($t, 0, $maxLen) . '…' : $t;
};

/**
 * Blocage installation par identifiant projet amont (Modrinth / CurseForge).
 * Variable panel optionnelle : PMCP_BLOCKLIST_PROJECT_IDS=id1,id2,modrinth:abc,curseforge:994854
 * (préfixe provider pour lever toute ambiguïté si le même id existe sur les deux sources).
 */
$pmcpInstallBlockedByPolicy = static function (string $provider, string $projectId): bool {
    $raw = env('PMCP_BLOCKLIST_PROJECT_IDS');
    if (! is_string($raw)) {
        return false;
    }
    $raw = trim($raw);
    if ($raw === '') {
        return false;
    }

    $prov = strtolower($provider);
    $pid = strtolower(ltrim($projectId));

    foreach (array_map('trim', explode(',', $raw)) as $token) {
        if ($token === '') {
            continue;
        }
        $t = strtolower($token);
        if (str_contains($t, ':')) {
            $parts = explode(':', $t, 2);
            if (count($parts) === 2 && $parts[0] === $prov && $parts[1] === $pid) {
                return true;
            }
        } elseif ($t === $pid) {
            return true;
        }
    }

    return false;
};

/** Choisit la version Modrinth la plus récente dans une liste /project/.../version. */
$modrinthLatestFromVersionList = static function (mixed $body): ?array {
    $list = is_array($body) ? $body : [];
    $best = null;

    /** @var string $bestDt */
    $bestDt = '';

    foreach ($list as $row) {
        if (! is_array($row)) {
            continue;
        }

        $id = isset($row['id']) ? (string) $row['id'] : '';

        if ($id === '') {
            continue;
        }

        $dt = isset($row['date_published']) ? (string) $row['date_published'] : '';

        if ($best === null || ($dt !== '' && strcmp($dt, $bestDt) > 0)) {
            $best = $row;
            $bestDt = $dt;
        }

    }

    return $best;
};

/**
 * Contexte MC / Panel pour filtrage catalogue côté client.
 *
 * @see \PteroMcPlugins\Services\ServerMcContextBuilder::build()
 */
$serverMcContextPayload = static fn (\Pterodactyl\Models\Server $server): array => \PteroMcPlugins\Services\ServerMcContextBuilder::build($server);

/** Résout uuidShort / uuid / uuid sans tirets → Server Panel. */
$resolveServer = static function (string $token) {
    $t = trim($token);
    if ($t === '' || !class_exists(\Pterodactyl\Models\Server::class)) {
        return null;
    }

    return \Pterodactyl\Models\Server::query()
        ->where(function ($q) use ($t): void {
            $q->where('uuidShort', $t)->orWhere('uuid', $t);
            if (preg_match('/^[0-9a-fA-F]{32}$/', $t)) {
                $q->orWhereRaw('REPLACE(`uuid`, "-", "") = ?', [strtolower($t)]);
            }
        })
        ->first();
};

/** Interdit path traversal ; retourne chemin type `/plugins`. */
$normalizeInstallDirectory = static function (?string $raw): ?string {
    if ($raw === null || $raw === '') {
        return null;
    }
    $dir = str_replace('\\', '/', trim($raw));
    if (str_contains($dir, '..')) {
        return null;
    }
    $dir = '/' . ltrim($dir, '/');
    if ($dir === '/') {
        return '/';
    }

    return $dir;
};

$validCronExpression = static function (string $expr): bool {
    $e = trim($expr);
    if ($e === '' || strlen($e) > 64) {
        return false;
    }

    $parts = preg_split('/\s+/', $e, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) !== 5) {
        return false;
    }

    $fieldBounds = [
        [0, 59], // minute
        [0, 23], // heure
        [1, 31], // day of month
        [1, 12], // month
        [0, 7],  // day of week (0 et 7 = dimanche selon implémentations courantes)
    ];

    foreach ($parts as $idx => $field) {
        [$min, $max] = $fieldBounds[$idx];
        $items = explode(',', $field);
        if (count($items) === 0) {
            return false;
        }
        foreach ($items as $item) {
            $it = trim($item);
            if ($it === '') {
                return false;
            }
            if ($it === '*') {
                continue;
            }

            $step = null;
            if (str_contains($it, '/')) {
                [$base, $stepRaw] = array_pad(explode('/', $it, 2), 2, '');
                if (! ctype_digit($stepRaw) || (int) $stepRaw <= 0) {
                    return false;
                }
                $step = (int) $stepRaw;
                $it = $base;
                if ($it === '') {
                    return false;
                }
            }

            if ($it === '*') {
                continue;
            }

            if (str_contains($it, '-')) {
                [$aRaw, $bRaw] = array_pad(explode('-', $it, 2), 2, '');
                if (! ctype_digit($aRaw) || ! ctype_digit($bRaw)) {
                    return false;
                }
                $a = (int) $aRaw;
                $b = (int) $bRaw;
                if ($a < $min || $b > $max || $a > $b) {
                    return false;
                }
            } else {
                if (! ctype_digit($it)) {
                    return false;
                }
                $n = (int) $it;
                if ($n < $min || $n > $max) {
                    return false;
                }
            }

            if ($step !== null && $step > ($max - $min + 1)) {
                return false;
            }
        }
    }

    return true;
};

/**
 * Infère si le serveur Panel est plutôt un stack « plugins » (Paper, proxies) ou « mods » (Forge/Fabric dédié).
 *
 * @return bool|null true = privilégier /plugins en cas de conflit loaders, false = /mods, null = pas d’indice fort
 */
$serverArtifactPreference = static function (\Pterodactyl\Models\Server $server): ?bool {
    $server->loadMissing('egg', 'nest');

    $text = strtolower(trim(
        (string) ($server->egg?->name ?? '')
            . ' ' . (string) ($server->nest?->name ?? '')
            . ' ' . (string) ($server->startup ?? '')
            . ' ' . (string) ($server->image ?? '')
    ));

    if ($text === '') {
        return null;
    }

    // Stacks mods Java — préférer /mods lorsque l’artefact est ambigu (ex. jar Forge + autre).
    if (preg_match('/\b(forge|neo[\s_-]?forge)\b/i', $text)) {
        return false;
    }

    // Œuf Fabric / Quilt « pur » (sans famille Paper/Spigot dans le libellé) → mods.
    if (preg_match('/\b(fabric|quilt)\b/i', $text)
        && !preg_match('/\b(paper|spigot|purpur|folia|pufferfish|craftbukkit|bukkit|velocity|waterfall|bungee|arclight|mohist|cardboard)\b/i', $text)) {
        return false;
    }

    // Paper / forks Bukkit / proxies — cibles typiques /plugins pour artefacts « hybrides » Modrinth (fabric + paper, etc.).
    if (preg_match(
        '/\b(paper|purpur|folia|pufferfish|spigot|craftbukkit|bukkit|tuinity|airplane|leaf|velocity|waterfall|bungeecord|bungee|travertine|hexacord)\b/i',
        $text
    )) {
        return true;
    }

    // PocketMine, Bedrock addons eggs souvent nommés explicitement ; plugins PHAR côté PM.
    if (preg_match('/\b(pocketmine|pmmp)\b/i', $text)) {
        return true;
    }

    return null;
};

/**
 * Répertoire d’installation par défaut pour un projet CurseForge (classId Mc + œuf Panel).
 */
$defaultInstallDirectoryCurseForge = static function (?int $classId, \Pterodactyl\Models\Server $server) use ($serverArtifactPreference): string {
    if ($classId === 5) {
        return '/plugins';
    }

    if (in_array($classId, [6, 4471], true)) {
        return '/mods';
    }

    $pref = $serverArtifactPreference($server);
    if ($pref === true) {
        return '/plugins';
    }
    if ($pref === false) {
        return '/mods';
    }

    return '/mods';
};

/**
 * Répertoire cible par défaut (racine sandbox serveur) d’après loaders, type projet Modrinth et œuf Panel.
 *
 * @param  array<string, mixed>  $version
 */
$defaultInstallDirectory = static function (array $version, string $projectId, \Pterodactyl\Models\Server $server) use ($modrinthGet, $serverArtifactPreference): string {
    $loaders = array_map('strtolower', is_array($version['loaders'] ?? null) ? $version['loaders'] : []);
    $modLike = ['fabric', 'forge', 'quilt', 'neoforge'];
    $pluginLike = ['bukkit', 'spigot', 'paper', 'purpur', 'velocity', 'bungeecord', 'waterfall', 'sponge', 'folia'];

    $hasModLoader = false;
    $hasPluginLoader = false;
    foreach ($loaders as $l) {
        if (in_array($l, $modLike, true)) {
            $hasModLoader = true;
        }
        if (in_array($l, $pluginLike, true)) {
            $hasPluginLoader = true;
        }
    }

    $pref = $serverArtifactPreference($server);

    if ($hasModLoader && $hasPluginLoader) {
        if ($pref === true) {
            return '/plugins';
        }
        if ($pref === false) {
            return '/mods';
        }

        try {
            $pr = $modrinthGet('/project/' . rawurlencode($projectId), []);
            if ($pr->successful()) {
                $pj = $pr->json();
                $ptype = is_array($pj) && isset($pj['project_type']) ? (string) $pj['project_type'] : 'mod';

                return $ptype === 'plugin' ? '/plugins' : '/mods';
            }
        } catch (\Throwable) {
            // fallback ci-dessous
        }

        return '/mods';
    }

    if ($hasModLoader && !$hasPluginLoader) {
        return '/mods';
    }
    if ($hasPluginLoader && !$hasModLoader) {
        return '/plugins';
    }

    try {
        $pr = $modrinthGet('/project/' . rawurlencode($projectId), []);
        if ($pr->successful()) {
            $pj = $pr->json();
            $ptype = is_array($pj) && isset($pj['project_type']) ? (string) $pj['project_type'] : 'mod';

            return $ptype === 'plugin' ? '/plugins' : '/mods';
        }
    } catch (\Throwable) {
        // fallback ci-dessous
    }

    return '/mods';
};

Route::post('/install/modrinth', static function (Request $request) use (
    $modrinthGet,
    $pmcpInstallBlockedByPolicy,
    $validProjectId,
    $resolveServer,
    $normalizeInstallDirectory,
    $defaultInstallDirectory
): JsonResponse {
    if (!class_exists(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class)) {
        return response()->json(['message' => 'Classes Wings du panel introuvables (DaemonFileRepository).'], 500);
    }

    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
        'project_id' => ['required', 'string', 'max:128'],
        'version_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
        'directory' => ['sometimes', 'nullable', 'string', 'max:255'],
        'backup_before' => ['sometimes', 'boolean'],
        'backup_context' => ['sometimes', 'nullable', 'string', 'max:48', 'regex:/^[a-z0-9_-]+$/'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    if (!$validProjectId($data['project_id'])) {
        return response()->json(['message' => 'Identifiant projet invalide.'], 422);
    }

    $user = $request->user();
    if (!$user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('node', 'subusers');

    if ($user->id !== $server->owner_id && !$user->root_admin) {
        if (!$server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }

    try {
        $server->validateCurrentState();
    } catch (\Pterodactyl\Exceptions\Http\Server\ServerStateConflictException) {
        return response()->json([
            'message' => 'Le serveur ne permet pas cette action pour le moment (installation, suspension, transfert, etc.).',
        ], 409);
    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_CREATE, $server)) {
        return response()->json(['message' => 'Permission refusée : création de fichiers sur ce serveur.'], 403);
    }

    if ($pmcpInstallBlockedByPolicy('modrinth', $data['project_id'])) {
        return response()->json([
            'message' => 'Installation bloquée par la politique du panneau (variable PMCP_BLOCKLIST_PROJECT_IDS).',
        ], 403);
    }

    $dirInput = $data['directory'] ?? null;
    $wantBackup = $request->boolean('backup_before');
    $bc = isset($data['backup_context']) ? trim((string) $data['backup_context']) : '';

    try {
        $out = \PteroMcPlugins\Services\PmcpArtifactInstall::modrinth(
            $server,
            $user,
            $data['project_id'],
            $data['version_id'],
            is_string($dirInput) ? $dirInput : null,
            $wantBackup,
            $bc,
            $modrinthGet,
            $validProjectId,
            $normalizeInstallDirectory,
            $defaultInstallDirectory,
            $pmcpInstallBlockedByPolicy,
        );
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(
            array_merge(['message' => $e->getMessage()], $e->extra),
            $e->status
        );
    }

    return response()->json($out);
});

Route::post('/install/curseforge', static function (Request $request) use (
    $curseForgeApiKey,
    $curseForgeAuthFailureMessage,
    $curseForgeCfResponseData,
    $curseForgeGameIdMc,
    $curseForgeGet,
    $defaultInstallDirectoryCurseForge,
    $normalizeInstallDirectory,
    $pmcpInstallBlockedByPolicy,
    $resolveServer
): JsonResponse {
    if ($curseForgeApiKey() === null) {
        return response()->json([
            'message' => 'CurseForge : clé API absente (CURSEFORGE_API_KEY ou CF_API_KEY dans .env du panel).',
        ], 503);
    }

    if (! class_exists(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class)) {
        return response()->json(['message' => 'Classes Wings du panel introuvables (DaemonFileRepository).'], 500);
    }

    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
        'mod_id' => ['required', 'integer', 'min:1', 'max:2147483646'],
        'file_id' => ['required', 'integer', 'min:1', 'max:2147483646'],
        'directory' => ['sometimes', 'nullable', 'string', 'max:255'],
        'backup_before' => ['sometimes', 'boolean'],
        'backup_context' => ['sometimes', 'nullable', 'string', 'max:48', 'regex:/^[a-z0-9_-]+$/'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $modId = (int) $data['mod_id'];
    $fileId = (int) $data['file_id'];

    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('node', 'subusers');

    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }

    try {
        $server->validateCurrentState();
    } catch (\Pterodactyl\Exceptions\Http\Server\ServerStateConflictException) {
        return response()->json([
            'message' => 'Le serveur ne permet pas cette action pour le moment (installation, suspension, transfert, etc.).',
        ], 409);
    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_CREATE, $server)) {
        return response()->json(['message' => 'Permission refusée : création de fichiers sur ce serveur.'], 403);
    }

    if ($pmcpInstallBlockedByPolicy('curseforge', (string) $modId)) {
        return response()->json([
            'message' => 'Installation bloquée par la politique du panneau (variable PMCP_BLOCKLIST_PROJECT_IDS).',
        ], 403);
    }

    $dirInput = $data['directory'] ?? null;
    $wantBackup = $request->boolean('backup_before');
    $bc = isset($data['backup_context']) ? trim((string) $data['backup_context']) : '';

    try {
        $out = \PteroMcPlugins\Services\PmcpArtifactInstall::curseforge(
            $server,
            $user,
            $modId,
            $fileId,
            is_string($dirInput) ? $dirInput : null,
            $wantBackup,
            $bc,
            $curseForgeGameIdMc,
            $curseForgeGet,
            $curseForgeAuthFailureMessage,
            $curseForgeCfResponseData,
            $defaultInstallDirectoryCurseForge,
            $normalizeInstallDirectory,
            $pmcpInstallBlockedByPolicy,
        );
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(
            array_merge(['message' => $e->getMessage()], $e->extra),
            $e->status
        );
    }

    return response()->json($out);
});

Route::get('/install/history', static function (Request $request) use ($resolveServer): JsonResponse {
    $validator = Validator::make($request->query(), [
        'server' => ['required', 'string', 'max:64'],
        'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $data = $validator->validated();
    $limit = (int) ($data['limit'] ?? 20);

    $user = $request->user();
    if (!$user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('subusers');

    if ($user->id !== $server->owner_id && !$user->root_admin) {
        if (!$server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée : liste des fichiers / historique sur ce serveur.'], 403);
    }

    if (! Schema::hasTable('pmcp_install_events')) {
        return response()->json([
            'items' => [],
            'migration_pending' => true,
        ]);
    }

    $rows = DB::table('pmcp_install_events')
        ->where('server_id', $server->id)
        ->orderByDesc('created_at')
        ->limit($limit)
        ->get(['id', 'provider', 'project_id', 'version_id', 'directory', 'filename', 'version_label', 'created_at']);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r->id,
            'provider' => (string) $r->provider,
            'project_id' => (string) $r->project_id,
            'version_id' => (string) $r->version_id,
            'directory' => (string) $r->directory,
            'filename' => $r->filename !== null ? (string) $r->filename : null,
            'version_label' => $r->version_label !== null ? (string) $r->version_label : null,
            'created_at' => $r->created_at !== null ? (string) $r->created_at : null,
        ];
    }

    return response()->json(['items' => $items]);
});

Route::get('/server/context', static function (Request $request) use ($resolveServer, $serverMcContextPayload): JsonResponse {
    $validator = Validator::make($request->query(), [
        'server' => ['required', 'string', 'max:64'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);

    }

    $data = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {

        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('subusers');

    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }

    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    $ctx = $serverMcContextPayload($server);

    return response()->json([
        'uuid_short' => (string) ($server->uuidShort ?? ''),
        'uuid_full' => (string) ($server->uuid ?? ''),
        ...$ctx,
    ]);
});

Route::get('/schedule', static function (Request $request) use ($resolveServer): JsonResponse {
    $validator = Validator::make($request->query(), [
        'server' => ['required', 'string', 'max:64'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    if (! Schema::hasTable('pmcp_server_schedules')) {
        return response()->json([
            'scheduled_enabled' => false,
            'backup_before_update' => true,
            'cron_expression' => '0 4 * * 1',
            'max_updates_per_run' => 5,
            'migration_pending' => true,
        ]);
    }

    $row = DB::table('pmcp_server_schedules')->where('server_id', $server->id)->first([
        'scheduled_enabled',
        'backup_before_update',
        'cron_expression',
        'max_updates_per_run',
        'last_preview_at',
        'updated_at',
    ]);

    if (! $row) {
        return response()->json([
            'scheduled_enabled' => false,
            'backup_before_update' => true,
            'cron_expression' => '0 4 * * 1',
            'max_updates_per_run' => 5,
            'migration_pending' => false,
        ]);
    }

    return response()->json([
        'scheduled_enabled' => (bool) $row->scheduled_enabled,
        'backup_before_update' => (bool) $row->backup_before_update,
        'cron_expression' => (string) $row->cron_expression,
        'max_updates_per_run' => (int) $row->max_updates_per_run,
        'last_preview_at' => $row->last_preview_at !== null ? (string) $row->last_preview_at : null,
        'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
        'migration_pending' => false,
    ]);
});

Route::post('/schedule', static function (Request $request) use ($resolveServer, $validCronExpression): JsonResponse {
    if (! Schema::hasTable('pmcp_server_schedules')) {
        return response()->json(['message' => 'Migration base schedule non appliquée.'], 503);
    }

    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
        'scheduled_enabled' => ['sometimes', 'boolean'],
        'backup_before_update' => ['sometimes', 'boolean'],
        'cron_expression' => ['sometimes', 'string', 'max:64'],
        'max_updates_per_run' => ['sometimes', 'integer', 'min:1', 'max:50'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    if (isset($data['cron_expression']) && ! $validCronExpression((string) $data['cron_expression'])) {
        return response()->json(['message' => 'Expression cron invalide (format 5 champs attendu).'], 422);
    }

    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_CREATE, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    $now = now();
    $current = DB::table('pmcp_server_schedules')->where('server_id', $server->id)->first([
        'scheduled_enabled',
        'backup_before_update',
        'cron_expression',
        'max_updates_per_run',
    ]);

    $payload = [
        'server_id' => $server->id,
        'updated_by_user_id' => $user->id,
        'scheduled_enabled' => isset($data['scheduled_enabled']) ? (bool) $data['scheduled_enabled'] : (bool) ($current->scheduled_enabled ?? false),
        'backup_before_update' => isset($data['backup_before_update']) ? (bool) $data['backup_before_update'] : (bool) ($current->backup_before_update ?? true),
        'cron_expression' => isset($data['cron_expression']) ? trim((string) $data['cron_expression']) : (string) ($current->cron_expression ?? '0 4 * * 1'),
        'max_updates_per_run' => isset($data['max_updates_per_run']) ? (int) $data['max_updates_per_run'] : (int) ($current->max_updates_per_run ?? 5),
        'updated_at' => $now,
    ];

    if ($current) {
        DB::table('pmcp_server_schedules')->where('server_id', $server->id)->update($payload);
    } else {
        try {
            $insertPayload = $payload;
            $insertPayload['created_at'] = $now;
            DB::table('pmcp_server_schedules')->insert($insertPayload);
        } catch (\Throwable) {
            /* Évite une 500 en cas de double soumission concurrente (contrainte unique server_id). */
            DB::table('pmcp_server_schedules')->where('server_id', $server->id)->update($payload);
        }
    }

    return response()->json([
        'message' => 'Planification mise à jour.',
        'scheduled_enabled' => (bool) $payload['scheduled_enabled'],
        'backup_before_update' => (bool) $payload['backup_before_update'],
        'cron_expression' => (string) $payload['cron_expression'],
        'max_updates_per_run' => (int) $payload['max_updates_per_run'],
    ]);
});

Route::post('/schedule/preview', static function (Request $request) use ($resolveServer): JsonResponse {
    if (! Schema::hasTable('pmcp_server_schedules')) {
        return response()->json(['message' => 'Migration base schedule non appliquée.'], 503);
    }
    if (! Schema::hasTable('pmcp_install_events')) {
        return response()->json(['message' => 'Historique installations indisponible (migration manquante).'], 503);
    }

    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $data = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }
    $server->loadMissing('subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }
    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    $cfg = DB::table('pmcp_server_schedules')->where('server_id', $server->id)->first([
        'id',
        'scheduled_enabled',
        'backup_before_update',
        'cron_expression',
        'max_updates_per_run',
    ]);

    if (! $cfg) {
        return response()->json([
            'message' => 'Aucune planification configurée pour ce serveur.',
            'configured' => false,
            'items' => [],
        ]);
    }

    $max = max(1, min(50, (int) $cfg->max_updates_per_run));
    $rows = DB::table('pmcp_install_events')
        ->where('server_id', $server->id)
        ->orderByDesc('created_at')
        ->limit(250)
        ->get(['provider', 'project_id', 'version_id', 'version_label', 'directory', 'created_at']);

    $seen = [];
    $items = [];
    foreach ($rows as $r) {
        $k = (string) $r->provider . ':' . (string) $r->project_id . ':' . (string) $r->directory;
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $items[] = [
            'provider' => (string) $r->provider,
            'project_id' => (string) $r->project_id,
            'current_version_id' => (string) $r->version_id,
            'current_version_label' => $r->version_label !== null ? (string) $r->version_label : null,
            'directory' => (string) $r->directory,
            'last_seen_at' => $r->created_at !== null ? (string) $r->created_at : null,
        ];
        if (count($items) >= $max) {
            break;
        }
    }

    return response()->json([
        'message' => 'Aperçu de la prochaine passe planifiée.',
        'configured' => true,
        'scheduled_enabled' => (bool) $cfg->scheduled_enabled,
        'backup_before_update' => (bool) $cfg->backup_before_update,
        'cron_expression' => (string) $cfg->cron_expression,
        'max_updates_per_run' => $max,
        'items' => $items,
    ]);
});

Route::get('/pins', static function (Request $request) use ($resolveServer): JsonResponse {
    if (! Schema::hasTable('pmcp_install_pins')) {
        return response()->json([
            'items' => [],

            'migration_pending' => true,
        ]);
    }

    $validator = Validator::make($request->query(), ['server' => ['required', 'string', 'max:64']]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);

    }

    $data = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);

    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('subusers');

    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }

    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    $rows = DB::table('pmcp_install_pins')->where('server_id', $server->id)->get([
        'id', 'provider', 'project_id', 'pinned_version_id', 'pinned_version_label', 'created_at',

    ]);

    $items = [];
    foreach ($rows as $r) {

        $items[] = [
            'id' => (int) $r->id,
            'provider' => (string) $r->provider,
            'project_id' => (string) $r->project_id,
            'pinned_version_id' => (string) $r->pinned_version_id,
            'pinned_version_label' => $r->pinned_version_label !== null ? (string) $r->pinned_version_label : null,
            'created_at' => $r->created_at !== null ? (string) $r->created_at : null,

        ];

    }

    return response()->json(['items' => $items]);

});

Route::post('/pins', static function (Request $request) use ($resolveServer): JsonResponse {

    if (! Schema::hasTable('pmcp_install_pins')) {

        return response()->json(['message' => 'Migration base pins non appliquée.'], 503);
    }

    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
        'provider' => ['required', 'string', 'max:32', 'regex:/^(modrinth|curseforge)$/'],
        'project_id' => ['required', 'string', 'max:128'],
        'pinned_version_id' => ['required', 'string', 'max:128'],
        'pinned_version_label' => ['sometimes', 'nullable', 'string', 'max:255'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();

    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);

    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('node', 'subusers');

    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }

    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_CREATE, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    $now = now();

    $match = [
        'server_id' => $server->id,
        'provider' => $data['provider'],
        'project_id' => $data['project_id'],
    ];

    $row = DB::table('pmcp_install_pins')->where($match)->first();
    $labelRaw = isset($data['pinned_version_label']) ? $data['pinned_version_label'] : null;
    $label = is_string($labelRaw) && trim($labelRaw) !== '' ? trim($labelRaw) : null;

    if ($row !== null) {
        DB::table('pmcp_install_pins')
            ->where('id', (int) $row->id)
            ->update([
                'user_id' => $user->id,
                'pinned_version_id' => $data['pinned_version_id'],
                'pinned_version_label' => $label,
                'updated_at' => $now,
            ]);
    } else {
        DB::table('pmcp_install_pins')->insert(array_merge($match, [
            'user_id' => $user->id,
            'pinned_version_id' => $data['pinned_version_id'],
            'pinned_version_label' => $label,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    return response()->json(['message' => 'Version épinglée pour ce serveur.']);
});

Route::delete('/pins', static function (Request $request) use ($resolveServer): JsonResponse {
    if (! Schema::hasTable('pmcp_install_pins')) {

        return response()->json(['message' => 'Migration base pins non appliquée.'], 503);
    }

    $validator = Validator::make($request->query(), [
        'server' => ['required', 'string', 'max:64'],
        'provider' => ['required', 'string', 'max:32', 'regex:/^(modrinth|curseforge)$/'],
        'project_id' => ['required', 'string', 'max:128'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $user = $request->user();

    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);

    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('subusers');

    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }

    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_CREATE, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    DB::table('pmcp_install_pins')
        ->where('server_id', $server->id)
        ->where('provider', $data['provider'])
        ->where('project_id', $data['project_id'])
        ->delete();

    return response()->json(['message' => 'Épingle supprimée.']);
});

Route::post('/install/check-updates', static function (Request $request) use (
    $curseForgeApiKey,
    $curseForgeAuthFailureMessage,
    $curseForgeGet,
    $modrinthGet,
    $modrinthLatestFromVersionList,
    $pmcpTruncatePlain,
    $resolveServer,
    $validCurseForgeModId,
    $validProjectId
): JsonResponse {
    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
        'entries' => ['required', 'array', 'min:1', 'max:25'],
        'entries.*.provider' => ['required', 'string', 'max:32', 'regex:/^(modrinth|curseforge)$/'],
        'entries.*.project_id' => ['required', 'string', 'max:128'],
        'entries.*.version_id' => ['required', 'string', 'max:128'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);

    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('subusers');

    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }

    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    $pinMap = [];
    if (Schema::hasTable('pmcp_install_pins')) {
        $pins = DB::table('pmcp_install_pins')

            ->where('server_id', $server->id)

            ->get(['provider', 'project_id', 'pinned_version_id', 'pinned_version_label']);

        foreach ($pins as $p) {
            $pinMap[(string) $p->provider . ':' . (string) $p->project_id] = [
                'pinned_version_id' => (string) $p->pinned_version_id,
                'pinned_version_label' => $p->pinned_version_label !== null ? (string) $p->pinned_version_label : null,
            ];
        }

    }

    $results = \PteroMcPlugins\Services\PmcpCheckUpdatesBatch::run($pinMap, $data['entries'], [
        'modrinthGet' => $modrinthGet,
        'modrinthLatestFromVersionList' => $modrinthLatestFromVersionList,
        'curseForgeApiKey' => $curseForgeApiKey,
        'curseForgeGet' => $curseForgeGet,
        'validProjectId' => $validProjectId,
        'validCurseForgeModId' => $validCurseForgeModId,
        'curseForgeAuthFailureMessage' => $curseForgeAuthFailureMessage,
        'pmcpTruncatePlain' => $pmcpTruncatePlain,
    ]);

    return response()->json(['items' => $results]);
});

Route::get('/install/backups', static function (Request $request) use ($resolveServer): JsonResponse {
    if (! Schema::hasTable('pmcp_backups')) {
        return response()->json(['items' => [], 'migration_pending' => true]);
    }

    $validator = Validator::make($request->query(), [
        'server' => ['required', 'string', 'max:64'],
        'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $data = $validator->validated();
    $limit = (int) ($data['limit'] ?? 40);

    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }
    $server->loadMissing('subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }
    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée.'], 403);
    }

    $rows = DB::table('pmcp_backups')
        ->where('server_id', $server->id)
        ->orderByDesc('id')
        ->limit($limit)
        ->get(['id', 'install_directory', 'archive_relative_path', 'context', 'provider', 'project_id', 'version_id', 'created_at']);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r->id,
            'install_directory' => (string) $r->install_directory,
            'archive_relative_path' => (string) $r->archive_relative_path,
            'context' => (string) $r->context,
            'provider' => $r->provider !== null ? (string) $r->provider : null,
            'project_id' => $r->project_id !== null ? (string) $r->project_id : null,
            'version_id' => $r->version_id !== null ? (string) $r->version_id : null,
            'created_at' => $r->created_at !== null ? (string) $r->created_at : null,
        ];
    }

    return response()->json(['items' => $items]);
});

Route::post('/install/backups/restore', static function (Request $request) use ($resolveServer): JsonResponse {
    if (! Schema::hasTable('pmcp_backups')) {
        return response()->json(['message' => 'Table pmcp_backups absente.'], 503);
    }

    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
        'backup_id' => ['required', 'integer', 'min:1'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $data = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }
    $server->loadMissing('node', 'subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }

    try {
        $server->validateCurrentState();
    } catch (\Pterodactyl\Exceptions\Http\Server\ServerStateConflictException) {
        return response()->json([
            'message' => 'Le serveur ne permet pas cette action pour le moment.',
        ], 409);
    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_CREATE, $server)) {
        return response()->json(['message' => 'Permission refusée : écriture fichiers.'], 403);
    }

    $row = DB::table('pmcp_backups')
        ->where('id', (int) $data['backup_id'])
        ->where('server_id', $server->id)
        ->first(['archive_relative_path']);

    if ($row === null) {
        return response()->json(['message' => 'Sauvegarde introuvable pour ce serveur.'], 404);
    }

    try {
        \PteroMcPlugins\Services\PmcpInstallBackupRestore::decompressWingsArchive(
            $server,
            (string) $row->archive_relative_path
        );
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(
            array_merge(['message' => $e->getMessage()], $e->extra),
            $e->status
        );
    }

    return response()->json([
        'message' => 'Archive extraite dans le dossier du serveur (Wings decompress). Redémarrage souvent nécessaire.',
        'archive' => (string) $row->archive_relative_path,
    ]);
});

Route::get('/workspace/list', static function (Request $request) use ($resolveServer): JsonResponse {
    if (! class_exists(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class)) {
        return response()->json(['message' => 'DaemonFileRepository introuvable.'], 500);
    }

    $validator = Validator::make($request->query(), [
        'server' => ['required', 'string', 'max:64'],
        'directory' => ['sometimes', 'nullable', 'string', 'max:255'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $q = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }
    $server = $resolveServer($q['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }
    $server->loadMissing('subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }
    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée : lecture fichier.'], 403);
    }

    try {
        $dir = \PteroMcPlugins\Services\PmcpWorkspacePath::sanitizeDirectory($q['directory'] ?? '/config');
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(['message' => $e->getMessage()], $e->status);
    }

    /** @var \Pterodactyl\Repositories\Wings\DaemonFileRepository $repo */
    $repo = app(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class);
    try {
        $list = $repo->setServer($server)->getDirectory($dir);
    } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
        return response()->json([
            'message' => 'Impossible de lister le répertoire via Wings.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 502);
    }

    return response()->json(['directory' => $dir, 'entries' => is_array($list) ? $list : []]);
});

Route::get('/workspace/file', static function (Request $request) use ($resolveServer): JsonResponse {
    if (! class_exists(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class)) {
        return response()->json(['message' => 'DaemonFileRepository introuvable.'], 500);
    }

    $validator = Validator::make($request->query(), [
        'server' => ['required', 'string', 'max:64'],
        'path' => ['required', 'string', 'max:512'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $q = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }
    $server = $resolveServer($q['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }
    $server->loadMissing('subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }
    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée : lecture fichier.'], 403);
    }

    try {
        $rel = \PteroMcPlugins\Services\PmcpWorkspacePath::sanitizeFilePath($q['path']);
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(['message' => $e->getMessage()], $e->status);
    }

    /** @var \Pterodactyl\Repositories\Wings\DaemonFileRepository $repo */
    $repo = app(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class);
    $maxDl = (int) config('filesystems.pmcp_workspace_max_download', 512_000);
    try {
        $content = $repo->setServer($server)->getContent($rel, $maxDl > 0 ? $maxDl : null);
    } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
        return response()->json([
            'message' => 'Lecture Wings impossible.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 502);
    } catch (\Throwable $e) {
        try {
            $content = $repo->setServer($server)->getContent($rel);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Lecture fichier échouée.',
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    return response()->json(['path' => $rel, 'content' => $content]);
});

Route::put('/workspace/file', static function (Request $request) use ($resolveServer): JsonResponse {
    if (! class_exists(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class)) {
        return response()->json(['message' => 'DaemonFileRepository introuvable.'], 500);
    }

    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
        'path' => ['required', 'string', 'max:512'],
        'content' => ['required', 'string', 'max:' . (string) ((int) (config('filesystems.pmcp_workspace_max_upload', 400 * 1024)))],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $q = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }
    $server = $resolveServer($q['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }
    $server->loadMissing('subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }
    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_CREATE, $server)) {
        return response()->json(['message' => 'Permission refusée : écriture fichier.'], 403);
    }

    try {
        $rel = \PteroMcPlugins\Services\PmcpWorkspacePath::sanitizeFilePath($q['path']);
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(['message' => $e->getMessage()], $e->status);
    }

    /** @var \Pterodactyl\Repositories\Wings\DaemonFileRepository $repo */
    $repo = app(\Pterodactyl\Repositories\Wings\DaemonFileRepository::class);
    try {
        $repo->setServer($server)->putContent($rel, (string) $q['content']);
    } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
        return response()->json([
            'message' => 'Écriture Wings impossible.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 502);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Écriture fichier échouée.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }

    return response()->json(['message' => 'Fichier enregistré.', 'path' => $rel]);
});

Route::get('/presets', static function (Request $request): JsonResponse {
    if (! Schema::hasTable('pmcp_presets')) {
        return response()->json(['items' => [], 'migration_pending' => true]);
    }

    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $ownerV = Validator::make($request->query(), ['owner' => ['sometimes', 'nullable', 'string', 'in:self,admin']]);
    if ($ownerV->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $ownerV->errors()], 422);
    }
    $ownerFilter = (string) ($ownerV->validated()['owner'] ?? 'self');

    $uid = (int) $user->id;
    $qb = DB::table('pmcp_presets')->orderByDesc('updated_at')->limit(100);
    if (! $user->root_admin || $ownerFilter === 'self') {
        $qb->where('user_id', $uid);
    }

    $rows = $qb->get(['id', 'user_id', 'name', 'description', 'items', 'updated_at']);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r->id,
            'owner_user_id' => (int) $r->user_id,
            'name' => (string) $r->name,
            'description' => $r->description !== null ? (string) $r->description : null,
            'items' => json_decode((string) $r->items, true) ?: [],
            'updated_at' => $r->updated_at !== null ? (string) $r->updated_at : null,
        ];
    }

    return response()->json(['items' => $items]);
});

Route::post('/presets', static function (Request $request): JsonResponse {
    if (! Schema::hasTable('pmcp_presets')) {
        return response()->json(['message' => 'Table presets absente (migrate).'], 503);
    }
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $validator = Validator::make($request->all(), [
        'name' => ['required', 'string', 'max:128'],
        'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        'items' => ['required', 'array', 'min:1'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $data = $validator->validated();

    try {
        $items = \PteroMcPlugins\Services\PmcpPresetItems::coerce($data['items']);
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(['message' => $e->getMessage()], $e->status);
    }

    $now = now();
    $payload = [
        'user_id' => (int) $user->id,
        'name' => trim((string) $data['name']),
        'description' => isset($data['description']) && is_string($data['description']) ? trim($data['description']) : null,
        'items' => json_encode($items),
        'created_at' => $now,
        'updated_at' => $now,
    ];

    try {
        $id = DB::table('pmcp_presets')->insertGetId($payload);
    } catch (\Throwable) {
        return response()->json(['message' => 'Nom de preset déjà utilisé ou erreur BD.'], 422);
    }

    return response()->json(['message' => 'Preset enregistré.', 'id' => (int) $id]);
});

Route::delete('/presets', static function (Request $request): JsonResponse {
    if (! Schema::hasTable('pmcp_presets')) {
        return response()->json(['message' => 'Table presets absente.'], 503);
    }

    $validator = Validator::make($request->query(), ['id' => ['required', 'integer', 'min:1']]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }
    $id = (int) $validator->validated()['id'];
    $q = DB::table('pmcp_presets')->where('id', $id);
    if (! $user->root_admin) {
        $q->where('user_id', (int) $user->id);
    }
    $n = $q->delete();

    return response()->json(['message' => $n > 0 ? 'Preset supprimé.' : 'Preset introuvable.', 'deleted' => $n > 0]);
});

Route::post('/presets/apply', static function (Request $request) use (
    $curseForgeApiKey,
    $curseForgeAuthFailureMessage,
    $curseForgeCfResponseData,
    $curseForgeGameIdMc,
    $curseForgeGet,
    $defaultInstallDirectoryCurseForge,
    $normalizeInstallDirectory,
    $pmcpInstallBlockedByPolicy,
    $resolveServer,
    $modrinthGet,
    $validProjectId,
    $defaultInstallDirectory
): JsonResponse {
    if (! Schema::hasTable('pmcp_presets')) {
        return response()->json(['message' => 'Table presets absente.'], 503);
    }

    $validator = Validator::make($request->all(), [
        'server' => ['required', 'string', 'max:64'],
        'preset_id' => ['required', 'integer', 'min:1'],
        'backup_before' => ['sometimes', 'boolean'],
        'backup_context' => ['sometimes', 'nullable', 'string', Rule::in(['catalog', 'history', 'scheduled', 'preset', 'cron'])],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $data = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $preset = DB::table('pmcp_presets')->where('id', (int) $data['preset_id'])->first(['user_id', 'items']);
    if ($preset === null) {
        return response()->json(['message' => 'Preset introuvable.'], 404);
    }
    if (! $user->root_admin && (int) $preset->user_id !== (int) $user->id) {
        return response()->json(['message' => 'Preset introuvable.'], 404);
    }

    $decoded = json_decode((string) $preset->items, true);
    try {
        $items = \PteroMcPlugins\Services\PmcpPresetItems::coerce(is_array($decoded) ? $decoded : []);
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(['message' => 'Preset invalide stocké en BD : ' . $e->getMessage()], 422);
    }

    foreach ($items as $it) {
        if (($it['provider'] ?? '') === 'curseforge' && $curseForgeApiKey() === null) {
            return response()->json(['message' => 'CurseForge : clé API absente (nécessaire pour ce preset).'], 503);
        }
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('node', 'subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }

    try {
        $server->validateCurrentState();
    } catch (\Pterodactyl\Exceptions\Http\Server\ServerStateConflictException) {
        return response()->json(['message' => 'Le serveur ne permet pas cette action pour le moment.'], 409);
    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_CREATE, $server)) {
        return response()->json(['message' => 'Permission refusée : écriture fichier.'], 403);
    }

    $wantBackup = (bool) ($data['backup_before'] ?? false);
    $bc = isset($data['backup_context']) ? trim((string) $data['backup_context']) : 'preset';

    $ok = 0;
    $errors = [];
    $idx = 0;
    foreach ($items as $it) {
        ++$idx;
        try {
            $server->validateCurrentState();
        } catch (\Pterodactyl\Exceptions\Http\Server\ServerStateConflictException) {
            $errors[] = 'Item #' . $idx . ' : état serveur conflictuel.';
            break;
        }

        try {
            if ($it['provider'] === 'modrinth') {
                \PteroMcPlugins\Services\PmcpArtifactInstall::modrinth(
                    $server,
                    $user,
                    $it['project_id'],
                    $it['version_id'],
                    $it['directory'],
                    $wantBackup,
                    $bc !== '' ? $bc : 'preset',
                    $modrinthGet,
                    $validProjectId,
                    $normalizeInstallDirectory,
                    $defaultInstallDirectory,
                    $pmcpInstallBlockedByPolicy,
                );
            } else {
                \PteroMcPlugins\Services\PmcpArtifactInstall::curseforge(
                    $server,
                    $user,
                    (int) $it['project_id'],
                    (int) $it['version_id'],
                    $it['directory'],
                    $wantBackup,
                    $bc !== '' ? $bc : 'preset',
                    $curseForgeGameIdMc,
                    $curseForgeGet,
                    $curseForgeAuthFailureMessage,
                    $curseForgeCfResponseData,
                    $defaultInstallDirectoryCurseForge,
                    $normalizeInstallDirectory,
                    $pmcpInstallBlockedByPolicy,
                );
            }
            ++$ok;
        } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
            $errors[] = 'Item #' . $idx . ' : ' . $e->getMessage();
        } catch (\Throwable $e) {
            $errors[] = 'Item #' . $idx . ' : ' . ($e->getMessage() ?: 'erreur');
        }
    }

    return response()->json([
        'message' => $ok === count($items)
            ? 'Preset appliqué intégralement.'
            : 'Preset partiellement appliqué (' . $ok . '/' . count($items) . ').',
        'installed' => $ok,
        'total' => count($items),
        'errors' => $errors,
    ], count($errors) > 0 && $ok === 0 ? 422 : 200);
});

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'extension' => '{identifier}',
    'version' => '{version}',
    'blueprint_target' => '{target}',
    'blueprint_matches_target' => '{is_target}',
    'blueprint_engine' => '{engine}',
]));

Route::get('/catalog/curseforge/status', static function () use ($curseForgeApiKey): JsonResponse {
    return response()->json([
        'provider' => 'curseforge',
        'configured' => $curseForgeApiKey() !== null,
    ]);
});

Route::get('/catalog/curseforge/search', static function (
    Request $request
) use (
    $curseForgeApiKey,
    $curseForgeAuthFailureHttp,
    $curseForgeClassToProjectType,
    $curseForgeGameIdMc,
    $curseForgeGet,
    $curseForgePageUrl,
    $validMcVersionFilter
): JsonResponse {
    if ($curseForgeApiKey() === null) {
        return response()->json([
            'message' => 'CurseForge : clé API absente (CURSEFORGE_API_KEY ou CF_API_KEY dans .env du panel).',
        ], 503);
    }

    $validator = Validator::make($request->query(), [
        'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        'offset' => ['sometimes', 'integer', 'min:0', 'max:50000'],
        'server' => ['sometimes', 'nullable', 'string', 'max:64'],
        'minecraft_version' => ['sometimes', 'nullable', 'string', 'max:48'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $query = isset($data['q']) ? trim((string) $data['q']) : '';
    $limit = min((int) ($data['limit'] ?? 15), 50);
    $offset = (int) ($data['offset'] ?? 0);

    $curseQuery = [
        'gameId' => $curseForgeGameIdMc,
        'searchFilter' => $query,
        'pageSize' => $limit,
        'index' => $offset,
    ];
    $mvCf = isset($data['minecraft_version']) ? trim((string) $data['minecraft_version']) : '';

    if ($mvCf !== '' && $validMcVersionFilter($mvCf)) {
        $curseQuery['gameVersion'] = $mvCf;
    }

    try {
        $response = $curseForgeGet('/mods/search', $curseQuery);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Réseau indisponible vers CurseForge.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 503);
    }

    if ($response === false) {
        return response()->json(['message' => 'CurseForge : clé API absente ou refusée.'], 503);
    }

    if (! $response->successful()) {
        $authResp = $curseForgeAuthFailureHttp($response);
        if ($authResp !== null) {
            return $authResp;
        }

        return response()->json([
            'message' => 'CurseForge a répondu une erreur.',
            'status' => $response->status(),
        ], 502);
    }

    $payload = $response->json();
    $hits = is_array($payload) && isset($payload['data']) && is_array($payload['data'])
        ? $payload['data']
        : [];
    $pag = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
    $total = isset($pag['totalCount'])
        ? (int) $pag['totalCount']
        : count($hits);

    $items = [];
    foreach ($hits as $row) {
        if (! is_array($row)) {
            continue;
        }
        $slug = isset($row['slug']) ? (string) $row['slug'] : '';
        $classId = isset($row['classId']) ? (int) $row['classId'] : null;
        $ptype = $curseForgeClassToProjectType($classId);
        $logo = is_array($row['logo'] ?? null) ? $row['logo'] : [];

        $icon = null;
        if (isset($logo['thumbnailUrl']) && is_string($logo['thumbnailUrl']) && $logo['thumbnailUrl'] !== '') {
            $icon = $logo['thumbnailUrl'];
        } elseif (isset($logo['url']) && is_string($logo['url']) && $logo['url'] !== '') {
            $icon = $logo['url'];
        }

        $nid = isset($row['id']) ? (string) $row['id'] : '';
        $items[] = [
            'provider' => 'curseforge',
            'external_id' => $nid,
            'slug' => $slug,
            'title' => isset($row['name']) ? (string) $row['name'] : '',
            'summary' => isset($row['summary']) ? (string) $row['summary'] : '',
            'project_type' => $ptype,
            'icon_url' => $icon,
            'downloads' => isset($row['downloadCount']) ? (int) $row['downloadCount'] : null,
            'page_url' => $curseForgePageUrl((int) ($classId ?? 0), $slug),
        ];
    }

    return response()->json([
        'provider' => 'curseforge',
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'items' => $items,
    ]);
});

Route::get('/catalog/curseforge/mod/{modId}/files', static function (
    Request $request,
    string $modId
) use (
    $curseForgeApiKey,
    $curseForgeAuthFailureHttp,
    $curseForgeCfResponseData,
    $curseForgeGet,
    $validCurseForgeModId
): JsonResponse {
    if (! $validCurseForgeModId($modId)) {
        return response()->json(['message' => 'Identifiant mod invalide.'], 422);
    }

    if ($curseForgeApiKey() === null) {
        return response()->json([
            'message' => 'CurseForge : clé API absente (CURSEFORGE_API_KEY ou CF_API_KEY dans .env du panel).',
        ], 503);
    }

    $validator = Validator::make($request->query(), [
        'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        'offset' => ['sometimes', 'integer', 'min:0', 'max:100000'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }
    $data = $validator->validated();
    $limit = min((int) ($data['limit'] ?? 20), 50);
    $offset = (int) ($data['offset'] ?? 0);

    try {
        $response = $curseForgeGet('/mods/' . $modId . '/files', [
            'pageSize' => $limit,
            'index' => $offset,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Réseau indisponible vers CurseForge.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 503);
    }

    if ($response === false) {
        return response()->json(['message' => 'CurseForge : clé API absente ou refusée.'], 503);
    }

    if ($response->status() === 404) {
        return response()->json(['message' => 'Mod ou fichiers introuvables sur CurseForge.'], 404);
    }

    if (! $response->successful()) {
        $authResp = $curseForgeAuthFailureHttp($response);
        if ($authResp !== null) {
            return $authResp;
        }

        return response()->json([
            'message' => 'CurseForge a répondu une erreur.',
            'status' => $response->status(),
        ], 502);
    }

    $decoded = $response->json();
    $list = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])
        ? $decoded['data']
        : [];

    $versions = [];
    foreach ($list as $v) {
        if (! is_array($v)) {
            continue;
        }

        $depList = [];
        foreach (is_array($v['dependencies'] ?? null) ? $v['dependencies'] : [] as $d) {
            if (! is_array($d)) {
                continue;
            }

            $depList[] = [
                'project_id' => isset($d['modId']) ? (string) $d['modId'] : null,
                'dependency_type' => isset($d['dependencyType'])
                    ? (string) $d['dependencyType']
                    : (isset($d['relationType']) ? (string) $d['relationType'] : null),
            ];
        }

        $disp = isset($v['displayName']) && is_string($v['displayName']) ? (string) $v['displayName'] : '';
        $fname = isset($v['fileName']) && is_string($v['fileName']) ? (string) $v['fileName'] : '';
        $label = $disp !== '' ? $disp : $fname;

        $primary = $fname !== ''
            ? [
                'filename' => $fname,
                'size' => isset($v['fileLength']) ? (int) $v['fileLength'] : 0,
                'sha512' => null,
            ]
            : null;

        $releaseRaw = isset($v['releaseType'])
            ? match ((int) $v['releaseType']) {
                2 => 'beta',
                3 => 'alpha',
                default => 'release',
            }
            : 'release';

        $versions[] = [
            'id' => isset($v['id']) ? (string) $v['id'] : '',
            'name' => $label,
            'version_number' => $label !== '' ? $label : '',
            'date_published' => $v['fileDate'] ?? null,
            'version_type' => $releaseRaw,
            'downloads' => isset($v['downloadCount']) ? (int) $v['downloadCount'] : 0,
            'loaders' => [],
            'game_versions' => is_array($v['gameVersions'] ?? null) ? $v['gameVersions'] : [],
            'changelog' => isset($v['changelogHtml']) ? (string) $v['changelogHtml'] : null,
            'primary_file' => $primary,
            'dependencies' => $depList,
        ];
    }

    return response()->json([
        'provider' => 'curseforge',
        'project_id' => $modId,
        'limit' => $limit,
        'offset' => $offset,
        'versions' => $versions,
    ]);
})->where('modId', '[0-9]+');

Route::get('/catalog/curseforge/mod/{modId}', static function (
    string $modId
) use (
    $curseForgeApiKey,
    $curseForgeAuthFailureHttp,
    $curseForgeCfResponseData,
    $curseForgeClassToProjectType,
    $curseForgeGet,
    $curseForgePageUrl,
    $validCurseForgeModId
): JsonResponse {
    if (! $validCurseForgeModId($modId)) {
        return response()->json(['message' => 'Identifiant mod invalide.'], 422);
    }

    if ($curseForgeApiKey() === null) {
        return response()->json([
            'message' => 'CurseForge : clé API absente (CURSEFORGE_API_KEY ou CF_API_KEY dans .env du panel).',
        ], 503);
    }

    try {
        $response = $curseForgeGet('/mods/' . $modId, []);
    } catch (\Throwable $e) {
        return response()->json([
            'message' => 'Réseau indisponible vers CurseForge.',
            'detail' => config('app.debug') ? $e->getMessage() : null,
        ], 503);
    }

    if ($response === false) {
        return response()->json(['message' => 'CurseForge : clé API absente ou refusée.'], 503);
    }

    if ($response->status() === 404) {
        return response()->json(['message' => 'Mod introuvable sur CurseForge.'], 404);
    }

    if (! $response->successful()) {
        $authResp = $curseForgeAuthFailureHttp($response);
        if ($authResp !== null) {
            return $authResp;
        }

        return response()->json([
            'message' => 'CurseForge a répondu une erreur.',
            'status' => $response->status(),
        ], 502);
    }

    $p = $curseForgeCfResponseData($response->json());
    if ($p === null) {
        return response()->json(['message' => 'Réponse CurseForge invalide.'], 502);
    }

    $slug = isset($p['slug']) ? (string) $p['slug'] : '';
    $classId = isset($p['classId']) ? (int) $p['classId'] : null;
    $ptype = $curseForgeClassToProjectType($classId);

    $logo = is_array($p['logo'] ?? null) ? $p['logo'] : [];
    $icon = null;
    if (isset($logo['thumbnailUrl']) && is_string($logo['thumbnailUrl']) && $logo['thumbnailUrl'] !== '') {
        $icon = $logo['thumbnailUrl'];
    } elseif (isset($logo['url']) && is_string($logo['url']) && $logo['url'] !== '') {
        $icon = $logo['url'];
    }

    $authors = '';
    if (is_array($p['authors'] ?? null)) {
        $names = [];
        foreach ($p['authors'] as $auth) {
            if (is_array($auth) && isset($auth['name']) && is_string($auth['name']) && $auth['name'] !== '') {
                $names[] = $auth['name'];
            }
        }
        $authors = implode(', ', array_slice($names, 0, 6));
    }

    $linksPkg = $p['links'] ?? [];
    $websiteAssoc = null;
    if (is_array($linksPkg) && isset($linksPkg['websiteUrl']) && is_string($linksPkg['websiteUrl'])) {
        $websiteAssoc = $linksPkg['websiteUrl'];
    }
    $issuesAssoc = null;
    if (is_array($linksPkg) && isset($linksPkg['issuesUrl']) && is_string($linksPkg['issuesUrl'])) {
        $issuesAssoc = $linksPkg['issuesUrl'];
    }
    $wikiAssoc = null;
    if (is_array($linksPkg) && isset($linksPkg['wikiUrl']) && is_string($linksPkg['wikiUrl'])) {
        $wikiAssoc = $linksPkg['wikiUrl'];
    }

    $websiteRoot = isset($p['websiteUrl']) && is_string($p['websiteUrl']) ? (string) $p['websiteUrl'] : null;

    return response()->json([
        'provider' => 'curseforge',
        'project' => [
            'id' => isset($p['id']) ? (string) $p['id'] : $modId,
            'slug' => $slug,
            'title' => isset($p['name']) ? (string) $p['name'] : '',
            'description' => isset($p['summary']) ? (string) $p['summary'] : '',
            'body' => $authors !== '' ? 'Auteur(s): ' . $authors : '',
            'project_type' => $ptype,
            'icon_url' => $icon,
            'downloads' => isset($p['downloadCount']) ? (int) $p['downloadCount'] : null,
            'followers' => null,
            'license' => null,
            'client_side' => null,
            'server_side' => null,
            'source_url' => $websiteRoot ?? $websiteAssoc,
            'issues_url' => $issuesAssoc,
            'wiki_url' => $wikiAssoc,
            'discord_url' => null,
            'page_url' => $curseForgePageUrl((int) ($classId ?? 0), $slug),
        ],
    ]);
})->where('modId', '[0-9]+');

// Route plus spécifique en premier (Modrinth)
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

Route::get('/catalog/search', static function (Request $request) use ($modrinthGet, $validMcVersionFilter): JsonResponse {
    $validator = Validator::make($request->query(), [
        'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
        'offset' => ['sometimes', 'integer', 'min:0', 'max:50000'],
        'server' => ['sometimes', 'nullable', 'string', 'max:64'],
        'minecraft_version' => ['sometimes', 'nullable', 'string', 'max:48'],
    ]);

    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $query = isset($data['q']) ? trim((string) $data['q']) : '';
    $limit = (int) ($data['limit'] ?? 15);
    $offset = (int) ($data['offset'] ?? 0);

    $modrinthQueryParams = [
        'query' => $query,
        'limit' => $limit,
        'offset' => $offset,
    ];

    $mvRaw = isset($data['minecraft_version']) ? trim((string) $data['minecraft_version']) : '';
    if ($mvRaw !== '' && $validMcVersionFilter($mvRaw)) {

        /* Facettes Modrinth : versions:<mc> — voir docs Modrinth /search facets. */

        $modrinthQueryParams['facets'] = json_encode([['versions:' . $mvRaw]]);

    }

    try {
        $response = $modrinthGet('/search', $modrinthQueryParams);
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
