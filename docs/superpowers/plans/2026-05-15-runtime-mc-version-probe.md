# Détection runtime de la version Minecraft via banner de démarrage

## Statut (aligné dépôt — 2026-05)

**Implémenté** dans le repo : `PmcpVersionLogParser`, `PmcpRuntimeVersionProbe`, route `GET /server/probe-mc-version` dans `ext/routes/client.php`, bouton dans `ext/dashboard/components/sections/McPluginsDashboard.tsx`, tests Pest + fixtures sous `tests/stubs/logs/`.

**Documentation à jour** : `docs/PTERODACTYL-PRIMER.md` (section « Détection runtime »), `docs/ARCHITECTURE.md` (sous-section « Sonde runtime »), rappels dans `src/backend/CLAUDE.md` et `src/frontend/CLAUDE.md`.

Les cases `- [ ]` des **Tasks 1–10** ci-dessous restent un **journal TDD / pas à pas** ; ne pas les interpréter comme un backlog ouvert.

**Comportement actuel (résumé)** : liste fermée de chemins `logs/latest.log` et `Logs/latest.log` (avec/sans `/` initial) ; en cas de **404** sur un chemin, essai du suivant ; parser retire BOM + séquences ANSI ; Paper/Spigot tolèrent du texte entre le marqueur et `(MC: …)` ; erreurs `DaemonConnectionException` mappées vers HTTP (403, 4xx, 5xx→502 avec `wings_status` si connu).

---

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Détection runtime de la version Minecraft en parsant un fichier `latest.log` (dossier `logs` ou `Logs`, sensible à la casse) via l'API Wings, exposée par un bouton dans le dashboard PMCP lorsque `ServerMcContextBuilder` ne déduit rien des variables d'œuf.

**Architecture:** Route Laravel `GET /server/probe-mc-version` : boucle sur les chemins candidats, `DaemonFileRepository::getContent($path, 512_000)`, délégation à `PmcpVersionLogParser` (regex par loader, pur, sans I/O), réponse `{ mc_version, loader, source_line, source: 'latest_log' }`. Le frontend appelle la route et met à jour le filtre catalogue (équivalent UX aux hints).

**Tech Stack:** PHP 8.2, Laravel 10/11, Pest 3, `\Pterodactyl\Repositories\Wings\DaemonFileRepository`, React 17 (Pterodactyl Vue 2 panel rend les composants TSX via Blueprint).

---

## Scope

**In:** détection runtime pour serveurs **Java** (Vanilla, Paper, Spigot, Forge, NeoForge, Fabric, Quilt) et **Bedrock** lorsque le démarrage écrit dans `logs/latest.log` ou `Logs/latest.log` avec les motifs reconnus.

**Out (v1.1) :**
- Détection via **WebSocket** Wings (historique stdout) — pour les œufs sans fichier `latest.log` exploitable. YAGNI v1.0.
- Détection live via la commande `version` (Paper/Spigot uniquement). YAGNI.
- Détection **auto** au chargement de la page — bouton manuel uniquement v1.0 pour éviter un appel Wings systématique.

## File Structure

