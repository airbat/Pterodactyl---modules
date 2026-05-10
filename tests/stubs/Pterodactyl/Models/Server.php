<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

/**
 * Stub minimal pour exécuter {@see \PteroMcPlugins\Services\ServerMcContextBuilder}
 * hors Panel (tests unitaires standalone).
 *
 * En production Blueprint, la classe réelle du Panel remplace cet autoload-dev.
 */
final class Server
{
    public mixed $startup = '';

    /** @var iterable<int|string, object> lignes pivot variables serveur (variable_value, variable) */
    public iterable $variables = [];

    public ?object $egg = null;

    public ?object $nest = null;

    public function loadMissing(string ...$relations): void
    {
        // no-op : le builder ne lit que des relations déjà chargées en test
    }

    /**
     * Présente uniquement pour que {@see \PteroMcPlugins\Services\ServerMcContextBuilder}
     * active la fusion des variables (le builder itère ensuite la propriété {@see self::$variables}).
     */
    public function variables(): void {}
}
