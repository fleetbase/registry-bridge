<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Developer accounts publish, upload bundles and purchase extensions without a company,
 * so the legacy `company_uuid` columns must accept null.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['registry_extensions', 'registry_extension_bundles', 'registry_extension_purchases'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('company_uuid')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['registry_extensions', 'registry_extension_bundles', 'registry_extension_purchases'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('company_uuid')->nullable(false)->change();
            });
        }
    }
};