| Fichier | Responsabilité |
|---|---|
| `ext/app/Services/PmcpVersionLogParser.php` | Parser pur : input = bloc de texte, output = `{mc_version, loader, source_line}` ou `null`. Aucune I/O. Normalisation BOM + ANSI. |
| `ext/app/Services/PmcpRuntimeVersionProbe.php` | Orchestrateur : boucle `DaemonFileRepository::getContent` sur les chemins candidats (512 Ko max), mappe les exceptions Wings → `PmcpHttpException`, délègue au parser. |
| `ext/routes/client.php` | Nouvelle route `Route::get('/server/probe-mc-version', …)` (closure inline, pattern existant). |
| `ext/dashboard/components/sections/McPluginsDashboard.tsx` | Bouton « Détecter via les logs serveur » dans le bloc `serverCtx`, état de chargement local, appelle `fetchJson`, pousse le résultat dans `setMinecraftVersionFilter`. |
| `tests/Unit/PmcpVersionLogParserTest.php` | Pest tests TDD : 1 test par loader + cas négatifs. |
| `tests/stubs/logs/<loader>.log` | Fixtures de banners réels (texte brut, copie d'un démarrage authentique). |

---

## Task 1 : Parser — fixtures Vanilla

**Files:**
- Create: `tests/stubs/logs/vanilla-1.20.1.log`
- Create: `tests/Unit/PmcpVersionLogParserTest.php`
- Create: `ext/app/Services/PmcpVersionLogParser.php`

- [ ] **Step 1.1 : Créer la fixture Vanilla**

Create `tests/stubs/logs/vanilla-1.20.1.log` with this exact content:

```
[12:34:56] [Server thread/INFO]: Starting minecraft server version 1.20.1
[12:34:56] [Server thread/INFO]: Loading properties
[12:34:56] [Server thread/INFO]: Default game type: SURVIVAL
[12:34:56] [Server thread/INFO]: Generating keypair
[12:34:56] [Server thread/INFO]: Starting Minecraft server on *:25565
[12:34:56] [Server thread/INFO]: Using epoll channel type
[12:34:56] [Server thread/INFO]: Preparing level "world"
[12:34:56] [Server thread/INFO]: Preparing start region for dimension minecraft:overworld
[12:34:56] [Server thread/INFO]: Time elapsed: 1234 ms
[12:34:56] [Server thread/INFO]: Done (3.456s)! For help, type "help"
```

- [ ] **Step 1.2 : Écrire le test Vanilla (RED)**

Create `tests/Unit/PmcpVersionLogParserTest.php`:

```php
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
```

- [ ] **Step 1.3 : Lancer le test pour vérifier qu'il échoue**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php --filter="Vanilla 1.20.1"`
Expected: FAIL — `Class "PteroMcPlugins\Services\PmcpVersionLogParser" not found`

- [ ] **Step 1.4 : Implémenter le parser minimal (GREEN)**

Create `ext/app/Services/PmcpVersionLogParser.php`:

```php
<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

/**
 * Parse un buffer de log de démarrage Minecraft et déduit { mc_version, loader, source_line }.
 *
 * Pure : aucun I/O. Toute la logique testable en isolation sur fixtures.
 *
 * Loaders supportés v1.0 : paper, spigot, neoforge, forge, fabric, quilt, vanilla, bedrock.
 * L'ordre des règles dans {@see RULES} est déterministe : la 1ère règle qui matche gagne.
 */
final class PmcpVersionLogParser
{
    /**
     * @return array{mc_version: string, loader: string, source_line: string}|null
     */
    public static function parse(string $buffer): ?array
    {
        if ($buffer === '') {
            return null;
        }

        if (preg_match('/Starting minecraft server version (\S+)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'vanilla',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        return null;
    }

    private static function lineAtOffset(string $buffer, int $offset): string
    {
        $start = strrpos(substr($buffer, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($buffer, "\n", $offset);
        $end = $end === false ? strlen($buffer) : $end;

        return trim(substr($buffer, $start, $end - $start));
    }
}
```

- [ ] **Step 1.5 : Vérifier que le test passe (GREEN)**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php --filter="Vanilla 1.20.1"`
Expected: PASS

- [ ] **Step 1.6 : Commit**

```bash
rtk git add ext/app/Services/PmcpVersionLogParser.php tests/Unit/PmcpVersionLogParserTest.php tests/stubs/logs/vanilla-1.20.1.log
rtk git commit -m "feat(probe): add PmcpVersionLogParser with vanilla detection"
```

---

## Task 2 : Parser — Paper (priorité sur vanilla)

**Files:**
- Create: `tests/stubs/logs/paper-1.20.4.log`
- Modify: `tests/Unit/PmcpVersionLogParserTest.php` (ajout d'un test)
- Modify: `ext/app/Services/PmcpVersionLogParser.php` (ajout règle Paper avant règle Vanilla)

- [ ] **Step 2.1 : Créer la fixture Paper**

Create `tests/stubs/logs/paper-1.20.4.log`:

```
[12:34:56 INFO]: Environment: authHost='https://authserver.mojang.com'
[12:34:56 INFO]: Loaded 7 recipes
[12:34:56 INFO]: Starting minecraft server version 1.20.4
[12:34:56 INFO]: Loading properties
[12:34:56 INFO]: This server is running Paper version git-Paper-435 (MC: 1.20.4) (Implementing API version 1.20.4-R0.1-SNAPSHOT)
[12:34:56 INFO]: Server Ping Player Sample Count: 12
[12:34:56 INFO]: Done (5.123s)! For help, type "help"
```

- [ ] **Step 2.2 : Écrire le test Paper (RED)**

Append to `tests/Unit/PmcpVersionLogParserTest.php`:

```php
test('parse banner Paper 1.20.4 → loader=paper (priorité sur vanilla)', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('paper-1.20.4.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.20.4')
        ->and($result['loader'])->toBe('paper')
        ->and($result['source_line'])->toContain('Paper version');
});
```

- [ ] **Step 2.3 : Lancer le test pour vérifier qu'il échoue**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php --filter="Paper 1.20.4"`
Expected: FAIL — `loader` vaut `vanilla` au lieu de `paper`

- [ ] **Step 2.4 : Ajouter la règle Paper avant la règle Vanilla (GREEN)**

Edit `ext/app/Services/PmcpVersionLogParser.php`, replace the body of `parse()` so the Paper rule runs **before** the Vanilla rule:

```php
    public static function parse(string $buffer): ?array
    {
        if ($buffer === '') {
            return null;
        }

        if (preg_match('/This server is running Paper version [^(]*\(MC: (\S+?)\)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'paper',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        if (preg_match('/Starting minecraft server version (\S+)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'vanilla',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        return null;
    }
```

