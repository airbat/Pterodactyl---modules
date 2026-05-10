import React, { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Page dashboard client — voir ext/dashboard/components/Components.yml
 * `{identifier}` remplacé par Blueprint à l’installation.
 */

const EXT_BASE = `/api/client/extensions/{identifier}`;
const IDENT = `{identifier}`;
const VERS_PAGE = 12;

type CatalogProvider = 'modrinth' | 'curseforge';

type CurseForgeStatusPayload = {
    provider?: string;
    configured?: boolean;
};

type HealthPayload = {
    status?: string;
    extension?: string;
    version?: string;
    blueprint_engine?: string;
    blueprint_matches_target?: string;
    blueprint_target?: string;
};

type CatalogHit = {
    provider: string;
    external_id: string;
    slug: string;
    title: string;
    summary: string;
    project_type: string;
    icon_url?: string | null;
    downloads?: number | null;
    page_url?: string | null;
};

type CatalogResponse = {
    provider: string;
    total: number;
    limit: number;
    offset: number;
    items: CatalogHit[];
};

type ModrinthProjectDetail = {
    id: string;
    slug: string;
    title: string;
    description: string;
    body: string;
    project_type: string;
    icon_url?: string | null;
    downloads?: number | null;
    followers?: number | null;
    license?: string | object | null;
    client_side?: string | null;
    server_side?: string | null;
    source_url?: string | null;
    issues_url?: string | null;
    wiki_url?: string | null;
    discord_url?: string | null;
    page_url?: string | null;
};

type ModrinthVersionRow = {
    id: string;
    name: string;
    version_number: string;
    date_published: string | null;
    version_type: string | null;
    downloads: number;
    loaders: string[];
    game_versions: string[];
    changelog: string | null;
    primary_file: { filename: string; size: number; sha512: string | null } | null;
    dependencies: { project_id: string | null; dependency_type: string | null }[];
};

type ProjectApiResponse = {
    provider: string;
    project: ModrinthProjectDetail;
};

type VersionsApiResponse = {
    provider: string;
    project_id: string;
    limit: number;
    offset: number;
    versions: ModrinthVersionRow[];
};

type InstallModrinthResponse = {
    message: string;
    directory: string;
    filename: string | null;
    loaders: string[];
    restart_recommended: boolean;
    event_id?: number | null;
};

type InstallHistoryItem = {
    id: number;
    provider: string;
    project_id: string;
    version_id: string;
    directory: string;
    filename: string | null;
    version_label: string | null;
    created_at: string | null;
};

type InstallHistoryApiResponse = {
    items: InstallHistoryItem[];
    migration_pending?: boolean;
};

type ServerContextPayload = {

    uuid_short?: string;

    uuid_full?: string;

    minecraft_versions_hint: string[];

    egg_variables?: Record<string, string>;

    egg_name?: string | null;
    nest_name?: string | null;
};

type PinApiItem = {
    id: number;
    provider: string;
    project_id: string;
    pinned_version_id: string;

    pinned_version_label: string | null;
    created_at: string | null;
};

type PinsApiResponse = {
    items: PinApiItem[];
    migration_pending?: boolean;

};

type ScheduleApiPayload = {
    scheduled_enabled: boolean;
    backup_before_update: boolean;
    cron_expression: string;
    max_updates_per_run: number;
    last_preview_at?: string | null;
    updated_at?: string | null;
    migration_pending?: boolean;
    message?: string;
};

type UpdateCheckItem = {
    provider: string;

    project_id: string;
    current_version_id: string;
    latest_version_id: string | null;
    latest_version_label: string | null;
    /** Extrait API (Modrinth changelog / CurseForge changelogHtml), tronqué côté panel */
    latest_changelog?: string | null;
    update_available: boolean;
    pinned_differs_from_latest?: boolean;
    pin: {
        pinned_version_id: string;
        pinned_version_label: string | null;
    } | null;
    error: string | null;
};


type UpdatesCheckApiResponse = {
    items: UpdateCheckItem[];
};

const box: React.CSSProperties = {
    padding: '1rem',
    maxWidth: '960px',
    marginBottom: '1rem',
};

const preSmall: React.CSSProperties = {
    marginTop: '0.35rem',
    overflow: 'auto',
    fontSize: '11px',
    borderRadius: '4px',
    background: 'rgba(0,0,0,0.45)',
    padding: '8px',
    fontFamily: 'ui-monospace, Consolas, monospace',
    color: '#a7f3d0',
};

const inputBar: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    gap: '8px',
    alignItems: 'center',
    marginBottom: '12px',
};

const input: React.CSSProperties = {
    flex: '1 1 200px',
    minWidth: '160px',
    padding: '8px 10px',
    borderRadius: '4px',
    border: '1px solid rgba(128,128,128,0.35)',
    background: 'rgba(0,0,0,0.2)',
    color: 'inherit',
};

function serverFromPath(pathname: string): string | null {
    const m = pathname.match(/^\/server\/([^/]+)/);
    return m ? m[1] : null;
}

