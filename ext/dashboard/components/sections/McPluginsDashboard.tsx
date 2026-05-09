import React, { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Page dashboard client — voir ext/dashboard/components/Components.yml
 * `{identifier}` remplacé par Blueprint à l’installation.
 */

const EXT_BASE = `/api/client/extensions/{identifier}`;
const IDENT = `{identifier}`;

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

    const onSubmit = (e: React.FormEvent): void => {
        e.preventDefault();
        void loadPage(0, 'replace');
    };

    const canLoadMore =
        catalog !== null && catalog.items.length > 0 && catalog.items.length < catalog.total;

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
                            <div style={{ minWidth: 0 }}>
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
                                <div style={{ fontSize: '0.68rem', opacity: 0.55, marginTop: '4px' }}>
                                    {hit.downloads != null ? <>~{hit.downloads.toLocaleString()} téléchargements — </> : null}
                                    <code>{hit.slug}</code> · <code>{hit.external_id}</code>
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
