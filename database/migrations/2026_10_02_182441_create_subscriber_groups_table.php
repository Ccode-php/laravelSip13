<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriber_groups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('subscriber_groups')
                ->nullOnDelete();

            $table->string('name');

            $table->text('description')->nullable();

            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index('parent_id');
            $table->index('enabled');

            $table->unique([
                'parent_id',
                'name',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriber_groups');
    }
};
