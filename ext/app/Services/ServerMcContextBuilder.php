<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Pterodactyl\Models\Server;

/**
 * Construit le payload `server/context` : hints de version Minecraft, sous-ensemble de variables œuf, méta UX.
 *
 * La logique est isolée dans ce fichier pour rester incluse via {@see require_once} depuis les routes Blueprint
 * (autoload Composer de l’extension non garanti sur tous les panels).
 */
final class ServerMcContextBuilder
{
    private const KEYS_INTEREST = [
        'MINECRAFT_VERSION',
        'MC_VERSION',
        'SERVER_VERSION',
        'VANILLA_VERSION',
        'GAME_VERSION',
        'BEDROCK_VERSION',
    ];

    /** Clés ou fragments de noms d’œufs pour lesquelles une valeur brute peut contenir la version MC. */
    private const ENV_KEY_HINTS_PATTERN = '/MINECRAFT|MC_VERSION|GAME_VERSION|VANILLA|BEDROCK|SERVER_JARFILE|CUSTOM_JAR|JAR_DL|DOWNLOAD|INSTALL_SCRIPT|PAPER|MCP|MINE|FABRIC|QUILT|LOADER|NEOFORGE|FORGE|VERSION\b/i';

    /**
     * @return array{
     *   minecraft_versions_hint: list<string>,
     *   egg_variables: array<string, string>,
     *   egg_name: string|null,
     *   nest_name: string|null,
     *   context_meta: array{bedrock_like_egg: bool, startup_has_placeholders_left: bool}
     * }
     */
    public static function build(Server $server): array
    {
        /** @var array<string, string> */
        $hints = [];
        $eggVarsSubset = [];

        $addHintClosure = static function (string $raw) use (&$hints): void {
            foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $p = trim((string) $part);
                $p = trim($p, "\"' ");

                if ($p === '') {
                    continue;
                }

                if (! preg_match('/^[\w.\-+]{2,48}$/', $p)) {
                    continue;
                }

                if (! self::looksLikeMcVersionHint($p)) {
                    continue;
                }

                $hints[strtolower($p)] = $p;
            }
        };

        $extractCb = static function (string $haystack) use ($addHintClosure): void {
            self::extractHintsFromHaystack($haystack, $addHintClosure);
        };

        $mergedEnv = [];

        try {
            if (method_exists($server, 'variables')) {
                $server->loadMissing('variables.variable');

                foreach ($server->variables as $sv) {
                    if (! is_object($sv)) {
                        continue;
                    }

                    $egg = isset($sv->variable) ? $sv->variable : null;

                    $key = '';
                    if (is_object($egg) && isset($egg->env_variable)) {
                        $key = (string) $egg->env_variable;
                    }

                    $valRaw = '';
                    if (isset($sv->variable_value)) {
                        $valRaw = trim((string) $sv->variable_value);
                    }

                    if ($valRaw === '' && is_object($egg) && method_exists($egg, 'getAttribute')) {
                        $valRaw = trim((string) $egg->getAttribute('default_value'));
                    }

                    if ($key !== '' && $valRaw !== '') {
                        $mergedEnv[$key] = $valRaw;
                    }
                }
            }

            try {
                $server->loadMissing('egg', 'egg.variables');
            } catch (\Throwable) {
                //
            }

            foreach ($server->egg?->variables ?? [] as $ev) {
                if (! is_object($ev) || ! method_exists($ev, 'getAttribute')) {
                    continue;
                }

                $key = isset($ev->env_variable) ? (string) $ev->env_variable : '';

                if ($key === '') {
                    continue;
                }

                $valRaw = trim((string) $ev->getAttribute('default_value'));

                if ($valRaw === '') {
                    continue;
                }

                if (($mergedEnv[$key] ?? '') !== '') {
                    continue;
                }

                $mergedEnv[$key] = $valRaw;
            }

            foreach ($mergedEnv as $key => $valRaw) {
                $keyStr = (string) $key;
                if (in_array($keyStr, self::KEYS_INTEREST, true)) {
                    $eggVarsSubset[$keyStr] = $valRaw;
                }

                if (
                    in_array($keyStr, self::KEYS_INTEREST, true)
                    || preg_match(self::ENV_KEY_HINTS_PATTERN, $keyStr) === 1
                ) {
                    $addHintClosure($valRaw);
                    $extractCb($valRaw);
                }
            }
        } catch (\Throwable) {
            //
        }

        $startup = (string) ($server->startup ?? '');
        $startupEffective = self::expandStartupPlaceholders($startup, $mergedEnv);

