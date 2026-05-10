<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/**
 * Ancrage des suppressions addons : uniquement sous /mods et /plugins (même périmètre que les installs courantes Java).
 */
final class PmcpAddonRemovalPath
{
    /**
     * Répertoire cible Wings (commence par /, pas de ..).
     */
    public static function normalizeModsPluginsDirectory(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $dir = str_replace('\\', '/', trim($raw));
        if (str_contains($dir, '..') || preg_match('#\p{C}#u', $dir) !== 0) {
            return null;
        }

        $dir = '/' . trim($dir, '/');
        if ($dir === '/') {
            return null;
        }

        if (preg_match('#^/(mods|plugins)(/|$)#', $dir) !== 1) {
            return null;
        }

        return $dir;
    }

    /** Nom de fichier seul ; pas de chemin (« ../ » hors sujet après basename). */
    public static function sanitizeArtifactBasename(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $t = trim($raw);
        if ($t === '') {
            return null;
        }

        $bn = basename(str_replace('\\', '/', $t));
        if ($bn === '' || $bn !== trim($bn)) {
            return null;
        }

        if (str_contains($bn, '/') || str_contains($bn, "\0")) {
            return null;
        }

        if (strlen($bn) > 255) {
            return null;
        }

        /** @lang RegExp safe names : jar/phar/disabled/zip légitimes côté plugins-mods Java */
        if (preg_match('/^[A-Za-z0-9._\\-\\+()[\\]\\s\\p{L}\\p{M}\\p{N}]{1,255}$/u', $bn) !== 1) {
            return null;
        }

        return $bn;
    }
}
