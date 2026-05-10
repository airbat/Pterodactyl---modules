<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/**
 * Erreur métier mappée vers une réponse HTTP JSON (routes et jobs partagés).
 */
final class PmcpHttpException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
