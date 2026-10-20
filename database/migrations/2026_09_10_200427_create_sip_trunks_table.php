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
        Schema::create('sip_trunks', function (Blueprint $table) {
            $table->id();
        
            // Trunk haqida
            $table->string('name');
            $table->string('provider')->nullable();
        
            // SIP server
            $table->string('host');
            $table->unsignedInteger('port')->default(5060);
            $table->string('transport')->default('udp');
        
            // Authentication
            $table->string('auth_type')->default('username_password');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('realm')->nullable();
        
            // SIP identity
            $table->string('from_user')->nullable();
            $table->string('from_domain')->nullable();
            $table->string('outbound_proxy')->nullable();
        
            // Media / DTMF
            $table->string('codec')->nullable();
            $table->string('dtmf_mode')->nullable();
        
            // Caller ID
            $table->string('caller_id')->nullable();
        
            // Concurrent calls
            $table->unsignedInteger('max_concurrent_calls')->default(1);
        
            // Holati
            $table->boolean('enabled')->default(true);
        
            $table->timestamps();

            $table->index('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sip_trunks');
    }
};
