<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // RG18 : reçu généré uniquement pour les transactions SUCCESS
        Schema::create('recus', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('transaction_id')->unique(); // 1 transaction = 1 reçu max
            $table->string('reference', 50)->unique();
            $table->decimal('montant', 10, 2);
            $table->timestamp('date_emission')->useCurrent();

            $table->foreign('transaction_id')
                  ->references('id')
                  ->on('transactions')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recus');
    }
};