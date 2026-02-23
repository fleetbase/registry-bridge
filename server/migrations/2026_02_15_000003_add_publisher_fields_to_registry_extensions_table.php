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
        Schema::table('registry_extensions', function (Blueprint $table) {
            $table->enum('publisher_type', ['cloud', 'developer'])->default('cloud')->after('company_uuid');
            $table->uuid('publisher_uuid')->nullable()->after('publisher_type');
            
            $table->index(['publisher_type', 'publisher_uuid']);
        });
        
        // Backfill existing extensions with publisher data from company_uuid
        DB::statement('UPDATE registry_extensions SET publisher_type = "cloud", publisher_uuid = company_uuid WHERE publisher_uuid IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registry_extensions', function (Blueprint $table) {
            $table->dropColumn(['publisher_type', 'publisher_uuid']);
        });
    }
};
