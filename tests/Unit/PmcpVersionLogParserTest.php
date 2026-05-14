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

test('parse banner Fabric 1.20.1 → loader=fabric', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('fabric-1.20.1.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.20.1')
        ->and($result['loader'])->toBe('fabric')
        ->and($result['source_line'])->toContain('Fabric Loader');
});

test('parse banner Quilt 1.20.1 → loader=quilt', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('quilt-1.20.1.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.20.1')
        ->and($result['loader'])->toBe('quilt')
        ->and($result['source_line'])->toContain('Quilt Loader');
});

test('parse banner NeoForge 1.21 → loader=neoforge (combine deux signaux)', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('neoforge-1.21.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.21')
        ->and($result['loader'])->toBe('neoforge')
        ->and($result['source_line'])->toContain('NeoForge mod loading');
});

test('parse banner Forge 1.20.1 → loader=forge (combine deux signaux)', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('forge-1.20.1.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.20.1')
        ->and($result['loader'])->toBe('forge')
        ->and($result['source_line'])->toContain('Forge mod loading');
});

test('parse banner Bedrock 1.21 → loader=bedrock', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('bedrock-1.21.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.21.30.03')
        ->and($result['loader'])->toBe('bedrock')
        ->and($result['source_line'])->toContain('Version:');
});

test('retourne null sur buffer vide', function (): void {
    expect(PmcpVersionLogParser::parse(''))->toBeNull();
});

test('retourne null sur buffer sans signal connu', function (): void {
    $buffer = "[INFO]: Done loading something\n[INFO]: Server ready.\n";
    expect(PmcpVersionLogParser::parse($buffer))->toBeNull();
});

test('ignore les lignes de chat qui mentionnent "minecraft server version"', function (): void {
    /* La ligne canonique commence par "Starting minecraft server version" — un message de chat
       qui contient ce wording entouré d'un marqueur <user> ne doit pas matcher. */
    $buffer = "[INFO]: <user> says: starting minecraft server version is annoying\n";
    expect(PmcpVersionLogParser::parse($buffer))->toBeNull();
});
