<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots dossier d’installation (archive .tar.gz sur le volume serveur via Wings compress).
 *
 * Pas de FK vers servers (ADR-003).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pmcp_backups', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('install_directory', 255);
            $table->string('archive_relative_path', 512);
            $table->string('context', 64)->default('install');
            $table->string('provider', 32)->nullable();
            $table->string('project_id', 128)->nullable();
            $table->string('version_id', 64)->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmcp_backups');
    }
};
