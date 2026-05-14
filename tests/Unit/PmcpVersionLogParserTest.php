<?php

declare(strict_types=1);

use PteroMcPlugins\Services\PmcpVersionLogParser;

function pmcpFixture(string $name): string
{
    $path = __DIR__ . '/../stubs/logs/' . $name;
    $content = @file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Fixture manquante : {$path}");
    }
    return $content;
}

test('parse banner Vanilla 1.20.1 → loader=vanilla', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('vanilla-1.20.1.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.20.1')
        ->and($result['loader'])->toBe('vanilla')
        ->and($result['source_line'])->toContain('Starting minecraft server version 1.20.1');
});

test('parse banner Paper 1.20.4 → loader=paper (priorité sur vanilla)', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('paper-1.20.4.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.20.4')
        ->and($result['loader'])->toBe('paper')
        ->and($result['source_line'])->toContain('Paper version');
});

test('parse banner Spigot 1.20.1 → loader=spigot (priorité sur vanilla)', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('spigot-1.20.1.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.20.1')
        ->and($result['loader'])->toBe('spigot')
        ->and($result['source_line'])->toContain('CraftBukkit version');
});
