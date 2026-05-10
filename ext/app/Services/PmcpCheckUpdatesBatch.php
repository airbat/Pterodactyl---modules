<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/**
 * Vérifie les mises à jour possibles pour une série d’artefacts (Modrinth / CurseForge).
 *
 * @param  array<string, array{pinned_version_id: string, pinned_version_label: string|null}>  $pinMap
 * @param  list<array{provider?: string, project_id?: string, version_id?: string}>  $entries
 * @param  array<string, mixed> $c Dependencies : modrinthGet, modrinthLatestFromVersionList,
 * curseForgeApiKey, curseForgeGet, validProjectId, validCurseForgeModId, curseForgeAuthFailureMessage, pmcpTruncatePlain
 * @return list<array<string, mixed>>
 */
final class PmcpCheckUpdatesBatch
{
    /** @param  array<string, mixed>  $c */
    public static function run(array $pinMap, array $entries, array $c): array
    {
        /** @var callable $modrinthGet */
        $modrinthGet = $c['modrinthGet'];
        /** @var callable $modrinthLatestFromVersionList */
        $modrinthLatestFromVersionList = $c['modrinthLatestFromVersionList'];
        /** @var callable $curseForgeApiKey */
        $curseForgeApiKey = $c['curseForgeApiKey'];
        /** @var callable $curseForgeGet */
        $curseForgeGet = $c['curseForgeGet'];
        /** @var callable(string):bool $validProjectId */
        $validProjectId = $c['validProjectId'];
        /** @var callable(string):bool $validCurseForgeModId */
        $validCurseForgeModId = $c['validCurseForgeModId'];
        /** @var callable(\Illuminate\Http\Client\Response):(?string) $curseForgeAuthFailureMessage */
        $curseForgeAuthFailureMessage = $c['curseForgeAuthFailureMessage'];
        /** @var callable(string, int):(string) $pmcpTruncatePlain */
        $pmcpTruncatePlain = $c['pmcpTruncatePlain'];

        $results = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $prov = (string) ($entry['provider'] ?? '');
            $pid = (string) ($entry['project_id'] ?? '');
            $vid = (string) ($entry['version_id'] ?? '');
            $pinKey = $prov . ':' . $pid;
            $pin = $pinMap[$pinKey] ?? null;

            $row = [
                'provider' => $prov,
                'project_id' => $pid,
                'current_version_id' => $vid,
                'latest_version_id' => null,
                'latest_version_label' => null,
                'latest_changelog' => null,
                'update_available' => false,
                'pin' => $pin,
                'error' => null,
            ];

            try {
                if ($prov === 'modrinth') {
                    if (! $validProjectId($pid)) {
                        $row['error'] = 'Identifiant projet Modrinth invalide.';
                        $results[] = $row;
                        continue;
                    }

                    $vr = $modrinthGet('/project/' . rawurlencode($pid) . '/version', ['limit' => 50]);
                    if (! $vr->successful()) {
                        $row['error'] = 'Modrinth indisponible ou projet introuvable.';
                        $results[] = $row;
                        continue;
                    }

                    $latest = $modrinthLatestFromVersionList($vr->json());
                    if (! is_array($latest) || empty($latest['id'])) {
                        $results[] = $row;
                        continue;
                    }

                    $lid = (string) $latest['id'];
                    $row['latest_version_id'] = $lid;
                    $row['latest_version_label'] = isset($latest['version_number'])
                        ? (string) $latest['version_number']
                        : (isset($latest['name']) ? (string) $latest['name'] : null);
                    $row['update_available'] = $lid !== $vid;

                    if ($pin !== null && $lid !== '' && isset($pin['pinned_version_id']) && $lid !== $pin['pinned_version_id']) {
                        $row['pinned_differs_from_latest'] = true;
                    }

                    $chg = isset($latest['changelog']) && is_string($latest['changelog'])
                        ? $pmcpTruncatePlain($latest['changelog'], 680)
                        : '';
                    if ($chg !== '') {
                        $row['latest_changelog'] = $chg;
                    }
                } else {
                    if ($curseForgeApiKey() === null) {
                        $row['error'] = 'CurseForge : clé API absente.';
                        $results[] = $row;
                        continue;
                    }
                    if (! $validCurseForgeModId($pid)) {
                        $row['error'] = 'Identifiant mod CurseForge invalide.';
                        $results[] = $row;
                        continue;
                    }

                    $fr = $curseForgeGet('/mods/' . $pid . '/files', ['pageSize' => 50, 'index' => 0]);
                    if ($fr === false) {
                        $row['error'] = 'CurseForge : clé API absente.';
                        $results[] = $row;
                        continue;
                    }

                    $authMsgFile = $curseForgeAuthFailureMessage($fr);
                    if ($authMsgFile !== null) {
                        $row['error'] = $authMsgFile;
                        $results[] = $row;
                        continue;
                    }

                    if (! $fr->successful()) {
                        $row['error'] = 'CurseForge indisponible ou mod introuvable.';
                        $results[] = $row;
                        continue;
                    }

                    $decoded = $fr->json();
                    $files = [];
                    if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                        $files = $decoded['data'];
                    }

                    $best = null;
                    $bestDt = '';
                    foreach ($files as $f) {
                        if (! is_array($f) || empty($f['id'])) {
                            continue;
                        }
                        $dt = isset($f['fileDate']) ? (string) $f['fileDate'] : '';
                        if ($best === null || ($dt !== '' && strcmp($dt, $bestDt) > 0)) {
                            $best = $f;
                            $bestDt = $dt;
                        }
                    }

                    if (! is_array($best)) {
                        $results[] = $row;
                        continue;
                    }

                    $lid = isset($best['id']) ? (string) $best['id'] : '';
                    $row['latest_version_id'] = $lid;
                    $label = '';
                    if (isset($best['displayName']) && is_string($best['displayName'])) {
                        $label = $best['displayName'];
                    } elseif (isset($best['fileName']) && is_string($best['fileName'])) {
                        $label = $best['fileName'];
                    }
                    $row['latest_version_label'] = $label !== '' ? $label : null;
                    $row['update_available'] = $lid !== '' && $lid !== $vid;

                    if ($pin !== null && $lid !== '' && isset($pin['pinned_version_id']) && $lid !== $pin['pinned_version_id']) {
                        $row['pinned_differs_from_latest'] = true;
                    }

                    $chCf = isset($best['changelogHtml']) && is_string($best['changelogHtml'])
                        ? $pmcpTruncatePlain(strip_tags($best['changelogHtml']), 680)
                        : '';
                    if ($chCf !== '') {
                        $row['latest_changelog'] = $chCf;
                    }
                }
            } catch (\Throwable $e) {
                $row['error'] = config('app.debug') ? $e->getMessage() : 'Erreur réseau ou parsing.';
            }

            $results[] = $row;
        }

        return $results;
    }
}
