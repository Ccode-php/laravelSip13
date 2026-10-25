<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_phones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscriber_id')
                ->constrained('subscribers')
                ->cascadeOnDelete();

            $table->string('phone', 30)->unique();

            $table->string('label', 50)->nullable();

            $table->boolean('is_primary')->default(false);

            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index('subscriber_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_phones');
    }
};
