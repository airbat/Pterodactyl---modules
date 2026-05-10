<?php

declare(strict_types=1);

use PteroMcPlugins\Services\PmcpAddonRemovalPath;

test('normalizeModsPluginsDirectory accepte /mods et sous-chemins', function (): void {
    expect(PmcpAddonRemovalPath::normalizeModsPluginsDirectory('/mods'))->toBe('/mods')
        ->and(PmcpAddonRemovalPath::normalizeModsPluginsDirectory('mods/foo'))->toBe('/mods/foo')
        ->and(PmcpAddonRemovalPath::normalizeModsPluginsDirectory('/mods/a/b'))->toBe('/mods/a/b');
});

test('normalizeModsPluginsDirectory accepte /plugins', function (): void {
    expect(PmcpAddonRemovalPath::normalizeModsPluginsDirectory('/plugins'))->toBe('/plugins')
        ->and(PmcpAddonRemovalPath::normalizeModsPluginsDirectory('/plugins/a/b'))->toBe('/plugins/a/b');
});

test('normalizeModsPluginsDirectory rejette hors mods/plugins, .. et vide', function (): void {
    expect(PmcpAddonRemovalPath::normalizeModsPluginsDirectory('/config'))->toBeNull()
        ->and(PmcpAddonRemovalPath::normalizeModsPluginsDirectory('/mods/../etc'))->toBeNull()
        ->and(PmcpAddonRemovalPath::normalizeModsPluginsDirectory(''))->toBeNull()
        ->and(PmcpAddonRemovalPath::normalizeModsPluginsDirectory('/'))->toBeNull();
});

test('sanitizeArtifactBasename accepte un artefact légitime', function (): void {
    expect(PmcpAddonRemovalPath::sanitizeArtifactBasename('fabric-api-0.92.2+1.20.6.jar'))
        ->toBe('fabric-api-0.92.2+1.20.6.jar');
});

test('sanitizeArtifactBasename rejette caractères non autorisés et noms trop longs', function (): void {
    expect(PmcpAddonRemovalPath::sanitizeArtifactBasename('evil<>.jar'))->toBeNull()
        ->and(PmcpAddonRemovalPath::sanitizeArtifactBasename(''))->toBeNull();

    $long = str_repeat('a', 256) . '.jar';
    expect(PmcpAddonRemovalPath::sanitizeArtifactBasename($long))->toBeNull();
});
