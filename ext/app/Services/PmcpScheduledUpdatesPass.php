<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Throwable;

/**
 * Une « tick » de mises à jour planifiées pour les serveurs ayant activé {@see pmcp_server_schedules}.
 * Appelée chaque minute via la commande Blueprint {@see data/console/scheduled-updates.php}.
 */
final class PmcpScheduledUpdatesPass
{
    /**
     * @param  callable(string): void  $log
     * @return array{servers_eligible: int, servers_processed: int, updates_applied: int, errors: list<string>}
     */
    public static function run(bool $dryRun, bool $force, callable $log): array
    {
        $errors = [];
        $serversProcessed = 0;
        $updatesApplied = 0;

        if (! Schema::hasTable('pmcp_server_schedules') || ! Schema::hasTable('pmcp_install_events')) {
            $log('[pmcp] Tables pmcp_server_schedules ou pmcp_install_events absentes — skip.');

            return [
                'servers_eligible' => 0,
                'servers_processed' => 0,
                'updates_applied' => 0,
                'errors' => ['migrations_manquantes'],
            ];
        }

        $now = Carbon::now('UTC');

        $deps = PmcpScheduledDepsFactory::create();
        $modrinthGet = $deps['modrinthGet'];
        $modrinthLatestFromVersionList = $deps['modrinthLatestFromVersionList'];
        $curseForgeApiKey = $deps['curseForgeApiKey'];
        $curseForgeGet = $deps['curseForgeGet'];
        $validProjectId = $deps['validProjectId'];
        $validCurseForgeModId = $deps['validCurseForgeModId'];
        $curseForgeAuthFailureMessage = $deps['curseForgeAuthFailureMessage'];
        $pmcpTruncatePlain = $deps['pmcpTruncatePlain'];

        $checkDeps = [
            'modrinthGet' => $modrinthGet,
            'modrinthLatestFromVersionList' => $modrinthLatestFromVersionList,
            'curseForgeApiKey' => $curseForgeApiKey,
            'curseForgeGet' => $curseForgeGet,
            'validProjectId' => $validProjectId,
            'validCurseForgeModId' => $validCurseForgeModId,
            'curseForgeAuthFailureMessage' => $curseForgeAuthFailureMessage,
            'pmcpTruncatePlain' => $pmcpTruncatePlain,
        ];

        $schedules = DB::table('pmcp_server_schedules')
            ->where('scheduled_enabled', true)
            ->get(['server_id', 'cron_expression', 'max_updates_per_run', 'backup_before_update']);

        $eligible = 0;

        foreach ($schedules as $sch) {
            $expr = trim((string) $sch->cron_expression);
            if ($expr === '') {
                continue;
            }

            if (! $force && ! self::cronExpressionIsDue($expr, $now, $log)) {
                continue;
            }

            ++$eligible;

            $server = Server::query()->where('id', (int) $sch->server_id)->first();
            if ($server === null) {
                $errors[] = 'server_id=' . (string) $sch->server_id . ' introuvable';
                continue;
            }

            try {
                $server->validateCurrentState();
            } catch (Throwable) {
                $log('[pmcp] Serveur #' . (string) $server->id . ' : état conflictuel — skip.');

                continue;
            }

            $user = User::query()->find($server->owner_id);
            if ($user === null) {
                $errors[] = 'owner absent pour server #' . (string) $server->id;

                continue;
            }

            if (! $user->can(Permission::ACTION_FILE_CREATE, $server)) {
                $log('[pmcp] Serveur #' . (string) $server->id . ' : owner sans permission fichiers — skip.');

                continue;
            }

            ++$serversProcessed;

            $maxRun = max(1, min(50, (int) $sch->max_updates_per_run));
            $wantBackup = (bool) $sch->backup_before_update;

            $historyRows = DB::table('pmcp_install_events')
                ->where('server_id', $server->id)
                ->orderByDesc('created_at')
                ->limit(80)
                ->get(['provider', 'project_id', 'version_id', 'directory']);

            $history = [];
            foreach ($historyRows as $r) {
                $history[] = [
                    'provider' => (string) $r->provider,
                    'project_id' => (string) $r->project_id,
                    'version_id' => (string) $r->version_id,
                    'directory' => (string) $r->directory,
                ];
            }

            $historySlice = self::dedupeHistoryByTargetNewestFirst($history);
            if ($historySlice === []) {
                $log('[pmcp] Serveur #' . (string) $server->id . ' : historique vide.');

                continue;
            }

            $pinMap = [];
            if (Schema::hasTable('pmcp_install_pins')) {
                foreach (DB::table('pmcp_install_pins')->where('server_id', $server->id)->get(['provider', 'project_id', 'pinned_version_id', 'pinned_version_label']) as $p) {
                    $pinMap[(string) $p->provider . ':' . (string) $p->project_id] = [
                        'pinned_version_id' => (string) $p->pinned_version_id,
                        'pinned_version_label' => $p->pinned_version_label !== null ? (string) $p->pinned_version_label : null,
                    ];
                }
            }

            $freshMap = [];
            $candidatePairs = [];

            for ($i = 0; $i < count($historySlice); $i += 25) {
                $chunk = array_slice($historySlice, $i, 25);
                $entries = [];
                foreach ($chunk as $h) {
                    $entries[] = [
                        'provider' => $h['provider'],
                        'project_id' => $h['project_id'],
                        'version_id' => $h['version_id'],
                    ];
                }

                try {
                    $items = PmcpCheckUpdatesBatch::run($pinMap, $entries, $checkDeps);
                } catch (Throwable $e) {
                    $errors[] = 'check server #' . $server->id . ' : ' . $e->getMessage();
                    continue 2;
                }

                foreach ($items as $row) {
                    $pk = (string) ($row['provider'] ?? '') . ':' . (string) ($row['project_id'] ?? '');
                    $freshMap[$pk] = $row;
                }

                foreach ($chunk as $h) {
                    $pk = $h['provider'] . ':' . $h['project_id'];
                    $up = $freshMap[$pk] ?? null;
                    if (! is_array($up)) {
                        continue;
                    }
                    if (! empty($up['error'])) {
                        continue;
                    }
                    if (empty($up['update_available'])) {
                        continue;
                    }
                    $lid = isset($up['latest_version_id']) ? (string) $up['latest_version_id'] : '';
                    if ($lid === '') {
                        continue;
                    }
                    $pinRow = is_array($up['pin'] ?? null) ? $up['pin'] : null;
                    if ($pinRow !== null && isset($pinRow['pinned_version_id']) && (string) $pinRow['pinned_version_id'] !== '') {
                        continue;
                    }
                    $candidatePairs[] = ['h' => $h, 'latestVersionId' => $lid];
                }
            }

            $candidates = array_slice($candidatePairs, 0, $maxRun);
            if ($candidates === []) {
                $log('[pmcp] Serveur #' . (string) $server->id . ' : aucune MAJ éligible.');

                continue;
            }

            foreach ($candidates as $c) {
                $h = $c['h'];
                $lid = $c['latestVersionId'];

                try {
                    $server->validateCurrentState();
                } catch (Throwable) {
                    break;
                }

                if ($dryRun) {
                    $log('[pmcp][dry-run] ' . $server->id . ' ' . $h['provider'] . ':' . $h['project_id'] . ' → ' . $lid);

                    continue;
                }

                try {
                    if ($h['provider'] === 'modrinth') {
                        PmcpArtifactInstall::modrinth(
                            $server,
                            $user,
                            $h['project_id'],
                            $lid,
                            $h['directory'],
                            $wantBackup,
                            'scheduled',
                            $deps['modrinthGet'],
                            $deps['validProjectId'],
                            $deps['normalizeInstallDirectory'],
                            $deps['defaultInstallDirectory'],
                            $deps['pmcpInstallBlockedByPolicy'],
                        );
                    } elseif ($h['provider'] === 'curseforge') {
                        $mid = (int) $h['project_id'];
                        $fid = (int) $lid;
                        if ($mid <= 0 || $fid <= 0) {
                            continue;
                        }
                        PmcpArtifactInstall::curseforge(
                            $server,
                            $user,
                            $mid,
                            $fid,
                            $h['directory'],
                            $wantBackup,
                            'scheduled',
                            $deps['curseForgeGameIdMc'],
                            $deps['curseForgeGet'],
                            $deps['curseForgeAuthFailureMessage'],
                            $deps['curseForgeCfResponseData'],
                            $deps['defaultInstallDirectoryCurseForge'],
                            $deps['normalizeInstallDirectory'],
                            $deps['pmcpInstallBlockedByPolicy'],
                        );
                    }
                    ++$updatesApplied;
                } catch (PmcpHttpException $e) {
                    $errors[] = 'srv ' . (string) $server->id . ' ' . $h['provider'] . ':' . $h['project_id'] . ' — ' . $e->getMessage();
                } catch (Throwable $e) {
                    $errors[] = 'srv ' . (string) $server->id . ' — ' . $e->getMessage();
                }
            }
        }

        $log(sprintf('[pmcp] Terminé : %d planification(s) dues, %d serveur(s) traité(s), %d install(s).', $eligible, $serversProcessed, $updatesApplied));

        return [
            'servers_eligible' => $eligible,
            'servers_processed' => $serversProcessed,
            'updates_applied' => $updatesApplied,
            'errors' => $errors,
        ];
    }

    private static function cronExpressionIsDue(string $expr, Carbon $now, callable $log): bool
    {
        if (! class_exists(\Cron\CronExpression::class)) {
            $log('[pmcp] Paquet dragonmantank/cron-expression introuvable — impossible d’évaluer le cron. Tapez `composer require dragonmantank/cron-expression` sur le panel ou utilisez --force.');

            return false;
        }

        try {
            $cron = \Cron\CronExpression::factory($expr);
        } catch (Throwable $e) {
            $log('[pmcp] Expression cron invalide « ' . $expr . ' » : ' . $e->getMessage());

            return false;
        }

        return $cron->isDue($now);
    }

    /**
     * @param  list<array{provider: string, project_id: string, version_id: string, directory: string}>  $items
     * @return list<array{provider: string, project_id: string, version_id: string, directory: string}>
     */
    private static function dedupeHistoryByTargetNewestFirst(array $items): array
    {
        $seen = [];
        $out = [];

        foreach ($items as $it) {
            $dir = strtolower(trim($it['directory']));
            $k = strtolower($it['provider']) . ':' . strtolower($it['project_id']) . ':' . $dir;
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $it;
        }

        return $out;
    }
}
