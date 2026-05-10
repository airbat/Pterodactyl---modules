<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

final class PmcpInstallBackupRestore
{
    /**
     * Décompresse une archive sur le volume du serveur (fichiers manager Wings).
     *
     * Autorisé : sous /config, /mods, /plugins, ou fichier .tar.gz directement sous /.
     *
     * @param  non-empty-string  $archiveRelativePath chemin depuis la racine volume (ex: /mods/back.tar.gz ou /bundle.tar.gz)
     */
    public static function decompressWingsArchive(Server $server, string $archiveRelativePath): void
    {
        if (! class_exists(DaemonFileRepository::class)) {
            throw new PmcpHttpException(500, 'DaemonFileRepository introuvable.');
        }

        $norm = '/' . trim(str_replace('\\', '/', $archiveRelativePath), '/');
        if (str_contains($norm, '..') || strlen($norm) > 512) {
            throw new PmcpHttpException(422, 'Chemin archive invalide.');
        }

        $ok = preg_match('#^/(config|mods|plugins)(/.*)?$|^/[^/]+\.tar\.gz$#', $norm) === 1;
        if (! $ok) {
            throw new PmcpHttpException(422, 'Restauration refusée : périmètre fichier non autorisé.');
        }

        $dName = dirname($norm);
        $base = basename($norm);
        $daemonRoot = ($dName === '/' || $dName === '.' || $dName === '') ? '/' : $dName;

        /** @var DaemonFileRepository $repo */
        $repo = app(DaemonFileRepository::class);
        try {
            $repo->setServer($server)->decompressFile($daemonRoot, $base);
        } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
            throw new PmcpHttpException(
                502,
                'Wings decompress échoué.',
                ['detail' => config('app.debug') ? $e->getMessage() : null]
            );
        }
    }
}
