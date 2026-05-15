<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Throwable;

/**
 * Probe runtime de la version Minecraft : lit `/logs/latest.log` côté Wings et délègue
 * le parsing à {@see PmcpVersionLogParser}.
 *
 * Pas de fallback v1.0 (WS history, sortie commande `version`) — voir docs/superpowers/plans
 * /2026-05-15-runtime-mc-version-probe.md pour les choix de scope.
 *
 * Path codé en dur (aucun input utilisateur) → pas de risque de path traversal.
 *
 * Détection 404 : Pterodactyl 1.11+ enveloppe les réponses Wings (dont fichier absent) dans
 * {@see DaemonConnectionException} avec {@see DaemonConnectionException::getStatusCode()}.
 * Pas de test unitaire mock ici : la suite Pest du module n’instancie pas Laravel (voir
 * {@see tests/TestCase.php}).
 */
final class PmcpRuntimeVersionProbe
{
    private const LOG_PATH = '/logs/latest.log';

    private const MAX_BYTES = 512_000;

    /**
     * @return array{mc_version: string, loader: string, source_line: string, source: string}
     *
     * @throws PmcpHttpException
     */
    public static function probe(Server $server): array
    {
        if (! class_exists(DaemonFileRepository::class)) {
            throw new PmcpHttpException(500, 'Classes Wings du panel introuvables (DaemonFileRepository).');
        }

        /** @var DaemonFileRepository $repo */
        $repo = app(DaemonFileRepository::class);

        try {
            $content = $repo->setServer($server)->getContent(self::LOG_PATH, self::MAX_BYTES);
        } catch (DaemonConnectionException $e) {
            throw self::mapDaemonConnectionException($e);
        } catch (Throwable $e) {
            if (self::isFileSizeTooLarge($e)) {
                throw new PmcpHttpException(
                    422,
                    "Le fichier `logs/latest.log` dépasse la taille maximale lisible (512 Ko).",
                );
            }

            throw new PmcpHttpException(500, 'Lecture du log de démarrage impossible.', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }

        if (! is_string($content) || $content === '') {
            throw new PmcpHttpException(404, "Le fichier `logs/latest.log` est vide ou inaccessible.");
        }

        $parsed = PmcpVersionLogParser::parse($content);
        if ($parsed === null) {
            throw new PmcpHttpException(422, "Aucun banner de démarrage Minecraft reconnu dans `logs/latest.log`.");
        }

        return [
            'mc_version' => $parsed['mc_version'],
            'loader' => $parsed['loader'],
            'source_line' => $parsed['source_line'],
            'source' => 'latest_log',
        ];
    }

    /**
     * Wings 404 (fichier absent) et erreurs réseau partagent {@see DaemonConnectionException}
     * sur Pterodactyl 1.11+ ; le code HTTP Wings est exposé via getStatusCode().
     */
    private static function mapDaemonConnectionException(DaemonConnectionException $e): PmcpHttpException
    {
        if (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 404) {
            return new PmcpHttpException(
                404,
                "Fichier `logs/latest.log` introuvable sur ce serveur (jamais démarré ou log purgé).",
            );
        }

        $detail = config('app.debug') ? $e->getMessage() : null;

        return new PmcpHttpException(502, 'Wings injoignable pour lire les logs du serveur.', ['detail' => $detail]);
    }

    private static function isFileSizeTooLarge(Throwable $e): bool
    {
        $class = 'Pterodactyl\\Exceptions\\Http\\Server\\FileSizeTooLargeException';

        return class_exists($class) && $e instanceof $class;
    }
}
