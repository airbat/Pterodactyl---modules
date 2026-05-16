<?php

declare(strict_types=1);

use PteroMcPlugins\Services\ServerMcContextBuilder;
use Pterodactyl\Models\Server;

/**
 * @return object{ env_variable: string, variable_value: string, variable: object }
 */
function pmcp_sv(string $env, string $variableValue, string $defaultFromEgg = ''): object
{
    $eggMeta = new class ($env, $defaultFromEgg) {
        public function __construct(
            public string $env_variable,
            private string $def,
        ) {
        }

        public function getAttribute(string $key): mixed
        {
            return $key === 'default_value' ? $this->def : null;
        }
    };

    return new class ($variableValue, $eggMeta) {
        public function __construct(
            public string $variable_value,
            public object $variable,
        ) {
        }
    };
}

/** @param list<object> $rows */
function pmcp_egg_stub(string $name, array $eggVarRows): object
{
    return new class ($name, $eggVarRows) {
        public function __construct(
            public string $name,
            /** @var list<object> */
            public array $variables,
        ) {
        }
    };
}

/** Retourne un item œuf avec env_variable + getAttribute(default_value). */
function pmcp_egg_var(string $env, string $defaultValue): object
{
    return new class ($env, $defaultValue) {
        public string $env_variable;

        private string $def;

        public function __construct(string $env, string $def)
        {
            $this->env_variable = $env;
            $this->def = $def;
        }

        public function getAttribute(string $key): string
        {
            return $key === 'default_value' ? $this->def : '';
        }
    };
}

test('expose la version Minecraft issue du fichier Paper dans startup expandé', function (): void {
    $server = new Server;
    $server->startup = 'java -jar {{SERVER_JARFILE}} nogui';
    $server->variables = [
        pmcp_sv(
            env: 'SERVER_JARFILE',
            variableValue: 'paper-1.21.4-123.jar',
            defaultFromEgg: ''
        ),
    ];

    $p = ServerMcContextBuilder::build($server);

    expect($p['minecraft_versions_hint'])->toContain('1.21.4')
        ->and($p['context_meta']['startup_has_placeholders_left'])->toBeFalse();
});

test('ne traite pas -Xmx128M comme hint de version', function (): void {
    $server = new Server;
    $server->startup = 'java -Xmx128M -jar server.jar nogui';

    $p = ServerMcContextBuilder::build($server);

    expect($p['minecraft_versions_hint'])->not()->toContain('128');
});

test('signalise placeholders non résolus quand merging env incomplet', function (): void {
    $server = new Server;
    $server->startup = 'java -jar {{SERVER_JARFILE}} {{FOO}} nogui';

    $p = ServerMcContextBuilder::build($server);

    expect($p['context_meta']['startup_has_placeholders_left'])->toBeTrue();
});

test('marque nest/œufs Bedrock comme bedrock_like_egg', function (): void {
    $server = new Server;
    $server->variables = [];
    $server->egg = pmcp_egg_stub('Official Bedrock Dedicated', []);
    $server->nest = (object) ['name' => 'Minecraft'];

    $p = ServerMcContextBuilder::build($server);

    expect($p['context_meta']['bedrock_like_egg'])->toBeTrue();
});

test('server_value (relation Panel Pterodactyl) prime sur default_value œuf', function (): void {
    $server = new Server;
    $server->variables = [
        new class {
            public string $env_variable = 'BEDROCK_VERSION';
            public string $server_value = '1.26.20.5';

            public function getAttribute(string $key): string
            {
                return match ($key) {
                    'env_variable' => 'BEDROCK_VERSION',
                    'server_value' => '1.26.20.5',
                    'default_value' => 'latest',
                    default => '',
                };
            }
        },
    ];
    $server->egg = pmcp_egg_stub('Vanilla Bedrock', [
        pmcp_egg_var('BEDROCK_VERSION', 'latest'),
    ]);
    $server->nest = (object) ['name' => 'Minecraft'];

    $p = ServerMcContextBuilder::build($server);

    expect($p['minecraft_versions_hint'])->toContain('1.26.20.5')
        ->and($p['minecraft_versions_hint'])->not->toContain('latest')
        ->and($p['egg_variables']['BEDROCK_VERSION'] ?? null)->toBe('1.26.20.5')
        ->and($p['context_meta']['context_builder_revision'] ?? null)
        ->toBe(\PteroMcPlugins\Services\ServerMcContextBuilder::CONTEXT_BUILDER_REVISION);
});

test('variable_id sans relation variable chargée → valeur serveur via map œuf', function (): void {
    $eggVar = pmcp_egg_var('BEDROCK_VERSION', 'latest');
    $eggVar->id = 42;

    $server = new Server;
    $server->variables = [
        new class {
            public int $variable_id = 42;
            public string $variable_value = '1.26.20.5';
            public int $id = 42;
        },
    ];
    $server->egg = pmcp_egg_stub('Vanilla Bedrock', [$eggVar]);

    $p = ServerMcContextBuilder::build($server);

    expect($p['minecraft_versions_hint'])->toContain('1.26.20.5')
        ->and($p['minecraft_versions_hint'])->not->toContain('latest')
        ->and($p['egg_variables']['BEDROCK_VERSION'] ?? null)->toBe('1.26.20.5');
});

test('BEDROCK_VERSION 1.26.20.5 (quatre segments) → hint catalogue', function (): void {
    $server = new Server;
    $server->variables = [
        pmcp_sv('BEDROCK_VERSION', '1.26.20.5', ''),
    ];
    $server->egg = pmcp_egg_stub('Vanilla Bedrock', []);

    $p = ServerMcContextBuilder::build($server);

    expect($p['minecraft_versions_hint'])->toContain('1.26.20.5')
        ->and($p['egg_variables']['BEDROCK_VERSION'] ?? null)->toBe('1.26.20.5');
});

test('channel latest sur BEDROCK_VERSION accepte comme hint', function (): void {
    $server = new Server;
    $server->variables = [
        pmcp_sv('BEDROCK_VERSION', 'latest', ''),
    ];

    $p = ServerMcContextBuilder::build($server);

    expect($p['minecraft_versions_hint'])->toContain('latest')
        ->and($p['egg_variables']['BEDROCK_VERSION'] ?? null)->toBe('latest')
        ->and($p['context_meta']['bedrock_like_egg'])->toBeTrue();
});

test('remplit depuis default_value œuf quand valeur serveur absente', function (): void {
    $server = new Server;
    $server->startup = '{{SERVER_JARFILE}}';
    $server->variables = [];
    $server->egg = pmcp_egg_stub('Paper', [
        pmcp_egg_var('SERVER_JARFILE', 'paper-1.20.6-500.jar'),
    ]);

    $p = ServerMcContextBuilder::build($server);

    expect($p['minecraft_versions_hint'])->toContain('1.20.6');
});