        foreach (
            [
                '/(1\.\d+(?:\.\d+)?(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?)/',
                '/(?<![0-9.])(\d{1,4}\.\d{1,4}\.\d{1,4}\.\d{1,4})(?![0-9.])/',
            ] as $pat
        ) {
            $n = preg_match_all($pat, $startupEffective, $ms);
            if ($n !== false && $n > 0) {
                foreach ($ms[1] ?? [] as $cand) {
                    $addHintClosure((string) $cand);
                }
            }
        }

        self::extractHintsFromHaystack($startupEffective, $addHintClosure);

        $server->loadMissing('egg', 'nest');

        $hintList = array_values($hints);
        usort($hintList, fn (string $a, string $b): int => strcmp(strtolower($a), strtolower($b)));

        $eggLabelLc = strtolower((string) ($server->egg?->name ?? ''));
        $nestLabelLc = strtolower((string) ($server->nest?->name ?? ''));

        $bedrockLikeEgg = array_key_exists('BEDROCK_VERSION', $mergedEnv)
            || preg_match('/bedrock|pocket edition|mcpe|mcbd|bedrock.?dedicated/i', $eggLabelLc."\n".$nestLabelLc) === 1;

        $startupHasPlaceholdersLeft = preg_match(
            '#\{\{\s*[A-Za-z][A-Za-z0-9_]*\s*\}\}#',
            $startupEffective
        ) === 1;

        return [
            'minecraft_versions_hint' => $hintList,
            'egg_variables' => $eggVarsSubset,
            'egg_name' => $server->egg?->name ?? null,
            'nest_name' => $server->nest?->name ?? null,
            'context_meta' => [
                'bedrock_like_egg' => $bedrockLikeEgg,
                'startup_has_placeholders_left' => $startupHasPlaceholdersLeft,
            ],
        ];
    }

    private static function looksLikeMcVersionHint(string $p): bool
    {
        $len = strlen($p);
        if ($len < 3 || $len > 48) {
            return false;
        }

        $lc = strtolower($p);

        $channels = ['latest', 'snapshot', 'preview', 'stable', 'release', 'ltr'];
        if (in_array($lc, $channels, true)) {
            return true;
        }

        if (preg_match('/^(?:\d{1,4}\.){3}\d{1,4}$/', $p) === 1) {
            return true;
        }

        return preg_match('/^1\.\d{1,5}(?:\.\d{1,5})?(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$/', $p) === 1;
    }

    /** @param callable(string): void $addHint */
    private static function extractHintsFromHaystack(string $haystack, callable $addHint): void
    {
        if ($haystack === '') {
            return;
        }

        $patterns = [
            '~/(?:versions|version)/+v?(1\\.\\d+(?:\\.\\d+)?)(?:[/\\"#?]|\\.json|\\b)~i',
            '#fabric-loader-[0-9][0-9.\\w_-]*-(1\\.\\d+(?:\\.\\d+)?)(?:\\.jar|\\b)#i',
            '#quilt-loader-[0-9][0-9.\\w_-]*-(1\\.\\d+(?:\\.\\d+)?)(?:\\.jar|\\b)#i',
            '#\\b(?:paper|purpur|pufferfish|leaf|folia)[_-](\\d+\\.\\d+(?:\\.\\d+)?)(?:[-_]\\d+)?\\.jar\\b#i',
            '#\\b(?:paper|purpur|pufferfish|leaf|folia)[_-](\\d+\\.\\d+(?:\\.\\d+)?)\\b#i',

        ];

        foreach ($patterns as $pat) {
            $n = preg_match_all($pat, $haystack, $ms);
            if ($n !== false && $n > 0) {
                foreach ($ms[1] ?? [] as $cand) {
                    $addHint((string) $cand);
                }
            }
        }
    }

    /** @param array<string, string> $env */
    private static function expandStartupPlaceholders(string $template, array $env): string
    {
        if ($template === '' || $env === []) {
            return $template;
        }

        $out = $template;
        $re = '#\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}#';

        for ($i = 0; $i < 8; ++$i) {
            $prev = $out;
            $out = (string) preg_replace_callback(
                $re,
                static function (array $m) use ($env): string {
                    $k = $m[1] ?? '';
                    $v = $env[$k] ?? null;
                    if ($v === null || $v === '') {
                        return '';
                    }

                    return (string) $v;
                },
                $out
            );

            if ($out === $prev || ! preg_match($re, $out)) {
                break;
            }
        }

        return $out;
    }
}