- [ ] **Step 2.5 : Vérifier que tous les tests passent**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php`
Expected: 2 passed (Vanilla et Paper)

- [ ] **Step 2.6 : Commit**

```bash
rtk git add tests/stubs/logs/paper-1.20.4.log tests/Unit/PmcpVersionLogParserTest.php ext/app/Services/PmcpVersionLogParser.php
rtk git commit -m "feat(probe): detect Paper banner before vanilla fallback"
```

---

## Task 3 : Parser — Spigot

**Files:**
- Create: `tests/stubs/logs/spigot-1.20.1.log`
- Modify: `tests/Unit/PmcpVersionLogParserTest.php`
- Modify: `ext/app/Services/PmcpVersionLogParser.php`

- [ ] **Step 3.1 : Créer la fixture Spigot**

Create `tests/stubs/logs/spigot-1.20.1.log`:

```
[12:34:56 INFO]: Starting minecraft server version 1.20.1
[12:34:56 INFO]: Loading properties
[12:34:56 INFO]: This server is running CraftBukkit version 3791-Spigot-c47abbe-cd7c5fb (MC: 1.20.1) (Implementing API version 1.20.1-R0.1-SNAPSHOT)
[12:34:56 INFO]: Server Ping Player Sample Count: 12
[12:34:56 INFO]: Done (3.789s)! For help, type "help"
```

- [ ] **Step 3.2 : Écrire le test Spigot (RED)**

Append to `tests/Unit/PmcpVersionLogParserTest.php`:

```php
test('parse banner Spigot 1.20.1 → loader=spigot (priorité sur vanilla)', function (): void {
    $result = PmcpVersionLogParser::parse(pmcpFixture('spigot-1.20.1.log'));

    expect($result)->not->toBeNull()
        ->and($result['mc_version'])->toBe('1.20.1')
        ->and($result['loader'])->toBe('spigot')
        ->and($result['source_line'])->toContain('CraftBukkit version');
});
```

- [ ] **Step 3.3 : Vérifier que le test échoue**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php --filter="Spigot 1.20.1"`
Expected: FAIL — `loader` vaut `vanilla`

- [ ] **Step 3.4 : Ajouter la règle Spigot après Paper, avant Vanilla (GREEN)**

Edit `ext/app/Services/PmcpVersionLogParser.php`, insert this block right after the Paper rule and before the Vanilla rule:

```php
        if (preg_match('/This server is running CraftBukkit version [^(]*\(MC: (\S+?)\)/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'spigot',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }
```

- [ ] **Step 3.5 : Vérifier que tous les tests passent**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php`
Expected: 3 passed

- [ ] **Step 3.6 : Commit**

```bash
rtk git add tests/stubs/logs/spigot-1.20.1.log tests/Unit/PmcpVersionLogParserTest.php ext/app/Services/PmcpVersionLogParser.php
rtk git commit -m "feat(probe): detect Spigot banner before vanilla fallback"
```

---

## Task 4 : Parser — Fabric et Quilt

**Files:**
- Create: `tests/stubs/logs/fabric-1.20.1.log`
- Create: `tests/stubs/logs/quilt-1.20.1.log`
- Modify: `tests/Unit/PmcpVersionLogParserTest.php`
- Modify: `ext/app/Services/PmcpVersionLogParser.php`

- [ ] **Step 4.1 : Créer la fixture Fabric**

Create `tests/stubs/logs/fabric-1.20.1.log`:

```
[12:34:56] [main/INFO] (FabricLoader): Loading 24 mods:
	- fabric-api 0.83.0+1.20.1
	- fabricloader 0.14.21
	- java 17
	- minecraft 1.20.1
[12:34:56] [main/INFO] (Minecraft): Loading Minecraft 1.20.1 with Fabric Loader 0.14.21
[12:34:56] [main/INFO] (Minecraft): Building unoptimized datafixer
[12:34:56] [Server thread/INFO] (Minecraft): Starting minecraft server version 1.20.1
```

- [ ] **Step 4.2 : Créer la fixture Quilt**

Create `tests/stubs/logs/quilt-1.20.1.log`:

```
[12:34:56] [main/INFO] (QuiltLoader): Loading Quilt Loader 0.20.2
[12:34:56] [main/INFO] (Minecraft): Loading Minecraft 1.20.1 with Quilt Loader 0.20.2
[12:34:56] [Server thread/INFO] (Minecraft): Starting minecraft server version 1.20.1
```

- [ ] **Step 4.3 : Écrire les tests Fabric et Quilt (RED)**

Append to `tests/Unit/PmcpVersionLogParserTest.php`:

```php
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
```

- [ ] **Step 4.4 : Vérifier que les tests échouent**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php --filter="Fabric|Quilt"`
Expected: FAIL — les deux tests retournent `loader=vanilla`

- [ ] **Step 4.5 : Ajouter les règles Fabric et Quilt (GREEN)**

Edit `ext/app/Services/PmcpVersionLogParser.php`, insert these blocks **after Spigot, before Vanilla**:

