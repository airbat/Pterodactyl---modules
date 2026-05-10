<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versions épinglées par serveur (pas de FK vers servers — ADR-003).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pmcp_install_pins', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('provider', 32);
            $table->string('project_id', 128);
            $table->string('pinned_version_id', 128);
            $table->string('pinned_version_label')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'provider', 'project_id'], 'pmcp_pins_server_proj_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmcp_install_pins');
    }
};
