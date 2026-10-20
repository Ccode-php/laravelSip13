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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};