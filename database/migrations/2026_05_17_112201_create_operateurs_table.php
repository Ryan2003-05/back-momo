<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operateurs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('nom', 50); // MTN, Moov, Celtiis
            $table->boolean('actif')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operateurs');
    }
};
