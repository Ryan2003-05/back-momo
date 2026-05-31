<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('session_paiements', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('commercant_id');
            $table->uuid('compte_operateur_id');
            $table->decimal('montant', 10, 2); // RG6 : montant > 0
            $table->string('libelle', 200);    // RG6 : libellé obligatoire
            $table->enum('statut', ['EN_ATTENTE', 'PAYEE', 'EXPIREE', 'ANNULEE'])->default('EN_ATTENTE');
            $table->enum('type_paiement', ['QR_CODE', 'LIEN', 'USSD']); // RG7
            $table->timestamp('expires_at');   // RG8 : expiration automatique
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('commercant_id')
                  ->references('id')
                  ->on('commercants')
                  ->onDelete('cascade');

            $table->foreign('compte_operateur_id')
                  ->references('id')
                  ->on('compte_operateurs')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_paiements');
    }
};