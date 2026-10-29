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
        
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();
            $table->foreignId('subscriber_phone_id')->nullable()->constrained('subscriber_phones')->nullOnDelete();
        
            // Kampaniya yaratilgan vaqtdagi nusxa (snapshot)
            $table->string('name');
            $table->string('group_name')->nullable();
            $table->string('phone');
            $table->unsignedTinyInteger('phone_order')->default(1);   // 1 = asosiy raqam, 2 = qo'shimcha
        
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('max_attempts')->default(1);
        
            $table->enum('status', [
                'pending', 'ringing', 'answered', 'busy', 'no_answer', 'failed', 'skipped',
            ])->default('pending');
        
            $table->string('sip_reason')->nullable();
            $table->text('error_message')->nullable();
        
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
        
            $table->boolean('played')->default(false);          // audio oxirigacha eshitildimi
            $table->string('uniqueid', 64)->nullable();         // Asterisk kanal ID si
            $table->timestamp('next_attempt_at')->nullable();   // qayta urinish qachondan
        
            $table->timestamps();
        
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
