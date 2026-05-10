<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/**
 * Ordonne les couples (project_id, version_id) pour installer d’abord les dépendances Modrinth « required »,
 * puis l’artefact racine (ordre postfix / profondeur d’abord).
 */
final class PmcpModrinthRequiredDepsPlanner
{
    private const MAX_DEPTH = 14;

    private const MAX_ROWS_SCAN = 100;

    /** @var array<string,true> */
    private array $visiting = [];

    /** @var array<string,true> */
    private array $finished = [];

    /** @var list<array{0:string,1:string}> */
    private array $order = [];

    /**
     * @param  callable(string, array<string,mixed>): \Illuminate\Http\Client\Response  $modrinthGet
     * @param  callable(string):bool  $validProjectId
     * @param  callable(mixed): (?array<string,mixed>) $modrinthLatestFromVersionList
     * @return list<array{0:string,1:string}>
     */
    public static function buildOrderedPairs(
        string $rootProjectId,
        string $rootVersionId,
        callable $modrinthGet,
        callable $validProjectId,
        callable $modrinthLatestFromVersionList,
        callable $installBlockedByPolicy,
    ): array {
        $p = new self();
        $p->dfs(
            $rootProjectId,
            $rootVersionId,
            $modrinthGet,
            $validProjectId,
            $modrinthLatestFromVersionList,
            $installBlockedByPolicy,
            0
        );

        return $p->order;
    }

    /**
     * @param  callable(string, array<string,mixed>): \Illuminate\Http\Client\Response  $modrinthGet
     * @param  callable(string):bool  $validProjectId
     * @param  callable(mixed): (?array<string,mixed>) $modrinthLatestFromVersionList
     */
    private function dfs(
        string $projectId,
        string $versionId,
        callable $modrinthGet,
        callable $validProjectId,
        callable $modrinthLatestFromVersionList,
        callable $installBlockedByPolicy,
        int $depth,
    ): void {
        if ($depth > self::MAX_DEPTH) {
            throw new PmcpHttpException(422, 'Chaîne de dépendances Modrinth trop profonde.');
        }

        if (! $validProjectId($projectId)) {
            throw new PmcpHttpException(422, 'Identifiant projet Modrinth invalide dans une dépendance.');
        }

        $key = $projectId . ':' . $versionId;
        if (isset($this->finished[$key])) {
            return;
        }
        if (isset($this->visiting[$key])) {
            return;
        }

        $version = self::fetchVersionArray($modrinthGet, $versionId);

        $vpid = isset($version['project_id']) ? (string) $version['project_id'] : '';
        if ($vpid !== $projectId) {
            throw new PmcpHttpException(
                422,
                'Dépendance Modrinth : incohérence entre project_id déclaré et version « ' . $versionId . ' ».'
            );
        }

        $this->visiting[$key] = true;

        $anchorGames = is_array($version['game_versions'] ?? null)
            ? array_values(array_filter(array_map('strval', $version['game_versions'])))
            : [];
        $anchorLoaders = is_array($version['loaders'] ?? null)
            ? array_values(array_filter(array_map('strtolower', array_map('strval', $version['loaders']))))
            : [];

        foreach (is_array($version['dependencies'] ?? null) ? $version['dependencies'] : [] as $rawDep) {
            if (! is_array($rawDep)) {
                continue;
            }
            $dtype = strtolower(trim((string) ($rawDep['dependency_type'] ?? '')));
            if ($dtype !== 'required') {
                continue;
            }

            [$depPid, $depVid] = self::resolveDepEndpoints($modrinthGet, $rawDep);

            if ($depPid === null || ! $validProjectId($depPid)) {
                continue;
            }
            if ($depVid === '') {
                $chosen = self::pickLatestCompatibleRow(
                    $modrinthGet,
                    $depPid,
                    $anchorGames,
                    $anchorLoaders,
                    $modrinthLatestFromVersionList
                );
                if ($chosen === null) {
                    throw new PmcpHttpException(
                        422,
                        'Dépendance requise « ' . $depPid . ' » sans version compatible avec les loaders / Minecraft de l’artefact parent.'
                    );
                }
                $depVid = (string) $chosen['id'];
            }

            if ($installBlockedByPolicy('modrinth', $depPid)) {
                throw new PmcpHttpException(
                    403,
                    'Dépendance requise bloquée par la politique du panneau (PMCP_BLOCKLIST_PROJECT_IDS).'
                );
            }

            $this->dfs(
                $depPid,
                $depVid,
                $modrinthGet,
                $validProjectId,
                $modrinthLatestFromVersionList,
                $installBlockedByPolicy,
                $depth + 1
            );
        }

        unset($this->visiting[$key]);
        $this->finished[$key] = true;
        $this->order[] = [$projectId, $versionId];
    }

