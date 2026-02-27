<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('registry_extension_purchases', function (Blueprint $table) {
            // Add polymorphic columns using Fleetbase convention (subject)
            $table->string('purchaser_uuid')->nullable()->after('uuid');
            $table->string('purchaser_type')->nullable()->after('purchaser_uuid');
            
            // Keep company_uuid for backward compatibility during migration
            // Will be removed in a future migration after data migration
        });

        // Migrate existing data: convert company_uuid to polymorphic relationship
        DB::table('registry_extension_purchases')
            ->whereNotNull('company_uuid')
            ->update([
                'purchaser_uuid' => DB::raw('company_uuid'),
                'purchaser_type' => 'Fleetbase\\Models\\Company'
            ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('registry_extension_purchases', function (Blueprint $table) {
            $table->dropColumn(['purchaser_uuid', 'purchaser_type']);
        });
    }
};
