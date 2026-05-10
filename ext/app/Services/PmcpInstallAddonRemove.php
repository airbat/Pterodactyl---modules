<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

require_once __DIR__ . '/PmcpAddonRemovalPath.php';

/** Supprime le fichier d’une ligne `pmcp_install_events` sous /mods ou /plugins via Wings. */
final class PmcpInstallAddonRemove
{
    /**
     * @return array{message: string, root: string, file: string}
     *
     * @throws PmcpHttpException
     */
    public static function runForInstallEvent(Server $server, int $installEventId): array
    {
        if (! class_exists(DaemonFileRepository::class)) {
            throw new PmcpHttpException(500, 'Classes Wings du panel introuvables (DaemonFileRepository).');
        }

        if (! Schema::hasTable('pmcp_install_events')) {
            throw new PmcpHttpException(503, 'Table d’historique des installations absente.');
        }

        $row = DB::table('pmcp_install_events')
            ->where('id', $installEventId)
            ->where('server_id', $server->id)
            ->first(['directory', 'filename']);

        if ($row === null) {
            throw new PmcpHttpException(404, 'Entrée d’installation introuvable pour ce serveur.');
        }

        $directory = PmcpAddonRemovalPath::normalizeModsPluginsDirectory((string) ($row->directory ?? ''));
        if ($directory === null) {
            throw new PmcpHttpException(
                422,
                'Cette entrée pointe vers un répertoire non autorisé pour la suppression (autorisé : sous /mods ou /plugins).'
            );
        }

        $filename = $row->filename !== null && is_string($row->filename) && trim($row->filename) !== ''
            ? PmcpAddonRemovalPath::sanitizeArtifactBasename($row->filename)
            : null;

        if ($filename === null) {
            throw new PmcpHttpException(
                422,
                'Cette entrée ne mémorise pas de nom de fichier : suppression impossible par ce flux (réinstallation manuelle / SFTP).'
            );
        }

        /** @var DaemonFileRepository $repo */
        $repo = app(DaemonFileRepository::class);
        try {
            $repo->setServer($server)->deleteFiles($directory, [$filename]);
        } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
            throw new PmcpHttpException(502, 'Wings : suppression du fichier impossible.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        } catch (\Throwable $e) {
            throw new PmcpHttpException(500, 'Erreur lors de la suppression sur le serveur.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }

        return [
            'message' => 'Fichier supprimé sur le volume serveur via Wings.',
            'root' => $directory,
            'file' => $filename,
        ];
    }
}
