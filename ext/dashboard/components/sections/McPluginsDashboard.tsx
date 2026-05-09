import React, { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Page dashboard client — voir ext/dashboard/components/Components.yml
 * `{identifier}` remplacé par Blueprint à l’installation.
 */

const EXT_BASE = `/api/client/extensions/{identifier}`;
const IDENT = `{identifier}`;
const VERS_PAGE = 12;

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
        throw new Error(`HTTP ${res.status}${text ? ` — ${text.slice(0, 120)}` : ''}`);
    }
    return res.json() as Promise<T>;
}

export default function McPluginsDashboard(): React.ReactElement {
    const serverId = useMemo(() => serverFromPath(typeof window !== 'undefined' ? window.location.pathname : ''), []);

    const [health, setHealth] = useState<HealthPayload | null>(null);
    const [healthErr, setHealthErr] = useState<string | null>(null);

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
            try {
                const data = await fetchJson<CatalogResponse>(`${EXT_BASE}/catalog/search?${params.toString()}`);
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
        [limit, q, serverId]
    );

    const openProjectDetail = useCallback(async (projectId: string) => {
        setSelectedProjectId(projectId);
        setDetailLoading(true);
        setDetailErr(null);
        setDetailProject(null);
        setDetailVersions([]);
        setVersionsExhausted(false);
        const enc = encodeURIComponent(projectId);
        try {
            const [pRes, vRes] = await Promise.all([
                fetchJson<ProjectApiResponse>(`${EXT_BASE}/catalog/modrinth/project/${enc}`),
                fetchJson<VersionsApiResponse>(
                    `${EXT_BASE}/catalog/modrinth/project/${enc}/versions?limit=${VERS_PAGE}&offset=0`
                ),
            ]);
            setDetailProject(pRes.project);
            setDetailVersions(vRes.versions);
            setVersionsExhausted(vRes.versions.length < VERS_PAGE);
        } catch (e: unknown) {
            setDetailErr(e instanceof Error ? e.message : 'Erreur inconnue');
            setSelectedProjectId(null);
        } finally {
            setDetailLoading(false);
        }
    }, []);

    const loadMoreVersions = useCallback(async () => {
        if (!selectedProjectId || versionsLoadingMore || versionsExhausted) return;
        setVersionsLoadingMore(true);
        setDetailErr(null);
        const enc = encodeURIComponent(selectedProjectId);
        const offset = detailVersions.length;
        try {
            const vRes = await fetchJson<VersionsApiResponse>(
                `${EXT_BASE}/catalog/modrinth/project/${enc}/versions?limit=${VERS_PAGE}&offset=${offset}`
            );
            setDetailVersions((prev) => [...prev, ...vRes.versions]);
            if (vRes.versions.length < VERS_PAGE) setVersionsExhausted(true);
        } catch (e: unknown) {
            setDetailErr(e instanceof Error ? e.message : 'Erreur inconnue');
        } finally {
            setVersionsLoadingMore(false);
        }
    }, [detailVersions.length, selectedProjectId, versionsExhausted, versionsLoadingMore]);

    const closeDetail = useCallback(() => {
        setSelectedProjectId(null);
        setDetailProject(null);
        setDetailVersions([]);
        setDetailErr(null);
        setVersionsExhausted(false);
    }, []);

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
                Catalogue via <strong>Modrinth</strong> (agrégateur côté panel). CurseForge et installation sur le serveur
                suivront.
            </p>
            {serverId && (
                <p style={{ fontSize: '0.75rem', opacity: 0.65, marginBottom: '1rem' }}>
                    Contexte serveur panel&nbsp;: <code>{serverId}</code>
                </p>
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
                <form onSubmit={onSubmit} style={inputBar}>
                    <input
                        style={input}
                        type="search"
                        placeholder="ex. ViaVersion, ferrite-core, chunky…"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        aria-label="Recherche catalogue Modrinth"
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
                            Fiche projet Modrinth
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
                                {detailProject.page_url ? (
                                    <>
                                        {' '}
                                        ·{' '}
                                        <a href={detailProject.page_url} target="_blank" rel="noopener noreferrer">
                                            Voir sur Modrinth
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
                            <p style={{ fontSize: '0.72rem', opacity: 0.6, marginBottom: '0.75rem' }}>
                                Installation depuis le panel arrive dans une prochaine version ; les URL de fichiers ne sont pas
                                exposées au navigateur par défaut (contrôle côté serveur).
                            </p>
                            <h4 style={{ fontSize: '0.8rem', fontWeight: 600, marginBottom: '0.5rem' }}>Versions</h4>
                            <div style={{ overflowX: 'auto' }}>
                                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.72rem' }}>
                                    <thead>
                                        <tr style={{ borderBottom: '1px solid rgba(128,128,128,0.3)' }}>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>Version</th>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>Fichier</th>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>Loaders</th>
                                            <th style={{ textAlign: 'left', padding: '4px 6px' }}>MC</th>
                                            <th style={{ textAlign: 'right', padding: '4px 6px' }}>DL</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {detailVersions.map((vr) => (
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
                                                <td style={{ padding: '6px', verticalAlign: 'top', maxWidth: '140px' }}>
                                                    {(vr.game_versions || []).slice(-4).join(', ')}
                                                    {(vr.game_versions || []).length > 4 ? '…' : ''}
                                                </td>
                                                <td style={{ padding: '6px', textAlign: 'right', verticalAlign: 'top' }}>
                                                    {vr.downloads.toLocaleString()}
                                                </td>
                                            </tr>
                                        ))}
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
