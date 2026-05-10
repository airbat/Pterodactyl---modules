<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Configuration Pest minimale — pas de bootstrap Laravel ; les tests ciblent
| la logique pure {@see \PteroMcPlugins\Services\ServerMcContextBuilder}.
|
*/

pest()->extend(Tests\TestCase::class);
