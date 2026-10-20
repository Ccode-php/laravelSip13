<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_groups', function (Blueprint $table) {
            $table->foreignId('campaign_id')
                ->constrained('campaigns')
                ->cascadeOnDelete();

            $table->foreignId('subscriber_group_id')
                ->constrained('subscriber_groups')
                ->cascadeOnDelete();

            $table->primary([
                'campaign_id',
                'subscriber_group_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_groups');
    }
};