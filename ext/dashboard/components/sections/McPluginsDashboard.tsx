import React, { useEffect, useState } from 'react';

/**
 * Page dashboard client — voir ext/dashboard/components/Components.yml
 * Placeholder Blueprint `{identifier}` remplacé à l’installation.
 */

type HealthPayload = {
    status?: string;
    extension?: string;
    version?: string;
    blueprint_engine?: string;
    blueprint_matches_target?: string;
    blueprint_target?: string;
};

const box: React.CSSProperties = {
    padding: '1rem',
    maxWidth: '960px',
    marginBottom: '1rem',
};

const pre: React.CSSProperties = {
    marginTop: '0.5rem',
    overflow: 'auto',
    fontSize: '12px',
    borderRadius: '4px',
    background: 'rgba(0,0,0,0.45)',
    padding: '12px',
    fontFamily: 'ui-monospace, Consolas, monospace',
    color: '#a7f3d0',
};

export default function McPluginsDashboard() {
    const [health, setHealth] = useState<HealthPayload | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let cancelled = false;
        const url = `/api/client/extensions/{identifier}/health`;

        fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))
            )
            .then((data: HealthPayload) => {
                if (!cancelled) {
                    setHealth(data);
                    setLoading(false);
                }
            })
            .catch((e: Error) => {
                if (!cancelled) {
                    setError(e.message);
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    return (
        <div style={box}>
            <h2 style={{ fontSize: '1.25rem', fontWeight: 600, marginBottom: '0.5rem' }}>
                Mods et plugins Minecraft
            </h2>
            <p style={{ opacity: 0.85, marginBottom: '1rem', lineHeight: 1.5 }}>
                Parcourir Modrinth et CurseForge depuis le panel — en cours d’implémentation. Cette vue valide la route
                dashboard et l’appel à l’API client de l’extension.
            </p>

            <div
                style={{
                    borderRadius: '6px',
                    border: '1px solid rgba(128,128,128,0.25)',
                    padding: '1rem',
                    background: 'rgba(0,0,0,0.12)',
                }}
            >
                <h3 style={{ fontSize: '0.875rem', fontWeight: 600, marginBottom: '0.5rem' }}>Diagnostic extension</h3>
                {loading && <p style={{ fontSize: '0.875rem', opacity: 0.75 }}>Chargement…</p>}
                {!loading && error && (
                    <p style={{ fontSize: '0.875rem', color: '#f87171' }}>
                        Échec du health-check : <code>{error}</code>. Vérifier la session et l’URL{' '}
                        <code>{`/api/client/extensions/{identifier}/health`}</code>.
                    </p>
                )}
                {!loading && !error && health && <pre style={pre}>{JSON.stringify(health, null, 2)}</pre>}
            </div>

            <p style={{ marginTop: '1.5rem', fontSize: '0.75rem', opacity: 0.65 }}>
                À venir : recherche, filtres loader / version MC, installation, backups et rollback.
            </p>
        </div>
    );
}
