<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal minimal des installations (Modrinth, etc.) — pas de FK vers tables core du panel (ADR-003).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pmcp_install_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('provider', 32);
            $table->string('project_id', 128);
            $table->string('version_id', 64);
            $table->string('directory', 255);
            $table->string('filename')->nullable();
            $table->string('version_label')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmcp_install_events');
    }
};
