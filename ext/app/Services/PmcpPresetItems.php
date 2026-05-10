<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

final class PmcpPresetItems
{
    /** @var int */
    private const MAX = 80;

    /**
     * Parse et normalise une liste JSON d’artéfacts présents.
     *
     * @return list<array{provider: string, project_id: string, version_id: string, directory: string|null}>
     */
    public static function coerce(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new PmcpHttpException(422, 'Le champ « items » doit être un tableau.');
        }

        if (count($raw) === 0) {
            throw new PmcpHttpException(422, 'Au moins un artefact dans le preset.');
        }

        if (count($raw) > self::MAX) {
            throw new PmcpHttpException(422, 'Trop d’éléments preset (maximum ' . self::MAX . ').');
        }

        $out = [];
        foreach ($raw as $idx => $it) {
            if (! is_array($it)) {
                throw new PmcpHttpException(422, 'Item #' . ((int) $idx + 1) . ' : objet attendu.');
            }
            $p = isset($it['provider']) ? trim((string) $it['provider']) : '';
            if ($p !== 'modrinth' && $p !== 'curseforge') {
                throw new PmcpHttpException(422, 'Item #' . ((int) $idx + 1) . ' : provider invalide.');
            }
            $pid = isset($it['project_id']) ? trim((string) $it['project_id']) : '';
            if ($pid === '' || strlen($pid) > 128) {
                throw new PmcpHttpException(422, 'Item #' . ((int) $idx + 1) . ' : project_id invalide.');
            }
            $vid = isset($it['version_id']) ? trim((string) $it['version_id']) : '';
            if ($vid === '' || strlen($vid) > 128) {
                throw new PmcpHttpException(422, 'Item #' . ((int) $idx + 1) . ' : version_id invalide.');
            }
            if ($p === 'curseforge') {
                if (! ctype_digit($pid) || ! ctype_digit($vid)) {
                    throw new PmcpHttpException(422, 'Item #' . ((int) $idx + 1) . ' : identifiants CurseForge numériques attendus.');
                }
            } else {
                if (! preg_match('/^[A-Za-z0-9_-]+$/', $vid)) {
                    throw new PmcpHttpException(422, 'Item #' . ((int) $idx + 1) . ' : version_id Modrinth invalide.');
                }
            }
            $dir = null;
            if (isset($it['directory']) && $it['directory'] !== null && is_string($it['directory'])) {
                $d = trim($it['directory']);
                $dir = $d !== '' ? $d : null;
            }

            $out[] = ['provider' => $p, 'project_id' => $pid, 'version_id' => $vid, 'directory' => $dir];
        }

        return $out;
    }
}