```php
        if (preg_match('/Loading Minecraft (\S+) with Quilt Loader/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'quilt',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }

        if (preg_match('/Loading Minecraft (\S+) with Fabric Loader/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'fabric',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }
```

Note ordering: Quilt **before** Fabric because Quilt logs sometimes also reference Fabric (Quilt is fork-compatible).

- [ ] **Step 4.6 : Vérifier que tous les tests passent**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php`
Expected: 5 passed

- [ ] **Step 4.7 : Commit**

```bash
rtk git add tests/stubs/logs/fabric-1.20.1.log tests/stubs/logs/quilt-1.20.1.log tests/Unit/PmcpVersionLogParserTest.php ext/app/Services/PmcpVersionLogParser.php
rtk git commit -m "feat(probe): detect Fabric and Quilt mod loaders"
```

---

## Task 5 : Parser — NeoForge et Forge

**Files:**
- Create: `tests/stubs/logs/neoforge-1.21.log`
- Create: `tests/stubs/logs/forge-1.20.1.log`
- Modify: `tests/Unit/PmcpVersionLogParserTest.php`
- Modify: `ext/app/Services/PmcpVersionLogParser.php`

- [ ] **Step 5.1 : Créer la fixture NeoForge**

Create `tests/stubs/logs/neoforge-1.21.log`:

```
[12:34:56] [main/INFO] [ne.ne.fm.lo.FMLLoader/CORE]: NeoForge mod loading service
[12:34:56] [main/INFO] [ne.ne.fm.lo.FMLLoader/CORE]: Loading immediate reference 0.7
[12:34:56] [main/INFO] [STDOUT/]: [com.mojang.logging.LogUtils:lambda$prefix$2:120]: NeoForge 21.0.143
[12:34:56] [Server thread/INFO]: Starting minecraft server version 1.21
[12:34:56] [Server thread/INFO]: Loading properties
```

- [ ] **Step 5.2 : Créer la fixture Forge**

Create `tests/stubs/logs/forge-1.20.1.log`:

```
[12:34:56] [main/INFO] [ne.mi.fm.lo.FMLLoader/CORE]: Forge mod loading service
[12:34:56] [main/INFO] [ne.mi.fm.lo.FMLLoader/CORE]: Loading immediate reference 0.7
[12:34:56] [main/INFO] [ne.mi.fm.lo.LoadingModList/]: Loading mods
[12:34:56] [Server thread/INFO]: Starting minecraft server version 1.20.1
[12:34:56] [Server thread/INFO]: Loading properties
```

- [ ] **Step 5.3 : Écrire les tests NeoForge et Forge (RED)**

Append to `tests/Unit/PmcpVersionLogParserTest.php`:

```php
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
```

- [ ] **Step 5.4 : Vérifier que les tests échouent**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php --filter="NeoForge|Forge 1.20"`
Expected: FAIL — `loader` vaut `vanilla`

- [ ] **Step 5.5 : Ajouter les règles combinées NeoForge/Forge (GREEN)**

Edit `ext/app/Services/PmcpVersionLogParser.php`, insert these blocks **after the Paper rule, before Spigot**:

```php
        // NeoForge avant Forge : la chaîne "Forge mod loading service" est un substring
        // de "NeoForge mod loading service", donc Forge matcherait par erreur si testé en premier.
        if (preg_match('/NeoForge mod loading service/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            $loaderLine = self::lineAtOffset($buffer, $m[0][1]);
            if (preg_match('/Starting minecraft server version (\S+)/i', $buffer, $v)) {
                return [
                    'mc_version' => $v[1],
                    'loader' => 'neoforge',
                    'source_line' => $loaderLine,
                ];
            }
        }

        if (preg_match('/(?<!Neo)Forge mod loading service/i', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            $loaderLine = self::lineAtOffset($buffer, $m[0][1]);
            if (preg_match('/Starting minecraft server version (\S+)/i', $buffer, $v)) {
                return [
                    'mc_version' => $v[1],
                    'loader' => 'forge',
                    'source_line' => $loaderLine,
                ];
            }
        }
```

- [ ] **Step 5.6 : Vérifier que tous les tests passent**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php`
Expected: 7 passed

- [ ] **Step 5.7 : Commit**

```bash
rtk git add tests/stubs/logs/neoforge-1.21.log tests/stubs/logs/forge-1.20.1.log tests/Unit/PmcpVersionLogParserTest.php ext/app/Services/PmcpVersionLogParser.php
rtk git commit -m "feat(probe): detect Forge and NeoForge with disambiguation"
```

---

## Task 6 : Parser — Bedrock + cas négatifs

**Files:**
- Create: `tests/stubs/logs/bedrock-1.21.log`
- Modify: `tests/Unit/PmcpVersionLogParserTest.php`
- Modify: `ext/app/Services/PmcpVersionLogParser.php`

- [ ] **Step 6.1 : Créer la fixture Bedrock**

Create `tests/stubs/logs/bedrock-1.21.log`:

```
[2026-05-15 12:34:56:123 INFO] Starting Server
[2026-05-15 12:34:56:124 INFO] Version: 1.21.30.03
[2026-05-15 12:34:56:125 INFO] Session ID 6b89a4c2-1234-abcd-5678-deadbeefcafe
[2026-05-15 12:34:56:126 INFO] Level Name: Bedrock level
[2026-05-15 12:34:56:127 INFO] Game mode: 0 Survival
```

- [ ] **Step 6.2 : Écrire le test Bedrock + cas négatifs (RED)**

Append to `tests/Unit/PmcpVersionLogParserTest.php`:

```php
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

