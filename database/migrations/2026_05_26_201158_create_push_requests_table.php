<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('push_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_paiement_id')->constrained('session_paiements')->onDelete('cascade');
            $table->string('numero_client', 20);
            $table->enum('statut', ['EN_ATTENTE', 'CONFIRME', 'REFUSE', 'EXPIRE'])->default('EN_ATTENTE');
            $table->string('pin', 10)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_requests');
    }
};