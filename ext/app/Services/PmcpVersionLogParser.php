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

        if (preg_match('/This server is running CraftBukkit version [^(]*\(MC: (\S+?)\)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'spigot',
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

    private static function lineAtOffset(string $buffer, int $offset): string
    {
        $start = strrpos(substr($buffer, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($buffer, "\n", $offset);
        $end = $end === false ? strlen($buffer) : $end;

        return trim(substr($buffer, $start, $end - $start));
    }
}