test('ignore les lignes Server Brand qui contiennent "minecraft server version" en chat', function (): void {
    /* La ligne canonique commence par "Starting minecraft server version" — un message de chat ou
       d'un plugin qui contient ce wording entouré d'autre texte ne doit pas matcher. */
    $buffer = "[INFO]: <user> says: starting minecraft server version is annoying\n";
    expect(PmcpVersionLogParser::parse($buffer))->toBeNull();
});
```

- [ ] **Step 6.3 : Vérifier que les tests échouent**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php --filter="Bedrock|null|Server Brand"`
Expected: 4 fail (Bedrock retourne null ; le test « chat » retourne `loader=vanilla` à tort)

- [ ] **Step 6.4 : Ancrer la regex Vanilla en début de ligne + ajouter règle Bedrock (GREEN)**

Edit `ext/app/Services/PmcpVersionLogParser.php`:

1. Insert this Bedrock block **after Fabric, before the Vanilla rule** (Bedrock has a distinctive `Version:` line near `Starting Server`, but vanilla regex must not match Bedrock log lines):

```php
        // Bedrock écrit "Version: X.Y.Z.W" peu après "Starting Server". On exige les deux signaux
        // pour éviter de matcher la sortie de "/version" envoyée par un opérateur dans un chat.
        if (preg_match('/Starting Server/i', $buffer) &&
            preg_match('/^\[[^\]]+INFO\]\s+Version:\s+(\d+\.\d+\.\d+(?:\.\d+)?)/mi', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'bedrock',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }
```

2. Update the Vanilla rule to anchor on line start, requiring the wording to begin a log message (after timestamp/bracketed prefixes like `[12:34:56] [Server thread/INFO]: `). Any leading bracketed sections (which may contain letters like `Server thread/INFO`) plus whitespace and colons are skipped, but anything else (including chat markers `<user>` or plain text) breaks the anchor:

```php
        if (preg_match('/^(?:\[[^\]]*\][\s:]*|[\s:])*Starting minecraft server version (\S+)/mi', $buffer, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'mc_version' => $m[1][0],
                'loader' => 'vanilla',
                'source_line' => self::lineAtOffset($buffer, $m[0][1]),
            ];
        }
```

Pattern walk-through on `[12:34:56] [Server thread/INFO]: Starting minecraft server version 1.20.1`:
- `^` anchors at line start.
- `(?:\[[^\]]*\][\s:]*|[\s:])*` greedily consumes `[12:34:56]`, then the space, then `[Server thread/INFO]`, then `: ` → cursor lands right before `Starting`.
- The literal then matches; capture group 1 is `1.20.1`.

Pattern walk-through on `[INFO]: <user> says: starting minecraft server version is annoying`:
- After consuming `[INFO]: `, cursor is at `<`. `<` is neither a bracket-section opener nor whitespace/colon, so the `*` quantifier stops. The literal `Starting` does not match `<` → no match for this line.

- [ ] **Step 6.5 : Vérifier que tous les tests passent**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php`
Expected: 11 passed (7 loaders + 4 négatifs)

- [ ] **Step 6.6 : Commit**

```bash
rtk git add tests/stubs/logs/bedrock-1.21.log tests/Unit/PmcpVersionLogParserTest.php ext/app/Services/PmcpVersionLogParser.php
rtk git commit -m "feat(probe): detect Bedrock and harden vanilla anchor"
```

---

## Task 7 : Orchestrateur — service `PmcpRuntimeVersionProbe`

**Files:**
- Create: `ext/app/Services/PmcpRuntimeVersionProbe.php`

- [ ] **Step 7.1 : Implémenter l'orchestrateur**

> **Note (alignement dépôt)** : le listing PHP ci-dessous correspond à l’**ébauche** du plan TDD. L’implémentation réelle (constante `CANDIDATE_LOG_PATHS`, boucle 404→chemin suivant, `mapDaemonConnectionException`, etc.) est la **source de vérité** dans `ext/app/Services/PmcpRuntimeVersionProbe.php`.

Create `ext/app/Services/PmcpRuntimeVersionProbe.php`:

```php
<?php

declare(strict_types=1);

namespace PteroMcPlugins\Services;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Throwable;

