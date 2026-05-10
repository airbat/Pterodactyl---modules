<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pmcp_server_schedules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id')->unique();
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->index();
            $table->boolean('scheduled_enabled')->default(false);
            $table->string('cron_expression', 64)->default('0 4 * * 1');
            $table->boolean('backup_before_update')->default(true);
            $table->unsignedTinyInteger('max_updates_per_run')->default(5);
            $table->timestamp('last_preview_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmcp_server_schedules');
    }
};
