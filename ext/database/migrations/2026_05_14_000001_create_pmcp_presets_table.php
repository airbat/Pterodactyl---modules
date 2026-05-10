<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presets utilisateur — liste JSON d’artefacts (provider + ids) sans FK externes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pmcp_presets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->json('items');
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'pmcp_presets_owner_name_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmcp_presets');
    }
};