function fmtBytes(n: number): string {
    if (n < 1024) return `${n} o`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} Kio`;
    return `${(n / (1024 * 1024)).toFixed(1)} Mio`;
}

/** Alignement version MC déclarée par l’artefact vs filtre catalogue / hints œuf */
type McCompatLevel = 'ok' | 'warn' | 'neutral';

function mcVersionCompatible(a: string, b: string): boolean {
    const x = a.trim().toLowerCase();
    const y = b.trim().toLowerCase();
    if (x === y) return true;
    if (x.startsWith(y + '.') || y.startsWith(x + '.')) return true;
    return false;
}

function mcCompatForVersion(
    gameVersions: string[],
    mcFilter: string,
    serverHints: string[]
): McCompatLevel {
    const gv = (gameVersions ?? []).map((s) => s.trim()).filter(Boolean);
    if (gv.length === 0) return 'neutral';

    const filt = mcFilter.trim();
    if (filt !== '') {
        return gv.some((g) => mcVersionCompatible(g, filt)) ? 'ok' : 'warn';
    }

    const hints = (serverHints ?? []).map((s) => s.trim()).filter(Boolean);
    if (hints.length > 0) {
        return hints.some((h) => gv.some((g) => mcVersionCompatible(g, h))) ? 'ok' : 'warn';
    }

    return 'neutral';
}

function csrfHeaders(): Record<string, string> {
    const out: Record<string, string> = {};

    /* Comportement Axios / SPA Laravel : préférer le cookie « XSRF-TOKEN » + en-tête X-XSRF-TOKEN pour éviter
     * divergence avec une balise meta obsolète. Sinon repli meta csrf-token. */
    const m =
        typeof document !== 'undefined' ? document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) : null;

    if (m?.[1]) {
        try {
            out['X-XSRF-TOKEN'] = decodeURIComponent(m[1]);
        } catch {
            out['X-XSRF-TOKEN'] = m[1];
        }

        return out;
    }

    const meta =
        typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]') : null;

    const token = meta instanceof HTMLMetaElement ? meta.content : '';

    if (token) {
        out['X-CSRF-TOKEN'] = token;
    }

    return out;
}

async function ensureSanctumCsrfCookie(): Promise<void> {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        await fetch(`${window.location.origin}/sanctum/csrf-cookie`, {
            method: 'GET',
            credentials: 'same-origin',

            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        });
    } catch {
        /* Route ou Sanctum absent : ignoré. */
    }
}

async function fetchJson<T>(url: string, init?: RequestInit): Promise<T> {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(init?.headers || {}),
        },
        ...init,
    });
    if (!res.ok) {
        const text = await res.text().catch(() => '');
        let msg = text.slice(0, 200);
        try {
            const j = JSON.parse(text) as { message?: string };
            if (j?.message) msg = j.message;
        } catch {
            /* ignore */
        }
        throw new Error(`HTTP ${res.status}${msg ? ` — ${msg}` : ''}`);
    }
    return res.json() as Promise<T>;
}

async function postJson<T>(url: string, body: Record<string, unknown>): Promise<T> {
    await ensureSanctumCsrfCookie();

    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        body: JSON.stringify(body),
    });
    const text = await res.text().catch(() => '');
    let parsed: unknown = null;
    if (text) {
        try {
            parsed = JSON.parse(text) as unknown;
        } catch {
            parsed = null;
        }
    }
    if (!res.ok) {
        const message =
            typeof parsed === 'object' && parsed !== null && 'message' in parsed
                ? String((parsed as { message?: string }).message)
                : text.slice(0, 200) || `HTTP ${res.status}`;
        throw new Error(message);
    }
    return (parsed ?? {}) as T;
}

function pinLookupKey(provider: string, projectId: string): string {
    return `${provider}:${projectId}`;
}

function dedupeHistoryNewestFirst(items: InstallHistoryItem[]): InstallHistoryItem[] {
    const seen = new Set<string>();
    const out: InstallHistoryItem[] = [];

    for (const it of items) {
        const k = pinLookupKey(it.provider, it.project_id);
        if (seen.has(k)) continue;
        seen.add(k);
        out.push(it);

    }

    return out;

}

async function jsonDelete(url: string): Promise<void> {
    await ensureSanctumCsrfCookie();

    const res = await fetch(url, {
        method: 'DELETE',

        credentials: 'same-origin',

        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
    });

    const text = await res.text().catch(() => '');
    if (!res.ok) {
        let msg = text.slice(0, 240);
        try {

            const j = JSON.parse(text) as { message?: string };

            if (j?.message) msg = j.message;

        } catch {

            /* ignore */

        }

        throw new Error(msg || `HTTP ${res.status}`);
    }

}

export default function McPluginsDashboard(): React.ReactElement {
    const serverId = useMemo(() => serverFromPath(typeof window !== 'undefined' ? window.location.pathname : ''), []);

    const [health, setHealth] = useState<HealthPayload | null>(null);
    const [healthErr, setHealthErr] = useState<string | null>(null);

    const [curseForgeConfigured, setCurseForgeConfigured] = useState<boolean | null>(null);
    const [catalogProvider, setCatalogProvider] = useState<CatalogProvider>('modrinth');

    const [q, setQ] = useState('');
    const [limit] = useState(12);
    const [catalog, setCatalog] = useState<CatalogResponse | null>(null);
    const [searchLoading, setSearchLoading] = useState(false);
    const [searchErr, setSearchErr] = useState<string | null>(null);

    const [selectedProjectId, setSelectedProjectId] = useState<string | null>(null);
    const [detailProject, setDetailProject] = useState<ModrinthProjectDetail | null>(null);
    const [detailVersions, setDetailVersions] = useState<ModrinthVersionRow[]>([]);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailErr, setDetailErr] = useState<string | null>(null);
    const [versionsLoadingMore, setVersionsLoadingMore] = useState(false);
    const [versionsExhausted, setVersionsExhausted] = useState(false);

    const [installingVersionId, setInstallingVersionId] = useState<string | null>(null);
    const [installOk, setInstallOk] = useState<string | null>(null);
    const [installErr, setInstallErr] = useState<string | null>(null);

    const [installHistory, setInstallHistory] = useState<InstallHistoryItem[]>([]);
    const [installHistoryLoading, setInstallHistoryLoading] = useState(false);
    const [installHistoryErr, setInstallHistoryErr] = useState<string | null>(null);
    const [installHistoryMigrationPending, setInstallHistoryMigrationPending] = useState(false);

    const [minecraftVersionFilter, setMinecraftVersionFilter] = useState('');
    const [serverCtx, setServerCtx] = useState<ServerContextPayload | null>(null);
    const [ctxErr, setCtxErr] = useState<string | null>(null);

    const [pinsByKey, setPinsByKey] = useState<Record<string, PinApiItem>>({});
    const [pinsMigrationPending, setPinsMigrationPending] = useState(false);
    const [pinsErr, setPinsErr] = useState<string | null>(null);

    const [updateStatusByKey, setUpdateStatusByKey] = useState<Record<string, UpdateCheckItem>>({});
    const [updateCheckBusy, setUpdateCheckBusy] = useState(false);
    const [updatesErr, setUpdatesErr] = useState<string | null>(null);
    /** Ligne historique dont la mise à jour rapide (« Installer MAJ ») est en cours */
    const [historyQuickUpdateRowId, setHistoryQuickUpdateRowId] = useState<number | null>(null);
    const [scheduleCfg, setScheduleCfg] = useState<ScheduleApiPayload | null>(null);
    const [scheduleErr, setScheduleErr] = useState<string | null>(null);
    const [scheduleSaveBusy, setScheduleSaveBusy] = useState(false);
    const [scheduleSaveOk, setScheduleSaveOk] = useState<string | null>(null);

    const pinnedForSelectedProject = useMemo((): PinApiItem | undefined => {
        if (!selectedProjectId) return undefined;
        return pinsByKey[pinLookupKey(catalogProvider, selectedProjectId)];
    }, [pinsByKey, catalogProvider, selectedProjectId]);

    const loadPins = useCallback(async (): Promise<void> => {
        if (!serverId) return;
        setPinsErr(null);
        try {

            const d = await fetchJson<PinsApiResponse>(`${EXT_BASE}/pins?${new URLSearchParams({ server: serverId }).toString()}`);
            setPinsMigrationPending(Boolean(d.migration_pending));
            const m: Record<string, PinApiItem> = {};

            for (const p of d.items ?? []) {
                m[pinLookupKey(p.provider, p.project_id)] = p;
            }
            setPinsByKey(m);
        } catch (e: unknown) {
            setPinsErr(e instanceof Error ? e.message : 'Pins indisponibles');
            setPinsByKey({});
        }
    }, [serverId]);

    const runUpdateCheck = useCallback(
        async (items: InstallHistoryItem[]): Promise<void> => {

            if (!serverId || items.length === 0) {
                setUpdateStatusByKey({});
                return;
            }

            const slice = dedupeHistoryNewestFirst(items).slice(0, 25);
            setUpdateCheckBusy(true);
            setUpdatesErr(null);

            try {
                const d = await postJson<UpdatesCheckApiResponse>(`${EXT_BASE}/install/check-updates`, {
                    server: serverId,
                    entries: slice.map((h) => ({
                        provider: h.provider,
                        project_id: h.project_id,
                        version_id: h.version_id,
                    })),
                });
                const m: Record<string, UpdateCheckItem> = {};

                for (const row of d.items ?? []) {
                    m[pinLookupKey(row.provider, row.project_id)] = row;
                }
                setUpdateStatusByKey(m);
            } catch (e: unknown) {
                setUpdatesErr(e instanceof Error ? e.message : 'Vérification MAJ impossible');
                setUpdateStatusByKey({});
            } finally {

                setUpdateCheckBusy(false);
            }
        },
        [serverId]
    );

    const loadInstallHistory = useCallback(async () => {
        if (!serverId) return;
        setInstallHistoryLoading(true);
        setInstallHistoryErr(null);
        const params = new URLSearchParams({ server: serverId, limit: '25' });

        try {
            const data = await fetchJson<InstallHistoryApiResponse>(`${EXT_BASE}/install/history?${params.toString()}`);
            setInstallHistory(data.items);
            setInstallHistoryMigrationPending(Boolean(data.migration_pending));
            await loadPins();
            await runUpdateCheck(data.items);
        } catch (e: unknown) {
            setInstallHistoryErr(e instanceof Error ? e.message : 'Erreur historique');
            setInstallHistory([]);
            setUpdateStatusByKey({});
        } finally {

            setInstallHistoryLoading(false);
        }
    }, [serverId, loadPins, runUpdateCheck]);

    const applyHistoryLatestUpdate = useCallback(
        async (h: InstallHistoryItem): Promise<void> => {
            if (!serverId) return;
            const rk = pinLookupKey(h.provider, h.project_id);
            const up = updateStatusByKey[rk];
            const lid = up?.latest_version_id;

            if (!lid || !up?.update_available || up.error) return;

            setHistoryQuickUpdateRowId(h.id);

            try {
                if (h.provider === 'modrinth') {
                    await postJson(`${EXT_BASE}/install/modrinth`, {
                        server: serverId,
                        project_id: h.project_id,
                        version_id: lid,
                        directory: h.directory,
                    });
                } else if (h.provider === 'curseforge') {
                    const mid = Number.parseInt(h.project_id, 10);
                    const fid = Number.parseInt(lid, 10);
                    if (Number.isNaN(mid) || Number.isNaN(fid)) {
                        alert('Identifiants CurseForge invalides pour cette mise à jour.');
                        return;
                    }
                    await postJson(`${EXT_BASE}/install/curseforge`, {
                        server: serverId,
                        mod_id: mid,
                        file_id: fid,
                        directory: h.directory,
                    });
                } else {
                    return;
                }

                await loadInstallHistory();
            } catch (e: unknown) {
                alert(e instanceof Error ? e.message : 'Échec de la mise à jour');
            } finally {

                setHistoryQuickUpdateRowId(null);

            }

        },

        [serverId, updateStatusByKey, loadInstallHistory]

    );

    const saveScheduleConfig = useCallback(async (): Promise<void> => {
        if (!serverId || !scheduleCfg) return;
        setScheduleSaveBusy(true);
        setScheduleSaveOk(null);
        setScheduleErr(null);
        try {
            const out = await postJson<ScheduleApiPayload>(`${EXT_BASE}/schedule`, {
                server: serverId,
                scheduled_enabled: Boolean(scheduleCfg.scheduled_enabled),
                backup_before_update: Boolean(scheduleCfg.backup_before_update),
                cron_expression: (scheduleCfg.cron_expression || '').trim(),
                max_updates_per_run: Number(scheduleCfg.max_updates_per_run || 5),
            });
            setScheduleCfg((prev) => ({
                ...(prev ?? out),
                ...out,
                migration_pending: false,
            }));
            setScheduleSaveOk(out.message || 'Planification enregistrée.');
        } catch (e: unknown) {
            setScheduleErr(e instanceof Error ? e.message : 'Échec enregistrement planification');
        } finally {
            setScheduleSaveBusy(false);
        }
    }, [serverId, scheduleCfg]);

    useEffect(() => {
        let cancel = false;
        if (!serverId) {

            setInstallHistory([]);
            setInstallHistoryMigrationPending(false);
            setPinsByKey({});
            setPinsMigrationPending(false);

            setPinsErr(null);
            setUpdateStatusByKey({});

            setUpdatesErr(null);
            setCtxErr(null);
            setServerCtx(null);

            setMinecraftVersionFilter('');
            setHistoryQuickUpdateRowId(null);
            setScheduleCfg(null);
            setScheduleErr(null);
            setScheduleSaveOk(null);
            return undefined;
        }
        loadInstallHistory().catch(() => {
            if (!cancel) {

                /* state déjà géré dans loadInstallHistory */
            }

        });

        fetchJson<ServerContextPayload>(`${EXT_BASE}/server/context?${new URLSearchParams({ server: serverId }).toString()}`)
            .then((ctx) => {
                if (!cancel) {
                    setServerCtx({
                        ...ctx,
                        minecraft_versions_hint: Array.isArray(ctx.minecraft_versions_hint)
                            ? ctx.minecraft_versions_hint
                            : [],
                    });
                    setCtxErr(null);
                }
            })
            .catch((e: Error) => {
                if (!cancel) {
                    setCtxErr(e.message);
                    setServerCtx(null);
                }
            });

        fetchJson<ScheduleApiPayload>(`${EXT_BASE}/schedule?${new URLSearchParams({ server: serverId }).toString()}`)
            .then((cfg) => {
                if (!cancel) {
                    setScheduleCfg(cfg);
                    setScheduleErr(null);
                }
            })
            .catch((e: Error) => {
                if (!cancel) {
                    setScheduleCfg(null);
                    setScheduleErr(e.message);
                }
            });

        return () => {
            cancel = true;
        };
    }, [serverId, loadInstallHistory]);

    useEffect(() => {
        let cancel = false;
        fetchJson<HealthPayload>(`${EXT_BASE}/health`)
            .then((data) => {
                if (!cancel) setHealth(data);
            })
            .catch((e: Error) => {
                if (!cancel) setHealthErr(e.message);
            });
        return () => {
            cancel = true;
        };
    }, []);

    useEffect(() => {
        let cancel = false;
        fetchJson<CurseForgeStatusPayload>(`${EXT_BASE}/catalog/curseforge/status`)
            .then((data) => {
                if (!cancel) setCurseForgeConfigured(Boolean(data.configured));
            })
            .catch(() => {
                if (!cancel) setCurseForgeConfigured(false);
            });
        return () => {
            cancel = true;
        };
    }, []);

    const loadPage = useCallback(
        async (nextOffset: number, mode: 'replace' | 'append') => {
            setSearchLoading(true);
            setSearchErr(null);
            const params = new URLSearchParams({
                limit: String(limit),
                offset: String(nextOffset),
            });
            if (q.trim() !== '') params.set('q', q.trim());
            if (serverId) params.set('server', serverId);
            const mv = minecraftVersionFilter.trim();
            if (mv !== '') params.set('minecraft_version', mv);

            const searchUrl =
                catalogProvider === 'modrinth'
                    ? `${EXT_BASE}/catalog/search?${params.toString()}`
                    : `${EXT_BASE}/catalog/curseforge/search?${params.toString()}`;
            try {
                const data = await fetchJson<CatalogResponse>(searchUrl);
                setCatalog((prev) => {
                    if (mode === 'replace' || prev === null) return data;
                    return {
                        ...data,
                        items: [...prev.items, ...data.items],
                    };
                });
            } catch (e: unknown) {
                setSearchErr(e instanceof Error ? e.message : 'Erreur inconnue');
            } finally {
                setSearchLoading(false);
            }
        },
        [catalogProvider, limit, minecraftVersionFilter, q, serverId]
    );

    const openProjectDetail = useCallback(
        async (projectId: string) => {
            setSelectedProjectId(projectId);
            setDetailLoading(true);
            setDetailErr(null);
            setDetailProject(null);
            setDetailVersions([]);
            setVersionsExhausted(false);
            setInstallOk(null);
            setInstallErr(null);
            const enc = encodeURIComponent(projectId);
            try {
                if (catalogProvider === 'modrinth') {
                    const [pRes, vRes] = await Promise.all([
                        fetchJson<ProjectApiResponse>(`${EXT_BASE}/catalog/modrinth/project/${enc}`),
                        fetchJson<VersionsApiResponse>(
                            `${EXT_BASE}/catalog/modrinth/project/${enc}/versions?limit=${VERS_PAGE}&offset=0`
                        ),
                    ]);
                    setDetailProject(pRes.project);
                    setDetailVersions(vRes.versions);
                    setVersionsExhausted(vRes.versions.length < VERS_PAGE);
                } else {
                    const [pRes, vRes] = await Promise.all([
                        fetchJson<ProjectApiResponse>(`${EXT_BASE}/catalog/curseforge/mod/${enc}`),
                        fetchJson<VersionsApiResponse>(
                            `${EXT_BASE}/catalog/curseforge/mod/${enc}/files?limit=${VERS_PAGE}&offset=0`
                        ),
                    ]);
                    setDetailProject(pRes.project);
                    setDetailVersions(vRes.versions);
                    setVersionsExhausted(vRes.versions.length < VERS_PAGE);
                }
            } catch (e: unknown) {
                setDetailErr(e instanceof Error ? e.message : 'Erreur inconnue');
                setSelectedProjectId(null);
            } finally {
                setDetailLoading(false);
            }
        },
        [catalogProvider]
    );

    const loadMoreVersions = useCallback(async () => {
        if (!selectedProjectId || versionsLoadingMore || versionsExhausted) return;
        setVersionsLoadingMore(true);
        setDetailErr(null);
        const enc = encodeURIComponent(selectedProjectId);
        const offset = detailVersions.length;
        const base =
            catalogProvider === 'modrinth'
                ? `${EXT_BASE}/catalog/modrinth/project/${enc}/versions?limit=${VERS_PAGE}&offset=${offset}`
                : `${EXT_BASE}/catalog/curseforge/mod/${enc}/files?limit=${VERS_PAGE}&offset=${offset}`;
        try {
            const vRes = await fetchJson<VersionsApiResponse>(base);
            setDetailVersions((prev) => [...prev, ...vRes.versions]);
            if (vRes.versions.length < VERS_PAGE) setVersionsExhausted(true);
        } catch (e: unknown) {
            setDetailErr(e instanceof Error ? e.message : 'Erreur inconnue');
        } finally {
            setVersionsLoadingMore(false);
        }
    }, [
        catalogProvider,
        detailVersions.length,
        selectedProjectId,
        versionsExhausted,
        versionsLoadingMore,
    ]);

    const closeDetail = useCallback(() => {
        setSelectedProjectId(null);
        setDetailProject(null);
        setDetailVersions([]);
        setDetailErr(null);
        setVersionsExhausted(false);
        setInstallOk(null);
        setInstallErr(null);
    }, []);

    useEffect(() => {
        setCatalog(null);
        setSearchErr(null);
        closeDetail();
    }, [catalogProvider, closeDetail]);

    const installCatalogVersion = useCallback(
        async (row: ModrinthVersionRow) => {
            if (!serverId || !selectedProjectId) return;
            if (!row.primary_file) {
                setInstallErr('Cette version ne fournit pas de fichier principal téléchargeable.');
                return;
            }
            const compat = mcCompatForVersion(
                row.game_versions || [],
                minecraftVersionFilter,
                serverCtx?.minecraft_versions_hint ?? []
            );

            if (compat === 'warn') {
                const ok = window.confirm(
                    'Compatibilité Minecraft : cette version ne correspond pas au filtre catalogue ni aux indications œuf détectées. Continuer l’installation ?'
                );
                if (!ok) return;
            }

            setInstallingVersionId(row.id);
            setInstallErr(null);
            setInstallOk(null);
            try {
                let out: InstallModrinthResponse;
                if (catalogProvider === 'modrinth') {
                    out = await postJson<InstallModrinthResponse>(`${EXT_BASE}/install/modrinth`, {
                        server: serverId,
                        project_id: selectedProjectId,
                        version_id: row.id,
                    });
                } else {
                    const mid = Number.parseInt(selectedProjectId, 10);
                    const fid = Number.parseInt(row.id, 10);
                    if (Number.isNaN(mid) || Number.isNaN(fid)) {
                        setInstallErr('Identifiants CurseForge invalides (mod / fichier).');
                        return;
                    }
                    out = await postJson<InstallModrinthResponse>(`${EXT_BASE}/install/curseforge`, {
                        server: serverId,
                        mod_id: mid,
                        file_id: fid,
                    });
                }
                const fn = out.filename ? ` — fichier ${out.filename}` : '';
                const ev =
                    out.event_id != null ? ` — journal #${String(out.event_id)}` : '';
                setInstallOk(
                    `${out.message} (${out.directory}${fn})${ev}. Redémarrage recommandé : ${out.restart_recommended ? 'oui' : 'non'}.`
                );
                void loadInstallHistory();
            } catch (e: unknown) {
                setInstallErr(e instanceof Error ? e.message : 'Erreur inconnue');
            } finally {
                setInstallingVersionId(null);
            }
        },
        [catalogProvider, selectedProjectId, serverId, loadInstallHistory, minecraftVersionFilter, serverCtx]
    );

    const onSubmit = (e: React.FormEvent): void => {
        e.preventDefault();
        void loadPage(0, 'replace');
    };

    const canLoadMore =
        catalog !== null && catalog.items.length > 0 && catalog.items.length < catalog.total;

    const bodyPreview = detailProject?.body
        ? detailProject.body.slice(0, 1200) + (detailProject.body.length > 1200 ? '\n…' : '')
        : '';

    return (
        <div style={box}>
            <h2 style={{ fontSize: '1.25rem', fontWeight: 600, marginBottom: '0.5rem' }}>Mods et plugins Minecraft</h2>
            <p style={{ opacity: 0.85, marginBottom: '1rem', lineHeight: 1.5 }}>
                Catalogue agrégé côté panel : <strong>Modrinth</strong> (sans clé) ou <strong>CurseForge</strong> (clé
                API <code>CURSEFORGE_API_KEY</code> ou <code>CF_API_KEY</code> dans le <code>.env</code> du panel).
                Installation : pull Wings vers <code>/plugins</code> ou <code>/mods</code> selon loaders / type de
                projet. Les badges <strong>MC ✓ / MC ⚠</strong> comparent les versions Minecraft déclarées au filtre
                catalogue et aux indications œuf ; une alerte demande confirmation avant installation si incohérence.
            </p>
            {serverId && (
                <p style={{ fontSize: '0.75rem', opacity: 0.65, marginBottom: '0.5rem' }}>
                    Contexte serveur panel&nbsp;: <code>{serverId}</code>
                </p>
            )}
            {serverId && ctxErr && (
                <p style={{ fontSize: '0.75rem', color: '#fb923c', marginBottom: '0.65rem' }}>
                    Contexte œuf / Minecraft indisponible&nbsp;: <code>{ctxErr}</code>
                </p>
            )}
            {serverId && serverCtx && (
                <div
                    style={{
                        fontSize: '0.72rem',

                        opacity: 0.88,
                        marginBottom: '1rem',

                        padding: '0.65rem 0.75rem',
                        borderRadius: '6px',

                        border: '1px solid rgba(82,169,255,0.35)',
                        background: 'rgba(82,169,255,0.06)',
                        lineHeight: 1.5,
                    }}
                >
                    {serverCtx.nest_name || serverCtx.egg_name ? (
                        <p style={{ marginBottom: '0.45rem' }}>
                            <strong>Œuf&nbsp;:</strong> {serverCtx.egg_name || '—'} ·{' '}
                            <strong>Nest&nbsp;:</strong> {serverCtx.nest_name || '—'}

                        </p>
                    ) : null}
                    {(serverCtx.minecraft_versions_hint ?? []).length > 0 ? (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px', alignItems: 'center' }}>
                            <span style={{ opacity: 0.8 }}>Hints MC&nbsp;:</span>

                            {(serverCtx.minecraft_versions_hint ?? []).map((v) => (
                                <button
                                    key={v}
                                    type="button"

                                    onClick={() => setMinecraftVersionFilter(v)}
                                    style={{
                                        padding: '3px 8px',

                                        borderRadius: '4px',

                                        border: '1px solid rgba(128,128,128,0.35)',
                                        background: 'rgba(0,0,0,0.14)',
                                        color: 'inherit',
                                        cursor: 'pointer',

                                        fontSize: '0.68rem',

                                    }}

                                >
                                    {v}

                                </button>

                            ))}
                        </div>

                    ) : (
                        <p style={{ opacity: 0.75 }}>

                            Aucune version Minecraft détectée automatiquement (variables d&apos;œuf ou startup vide).
                            Vous pouvez filtrer manuellement ci-dessous.

                        </p>

                    )}
                </div>

            )}

            {serverId && (
                <section
                    style={{
                        borderRadius: '6px',
                        border: '1px solid rgba(128,128,128,0.25)',
                        padding: '1rem',
                        marginBottom: '1rem',
                        background: 'rgba(0,0,0,0.1)',
                    }}
                >
                    <h3 style={{ fontSize: '0.875rem', fontWeight: 600, marginBottom: '0.35rem' }}>
                        Mises à jour planifiées (serveur)
                    </h3>
                    {!scheduleCfg && !scheduleErr && (
                        <p style={{ fontSize: '0.8rem', opacity: 0.75 }}>Chargement de la planification…</p>
                    )}
                    {scheduleErr && <p style={{ fontSize: '0.78rem', color: '#f87171' }}>{scheduleErr}</p>}
                    {scheduleCfg?.migration_pending && (
                        <p style={{ fontSize: '0.75rem', color: '#fbbf24' }}>
                            Migration <code>pmcp_server_schedules</code> absente : exécutez <code>php artisan migrate</code>.
                        </p>
                    )}
                    {scheduleCfg && !scheduleCfg.migration_pending && (
                        <>
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'center', marginBottom: '10px' }}>
                                <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '0.78rem' }}>
                                    <input
                                        type="checkbox"
                                        checked={Boolean(scheduleCfg.scheduled_enabled)}
                                        onChange={(e) =>
                                            setScheduleCfg((prev) =>
                                                prev ? { ...prev, scheduled_enabled: e.target.checked } : prev
                                            )
                                        }
                                    />
                                    Activer les mises à jour planifiées
                                </label>
                                <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '0.78rem' }}>
                                    <input
                                        type="checkbox"
                                        checked={Boolean(scheduleCfg.backup_before_update)}
                                        onChange={(e) =>
                                            setScheduleCfg((prev) =>
                                                prev ? { ...prev, backup_before_update: e.target.checked } : prev
                                            )
                                        }
                                    />
                                    Backup avant update (flag config)
                                </label>
                            </div>
                            <div style={{ ...inputBar, marginBottom: '8px' }}>
                                <label style={{ fontSize: '0.74rem' }}>
                                    Cron (UTC)
                                    <input
                                        style={{ ...input, marginTop: '4px', minWidth: '170px' }}
                                        type="text"
                                        value={scheduleCfg.cron_expression || ''}
                                        onChange={(e) =>
                                            setScheduleCfg((prev) =>
                                                prev ? { ...prev, cron_expression: e.target.value } : prev
                                            )
                                        }
                                    />
                                </label>
                                <label style={{ fontSize: '0.74rem' }}>
                                    Max updates/run
                                    <input
                                        style={{ ...input, marginTop: '4px', width: '90px', minWidth: '90px' }}
                                        type="number"
                                        min={1}
                                        max={50}
                                        value={Number(scheduleCfg.max_updates_per_run || 5)}
                                        onChange={(e) =>
                                            setScheduleCfg((prev) =>
                                                prev
                                                    ? {
                                                          ...prev,
                                                          max_updates_per_run: Math.max(
                                                              1,
                                                              Math.min(50, Number.parseInt(e.target.value || '1', 10) || 1)
                                                          ),
                                                      }
                                                    : prev
                                            )
                                        }
                                    />
                                </label>
                                <button
                                    type="button"
                                    disabled={scheduleSaveBusy}
                                    onClick={() => void saveScheduleConfig()}
                                    style={{
                                        padding: '7px 12px',
                                        borderRadius: '4px',
                                        border: '1px solid rgba(82,169,255,0.55)',
                                        background: 'rgba(82,169,255,0.22)',
                                        color: 'inherit',
                                        cursor: scheduleSaveBusy ? 'wait' : 'pointer',
                                        fontSize: '0.74rem',
                                    }}
                                >
                                    {scheduleSaveBusy ? 'Enregistrement…' : 'Enregistrer planification'}
                                </button>
                            </div>
                            {scheduleSaveOk && <p style={{ fontSize: '0.75rem', color: '#86efac' }}>{scheduleSaveOk}</p>}
                            <p style={{ fontSize: '0.68rem', opacity: 0.6 }}>
                                Ce bloc configure la planification ; l’exécution cron/queue globale est branchée côté panel.
                            </p>
                        </>
                    )}
                </section>
            )}

            {serverId && (
                <section
                    style={{
                        borderRadius: '6px',
                        border: '1px solid rgba(128,128,128,0.25)',
                        padding: '1rem',
                        marginBottom: '1rem',
                        background: 'rgba(0,0,0,0.1)',
                    }}
                >
                    <h3 style={{ fontSize: '0.875rem', fontWeight: 600, marginBottom: '0.35rem' }}>
                        Historique des installations (ce serveur)
                    </h3>

                    <div
                        style={{
                            display: 'flex',
                            flexWrap: 'wrap',
                            gap: '8px',
                            alignItems: 'center',

                            marginBottom: '0.65rem',

                        }}

                    >
                        <button
                            type="button"
                            disabled={installHistory.length === 0 || updateCheckBusy || installHistoryLoading}
                            onClick={() => {

                                void runUpdateCheck(installHistory);
                            }}

                            style={{
                                padding: '4px 12px',

                                borderRadius: '4px',
                                border: '1px solid rgba(82,169,255,0.55)',
                                background: installHistory.length === 0 ? 'transparent' : 'rgba(82,169,255,0.22)',
                                color: 'inherit',
                                cursor: installHistory.length === 0 ? 'not-allowed' : 'pointer',
                                fontSize: '0.72rem',

                            }}

                        >
                            {updateCheckBusy ? 'Vérification…' : 'Vérifier mises à jour (API)'}

                        </button>
                        <span style={{ fontSize: '0.68rem', opacity: 0.55 }}>
                            Dernières entrées agrégées par projet ({Math.min(installHistory.length, 25)} max côté API).
                        </span>
                    </div>

                    {pinsMigrationPending && (

                        <p style={{ fontSize: '0.75rem', color: '#fbbf24', marginBottom: '0.5rem' }}>
                            Migration <code>pmcp_install_pins</code> absente&nbsp;: épingle désactivée sur ce panel jusqu&apos;à
                            <code>php artisan migrate</code>.

                        </p>

                    )}
                    {pinsErr && (
                        <p style={{ fontSize: '0.75rem', color: '#fb923c', marginBottom: '0.5rem' }}>{pinsErr}</p>
                    )}
                    {updatesErr && (
                        <p style={{ fontSize: '0.75rem', color: '#fb923c', marginBottom: '0.5rem' }}>{updatesErr}</p>

                    )}
                    {installHistoryMigrationPending && (
                        <p style={{ fontSize: '0.75rem', color: '#fbbf24', marginBottom: '0.5rem' }}>
                            Migration base de données non appliquée : exécutez les migrations Blueprint / Artisan sur le panel.
                        </p>
                    )}
                    {installHistoryLoading && (
                        <p style={{ fontSize: '0.8rem', opacity: 0.75 }}>Chargement de l&apos;historique…</p>
                    )}
                    {installHistoryErr && (
                        <p style={{ fontSize: '0.8rem', color: '#f87171' }}>{installHistoryErr}</p>
                    )}
                    {!installHistoryLoading &&
                        installHistory.length === 0 &&
                        !installHistoryErr &&
                        !installHistoryMigrationPending && (
                            <p style={{ fontSize: '0.78rem', opacity: 0.7 }}>
                                Aucune installation enregistrée pour l’instant.
                            </p>
                        )}
                    {installHistory.length > 0 && (
                        <div style={{ overflowX: 'auto' }}>
                            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.7rem' }}>
                                <thead>
                                    <tr style={{ borderBottom: '1px solid rgba(128,128,128,0.28)' }}>
                                        <th style={{ textAlign: 'left', padding: '4px 6px' }}>Date</th>
                                        <th style={{ textAlign: 'left', padding: '4px 6px' }}>Source</th>
                                        <th style={{ textAlign: 'left', padding: '4px 6px' }}>Projet</th>
                                        <th style={{ textAlign: 'left', padding: '4px 6px' }}>Installé</th>
                                        <th style={{ textAlign: 'left', padding: '4px 6px' }}>API</th>
                                        <th style={{ textAlign: 'left', padding: '4px 6px' }}>Cible</th>
                                        <th style={{ textAlign: 'left', padding: '4px 6px' }}>Fichier</th>
                                        <th style={{ textAlign: 'left', padding: '4px 6px' }}>Épinglage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {installHistory.map((h) => {
                                        const rkey = pinLookupKey(h.provider, h.project_id);
                                        const pinRow = pinsByKey[rkey];
                                        const up = updateStatusByKey[rkey];
                                        const pinSmallBtn: React.CSSProperties = {
                                            padding: '2px 6px',
                                            fontSize: '0.62rem',
                                            borderRadius: '3px',
                                            border: '1px solid rgba(82,169,255,0.45)',
                                            background: 'rgba(82,169,255,0.14)',
                                            color: 'inherit',
                                            cursor: 'pointer',
                                        };
                                        return (
                                            <tr key={h.id} style={{ borderBottom: '1px solid rgba(128,128,128,0.1)' }}>
                                                <td style={{ padding: '5px 6px', whiteSpace: 'nowrap' }}>
                                                    {h.created_at
                                                        ? new Date(h.created_at).toLocaleString()
                                                        : '—'}
                                                </td>
                                                <td style={{ padding: '5px 6px' }}>{h.provider}</td>
                                                <td style={{ padding: '5px 6px' }}>
                                                    <code>{h.project_id}</code>
                                                </td>
                                                <td style={{ padding: '5px 6px', maxWidth: '160px', wordBreak: 'break-word' }}>
                                                    {h.version_label || <code>{h.version_id}</code>}
                                                </td>
                                                <td style={{ padding: '5px 6px', maxWidth: '180px', wordBreak: 'break-word', verticalAlign: 'top' }}>
                                                    {!up?.error && up?.update_available ? (
                                                        <span style={{ color: '#86efac', display: 'block' }}>
                                                            Oui → <code>{up.latest_version_label || up.latest_version_id}</code>
                                                        </span>
                                                    ) : !up?.error && up?.latest_version_id ? (
                                                        <span style={{ opacity: 0.55 }}>À jour</span>
                                                    ) : up?.error ? (
                                                        <span style={{ color: '#fb923c', fontSize: '0.62rem' }} title={up.error}>
                                                            ?
                                                        </span>
                                                    ) : (
                                                        '—'
                                                    )}
                                                    {pinRow && Boolean(up?.pinned_differs_from_latest) ? (
                                                        <span
                                                            style={{
                                                                display: 'block',
                                                                fontSize: '0.6rem',
                                                                opacity: 0.6,
                                                                marginTop: '3px',
                                                            }}
                                                        >
                                                            (épinglage ≠ dernière)
                                                        </span>
                                                    ) : null}
                                                    {!up?.error &&
                                                    up?.update_available &&
                                                    up.latest_version_id &&
                                                    serverId ? (
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                installHistoryLoading ||
                                                                updateCheckBusy ||
                                                                historyQuickUpdateRowId === h.id
                                                            }
                                                            onClick={() => void applyHistoryLatestUpdate(h)}
                                                            style={{
                                                                marginTop: '6px',
                                                                padding: '2px 6px',
                                                                fontSize: '0.6rem',
                                                                borderRadius: '3px',
                                                                border: '1px solid rgba(34,197,94,0.55)',
                                                                background: 'rgba(34,197,94,0.15)',
                                                                color: 'inherit',
                                                                cursor:
                                                                    installHistoryLoading ||
                                                                    updateCheckBusy ||
                                                                    historyQuickUpdateRowId === h.id
                                                                        ? 'wait'
                                                                        : 'pointer',
                                                            }}
                                                            title={
                                                                pinRow
                                                                    ? 'Installe la dernière version listée par l’API (l’épingle reste inchangée).'
                                                                    : 'Réinstalle dans le même dossier que la dernière entrée.'
                                                            }
                                                        >
                                                            {historyQuickUpdateRowId === h.id
                                                                ? 'Mise à jour…'
                                                                : 'Installer MAJ'}
                                                        </button>
                                                    ) : null}
                                                    {typeof up?.latest_changelog === 'string' &&
                                                    up.latest_changelog.trim() !== '' ? (
                                                        <details
                                                            style={{
                                                                marginTop: '8px',
                                                                fontSize: '0.6rem',
                                                                opacity: 0.82,
                                                                maxWidth: '220px',
                                                            }}
                                                        >
                                                            <summary style={{ cursor: 'pointer', userSelect: 'none' }}>
                                                                Notes / changelog (extrait)
                                                            </summary>
                                                            <div
                                                                style={{
                                                                    marginTop: '6px',
                                                                    whiteSpace: 'pre-wrap',
                                                                    wordBreak: 'break-word',
                                                                    lineHeight: 1.35,
                                                                }}
                                                            >
                                                                {up.latest_changelog}
                                                            </div>
                                                        </details>
                                                    ) : null}
                                                </td>
                                                <td style={{ padding: '5px 6px' }}>
                                                    <code>{h.directory}</code>
                                                </td>
                                                <td style={{ padding: '5px 6px' }}>{h.filename || '—'}</td>
                                                <td style={{ padding: '5px 6px', verticalAlign: 'top' }}>
                                                    {!serverId ? null : pinRow ? (
                                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                                                            <code style={{ fontSize: '0.62rem' }}>
                                                                {pinRow.pinned_version_label || pinRow.pinned_version_id}
                                                            </code>
                                                            <button
                                                                type="button"
                                                                disabled={pinsMigrationPending || installHistoryLoading}
                                                                style={{
                                                                    ...pinSmallBtn,
                                                                    borderColor: 'rgba(248,113,113,0.5)',
                                                                    background: 'rgba(248,113,113,0.1)',
                                                                }}
                                                                onClick={() =>
                                                                    void (async (): Promise<void> => {
                                                                        try {
                                                                            const qp = new URLSearchParams({
                                                                                server: serverId,
                                                                                provider: h.provider,
                                                                                project_id: h.project_id,
                                                                            });
                                                                            await jsonDelete(`${EXT_BASE}/pins?${qp.toString()}`);
                                                                            await loadInstallHistory();
                                                                        } catch (e: unknown) {
                                                                            alert(e instanceof Error ? e.message : 'Erreur');
                                                                        }
                                                                    })()
                                                                }
                                                            >
                                                                Retirer
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            disabled={pinsMigrationPending || installHistoryLoading}
                                                            style={pinSmallBtn}
                                                            title="Mémoriser cette version pour ce projet"
                                                            onClick={() =>
                                                                void (async (): Promise<void> => {
                                                                    if (!serverId) return;
                                                                    try {
                                                                        await postJson(`${EXT_BASE}/pins`, {
                                                                            server: serverId,
                                                                            provider: h.provider,
                                                                            project_id: h.project_id,
                                                                            pinned_version_id: h.version_id,
                                                                            pinned_version_label:
                                                                                typeof h.version_label === 'string' &&
                                                                                h.version_label !== ''
                                                                                    ? h.version_label
                                                                                    : null,
                                                                        });
                                                                        await loadInstallHistory();
                                                                    } catch (e: unknown) {
                                                                        alert(e instanceof Error ? e.message : 'Erreur');
                                                                    }
                                                                })()
                                                            }
                                                        >
                                                            Épingler
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            )}

            <section
                style={{
                    borderRadius: '6px',
                    border: '1px solid rgba(128,128,128,0.25)',
                    padding: '1rem',
                    marginBottom: '1rem',
                    background: 'rgba(0,0,0,0.12)',
                }}
            >
                <h3 style={{ fontSize: '0.875rem', fontWeight: 600, marginBottom: '0.75rem' }}>Recherche catalogue</h3>
                <div style={{ ...inputBar, marginBottom: '10px', flexWrap: 'wrap', alignItems: 'center' }}>
                    <span style={{ fontSize: '0.75rem', opacity: 0.8, flex: '1 1 100%' }}>Source catalogue</span>
                    <button
                        type="button"
                        onClick={() => setCatalogProvider('modrinth')}
                        style={{
                            padding: '6px 14px',
                            borderRadius: '4px',
                            border: `1px solid ${catalogProvider === 'modrinth' ? 'rgba(82,169,255,0.85)' : 'rgba(128,128,128,0.35)'}`,
                            background: catalogProvider === 'modrinth' ? 'rgba(82,169,255,0.28)' : 'transparent',
                            color: 'inherit',
                            cursor: 'pointer',
                            fontSize: '0.8125rem',
                            fontWeight: catalogProvider === 'modrinth' ? 700 : 500,
                        }}
                    >
                        Modrinth
                    </button>
                    <button
                        type="button"
                        disabled={
                            curseForgeConfigured === false ||
                            curseForgeConfigured === null
                        }
                        title={
                            curseForgeConfigured === null
                                ? 'Vérification de la configuration CurseForge…'
                                : curseForgeConfigured === false
                                  ? 'Définir CURSEFORGE_API_KEY ou CF_API_KEY sur le panel (cf. aide admin)'
                                  : 'Catalogue CurseForge'
                        }
                        onClick={() => {
                            if (curseForgeConfigured) setCatalogProvider('curseforge');
                        }}
                        style={{
                            padding: '6px 14px',
                            borderRadius: '4px',
                            border: `1px solid ${catalogProvider === 'curseforge' ? 'rgba(82,169,255,0.85)' : 'rgba(128,128,128,0.35)'}`,
                            background: catalogProvider === 'curseforge' ? 'rgba(82,169,255,0.28)' : 'transparent',
                            color: 'inherit',
                            cursor:
                                curseForgeConfigured === true
                                    ? 'pointer'
                                    : 'not-allowed',
                            fontSize: '0.8125rem',
                            opacity: curseForgeConfigured === true ? 1 : 0.45,
                            fontWeight: catalogProvider === 'curseforge' ? 700 : 500,
                        }}
                    >
                        CurseForge
                    </button>
                </div>
                <div
                    style={{
                        ...inputBar,
                        marginBottom: '10px',
                        flexWrap: 'wrap',
                        alignItems: 'center',
                        gap: '8px',
                    }}
                >
                    <label
                        htmlFor="pmcp-catalog-mc-version"
                        style={{ fontSize: '0.75rem', opacity: 0.8, flex: '1 1 100%' }}
                    >
                        Version Minecraft (filtre catalogue, optionnel)
                    </label>
                    <input
                        id="pmcp-catalog-mc-version"
                        style={{ ...input, maxWidth: '200px' }}
                        type="text"
                        inputMode="text"
                        autoComplete="off"
                        placeholder="ex. 1.21.1"
                        value={minecraftVersionFilter}
                        onChange={(e) => setMinecraftVersionFilter(e.target.value)}
                        aria-label="Filtrer le catalogue par version Minecraft"
                    />
                    <span style={{ fontSize: '0.68rem', opacity: 0.55 }}>
                        Modrinth (facettes) · CurseForge (gameVersion) — relancez la recherche après modification.
                    </span>
                </div>
                <form onSubmit={onSubmit} style={inputBar}>
                    <input
                        style={input}
                        type="search"
                        placeholder="ex. ViaVersion, ferrite-core, chunky…"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        aria-label={
                            catalogProvider === 'modrinth'
                                ? 'Recherche catalogue Modrinth'
                                : 'Recherche catalogue CurseForge'
                        }
                    />
                    <button
                        type="submit"
                        disabled={searchLoading}
                        style={{
                            padding: '8px 14px',
                            borderRadius: '4px',
                            border: '1px solid rgba(128,128,128,0.4)',
                            background: searchLoading ? 'rgba(255,255,255,0.08)' : 'rgba(82,169,255,0.35)',
                            color: 'inherit',
                            cursor: searchLoading ? 'wait' : 'pointer',
                            fontWeight: 600,
                            fontSize: '0.8125rem',
                        }}
                    >
                        {searchLoading ? 'Patience…' : 'Chercher'}
                    </button>
                </form>
                {searchErr && (
                    <p style={{ fontSize: '0.875rem', color: '#f87171', marginBottom: '0.5rem' }}>{searchErr}</p>
                )}
                {catalog !== null && (
                    <p style={{ fontSize: '0.75rem', opacity: 0.7, marginBottom: '0.75rem' }}>
                        {catalog.total} résultat{catalog.total > 1 ? 's' : ''} — source {catalog.provider}
                    </p>
                )}
                <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                    {(catalog?.items || []).map((hit) => (
                        <li
                            key={`${hit.provider}:${hit.external_id}`}
                            style={{
                                display: 'flex',
                                gap: '10px',
                                alignItems: 'flex-start',
                                padding: '10px 0',
                                borderBottom: '1px solid rgba(128,128,128,0.15)',
                            }}
                        >
                            {hit.icon_url ? (
                                <img
                                    src={hit.icon_url}
                                    alt=""
                                    width={40}
                                    height={40}
                                    style={{ borderRadius: '6px', flexShrink: 0 }}
                                />
                            ) : (
                                <div
                                    style={{
                                        width: 40,
                                        height: 40,
                                        borderRadius: '6px',
                                        background: 'rgba(128,128,128,0.2)',
                                        flexShrink: 0,
                                    }}
                                />
                            )}
                            <div style={{ minWidth: 0, flex: 1 }}>
                                <div style={{ fontWeight: 600, fontSize: '0.9rem', lineHeight: 1.3 }}>
                                    {hit.page_url ? (
                                        <a href={hit.page_url} target="_blank" rel="noopener noreferrer">
                                            {hit.title || hit.slug || hit.external_id}
                                        </a>
                                    ) : (
                                        <span>{hit.title || hit.slug || hit.external_id}</span>
                                    )}
                                    <span style={{ opacity: 0.65, fontWeight: 400, marginLeft: 6 }}>
                                        ({hit.project_type})
                                    </span>
                                </div>
                                <div style={{ fontSize: '0.75rem', opacity: 0.8, marginTop: '3px', lineHeight: 1.45 }}>
                                    {hit.summary.slice(0, 220)}
                                    {(hit.summary || '').length > 220 ? '…' : ''}
                                </div>
                                <div
                                    style={{
                                        fontSize: '0.68rem',
                                        opacity: 0.55,
                                        marginTop: '6px',
                                        display: 'flex',
                                        flexWrap: 'wrap',
                                        gap: '8px',
                                        alignItems: 'center',
                                    }}
                                >
                                    {hit.downloads != null ? <span>~{hit.downloads.toLocaleString()} téléchargements</span> : null}
                                    <code>{hit.slug}</code>
                                    <span>·</span>
                                    <code>{hit.external_id}</code>
                                    <button
                                        type="button"
                                        onClick={() => void openProjectDetail(hit.external_id)}
                                        style={{
                                            marginLeft: '4px',
                                            padding: '3px 8px',
                                            fontSize: '0.68rem',
                                            borderRadius: '4px',
                                            border: '1px solid rgba(82,169,255,0.45)',
                                            background: 'rgba(82,169,255,0.15)',
                                            color: 'inherit',
                                            cursor: 'pointer',
                                        }}
                                    >
                                        Détails &amp; versions
                                    </button>
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
                {canLoadMore && (
                    <div style={{ marginTop: '10px', textAlign: 'center' }}>
                        <button
                            type="button"
                            disabled={searchLoading}
                            onClick={() => catalog != null && void loadPage(catalog.offset + catalog.limit, 'append')}
                            style={{
                                padding: '6px 12px',
                                borderRadius: '4px',
                                border: '1px solid rgba(128,128,128,0.35)',
                                background: 'rgba(0,0,0,0.15)',
                                color: 'inherit',
                                cursor: searchLoading ? 'wait' : 'pointer',
                                fontSize: '0.8rem',
                            }}
                        >
                            Charger plus
                        </button>
                    </div>
                )}
            </section>

            {(selectedProjectId !== null || detailLoading) && (
                <section
                    style={{
                        borderRadius: '6px',
                        border: '1px solid rgba(82,169,255,0.35)',
                        padding: '1rem',
                        marginBottom: '1rem',
                        background: 'rgba(82,169,255,0.06)',
                    }}
                >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '8px' }}>
                        <h3 style={{ fontSize: '0.875rem', fontWeight: 600, marginBottom: detailProject ? '0.75rem' : 0 }}>
                            Fiche projet {catalogProvider === 'modrinth' ? 'Modrinth' : 'CurseForge'}
                        </h3>
                        <button
                            type="button"
                            onClick={closeDetail}
                            style={{
                                padding: '4px 10px',
                                fontSize: '0.75rem',
                                borderRadius: '4px',
                                border: '1px solid rgba(128,128,128,0.4)',
                                background: 'transparent',
                                color: 'inherit',
                                cursor: 'pointer',
                            }}
                        >
                            Fermer
                        </button>
                    </div>
                    {detailLoading && <p style={{ fontSize: '0.85rem', opacity: 0.8 }}>Chargement…</p>}
                    {detailErr && !detailLoading && (
                        <p style={{ fontSize: '0.85rem', color: '#f87171' }}>{detailErr}</p>
                    )}
                    {detailProject && !detailLoading && (
                        <>
                            <p style={{ fontSize: '1rem', fontWeight: 600, marginBottom: '0.35rem' }}>{detailProject.title}</p>
                            <p style={{ fontSize: '0.78rem', opacity: 0.75, marginBottom: '0.5rem' }}>
                                <code>{detailProject.id}</code> · {detailProject.project_type}
                                {pinnedForSelectedProject ? (
                                    <>
                                        {' '}
                                        ·{' '}
                                        <span
                                            style={{
                                                color: 'rgba(147,197,253,0.95)',
                                                fontWeight: 600,
                                            }}
                                            title="Version mémorisée pour ce serveur"
                                        >
                                            Épinglé{' '}
                                            <code>
                                                {pinnedForSelectedProject.pinned_version_label ||
                                                    pinnedForSelectedProject.pinned_version_id}
                                            </code>
                                        </span>
                                    </>
                                ) : null}
                                {detailProject.page_url ? (
                                    <>
                                        {' '}
                                        ·{' '}
                                        <a href={detailProject.page_url} target="_blank" rel="noopener noreferrer">
                                            {catalogProvider === 'modrinth'
                                                ? 'Voir sur Modrinth'
                                                : 'Voir sur CurseForge'}
                                        </a>
                                    </>
                                ) : null}
                            </p>
                            {detailProject.description ? (
                                <p style={{ fontSize: '0.8rem', lineHeight: 1.45, marginBottom: '0.5rem' }}>
                                    {detailProject.description}
                                </p>
                            ) : null}
                            {bodyPreview ? (
                                <pre
                                    style={{
                                        fontSize: '0.72rem',
                                        lineHeight: 1.4,
                                        whiteSpace: 'pre-wrap',
                                        wordBreak: 'break-word',
                                        maxHeight: '200px',
                                        overflow: 'auto',
                                        padding: '8px',
                                        borderRadius: '4px',
                                        background: 'rgba(0,0,0,0.25)',
                                        marginBottom: '0.75rem',
                                    }}
                                >
                                    {bodyPreview}
                                </pre>
                            ) : null}
                            {!serverId && (
                                <p style={{ fontSize: '0.8rem', color: '#fbbf24', marginBottom: '0.75rem' }}>
                                    Ouvrez cet onglet depuis une page serveur (<code>/server/…</code>) pour activer
                                    l’installation.
                                </p>
                            )}
                            {installOk && (
                                <p style={{ fontSize: '0.78rem', color: '#86efac', marginBottom: '0.5rem' }}>{installOk}</p>
                            )}
                            {installErr && (
                                <p style={{ fontSize: '0.78rem', color: '#f87171', marginBottom: '0.5rem' }}>{installErr}</p>
                            )}
                            <h4 style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.5rem' }}>Versions</h4>
                            <div style={{ overflowX: 'auto' }}>
                                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.72rem' }}>
                                    <thead>
                                        <tr style={{ borderBottom: '1px solid rgba(128,128,128,0.3)' }}>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>Version</th>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>Fichier</th>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>Loaders</th>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>Dép.</th>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>MC</th>
                                            <th style={{ textAlign: 'right', padding: '4px 6px' }}>DL</th>
                                            <th style={{ textAlign: 'right', padding: '4px 6px' }}>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {detailVersions.map((vr) => {
                                            const mcCompat = mcCompatForVersion(
                                                vr.game_versions || [],
                                                minecraftVersionFilter,
                                                serverCtx?.minecraft_versions_hint ?? []
                                            );

                                            return (
                                            <tr key={vr.id} style={{ borderBottom: '1px solid rgba(128,128,128,0.12)' }}>
                                                <td style={{ padding: '6px', verticalAlign: 'top' }}>
                                                    <div>{vr.version_number || vr.name}</div>
                                                    <div style={{ opacity: 0.6, fontSize: '0.65rem' }}>
                                                        {vr.date_published
                                                            ? new Date(vr.date_published).toLocaleString()
                                                            : ''}{' '}
                                                        · {vr.version_type || '—'}
                                                    </div>
                                                </td>
                                                <td style={{ padding: '6px', verticalAlign: 'top' }}>
                                                    {vr.primary_file ? (
                                                        <>
                                                            {vr.primary_file.filename}
                                                            <br />
                                                            <span style={{ opacity: 0.65 }}>
                                                                {fmtBytes(vr.primary_file.size)}
                                                            </span>
                                                        </>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td style={{ padding: '6px', verticalAlign: 'top' }}>
                                                    {(vr.loaders || []).slice(0, 6).join(', ')}
                                                    {(vr.loaders || []).length > 6 ? '…' : ''}
                                                </td>
                                                <td style={{ padding: '6px', verticalAlign: 'top', maxWidth: '120px' }}>
                                                    {(vr.dependencies || []).length === 0 ? (
                                                        '—'
                                                    ) : (
                                                        <span title={(vr.dependencies || [])
                                                            .map((d) => d.project_id || d.dependency_type || '')
                                                            .filter(Boolean)
                                                            .join(', ')}>
                                                            {(vr.dependencies || []).slice(0, 5).map((d, idx) => (
                                                                <span key={idx}>
                                                                    {idx > 0 ? ', ' : ''}
                                                                    <code style={{ fontSize: '0.62rem' }}>
                                                                        {d.project_id ?? d.dependency_type ?? '—'}
                                                                    </code>
                                                                </span>
                                                            ))}
                                                            {(vr.dependencies || []).length > 5 ? '…' : ''}
                                                        </span>
                                                    )}
                                                </td>
                                                <td style={{ padding: '6px', verticalAlign: 'top', maxWidth: '140px' }}>
                                                    {mcCompat === 'ok' ? (
                                                        <span
                                                            style={{
                                                                color: '#86efac',
                                                                fontSize: '0.58rem',
                                                                display: 'block',
                                                                marginBottom: '3px',
                                                            }}
                                                        >
                                                            MC ✓
                                                        </span>
                                                    ) : mcCompat === 'warn' ? (
                                                        <span
                                                            style={{
                                                                color: '#fb923c',
                                                                fontSize: '0.58rem',
                                                                display: 'block',
                                                                marginBottom: '3px',
                                                            }}
                                                            title="Écart entre versions Minecraft déclarées, le filtre catalogue et les indications œuf"
                                                        >
                                                            MC ⚠
                                                        </span>
                                                    ) : null}
                                                    {(vr.game_versions || []).slice(-4).join(', ')}
                                                    {(vr.game_versions || []).length > 4 ? '…' : ''}
                                                </td>
                                                <td style={{ padding: '6px', textAlign: 'right', verticalAlign: 'top' }}>
                                                    {vr.downloads.toLocaleString()}
                                                </td>
                                                <td style={{ padding: '6px', textAlign: 'right', verticalAlign: 'top' }}>
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            !serverId ||
                                                            !vr.primary_file ||
                                                            installingVersionId === vr.id
                                                        }
                                                        title={
                                                            !serverId
                                                                ? 'Contexte serveur requis'
                                                                : !vr.primary_file
                                                                  ? 'Pas de fichier principal'
                                                                  : undefined
                                                        }
                                                        onClick={() => void installCatalogVersion(vr)}
                                                        style={{
                                                            padding: '4px 8px',
                                                            fontSize: '0.68rem',
                                                            borderRadius: '4px',
                                                            border: '1px solid rgba(34,197,94,0.5)',
                                                            background:
                                                                !serverId || !vr.primary_file
                                                                    ? 'rgba(128,128,128,0.15)'
                                                                    : 'rgba(34,197,94,0.18)',
                                                            color: 'inherit',
                                                            cursor:
                                                                !serverId || !vr.primary_file
                                                                    ? 'not-allowed'
                                                                    : 'pointer',
                                                        }}
                                                    >
                                                        {installingVersionId === vr.id ? '…' : 'Installer'}
                                                    </button>
                                                </td>
                                            </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                            {!versionsExhausted && detailVersions.length > 0 && (
                                <div style={{ marginTop: '10px', textAlign: 'center' }}>
                                    <button
                                        type="button"
                                        disabled={versionsLoadingMore}
                                        onClick={() => void loadMoreVersions()}
                                        style={{
                                            padding: '6px 12px',
                                            borderRadius: '4px',
                                            border: '1px solid rgba(128,128,128,0.35)',
                                            background: 'rgba(0,0,0,0.12)',
                                            color: 'inherit',
                                            cursor: versionsLoadingMore ? 'wait' : 'pointer',
                                            fontSize: '0.78rem',
                                        }}
                                    >
                                        {versionsLoadingMore ? 'Patience…' : 'Plus de versions'}
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </section>
            )}

            <details style={{ opacity: 0.9 }}>
                <summary style={{ cursor: 'pointer', fontSize: '0.8rem', fontWeight: 600 }}>Diagnostic extension</summary>
                {healthErr && (
                    <p style={{ fontSize: '0.8rem', color: '#f87171', marginTop: '8px' }}>
                        Health-check&nbsp;: <code>{healthErr}</code>
                    </p>
                )}
                {health && <pre style={preSmall}>{JSON.stringify(health, null, 2)}</pre>}
            </details>

            <p style={{ marginTop: '1rem', fontSize: '0.72rem', opacity: 0.55 }}>
                Extension <code>{IDENT}</code> — User-Agent envoyé aux APIs publiques est défini dans le backend.
            </p>
        </div>
    );
}
