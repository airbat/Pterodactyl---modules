<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/**
 * Parse un buffer de log de démarrage Minecraft et déduit { mc_version, loader, source_line }.
 *
 * Pure : aucun I/O. Toute la logique testable en isolation sur fixtures.
 *
 * Loaders supportés v1.0 : paper, spigot, neoforge, forge, fabric, quilt, vanilla, bedrock.
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

        if (preg_match('/This server is running Paper version [^(]*\(MC: (\S+?)\)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'paper',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
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

        if (preg_match('/This server is running CraftBukkit version [^(]*\(MC: (\S+?)\)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
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

        // Bedrock écrit "Version: X.Y.Z.W" peu après "Starting Server". On exige les deux signaux
        // pour éviter de matcher la sortie de "/version" envoyée par un opérateur dans un chat.
        if (preg_match('/Starting Server/i', $buffer)
            && preg_match('/^\[[^\]]+INFO\]\s+Version:\s+(\d+\.\d+\.\d+(?:\.\d+)?)/mi', $buffer, $m, PREG_OFFSET_CAPTURE)) {
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
}
