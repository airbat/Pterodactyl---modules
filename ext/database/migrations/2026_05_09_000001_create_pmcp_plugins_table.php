<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pmcp_plugins', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('source_code', 32);
            $table->string('external_project_id', 128);
            $table->string('slug')->index();
            $table->string('name_normalized');
            $table->text('summary')->nullable();
            $table->json('loaders_hint')->nullable();
            $table->timestamps();

            $table->unique(['source_code', 'external_project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmcp_plugins');
    }
};
