<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_subscribers', function (Blueprint $table) {
            $table->foreignId('campaign_id')
                ->constrained('campaigns')
                ->cascadeOnDelete();

            $table->foreignId('subscriber_id')
                ->constrained('subscribers')
                ->cascadeOnDelete();

            $table->primary([
                'campaign_id',
                'subscriber_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_subscribers');
    }
};