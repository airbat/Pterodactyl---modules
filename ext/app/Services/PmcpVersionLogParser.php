<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/**
 * Parse un buffer de log de démarrage Minecraft et déduit { mc_version, loader, source_line }.
 *
 * Pure : aucun I/O. Toute la logique testable en isolation sur fixtures.
 *
 * Loaders supportés v1.0 : paper, purpur, folia, pufferfish, leaf (forks Paper-like), spigot,
 * neoforge, forge, fabric, quilt, vanilla, bedrock.
 * L'ordre des règles dans {@see parse()} est déterministe : la 1ère règle qui matche gagne.
 */
final class PmcpVersionLogParser
{
    /**
     * @return array{mc_version: string, loader: string, source_line: string}|null
     */
    public static function parse(string $buffer): ?array
    {
        if ($buffer === '') {
            return null;
        }

        $buffer = self::normalizeLogBuffer($buffer);

        $paperLike = self::matchPaperLikeFork($buffer);
        if ($paperLike !== null) {
            return $paperLike;
        }

        // NeoForge avant Forge : "Forge mod loading service" est un substring de "NeoForge mod
        // loading service". L'ordre rend `source_line` correct ; la lookbehind sur Forge fait
        // une seconde barrière pour éviter le faux match si l'ordre venait à changer.
        $forgeFamily = self::matchForgeFamily($buffer, '/NeoForge mod loading service/i', 'neoforge');
        if ($forgeFamily !== null) {
            return $forgeFamily;
        }

        $forgeFamily = self::matchForgeFamily($buffer, '/(?<!Neo)Forge mod loading service/i', 'forge');
        if ($forgeFamily !== null) {
            return $forgeFamily;
        }

        if (preg_match('/\bCraftBukkit version\b[\s\S]{0,1200}?\(MC:\s*(\S+?)\)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'spigot',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        // Quilt avant Fabric : un log Quilt peut aussi mentionner Fabric (fork-compatible).
        if (preg_match('/Loading Minecraft (\S+) with Quilt Loader/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'quilt',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        if (preg_match('/Loading Minecraft (\S+) with Fabric Loader/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'fabric',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        // Bedrock : « Starting Server » puis une ligne « … Version: a.b.c.d » (timestamp étendu ou [INFO] seul).
        // Évite les faux positifs chat en exigeant les deux signaux.
        if (preg_match('/Starting Server/i', $buffer)
            && preg_match('/^\[[^\]]*\]\s+Version:\s*(\d+\.\d+\.\d+(?:\.\d+)?)/mi', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'bedrock',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        if (preg_match('/^(?:\[[^\]]*\][\s:]*|[\s:])*Starting minecraft server version (\S+)/mi', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'vanilla',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        // Repli : certains œufs / wrappers omettent le préfixe `[thread/INFO]:` attendu par la règle stricte.
        if (preg_match('/^\s*Starting minecraft server version (\S+)/mi', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'vanilla',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        return null;
    }

    /**
     * Paper et forks (Purpur, Folia, …) : `(MC: …)` si présent, sinon version MC extraite de
     * `(Implementing API version 1.21.x-R0.1-SNAPSHOT)` (builds récents sans `(MC:)` sur la même ligne).
     *
     * @return array{mc_version: string, loader: string, source_line: string}|null
     */
    private static function matchPaperLikeFork(string $buffer): ?array
    {
        if (preg_match(
            '/\b(Paper|Purpur|Folia|Pufferfish|Leaf)\s+version\b/i',
            $buffer,
            $head,
            PREG_OFFSET_CAPTURE,
        ) !== 1) {
            return null;
        }

        $fork = strtolower($head[1][0]);
        $start = $head[0][1];
        $sliceLen = min(4000, strlen($buffer) - $start);
        if ($sliceLen < 1) {
            return null;
        }

        $window = substr($buffer, $start, $sliceLen);

        if (preg_match('/\(MC:\s*(\S+?)\)/i', $window, $m, PREG_OFFSET_CAPTURE) === 1) {
            return [
                'mc_version' => $m[1][0],
                'loader' => $fork,
                'source_line' => self::lineAtOffset($buffer, $start),
            ];
        }

        if (preg_match(
            '/\(Implementing API version\s*(\d+(?:\.\d+)+)(?:-R[\d.]+(?:-SNAPSHOT)?)?\)/i',
            $window,
            $m,
            PREG_OFFSET_CAPTURE,
        ) === 1) {
            return [
                'mc_version' => $m[1][0],
                'loader' => $fork,
                'source_line' => self::lineAtOffset($buffer, $start),
            ];
        }

        return null;
    }

    /**
     * Marqueur loader Forge/NeoForge puis version MC : la version doit apparaître **après** le
     * marqueur (offset du match "Starting minecraft server version" >= offset du loader) pour
     * éviter d'associer un extrait de log ancien concaténé au buffer.
     *
     * @return array{mc_version: string, loader: string, source_line: string}|null
     */
    private static function matchForgeFamily(string $buffer, string $markerPattern, string $loaderKey): ?array
    {
        if (preg_match($markerPattern, $buffer, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $loaderOffset = $m[0][1];
        $loaderLine = self::lineAtOffset($buffer, $loaderOffset);

        if (preg_match('/Starting minecraft server version (\S+)/i', $buffer, $v, PREG_OFFSET_CAPTURE, $loaderOffset) !== 1) {
            return null;
        }

        if ($v[0][1] < $loaderOffset) {
            return null;
        }

        return [
            'mc_version' => $v[1][0],
            'loader' => $loaderKey,
            'source_line' => $loaderLine,
        ];
    }

    private static function lineAtOffset(string $buffer, int $offset): string
    {
        $start = strrpos(substr($buffer, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($buffer, "\n", $offset);
        $end = $end === false ? strlen($buffer) : $end;

        return trim(substr($buffer, $start, $end - $start));
    }

    /** Supprime BOM UTF-8 et séquences ANSI (couleurs Paper / conteneur) qui empêchent les regex. */
    private static function normalizeLogBuffer(string $buffer): string
    {
        if (str_starts_with($buffer, "\xEF\xBB\xBF")) {
            $buffer = substr($buffer, 3);
        }

        $out = preg_replace('/\x1b\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]/', '', $buffer);
        $out = is_string($out) ? $out : $buffer;
        $out2 = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $out);

        return is_string($out2) ? $out2 : $out;
    }
}
