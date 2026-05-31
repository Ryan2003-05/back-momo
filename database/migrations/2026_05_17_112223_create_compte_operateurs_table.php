<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('compte_operateurs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('commercant_id');
            $table->uuid('operateur_id');
            $table->string('numero', 20);
            $table->boolean('actif')->default(true);
            $table->decimal('solde', 10, 2)->default(0);
            $table->timestamps();

            $table->foreign('commercant_id')
                  ->references('id')
                  ->on('commercants')
                  ->onDelete('cascade');

            $table->foreign('operateur_id')
                  ->references('id')
                  ->on('operateurs')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compte_operateurs');
    }
};