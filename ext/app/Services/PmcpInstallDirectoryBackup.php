<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Compress un sous-dossier cible avant install / MAJ ; retour utilisé pour journal pmcp_backups.
 */
final class PmcpInstallDirectoryBackup
{
    /**
     * @return array{
     *   ok: bool,
     *   backup_id?: int,
     *   archive_relative_path?: string,
     *   message?: non-empty-string
     * }
     */
    public static function runBeforeInstallIfRequested(
        bool $requested,
        Server $server,
        object $actingUser,
        string $normalizedInstallDirectory,
        string $context,
        ?string $provider,
        ?string $projectId,
        ?string $versionId,
    ): array {
        if (! $requested) {
            return ['ok' => true];
        }

        if (! Schema::hasTable('pmcp_backups')) {
            return [
                'ok' => false,
                'message' => 'Table pmcp_backups absente — appliquer les migrations blueprint.',
            ];
        }

        if (! isset($actingUser->id)) {
            return ['ok' => false, 'message' => 'Utilisateur panel invalide pour la sauvegarde.'];
        }

        $split = PmcpInstallCompressPaths::forNormalizedDirectory($normalizedInstallDirectory);
        if ($split === null) {
            return ['ok' => false, 'message' => 'Dossier cible incomprès pour compression (racine "/" interdite).'];
        }

        if (! class_exists(DaemonFileRepository::class)) {
            return ['ok' => false, 'message' => 'DaemonFileRepository introuvable sur ce panel.'];
        }

        /** @var DaemonFileRepository $repo */
        $repo = app(DaemonFileRepository::class);
        $repo->setServer($server);

        try {
            $decoded = $repo->compressFiles($split['daemon_root'], $split['wing_files']);
        } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
            return [
                'ok' => false,
                'message' => 'Wings compress : ' . ($e->getMessage() ?: 'connexion daemon'),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Compress : ' . ($e->getMessage() ?: 'erreur inconnue'),
            ];
        }

        if (! is_array($decoded)) {
            return ['ok' => false, 'message' => 'Réponse Wings compress invalide (non tableau).'];
        }

        $archiveBasename = self::extractArchiveBasename($decoded);
        if ($archiveBasename === '') {
            return ['ok' => false, 'message' => 'Réponse Wings compress sans nom d’archive (clé attendue File.name ou name).'];
        }

        $archiveRelative = self::relativeArchivePath($split['daemon_root'], $archiveBasename);

        $uid = (int) $actingUser->id;

        try {
            $bid = DB::table('pmcp_backups')->insertGetId([
                'server_id' => $server->id,
                'user_id' => $uid > 0 ? $uid : null,
                'install_directory' => $normalizedInstallDirectory,
                'archive_relative_path' => $archiveRelative,
                'context' => $context !== '' ? $context : 'install',
                'provider' => $provider,
                'project_id' => $projectId,
                'version_id' => $versionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Insertion pmcp_backups : ' . ($e->getMessage() ?: 'erreur DB'),
            ];
        }

        return [
            'ok' => true,
            'backup_id' => (int) $bid,
            'archive_relative_path' => $archiveRelative,
        ];
    }

    /** @param  array<mixed,mixed>  $decoded */
    private static function extractArchiveBasename(array $decoded): string
    {
        $fileMeta = $decoded['file'] ?? null;
        if (is_array($fileMeta)) {
            $n = isset($fileMeta['name']) && is_string($fileMeta['name']) ? basename($fileMeta['name']) : '';
            if ($n !== '') {
                return self::sanitizeFilename($n);
            }
        }

        $nTop = isset($decoded['name']) && is_string($decoded['name']) ? basename((string) $decoded['name']) : '';

        return $nTop !== '' ? self::sanitizeFilename($nTop) : '';
    }

    private static function sanitizeFilename(string $raw): string
    {
        $b = basename(str_replace('\\', '/', $raw));
        if ($b === '' || $b === '.' || $b === '..' || str_contains($b, '..')) {
            return '';
        }

        return $b;
    }

    private static function relativeArchivePath(string $daemonRoot, string $archiveBasename): string
    {
        if ($daemonRoot === '/' || $daemonRoot === '') {
            return '/' . ltrim($archiveBasename, '/');
        }

        return '/' . trim($daemonRoot, '/') . '/' . ltrim($archiveBasename, '/');
    }
}
