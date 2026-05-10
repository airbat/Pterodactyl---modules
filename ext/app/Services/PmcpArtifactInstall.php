<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/** Pull Modrinth / CurseForge — partagé routes, presets, cron. */
final class PmcpArtifactInstall
{
    /** @param  callable(string, array<string,mixed>): \Illuminate\Http\Client\Response  $modrinthGet */
    public static function modrinth(
        Server $server,
        object $user,
        string $projectId,
        string $versionId,
        ?string $directoryRaw,
        bool $wantBackup,
        string $backupContext,
        callable $modrinthGet,
        callable $validProjectId,
        callable $normalizeInstallDirectory,
        callable $defaultInstallDirectory,
        callable $installBlockedByPolicy,
    ): array {
        if (! class_exists(DaemonFileRepository::class)) {
            throw new PmcpHttpException(500, 'Classes Wings du panel introuvables (DaemonFileRepository).');
        }
        if (! $validProjectId($projectId)) {
            throw new PmcpHttpException(422, 'Identifiant projet invalide.');
        }
        if ($installBlockedByPolicy('modrinth', $projectId)) {
            throw new PmcpHttpException(403, 'Installation bloquée par la politique du panneau (variable PMCP_BLOCKLIST_PROJECT_IDS).');
        }

        try {
            $vr = $modrinthGet('/version/' . rawurlencode($versionId), []);
        } catch (\Throwable $e) {
            throw new PmcpHttpException(503, 'Réseau indisponible vers Modrinth.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }
        if ($vr->status() === 404) {
            throw new PmcpHttpException(404, 'Version Modrinth introuvable.');
        }
        if (! $vr->successful()) {
            throw new PmcpHttpException(502, 'Modrinth a répondu une erreur.', ['status' => $vr->status()]);
        }
        $version = $vr->json();
        if (! is_array($version)) {
            throw new PmcpHttpException(502, 'Réponse Modrinth invalide.');
        }
        $vpid = isset($version['project_id']) ? (string) $version['project_id'] : '';
        if ($vpid === '' || $vpid !== $projectId) {
            throw new PmcpHttpException(422, 'La version ne correspond pas à ce projet.');
        }

        $primary = null;
        foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $f) {
            if (is_array($f) && ! empty($f['primary'])) {
                $primary = $f;
                break;
            }
        }
        if ($primary === null && isset($version['files'][0]) && is_array($version['files'][0])) {
            $primary = $version['files'][0];
        }
        if ($primary === null || empty($primary['url'])) {
            throw new PmcpHttpException(422, 'Aucun fichier téléchargeable pour cette version.');
        }

        $fileUrl = (string) $primary['url'];
        $filename = isset($primary['filename']) ? (string) $primary['filename'] : null;
        if ($filename === '') {
            $filename = null;
        }

        $directory = $normalizeInstallDirectory(is_string($directoryRaw) ? $directoryRaw : null);
        if (is_string($directoryRaw) && trim($directoryRaw) !== '' && $directory === null) {
            throw new PmcpHttpException(422, 'Chemin cible invalide (traversée interdite).');
        }
        if ($directory === null) {
            $directory = $defaultInstallDirectory($version, $projectId, $server);
        }

        $backupOutcome = null;
        if ($wantBackup) {
            $bcRaw = trim($backupContext) !== '' ? trim($backupContext) : 'history';
            $allowed = ['catalog', 'history', 'scheduled', 'preset', 'cron'];
            if (! in_array($bcRaw, $allowed, true)) {
                throw new PmcpHttpException(422, 'Paramètre backup_context invalide.', ['allowed' => $allowed]);
            }
            $backupOutcome = PmcpInstallDirectoryBackup::runBeforeInstallIfRequested(
                true,
                $server,
                $user,
                $directory,
                $bcRaw,
                'modrinth',
                $projectId,
                $versionId,
            );
            if (! ($backupOutcome['ok'] ?? false)) {
                $msg = isset($backupOutcome['message']) && is_string($backupOutcome['message']) && $backupOutcome['message'] !== ''
                    ? $backupOutcome['message'] : 'Sauvegarde compressée impossible.';
                throw new PmcpHttpException(str_contains($msg, 'Table pmcp_backups') ? 503 : 422, $msg);
            }
        }

        /** @var DaemonFileRepository $repo */
        $repo = app(DaemonFileRepository::class);
        $pullParams = array_filter(['filename' => $filename], static fn ($v) => $v !== null && $v !== '');
        try {
            $repo->setServer($server)->pull($fileUrl, $directory, $pullParams);
        } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
            throw new PmcpHttpException(502, 'Échec du téléchargement distant via Wings.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        } catch (\Throwable $e) {
            throw new PmcpHttpException(500, 'Erreur lors de l’installation sur le serveur.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }

        $loaders = is_array($version['loaders'] ?? null) ? $version['loaders'] : [];
        $eventId = null;
        if (Schema::hasTable('pmcp_install_events')) {
            try {
                $versionLabel = null;
                if (isset($version['version_number']) && is_string($version['version_number']) && $version['version_number'] !== '') {
                    $versionLabel = $version['version_number'];
                } elseif (isset($version['name']) && is_string($version['name']) && $version['name'] !== '') {
                    $versionLabel = $version['name'];
                }
                $now = now();
                $eventId = DB::table('pmcp_install_events')->insertGetId([
                    'server_id' => $server->id,
                    'user_id' => $user->id,
                    'provider' => 'modrinth',
                    'project_id' => $projectId,
                    'version_id' => $versionId,
                    'directory' => $directory,
                    'filename' => $filename,
                    'version_label' => $versionLabel,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable) {
                $eventId = null;
            }
        }

        return self::assembleResponse($wantBackup, $backupOutcome, $directory, $filename, $loaders, $eventId);
    }

    /**
     * @param  callable(string, array<string,mixed>): (\Illuminate\Http\Client\Response)|false $curseForgeGet
     * @param  callable(\Illuminate\Http\Client\Response): ?string $curseForgeAuthFailureMessage
     * @param  callable(mixed): array<string,mixed>|null $curseForgeCfResponseData
     */
    public static function curseforge(
        Server $server,
        object $user,
        int $modId,
        int $fileId,
        ?string $directoryRaw,
        bool $wantBackup,
        string $backupContext,
        int $curseForgeGameIdMc,
        callable $curseForgeGet,
        callable $curseForgeAuthFailureMessage,
        callable $curseForgeCfResponseData,
        callable $defaultInstallDirectoryCurseForge,
        callable $normalizeInstallDirectory,
        callable $installBlockedByPolicy,
    ): array {
        if (! class_exists(DaemonFileRepository::class)) {
            throw new PmcpHttpException(500, 'Classes Wings du panel introuvables (DaemonFileRepository).');
        }
        if ($installBlockedByPolicy('curseforge', (string) $modId)) {
            throw new PmcpHttpException(403, 'Installation bloquée par la politique du panneau (variable PMCP_BLOCKLIST_PROJECT_IDS).');
        }

        try {
            $mr = $curseForgeGet('/mods/' . $modId);
        } catch (\Throwable $e) {
            throw new PmcpHttpException(503, 'Réseau indisponible vers CurseForge.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }
        if ($mr === false) {
            throw new PmcpHttpException(503, 'CurseForge : clé API absente ou refusée.');
        }
        if ($mr->status() === 404) {
            throw new PmcpHttpException(404, 'Mod CurseForge introuvable.');
        }
        if (! $mr->successful()) {
            $authMsg = $curseForgeAuthFailureMessage($mr);
            if ($authMsg !== null) {
                throw new PmcpHttpException(503, $authMsg, ['status' => $mr->status()]);
            }
            throw new PmcpHttpException(502, 'CurseForge a répondu une erreur.', ['status' => $mr->status()]);
        }

        $modRow = $curseForgeCfResponseData($mr->json());
        if ($modRow === null) {
            throw new PmcpHttpException(502, 'Réponse CurseForge invalide (mod).');
        }
        if (isset($modRow['gameId']) && (int) $modRow['gameId'] !== $curseForgeGameIdMc) {
            throw new PmcpHttpException(422, 'Ce projet n’est pas Minecraft Java (gameId différent dans CurseForge).');
        }
        $classId = isset($modRow['classId']) ? (int) $modRow['classId'] : null;

        try {
            $fr = $curseForgeGet('/mods/' . $modId . '/files/' . $fileId);
        } catch (\Throwable $e) {
            throw new PmcpHttpException(503, 'Réseau indisponible vers CurseForge (fichier).', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }
        if ($fr === false) {
            throw new PmcpHttpException(503, 'CurseForge : clé API absente ou refusée.');
        }
        if ($fr->status() === 404) {
            throw new PmcpHttpException(404, 'Fichier CurseForge introuvable.');
        }
        if (! $fr->successful()) {
            $authMsg = $curseForgeAuthFailureMessage($fr);
            if ($authMsg !== null) {
                throw new PmcpHttpException(503, $authMsg, ['status' => $fr->status()]);
            }
            throw new PmcpHttpException(502, 'CurseForge a répondu une erreur (fichier).', ['status' => $fr->status()]);
        }

        $fileRow = $curseForgeCfResponseData($fr->json());
        if ($fileRow === null) {
            throw new PmcpHttpException(502, 'Réponse CurseForge invalide (fichier).');
        }
        if (isset($fileRow['modId']) && (int) $fileRow['modId'] !== $modId) {
            throw new PmcpHttpException(422, 'Le fichier ne correspond pas à ce mod.');
        }

        $filename = isset($fileRow['fileName']) && is_string($fileRow['fileName']) && $fileRow['fileName'] !== ''
            ? (string) $fileRow['fileName'] : null;

        try {
            $dur = $curseForgeGet('/mods/' . $modId . '/files/' . $fileId . '/download-url');
        } catch (\Throwable $e) {
            throw new PmcpHttpException(503, 'Réseau indisponible vers CurseForge (URL de téléchargement).', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }
        if ($dur === false) {
            throw new PmcpHttpException(503, 'CurseForge : clé API absente ou refusée.');
        }
        if ($dur->status() === 404) {
            throw new PmcpHttpException(404, 'URL de téléchargement introuvable.');
        }
        if (! $dur->successful()) {
            $authMsg = $curseForgeAuthFailureMessage($dur);
            if ($authMsg !== null) {
                throw new PmcpHttpException(503, $authMsg, ['status' => $dur->status()]);
            }
            throw new PmcpHttpException(
                502,
                'CurseForge a répondu une erreur (URL de téléchargement).',
                ['status' => $dur->status()]
            );
        }

        $dlPayload = $dur->json();
        $fileUrl = null;
        if (is_array($dlPayload) && array_key_exists('data', $dlPayload)) {
            $d = $dlPayload['data'];
            if (is_string($d) && $d !== '') {
                $fileUrl = $d;
            }
        }
        if ($fileUrl === null || ! filter_var($fileUrl, FILTER_VALIDATE_URL)) {
            throw new PmcpHttpException(502, 'URL de téléchargement CurseForge invalide ou absente.');
        }

        $directory = $normalizeInstallDirectory(is_string($directoryRaw) ? $directoryRaw : null);
        if (is_string($directoryRaw) && trim($directoryRaw) !== '' && $directory === null) {
            throw new PmcpHttpException(422, 'Chemin cible invalide (traversée interdite).');
        }
        if ($directory === null) {
            $directory = $defaultInstallDirectoryCurseForge($classId, $server);
        }

        $backupOutcome = null;
        if ($wantBackup) {
            $bcRaw = trim($backupContext) !== '' ? trim($backupContext) : 'history';
            $allowed = ['catalog', 'history', 'scheduled', 'preset', 'cron'];
            if (! in_array($bcRaw, $allowed, true)) {
                throw new PmcpHttpException(422, 'Paramètre backup_context invalide.', ['allowed' => $allowed]);
            }
            $backupOutcome = PmcpInstallDirectoryBackup::runBeforeInstallIfRequested(
                true,
                $server,
                $user,
                $directory,
                $bcRaw,
                'curseforge',
                (string) $modId,
                (string) $fileId,
            );
            if (! ($backupOutcome['ok'] ?? false)) {
                $msg = isset($backupOutcome['message']) && is_string($backupOutcome['message']) && $backupOutcome['message'] !== ''
                    ? $backupOutcome['message'] : 'Sauvegarde compressée impossible.';
                throw new PmcpHttpException(str_contains($msg, 'Table pmcp_backups') ? 503 : 422, $msg);
            }
        }

        /** @var DaemonFileRepository $repo */
        $repo = app(DaemonFileRepository::class);
        $pullParams = array_filter(['filename' => $filename], static fn ($v) => $v !== null && $v !== '');
        try {
            $repo->setServer($server)->pull($fileUrl, $directory, $pullParams);
        } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
            throw new PmcpHttpException(502, 'Échec du téléchargement distant via Wings.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        } catch (\Throwable $e) {
            throw new PmcpHttpException(500, 'Erreur lors de l’installation sur le serveur.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }

        $displayNameForLog = isset($fileRow['displayName']) && is_string($fileRow['displayName']) && $fileRow['displayName'] !== ''
            ? $fileRow['displayName'] : $filename;

        $eventId = null;
        if (Schema::hasTable('pmcp_install_events')) {
            try {
                $now = now();
                $eventId = DB::table('pmcp_install_events')->insertGetId([
                    'server_id' => $server->id,
                    'user_id' => $user->id,
                    'provider' => 'curseforge',
                    'project_id' => (string) $modId,
                    'version_id' => (string) $fileId,
                    'directory' => $directory,
                    'filename' => $filename,
                    'version_label' => is_string($displayNameForLog) ? $displayNameForLog : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable) {
                $eventId = null;
            }
        }

        return self::assembleResponse($wantBackup, $backupOutcome, $directory, $filename, [], $eventId);
    }

    /**
     * @param  ?array<string, mixed>  $backupOutcome
     * @param  list<string>  $loaders
     * @return array{
     *   message: string,
     *   directory: string,
     *   filename: string|null,
     *   loaders: list<string>,
     *   restart_recommended: true,
     *   event_id: int|null,
     *   backup: array{id: int, archive: string}|null
     * }
     */
    private static function assembleResponse(
        bool $wantBackup,
        ?array $backupOutcome,
        string $directory,
        ?string $filename,
        array $loaders,
        ?int $eventId,
    ): array {
        $backupResponse = null;
        if (
            $wantBackup
            && is_array($backupOutcome)
            && ($backupOutcome['ok'] ?? false)
            && isset($backupOutcome['backup_id'], $backupOutcome['archive_relative_path'])
        ) {
            $backupResponse = [
                'id' => (int) $backupOutcome['backup_id'],
                'archive' => (string) $backupOutcome['archive_relative_path'],
            ];
        }

        return [
            'message' => 'Téléchargement demandé sur le serveur (pull distant Wings).',
            'directory' => $directory,
            'filename' => $filename,
            'loaders' => $loaders,
            'restart_recommended' => true,
            'event_id' => $eventId,
            'backup' => $backupResponse,
        ];
    }
}
