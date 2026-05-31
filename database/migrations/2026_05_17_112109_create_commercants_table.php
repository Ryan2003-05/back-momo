<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commercants', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('utilisateur_id');
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('nom_entreprise', 150);
            $table->string('telephone', 20);
            $table->string('ifu', 100)->nullable();
            $table->string('type_commerce', 100);
            $table->string('ville', 100);
            $table->timestamps();

            $table->foreign('utilisateur_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercants');
    }
};