<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('session_paiement_id');
            $table->uuid('operateur_id');
            $table->string('reference_gateway', 100)->unique();
            $table->enum('statut', ['EN_ATTENTE', 'SUCCESS', 'FAILED'])->default('EN_ATTENTE');
            $table->string('numero_client', 20);
            $table->timestamp('created_at')->useCurrent();

            // RG13 : pas de update possible → pas de updated_at

            $table->foreign('session_paiement_id')
                  ->references('id')
                  ->on('session_paiements')
                  ->onDelete('cascade');

            $table->foreign('operateur_id')
                  ->references('id')
                  ->on('operateurs')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};