    /**
     * @param  callable(string, array<string,mixed>): \Illuminate\Http\Client\Response  $modrinthGet
     * @return array<string,mixed>
     */
    private static function fetchVersionArray(callable $modrinthGet, string $versionId): array
    {
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

        $decoded = $vr->json();
        if (! is_array($decoded)) {
            throw new PmcpHttpException(502, 'Réponse Modrinth invalide (version).');
        }

        return $decoded;
    }

    /** @param  array<string,mixed>  $rawDep */
    private static function resolveDepEndpoints(callable $modrinthGet, array $rawDep): array
    {
        $vid = isset($rawDep['version_id']) ? trim((string) $rawDep['version_id']) : '';
        $pid = isset($rawDep['project_id']) ? trim((string) $rawDep['project_id']) : '';

        if ($vid !== '') {
            $dv = self::fetchVersionArray($modrinthGet, $vid);
            $proj = isset($dv['project_id']) ? trim((string) $dv['project_id']) : '';
            if ($proj === '') {
                return [null, ''];
            }

            return [$proj, $vid];
        }

        if ($pid !== '') {
            return [$pid, ''];
        }

        return [null, ''];
    }

    /** @param  list<string>  $anchorGames
     * @param  list<string>  $anchorLoaders
     * @param  callable(mixed): (?array<string,mixed>) $modrinthLatestFromVersionList
     * @return ?array<string,mixed>
     */
    private static function pickLatestCompatibleRow(
        callable $modrinthGet,
        string $projectId,
        array $anchorGames,
        array $anchorLoaders,
        callable $modrinthLatestFromVersionList,
    ): ?array {
        try {
            $r = $modrinthGet('/project/' . rawurlencode($projectId) . '/version', [
                'limit' => self::MAX_ROWS_SCAN,
            ]);
        } catch (\Throwable $e) {
            throw new PmcpHttpException(503, 'Réseau indisponible vers Modrinth (liste des versions).', [
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }

        if (! $r->successful()) {
            throw new PmcpHttpException(502, 'Modrinth liste des versions : erreur HTTP.', ['status' => $r->status()]);
        }

        $list = $r->json();
        if (! is_array($list)) {
            return null;
        }

        $eligible = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $gid = isset($row['id']) ? (string) $row['id'] : '';
            if ($gid === '') {
                continue;
            }

            $gVers = is_array($row['game_versions'] ?? null)
                ? array_map('strval', $row['game_versions'])
                : [];
            $loadersRow = is_array($row['loaders'] ?? null)
                ? array_map('strtolower', array_map('strval', $row['loaders']))
                : [];

            if ($anchorGames !== [] && ! self::listIntersectsString($anchorGames, $gVers)) {
                continue;
            }
            if ($anchorLoaders !== [] && ! self::listIntersectsString($anchorLoaders, $loadersRow)) {
                continue;
            }

            $eligible[] = $row;
        }

        if ($eligible === []) {
            $eligible = [];
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rid = isset($row['id']) ? trim((string) $row['id']) : '';
                if ($rid === '') {
                    continue;
                }
                $eligible[] = $row;
            }
        }

        if ($eligible === []) {
            return null;
        }

        return $modrinthLatestFromVersionList($eligible);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function listIntersectsString(array $a, array $b): bool
    {
        $normA = array_flip(array_map('strtolower', $a));
        foreach ($b as $x) {
            if (isset($normA[strtolower((string) $x)])) {
                return true;
            }
        }

        return false;
    }
}
