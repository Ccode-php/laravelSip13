<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            $table->foreignId('sip_trunk_id')
                ->constrained('sip_trunks')
                ->restrictOnDelete();

            $table->foreignId('audio_file_id')
                ->constrained('audio_files')
                ->restrictOnDelete();

            $table->enum('target_type', [
                'all',
                'groups',
                'individual',
            ]);

            /*
             * Bir vaqtda nechta call.
             */
            $table->unsignedInteger('max_concurrent_calls')
                ->default(1);

            /*
             * Bir abonentga maksimal necha marta
             * qo‘ng‘iroq qilish.
             */
            $table->unsignedInteger('max_attempts')
                ->default(1);

            $table->enum('status', [
                'draft',
                'queued',
                'running',
                'completed',
                'stopped',
                'failed',
            ])->default('draft');

            $table->timestamp('started_at')
                ->nullable();

            $table->timestamp('finished_at')
                ->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('target_type');
            $table->unsignedInteger('retry_delay_minutes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};