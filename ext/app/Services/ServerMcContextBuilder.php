<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;

/**
 * Construit le payload `server/context` : hints de version Minecraft, sous-ensemble de variables œuf, méta UX.
 *
 * La logique est isolée dans ce fichier pour rester incluse via {@see require_once} depuis les routes Blueprint
 * (autoload Composer de l’extension non garanti sur tous les panels).
 */
final class ServerMcContextBuilder
{
    /** Incrémenter après changement de fusion env (vérif déploiement via context_meta). */
    public const CONTEXT_BUILDER_REVISION = 3;

    private const KEYS_INTEREST = [
        'MINECRAFT_VERSION',
        'MC_VERSION',
        'SERVER_VERSION',
        'VANILLA_VERSION',
        'GAME_VERSION',
        'BEDROCK_VERSION',
        'VERSION',
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

        $mergedEnv = self::mergeServerEnvironment($server);

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
            || array_key_exists('VERSION', $mergedEnv)
            || preg_match('/bedrock|pocket edition|mcpe|mcbd|bedrock.?dedicated/i', $eggLabelLc."\n".$nestLabelLc) === 1;

        if ($bedrockLikeEgg && $hintList === []) {
            self::collectBedrockFallbackHints($mergedEnv, $addHintClosure);
            $hintList = array_values($hints);
            usort($hintList, fn (string $a, string $b): int => strcmp(strtolower($a), strtolower($b)));
        }

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
                'context_builder_revision' => self::CONTEXT_BUILDER_REVISION,
            ],
        ];
    }

    /**
     * Fusionne variables serveur (valeurs instance) + défauts œuf. Chaque étape est isolée :
     * une erreur Eloquent sur une relation ne doit pas vider tout l’environnement (cas observé Bedrock).
     *
     * @return array<string, string>
     */
    private static function mergeServerEnvironment(Server $server): array
    {
        $mergedEnv = [];
        /** @var array<int, string> $envKeyByEggVariableId */
        $envKeyByEggVariableId = [];

        try {
            $server->loadMissing('egg', 'egg.variables');
        } catch (\Throwable) {
            //
        }

        try {
            foreach ($server->egg?->variables ?? [] as $ev) {
                if (! is_object($ev)) {
                    continue;
                }

                $key = self::resolveEnvKey($ev);
                if ($key === '') {
                    continue;
                }

                $eggVarId = self::resolveEggVariableId($ev);
                if ($eggVarId !== null) {
                    $envKeyByEggVariableId[$eggVarId] = $key;
                }

                $defaultVal = method_exists($ev, 'getAttribute')
                    ? trim((string) $ev->getAttribute('default_value'))
                    : '';

                if ($defaultVal !== '' && ($mergedEnv[$key] ?? '') === '') {
                    $mergedEnv[$key] = $defaultVal;
                }
            }
        } catch (\Throwable) {
            //
        }

        foreach (self::iteratePanelServerVariables($server) as $sv) {
            $eggMeta = self::resolveEggVariableFromServerRow($sv) ?? (
                self::resolveEnvKey($sv) !== '' ? $sv : null
            );
            $key = self::resolveEnvKey($eggMeta);
            if ($key === '') {
                $varId = self::resolveEggVariableId($sv) ?? self::resolveServerVariableRowId($sv);
                if ($varId !== null) {
                    $key = $envKeyByEggVariableId[$varId] ?? '';
                }
            }

            $valRaw = self::resolveServerVariableValue($sv);

            if ($key !== '' && $valRaw !== '') {
                $mergedEnv[$key] = $valRaw;
            }
        }

        return self::mergeDatabaseServerVariables($server, $mergedEnv);
    }

    /**
     * Valeurs instance : même source que GET /api/client/servers/{id}/startup (join egg_variables + server_variables).
     *
     * @return list<object>
     */
    private static function iteratePanelServerVariables(Server $server): array
    {
        if (! method_exists($server, 'variables')) {
            return is_iterable($server->variables ?? null) ? iterator_to_array($server->variables, false) : [];
        }

        try {
            $relation = $server->variables();
            if (is_object($relation) && method_exists($relation, 'get')) {
                $rows = $relation->get();
                if (is_iterable($rows)) {
                    return is_array($rows) ? $rows : iterator_to_array($rows, false);
                }
            }
        } catch (\Throwable) {
            //
        }

        try {
            $server->loadMissing('variables');
        } catch (\Throwable) {
            //
        }

        $loaded = $server->variables ?? null;

        return is_iterable($loaded) ? (is_array($loaded) ? $loaded : iterator_to_array($loaded, false)) : [];
    }

    /**
     * Repli SQL : lit server_variables.variable_value (colonne réelle), indépendant d'Eloquent.
     *
     * @param array<string, string> $mergedEnv
     *
     * @return array<string, string>
     */
    private static function mergeDatabaseServerVariables(Server $server, array $mergedEnv): array
    {
        $serverId = self::resolveServerId($server);
        if ($serverId === null) {
            return $mergedEnv;
        }

        if (! class_exists(\Illuminate\Support\Facades\Schema::class)
            || ! class_exists(\Illuminate\Support\Facades\DB::class)) {
            return $mergedEnv;
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('server_variables')
                || ! \Illuminate\Support\Facades\Schema::hasTable('egg_variables')) {
                return $mergedEnv;
            }

            $rows = \Illuminate\Support\Facades\DB::table('server_variables')
                ->join('egg_variables', 'egg_variables.id', '=', 'server_variables.variable_id')
                ->where('server_variables.server_id', $serverId)
                ->select(['egg_variables.env_variable', 'server_variables.variable_value'])
                ->get();

            foreach ($rows as $row) {
                $key = trim((string) ($row->env_variable ?? ''));
                $val = trim((string) ($row->variable_value ?? ''));
                if ($key !== '' && $val !== '') {
                    $mergedEnv[$key] = $val;
                }
            }
        } catch (\Throwable) {
            //
        }

        return $mergedEnv;
    }

    private static function resolveServerId(Server $server): ?int
    {
        if (isset($server->id) && is_numeric($server->id)) {
            return (int) $server->id;
        }

        if (method_exists($server, 'getAttribute')) {
            $id = $server->getAttribute('id');
            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return null;
    }

    private static function resolveEggVariableId(object $ev): ?int
    {
        if (isset($ev->id) && is_numeric($ev->id)) {
            return (int) $ev->id;
        }

        if (method_exists($ev, 'getAttribute')) {
            $id = $ev->getAttribute('id');
            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return null;
    }

    private static function resolveServerVariableRowId(object $sv): ?int
    {
        if (isset($sv->variable_id) && is_numeric($sv->variable_id)) {
            return (int) $sv->variable_id;
        }

        if (method_exists($sv, 'getAttribute')) {
            $id = $sv->getAttribute('variable_id');
            if (is_numeric($id)) {
                return (int) $id;
            }
        }

        return null;
    }

    private static function resolveEggVariableFromServerRow(object $sv): ?object
    {
        $egg = null;
        if (isset($sv->variable) && is_object($sv->variable)) {
            $egg = $sv->variable;
        }

        if ($egg === null && isset($sv->variable_id) && class_exists(EggVariable::class)) {
            try {
                $egg = EggVariable::query()->find((int) $sv->variable_id);
            } catch (\Throwable) {
                return null;
            }
        }

        return is_object($egg) ? $egg : null;
    }

    private static function resolveServerVariableValue(object $sv): string
    {
        foreach (['server_value', 'variable_value'] as $attr) {
            if (isset($sv->{$attr})) {
                $valRaw = trim((string) $sv->{$attr});
                if ($valRaw !== '') {
                    return $valRaw;
                }
            }
        }

        if (method_exists($sv, 'getAttribute')) {
            foreach (['server_value', 'variable_value'] as $attr) {
                $valRaw = trim((string) ($sv->getAttribute($attr) ?? ''));
                if ($valRaw !== '') {
                    return $valRaw;
                }
            }
        }

        $egg = self::resolveEggVariableFromServerRow($sv);
        if ($egg !== null && method_exists($egg, 'getAttribute')) {
            return trim((string) $egg->getAttribute('default_value'));
        }

        if (method_exists($sv, 'getAttribute')) {
            return trim((string) ($sv->getAttribute('default_value') ?? ''));
        }

        return '';
    }

    private static function resolveEnvKey(?object $eggOrVariable): string
    {
        if ($eggOrVariable === null) {
            return '';
        }

        if (isset($eggOrVariable->env_variable) && (string) $eggOrVariable->env_variable !== '') {
            return (string) $eggOrVariable->env_variable;
        }

        if (method_exists($eggOrVariable, 'getAttribute')) {
            $fromAttr = $eggOrVariable->getAttribute('env_variable');
            if (is_string($fromAttr) && $fromAttr !== '') {
                return $fromAttr;
            }
        }

        return '';
    }

    /**
     * Sur Bedrock, certaines installations ont des clés d’œuf atypiques : on accepte toute valeur
     * ressemblant à une version MC si la clé évoque Bedrock ou VERSION.
     *
     * @param array<string, string> $mergedEnv
     * @param callable(string): void $addHint
     */
    private static function collectBedrockFallbackHints(array $mergedEnv, callable $addHint): void
    {
        foreach ($mergedEnv as $key => $valRaw) {
            $keyStr = (string) $key;
            if (! preg_match('/bedrock|\bversion\b/i', $keyStr)) {
                continue;
            }

            $addHint($valRaw);
        }
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
