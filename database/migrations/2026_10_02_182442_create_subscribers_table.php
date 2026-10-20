<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscriber_group_id')
                ->nullable()
                ->constrained('subscriber_groups')
                ->nullOnDelete();

            $table->string('name');

            $table->enum('type', [
                'student',
                'employee',
            ])->default('student');

            $table->boolean('enabled')
                ->default(true);

            $table->timestamps();

            $table->index('subscriber_group_id');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};