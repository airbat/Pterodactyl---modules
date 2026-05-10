<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * Fermetures HTTP / blocage projet partagées entre {@see ext/routes/client.php}, le cron Blueprint
 * et tout script qui évite de charger tout le fichier de routes (~2500 lignes).
 */
final class PmcpClientHttpDelegates
{
    /**
     * @return array<string, mixed>
     */
    public static function createScheduledSubset(): array
    {
        $full = self::create();

        return array_intersect_key($full, array_flip([
            'modrinthGet',
            'curseForgeApiKey',
            'curseForgeGet',
            'curseForgeAuthFailureMessage',
            'curseForgeCfResponseData',
            'curseForgeGameIdMc',
            'validCurseForgeModId',
            'validProjectId',
            'pmcpTruncatePlain',
            'pmcpInstallBlockedByPolicy',
            'modrinthLatestFromVersionList',
            'normalizeInstallDirectory',
            'serverArtifactPreference',
            'defaultInstallDirectory',
            'defaultInstallDirectoryCurseForge',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public static function create(): array
    {
        $pmcpExtensionVersion = '{version}';
        if (! is_string($pmcpExtensionVersion) || $pmcpExtensionVersion === '' || $pmcpExtensionVersion === '{version}') {
            $pmcpExtensionVersion = '0.7.3-dev';
        }

        $modrinthBase = 'https://api.modrinth.com/v2';
        $modrinthUa = 'pteromcplugins/' . $pmcpExtensionVersion . ' (+https://blueprint.zip)';

        /** @var callable(string, array<string, mixed>): \Illuminate\Http\Client\Response */
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
                $t = trim($t, " \t\n\r\0\x0B\"'");
                if ($t !== '') {
                    return $t;
                }
            }

            return null;
        };

        /** @var callable(): ?string */
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
         * @param array<string,mixed> $query
         * @return (\Illuminate\Http\Client\Response)|false
         */
        $curseForgeGet = static function (string $path, array $query = []) use (
            $curseForgeBase,
            $curseForgeUa,
            $curseForgeApiKey
        ) {
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

        /** @return non-empty-string|null */
        $curseForgeAuthFailureMessage = static function (\Illuminate\Http\Client\Response $r): ?string {
            $c = $r->status();
            if ($c === 401 || $c === 403) {
                return 'CurseForge : accès refusé par l’API (HTTP ' . $c . '). Vérifiez une clé créée sur https://console.curseforge.com/ dans le .env du panel (CURSEFORGE_API_KEY ou CF_API_KEY), sans guillemets ni espaces parasites ; après toute modification du .env exécutez `php artisan config:clear`. Si la clé est sure, un plafond de débit ou un filtrage IP (403 côté CDN) est aussi possible.';
            }

            return null;
        };

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

        /** @var callable(mixed): array<string,mixed>|null */
        $curseForgeCfResponseData = static function (mixed $json): ?array {
            if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
                return null;
            }

            return $json['data'];
        };

        /** @var callable(string):bool */
        $validCurseForgeModId = static function (string $id): bool {
            if (! ctype_digit($id)) {
                return false;
            }
            $n = (int) $id;

            return $n > 0 && $n <= 2147483646;
        };

        /** @var callable(string):bool */
        $validProjectId = static function (string $id): bool {
            return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/', $id);
        };

        /** @var callable(string):bool */
        $validMcVersionFilter = static function (string $raw): bool {
            $v = trim($raw);

            return $v !== ''
                && (bool) preg_match('/^[A-Za-z0-9.+_\-]{1,48}$/', $v);
        };

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

        /** @var callable(mixed): ?array<string,mixed> */
        $modrinthLatestFromVersionList = static function (mixed $body): ?array {
            $list = is_array($body) ? $body : [];
            $best = null;

            /** @var string */
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

        /** @var callable(?string): ?string */
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

            if (preg_match('/\b(forge|neo[\s_-]?forge)\b/i', $text)) {
                return false;
            }

            if (preg_match('/\b(fabric|quilt)\b/i', $text)
                && ! preg_match('/\b(paper|spigot|purpur|folia|pufferfish|craftbukkit|bukkit|velocity|waterfall|bungee|arclight|mohist|cardboard)\b/i', $text)) {
                return false;
            }

            if (preg_match(
                '/\b(paper|purpur|folia|pufferfish|spigot|craftbukkit|bukkit|tuinity|airplane|leaf|velocity|waterfall|bungeecord|bungee|travertine|hexacord)\b/i',
                $text
            )) {
                return true;
            }

            if (preg_match('/\b(pocketmine|pmmp)\b/i', $text)) {
                return true;
            }

            return null;
        };

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
         * @param  array<string, mixed>  $version
         */
        $defaultInstallDirectory = static function (
            array $version,
            string $projectId,
            \Pterodactyl\Models\Server $server
        ) use ($modrinthGet, $serverArtifactPreference): string {
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
                }

                return '/mods';
            }

            if ($hasModLoader && ! $hasPluginLoader) {
                return '/mods';
            }
            if ($hasPluginLoader && ! $hasModLoader) {
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
            }

            return '/mods';
        };

        return [
            'modrinthGet' => $modrinthGet,
            'curseForgeGameIdMc' => $curseForgeGameIdMc,
            'curseForgeApiKey' => $curseForgeApiKey,
            'curseForgeGet' => $curseForgeGet,
            'curseForgeAuthFailureMessage' => $curseForgeAuthFailureMessage,
            'curseForgeAuthFailureHttp' => $curseForgeAuthFailureHttp,
            'curseForgeCfResponseData' => $curseForgeCfResponseData,
            'curseForgeClassToProjectType' => $curseForgeClassToProjectType,
            'curseForgePageUrl' => $curseForgePageUrl,
            'validCurseForgeModId' => $validCurseForgeModId,
            'validProjectId' => $validProjectId,
            'validMcVersionFilter' => $validMcVersionFilter,
            'pmcpTruncatePlain' => $pmcpTruncatePlain,
            'pmcpInstallBlockedByPolicy' => $pmcpInstallBlockedByPolicy,
            'modrinthLatestFromVersionList' => $modrinthLatestFromVersionList,
            'normalizeInstallDirectory' => $normalizeInstallDirectory,
            'serverArtifactPreference' => $serverArtifactPreference,
            'defaultInstallDirectoryCurseForge' => $defaultInstallDirectoryCurseForge,
            'defaultInstallDirectory' => $defaultInstallDirectory,
        ];
    }
}
