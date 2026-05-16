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
     * Relation Panel simulée : si {@see self::$variablesRelation} est défini, il est utilisé ;
     * sinon repli sur {@see self::$variables} via {@see get()}.
     */
    public ?object $variablesRelation = null;

    public function variables(): object
    {
        if ($this->variablesRelation !== null) {
            return $this->variablesRelation;
        }

        $rows = is_iterable($this->variables)
            ? (is_array($this->variables) ? $this->variables : iterator_to_array($this->variables, false))
            : [];

        return new class ($rows) {
            /** @param list<object> $rows */
            public function __construct(private array $rows)
            {
            }

            /** @return list<object> */
            public function get(): array
            {
                return $this->rows;
            }
        };
    }
}
