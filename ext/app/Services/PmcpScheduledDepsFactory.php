<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

require_once __DIR__ . '/PmcpClientHttpDelegates.php';

/**
 * @deprecated Prefer {@see PmcpClientHttpDelegates::createScheduledSubset()} — conserve ce fichier pour les chemins Blueprint déjà référencés.
 */
final class PmcpScheduledDepsFactory
{
    /**
     * @return array<string, mixed>
     */
    public static function create(): array
    {
        return PmcpClientHttpDelegates::createScheduledSubset();
    }
}