/**
 * Probe runtime de la version Minecraft : lit `/logs/latest.log` côté Wings et délègue
 * le parsing à {@see PmcpVersionLogParser}.
 *
 * Pas de fallback v1.0 (WS history, sortie commande `version`) — voir docs/superpowers/plans
 * /2026-05-15-runtime-mc-version-probe.md pour les choix de scope.
 *
 * Path codé en dur (aucun input utilisateur) → pas de risque de path traversal.
 */
final class PmcpRuntimeVersionProbe
{
    private const LOG_PATH = '/logs/latest.log';

    private const MAX_BYTES = 512_000;

    /**
     * @return array{mc_version: string, loader: string, source_line: string, source: string}
     *
     * @throws PmcpHttpException
     */
    public static function probe(Server $server): array
    {
        if (! class_exists(DaemonFileRepository::class)) {
            throw new PmcpHttpException(500, 'Classes Wings du panel introuvables (DaemonFileRepository).');
        }

        /** @var DaemonFileRepository $repo */
        $repo = app(DaemonFileRepository::class);

        try {
            $content = $repo->setServer($server)->getContent(self::LOG_PATH, self::MAX_BYTES);
        } catch (DaemonConnectionException $e) {
            $detail = config('app.debug') ? $e->getMessage() : null;
            throw new PmcpHttpException(502, 'Wings injoignable pour lire les logs du serveur.', ['detail' => $detail]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // Wings renvoie 404 quand le fichier n'existe pas (jamais démarré, log purgé).
            if (str_contains($msg, '404') || stripos($msg, 'not found') !== false) {
                throw new PmcpHttpException(404, "Fichier `logs/latest.log` introuvable sur ce serveur (jamais démarré ou log purgé).");
            }
            throw new PmcpHttpException(500, 'Lecture du log de démarrage impossible.', [
                'detail' => config('app.debug') ? $msg : null,
            ]);
        }

        if (! is_string($content) || $content === '') {
            throw new PmcpHttpException(404, "Le fichier `logs/latest.log` est vide ou inaccessible.");
        }

        $parsed = PmcpVersionLogParser::parse($content);
        if ($parsed === null) {
            throw new PmcpHttpException(422, "Aucun banner de démarrage Minecraft reconnu dans `logs/latest.log`.");
        }

        return [
            'mc_version' => $parsed['mc_version'],
            'loader' => $parsed['loader'],
            'source_line' => $parsed['source_line'],
            'source' => 'latest_log',
        ];
    }
}
```

- [ ] **Step 7.2 : Vérifier que la suite Pest reste verte**

Run: `rtk vendor/bin/pest tests/Unit/PmcpVersionLogParserTest.php`
Expected: 11 passed (le service n'a pas de test unitaire dédié — il est testé via la route en intégration manuelle ; il n'a pas d'autre logique que la délégation au parser et la traduction d'exceptions Wings).

- [ ] **Step 7.3 : Commit**

```bash
rtk git add ext/app/Services/PmcpRuntimeVersionProbe.php
rtk git commit -m "feat(probe): add PmcpRuntimeVersionProbe orchestrator"
```

---

## Task 8 : Route Laravel

**Files:**
- Modify: `ext/routes/client.php` (ajouter `require_once` + nouvelle route)

- [ ] **Step 8.1 : Enregistrer les deux nouveaux services via `require_once`**

Edit `ext/routes/client.php`. After the existing `require_once` block (line 13-22), add:

```php
require_once dirname(__DIR__) . '/app/Services/PmcpVersionLogParser.php';
require_once dirname(__DIR__) . '/app/Services/PmcpRuntimeVersionProbe.php';
```

- [ ] **Step 8.2 : Ajouter la route `GET /server/probe-mc-version`**

Edit `ext/routes/client.php`. Insert this route block **right after** the existing `Route::get('/server/context', …)` route (around line 506):

```php
Route::get('/server/probe-mc-version', static function (Request $request) use ($resolveServer): JsonResponse {
    $validator = Validator::make($request->query(), [
        'server' => ['required', 'string', 'max:64'],
    ]);
    if ($validator->fails()) {
        return response()->json(['message' => 'Paramètres invalides.', 'errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $user = $request->user();
    if (! $user) {
        return response()->json(['message' => 'Non authentifié.'], 401);
    }

    $server = $resolveServer($data['server']);
    if ($server === null) {
        return response()->json(['message' => 'Serveur introuvable.'], 404);
    }

    $server->loadMissing('node', 'subusers');
    if ($user->id !== $server->owner_id && ! $user->root_admin) {
        if (! $server->subusers->contains('user_id', $user->id)) {
            return response()->json(['message' => 'Serveur introuvable.'], 404);
        }
    }

    if (! $user->can(\Pterodactyl\Models\Permission::ACTION_FILE_READ, $server)) {
        return response()->json(['message' => 'Permission refusée : lecture des fichiers du serveur.'], 403);
    }

    try {
        $payload = \PteroMcPlugins\Services\PmcpRuntimeVersionProbe::probe($server);
    } catch (\PteroMcPlugins\Services\PmcpHttpException $e) {
        return response()->json(
            array_merge(['message' => $e->getMessage()], $e->extra),
            $e->status
        );
    }

    return response()->json($payload);
});
```

- [ ] **Step 8.3 : Vérifier syntaxe PHP**

Run: `rtk php -l ext/routes/client.php`
Expected: `No syntax errors detected in ext/routes/client.php`

- [ ] **Step 8.4 : Re-run la suite de tests pour s'assurer qu'aucun test précédent n'est cassé**

Run: `rtk vendor/bin/pest`
Expected: tous les tests passent (parser + tests existants)

- [ ] **Step 8.5 : Commit**

```bash
rtk git add ext/routes/client.php
rtk git commit -m "feat(probe): expose GET /server/probe-mc-version client route"
```

---

## Task 9 : Frontend — bouton de détection

**Files:**
- Modify: `ext/dashboard/components/sections/McPluginsDashboard.tsx`

- [ ] **Step 9.1 : Ajouter le type de réponse et l'état local**

Edit `ext/dashboard/components/sections/McPluginsDashboard.tsx`. After the existing `type CatalogResponse = { … }` declaration (around line 46), add a new type:

```typescript
type ProbeVersionResponse = {
    mc_version: string;
    loader: string;
    source_line: string;
    source: string;
};
```

- [ ] **Step 9.2 : Ajouter l'état React et le handler dans le composant**

Edit `ext/dashboard/components/sections/McPluginsDashboard.tsx`, inside `export default function McPluginsDashboard()`. After the existing `useState` calls for context (around line 615-700, find the line that declares `setMinecraftVersionFilter`), add:

```typescript
    const [probeLoading, setProbeLoading] = useState<boolean>(false);
    const [probeError, setProbeError] = useState<string | null>(null);
    const [probeResult, setProbeResult] = useState<ProbeVersionResponse | null>(null);

    const handleProbeVersion = useCallback(async (): Promise<void> => {
        if (!serverId) {
            return;
        }
        setProbeLoading(true);
        setProbeError(null);
        try {
            const data = await getJson<ProbeVersionResponse>(
                `${EXT_BASE}/server/probe-mc-version?server=${encodeURIComponent(serverId)}`,
            );
            setProbeResult(data);
            setMinecraftVersionFilter(data.mc_version);
        } catch (err) {
            setProbeError(err instanceof Error ? err.message : 'Erreur inconnue lors de la sonde version.');
            setProbeResult(null);
        } finally {
            setProbeLoading(false);
        }
    }, [serverId]);
```

Note: `setMinecraftVersionFilter` is already in scope from the existing context. `getJson` is already defined at module scope (line ~390).

- [ ] **Step 9.3 : Ajouter le bouton et l'affichage du résultat dans le bloc `serverCtx`**

Edit `ext/dashboard/components/sections/McPluginsDashboard.tsx`. Locate the `else` branch of the existing `(serverCtx.minecraft_versions_hint ?? []).length > 0 ? (…) : (…)` ternary in the JSX (around line 1481-1500, the branch shown when no hints are detected). Inside the `<div style={{ opacity: 0.75 }}>` block, after the existing explanatory `<p>` paragraphs, append:

```typescript
                            <div style={{ marginTop: '0.5rem', display: 'flex', flexWrap: 'wrap', gap: '8px', alignItems: 'center' }}>
                                <button
                                    type="button"
                                    onClick={handleProbeVersion}
                                    disabled={probeLoading || !serverId}
                                    style={{
                                        padding: '4px 10px',
                                        borderRadius: '4px',
                                        border: '1px solid rgba(82,169,255,0.5)',
                                        background: 'rgba(82,169,255,0.12)',
                                        color: 'inherit',
                                        cursor: probeLoading ? 'wait' : 'pointer',
                                        fontSize: '0.7rem',
                                    }}
                                >
                                    {probeLoading ? 'Lecture des logs…' : 'Détecter via les logs serveur'}
                                </button>
                                {probeResult && (
                                    <span style={{ fontSize: '0.7rem', color: '#a7f3d0' }}>
                                        Détecté&nbsp;: <strong>{probeResult.mc_version}</strong> ({probeResult.loader})
                                    </span>
                                )}
                                {probeError && (
                                    <span style={{ fontSize: '0.7rem', color: '#fb923c' }}>
                                        {probeError}
                                    </span>
                                )}
                            </div>
```

- [ ] **Step 9.4 : Vérifier qu'il n'y a pas d'erreurs de lint TypeScript**

Run: `rtk npm run lint -- ext/dashboard/components/sections/McPluginsDashboard.tsx`
Expected: no errors (project's ESLint config, voir `package.json` pour la commande exacte). Si la commande n'existe pas, fallback: `rtk npx tsc --noEmit ext/dashboard/components/sections/McPluginsDashboard.tsx` to verify TS types.

- [ ] **Step 9.5 : Commit**

```bash
rtk git add ext/dashboard/components/sections/McPluginsDashboard.tsx
rtk git commit -m "feat(probe): add 'Detect via server logs' button in dashboard"
```

---

## Task 10 : Documentation et package

**Files:**
- Modify: `docs/PTERODACTYL-PRIMER.md` (mention de la sonde runtime)
- Modify: `docs/ARCHITECTURE.md` (sous-section sonde vs `server/context`)
- Modify: `src/backend/CLAUDE.md` (section antipatterns / consignes)
- Modify: `src/frontend/CLAUDE.md` (pointeur dashboard / sonde)

- [ ] **Step 10.1 : Vérifier / compléter `docs/PTERODACTYL-PRIMER.md`**

La section **« Détection runtime (probe via logs) »** est la **source de vérité** (route, chemins candidats, parser, codes HTTP, UI). Maintenir ce bloc aligné sur `PmcpRuntimeVersionProbe` et `PmcpVersionLogParser` lors de toute évolution.

- [ ] **Step 10.2 : Ajouter un rappel dans `src/backend/CLAUDE.md`**

Edit `src/backend/CLAUDE.md`. Trouver la section antipatterns ou conventions Wings. Ajouter :

```markdown

## Lecture de logs serveur

Pour toute lecture de log de démarrage MC, **toujours** passer par `\Pterodactyl\Repositories\Wings\DaemonFileRepository::getContent($path, $maxBytes)`. Ne jamais construire un chemin à partir d’une entrée utilisateur brute : soit une **liste fermée** de chemins relatifs (comme `PmcpRuntimeVersionProbe`), soit un chemin validé par `PmcpWorkspacePath::sanitizeFilePath()` pour les parcours élargis.
```

- [ ] **Step 10.3 : Repackager le Blueprint et tester manuellement**

Run:

```bash
rtk ./scripts/package-blueprint.sh
rtk blueprint -f pteromcplugins
```

Ouvrir un serveur Minecraft Paper/Forge/Fabric en local, naviguer vers `/server/<id>/mc-plugins`, et vérifier :
1. Si l'œuf produit des hints (cas standard) → comportement inchangé.
2. Sur un œuf custom sans hints → le bouton « Détecter via les logs serveur » apparaît.
3. Cliquer → la version détectée s'affiche et alimente le filtre catalogue.
4. Erreurs à valider manuellement :
   - Serveur jamais démarré / aucun `latest.log` candidat → **404** (message + `tried_paths`).
   - Permission `file.read` absente sur subuser → **403**.
   - Wings injoignable ou **5xx** Wings → **502** (éventuellement `wings_status` / `detail` en debug).

- [ ] **Step 10.4 : Commit final**

```bash
rtk git add docs/PTERODACTYL-PRIMER.md docs/ARCHITECTURE.md src/backend/CLAUDE.md src/frontend/CLAUDE.md
rtk git commit -m "docs(probe): document runtime MC version detection"
```

---

## Self-Review Checklist

Après exécution, vérifier :

1. **Spec coverage** :
   - [ ] Approche B (parsing banner) implémentée ✓ (Tasks 1-7)
   - [ ] Tous les loaders cibles couverts (Paper, Spigot, Forge, NeoForge, Fabric, Quilt, Vanilla, Bedrock) ✓ (Tasks 1-6)
   - [ ] Route REST exposée ✓ (Task 8)
   - [ ] Bouton frontend intégré au dashboard ✓ (Task 9)
   - [ ] Documentation mise à jour ✓ (Task 10)
   - [ ] TDD strict respecté sur le parser ✓ (Tasks 1-6, chaque loader = test RED → impl → GREEN)

2. **Sécurité** :
   - [ ] Chemins = **liste fermée** (`logs`/`Logs` + `latest.log`) — pas d’input utilisateur, pas de path traversal.
   - [ ] Limite de lecture 512 KB.
   - [ ] Permission `ACTION_FILE_READ` vérifiée.
   - [ ] Vérification ownership/subuser standard.
   - [ ] Erreurs Wings → réponses HTTP propres (403, 404, 422, 500, 502 avec `wings_status` quand le code HTTP Wings est connu).

3. **Types consistency** :
   - `PmcpVersionLogParser::parse(string): ?array{mc_version: string, loader: string, source_line: string}` — utilisé identiquement dans `PmcpRuntimeVersionProbe::probe()` qui ajoute `source: 'latest_log'`.
   - `ProbeVersionResponse` côté frontend matche exactement le payload `PmcpRuntimeVersionProbe::probe()` retourné par la route.
   - `setMinecraftVersionFilter` réutilisé tel quel — pas de nouveau setter.

4. **DRY / YAGNI** :
   - Pas de fallback WS dans v1.0 (YAGNI explicite).
   - Pattern routes existant (closure + `use ($resolveServer)`) réutilisé tel quel.
   - `fetchJson` (module dashboard) pour l’appel probe — pas de nouveau helper HTTP dédié.
   - Pattern `require_once` services existant respecté.
