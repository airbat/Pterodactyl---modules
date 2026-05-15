<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Throwable;

/**
 * Probe runtime de la version Minecraft : lit `latest.log` sous `logs/` ou `Logs/`
 * (plusieurs chemins candidats, voir {@see CANDIDATE_LOG_PATHS}) via Wings et délègue
 * le parsing à {@see PmcpVersionLogParser}.
 *
 * Pas de fallback v1.0 (WS history, sortie commande `version`) — voir docs/superpowers/plans
 * /2026-05-15-runtime-mc-version-probe.md pour les choix de scope.
 *
 * Path codé en dur (liste fermée, pas d’input utilisateur) → pas de path traversal.
 *
 * Détection 404 : Pterodactyl 1.11+ enveloppe les réponses Wings (dont fichier absent) dans
 * {@see DaemonConnectionException} avec {@see DaemonConnectionException::getStatusCode()}.
 * Pas de test unitaire mock ici : la suite Pest du module n’instancie pas Laravel (voir
 * {@see tests/TestCase.php}).
 */
final class PmcpRuntimeVersionProbe
{
    /** @var list<string> Chemins relatifs racine serveur (Linux : sensible à la casse ; Bedrock = souvent Logs/). */
    private const CANDIDATE_LOG_PATHS = [
        '/logs/latest.log',
        '/Logs/latest.log',
        'logs/latest.log',
        'Logs/latest.log',
    ];

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
        $repo = app(DaemonFileRepository::class)->setServer($server);

        $content = null;
        $lastDaemonEx = null;

        foreach (self::CANDIDATE_LOG_PATHS as $path) {
            try {
                $candidate = $repo->getContent($path, self::MAX_BYTES);
            } catch (DaemonConnectionException $e) {
                $lastDaemonEx = $e;
                if (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 404) {
                    continue;
                }
                throw self::mapDaemonConnectionException($e);
            } catch (Throwable $e) {
                if (self::isFileSizeTooLarge($e)) {
                    throw new PmcpHttpException(
                        422,
                        "Le fichier de log dépasse la taille maximale lisible (512 Ko).",
                    );
                }

                throw new PmcpHttpException(500, 'Lecture du log de démarrage impossible.', [
                    'detail' => config('app.debug') ? $e->getMessage() : null,
                ]);
            }

            if (is_string($candidate) && $candidate !== '') {
                $content = $candidate;
                break;
            }
        }

        if ($content === null) {
            if ($lastDaemonEx !== null && method_exists($lastDaemonEx, 'getStatusCode') && $lastDaemonEx->getStatusCode() === 404) {
                throw new PmcpHttpException(
                    404,
                    'Aucun fichier de log trouvé (`logs/latest.log` ni `Logs/latest.log`). Démarrez le serveur au moins une fois.',
                    ['tried_paths' => self::CANDIDATE_LOG_PATHS],
                );
            }

            throw new PmcpHttpException(404, "Les fichiers de log candidats sont vides ou introuvables.", [
                'tried_paths' => self::CANDIDATE_LOG_PATHS,
            ]);
        }

        $parsed = PmcpVersionLogParser::parse($content);
        if ($parsed === null) {
            throw new PmcpHttpException(
                422,
                'Aucun banner de démarrage Minecraft reconnu dans le fichier de log lu (aperçu sans les motifs Paper / Bedrock / etc.).',
            );
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
        $code = 0;
        if (method_exists($e, 'getStatusCode')) {
            $code = (int) $e->getStatusCode();
        }

        $detail = config('app.debug') ? $e->getMessage() : null;
        $extra = array_merge(
            $detail !== null ? ['detail' => $detail] : [],
            $code > 0 ? ['wings_status' => $code] : [],
        );

        if ($code === 404) {
            return new PmcpHttpException(
                404,
                "Fichier de log introuvable sur ce serveur (chemin relatif inexistant côté Wings).",
                $extra,
            );
        }

        if ($code === 403) {
            return new PmcpHttpException(
                403,
                'Wings a refusé la lecture du fichier de log (permissions daemon ou politique du nœud).',
                $extra,
            );
        }

        if ($code >= 400 && $code < 500) {
            return new PmcpHttpException(
                $code,
                'La requête vers Wings a échoué lors de la lecture du log.',
                $extra,
            );
        }

        if ($code >= 500) {
            return new PmcpHttpException(
                502,
                'Wings a renvoyé une erreur serveur lors de la lecture du log.',
                $extra,
            );
        }

        return new PmcpHttpException(502, 'Wings injoignable pour lire les logs du serveur.', $extra);
    }

    private static function isFileSizeTooLarge(Throwable $e): bool
    {
        $class = 'Pterodactyl\\Exceptions\\Http\\Server\\FileSizeTooLargeException';

        return class_exists($class) && $e instanceof $class;
    }
}
