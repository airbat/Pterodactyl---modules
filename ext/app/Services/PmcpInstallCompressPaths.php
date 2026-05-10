<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/**
 * Coupe un chemin d’installation normalisé (/plugins ou /mods/sous/répertoire) pour
 * {@see \Pterodactyl\Repositories\Wings\DaemonFileRepository::compressFiles()}.
 */
final class PmcpInstallCompressPaths
{
    /**
     * @return array{daemon_root: string, wing_files: list<string>}|null Null si dossier vide ou racine seule /
     */
    public static function forNormalizedDirectory(string $normalizedInstallDirectory): ?array
    {
        $s = '/' . trim($normalizedInstallDirectory, '/');
        if ($s === '/' || $normalizedInstallDirectory === '') {
            return null;
        }

        $inner = trim(substr($s, 1), '/');
        if ($inner === '') {
            return null;
        }

        $parts = preg_split('#/#', $inner, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts) || $parts === []) {
            return null;
        }

        $leaf = (string) array_pop($parts);
        if ($leaf === '' || $leaf === '.' || $leaf === '..') {
            return null;
        }

        $daemonRoot = $parts !== [] ? '/' . implode('/', $parts) : '/';

        return [
            'daemon_root' => $daemonRoot,
            'wing_files' => [$leaf],
        ];
    }
}
