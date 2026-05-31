<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('commercant_id');
            $table->uuid('transaction_id');
            $table->boolean('lue')->default(false); // RG22
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('commercant_id')
                  ->references('id')
                  ->on('commercants')
                  ->onDelete('cascade');

            $table->foreign('transaction_id')
                  ->references('id')
                  ->on('transactions')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};