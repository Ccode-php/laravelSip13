<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')
                ->constrained('campaigns')
                ->cascadeOnDelete();

            $table->foreignId('subscriber_id')
                ->nullable()
                ->constrained('subscribers')
                ->nullOnDelete();

            $table->foreignId('subscriber_phone_id')
                ->nullable()
                ->constrained('subscriber_phones')
                ->nullOnDelete();

            /*
             * Campaign yaratilgan vaqtdagi snapshot.
             */
            $table->string('name');
            $table->string('group_name')->nullable();   // <-- YANGI: kategoriya nomi (snapshot)
            $table->unsignedTinyInteger('phone_order')->default(1);   // 1 = asosiy raqam, 2 = qo'shimcha
            $table->string('phone');

            /*
             * Hozirgi attempt.
             *
             * 1
             * 2
             * 3
             */
            $table->unsignedInteger('attempt')
                ->default(1);

            /*
             * Campaigndagi maksimal attempt.
             */
            $table->unsignedInteger('max_attempts')
                ->default(1);

            $table->enum('status', [
                'pending',
                'ringing',
                'answered',
                'busy',
                'no_answer',
                'failed',
                'skipped',
            ])->default('pending');

            $table->string('sip_code')
                ->nullable();

            $table->string('sip_reason')
                ->nullable();

            $table->timestamp('started_at')
                ->nullable();

            $table->timestamp('answered_at')
                ->nullable();

            $table->timestamp('finished_at')
                ->nullable();

            $table->unsignedInteger('duration')
                ->nullable();

            $table->text('error_message')
                ->nullable();

            $table->timestamps();

            $table->index([
                'campaign_id',
                'status',
            ]);

            $table->index([
                'subscriber_id',
                'status',
            ]);

            $table->index('phone');

            // Audio oxirigacha eshitildimi
            $table->boolean('played')->default(false);

            // Asterisk kanal ID si (nosozliklarni qidirish uchun)
            $table->string('uniqueid', 64)->nullable();

            // Qayta urinish qachondan boshlab mumkin
            $table->timestamp('next_attempt_at')->nullable();

            $table->index(['campaign_id', 'status', 'next_attempt_at'], 'recipients_pick_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
