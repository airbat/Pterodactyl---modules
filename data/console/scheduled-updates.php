<?php

declare(strict_types=1);

/**
 * Blueprint console — copié vers app/Console/Commands/BlueprintFramework/Extensions/{identifier}/
 *
 * Invocation : php artisan pteromcplugins:scheduled-updates [--dry-run] [--force]
 * - sans --force : uniquement les serveurs dont la ligne pmcp_server_schedules tombe « due » cette minute UTC ;
 * - --force : tous les serveurs avec planification active (ignore le cron cette fois) ;
 * - --dry-run : affiche les actions sans installer.
 */

$svc = \PteroMcPlugins\Services\PmcpScheduledUpdatesPass::class;

if (! class_exists($svc)) {
    $base = base_path('app/BlueprintFramework/Extensions/pteromcplugins/Services');
    foreach ([$base . '/PmcpClientHttpDelegates.php', $base . '/PmcpScheduledDepsFactory.php', $base . '/PmcpScheduledUpdatesPass.php'] as $f) {
        if (is_file($f)) {
            require_once $f;
        }
    }
}

if (! class_exists($svc)) {
    fwrite(STDERR, "[pmcp] PmcpScheduledUpdatesPass introuvable — vérifiez l’extension Blueprint (requests.app symlink).\n");

    return 1;
}

$globArgv = isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : [];

$dryRun = in_array('--dry-run', $globArgv, true);
$force = in_array('--force', $globArgv, true);

$log = static function (string $line): void {
    echo $line . PHP_EOL;
};

$svc::run($dryRun, $force, $log);

return 0;
