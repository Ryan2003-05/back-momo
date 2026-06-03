<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('push_requests', function (Blueprint $table) {
            $table->string('provider', 50)->default('simulation')->after('statut');
            $table->string('provider_reference')->nullable()->after('provider');
            $table->json('provider_payload')->nullable()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('push_requests', function (Blueprint $table) {
            $table->dropColumn(['provider', 'provider_reference', 'provider_payload']);
        });
    }
};
