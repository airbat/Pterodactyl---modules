<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/** Chemins relatifs configurables Minecraft autorisés (listing / lecture / écriture). */
final class PmcpWorkspacePath
{
    private const ALLOW_EXT = ['yml', 'yaml', 'json', 'toml', 'properties', 'cfg', 'conf', 'txt', 'md'];

    /** @return non-empty-string Repertoire Wings normalisé (commence par / ) */
    public static function sanitizeDirectory(?string $raw): string
    {
        $dir = '/' . trim((string) ($raw ?? '/'), '/');
        $dir = str_replace('\\', '/', $dir);
        if ($dir === '//') {
            return '/';
        }

        if (str_contains($dir, '..') || preg_match('#\p{C}#u', $dir)) {
            throw new PmcpHttpException(422, 'Chemin répertoire invalide.');
        }
        foreach (['config', 'plugins', 'mods'] as $stem) {
            if ($dir === '/' . $stem || str_starts_with($dir, '/' . $stem . '/')) {
                return $dir;
            }
        }

        throw new PmcpHttpException(422, 'Ce répertoire n’est pas autorisé.', ['allowed_prefixes' => ['/config', '/plugins', '/mods']]);
    }

    /** Normalise fichier sous la racine autorisée. */
    public static function sanitizeFilePath(string $raw): string
    {
        $p = '/' . trim(str_replace('\\', '/', $raw), '/');
        if (str_contains($p, '..') || preg_match('#\p{C}#u', $p) || strlen($p) > 512) {
            throw new PmcpHttpException(422, 'Chemin fichier invalide.');
        }

        foreach (['config/', 'plugins/', 'mods/'] as $prefix) {
            if (str_starts_with(trim($p, '/') . '/', $prefix)) {
                $ext = strtolower((string) pathinfo($p, PATHINFO_EXTENSION));
                if (! in_array($ext, self::ALLOW_EXT, true)) {
                    throw new PmcpHttpException(422, 'Extension de fichier non autorisée pour l’éditeur sécurisé.', ['allowed_ext' => self::ALLOW_EXT]);
                }

                return $p === '' ? '/' : $p;
            }
        }

        throw new PmcpHttpException(422, 'Le fichier doit résider sous /config/, /plugins/ ou /mods/.');
    }
}
