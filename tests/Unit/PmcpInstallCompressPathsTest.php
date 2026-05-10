<?php

declare(strict_types=1);

use PteroMcPlugins\Services\PmcpInstallCompressPaths;

test('forNormalizedDirectory coupe /plugins en racine / et fichier plugins', function (): void {
    $split = PmcpInstallCompressPaths::forNormalizedDirectory('/plugins');
    expect($split)->not->toBeNull()
        ->and($split['daemon_root'])->toBe('/')
        ->and($split['wing_files'])->toBe(['plugins']);
});

test('forNormalizedDirectory prend le dernier segment pour /mods/sous/arbo', function (): void {
    $split = PmcpInstallCompressPaths::forNormalizedDirectory('/mods/extra/target');
    expect($split)->not->toBeNull()
        ->and($split['daemon_root'])->toBe('/mods/extra')
        ->and($split['wing_files'])->toBe(['target']);
});

test('forNormalizedDirectory rejette racine ou vide', function (): void {
    expect(PmcpInstallCompressPaths::forNormalizedDirectory('/'))->toBeNull()
        ->and(PmcpInstallCompressPaths::forNormalizedDirectory(''))->toBeNull();
});
