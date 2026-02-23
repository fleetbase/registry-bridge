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
            // Add indexes for polymorphic relationship queries
            $table->index(['purchaser_uuid', 'purchaser_type'], 'purchases_purchaser_index');
            $table->index(['extension_uuid', 'purchaser_uuid', 'purchaser_type'], 'purchases_extension_purchaser_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('registry_extension_purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_purchaser_index');
            $table->dropIndex('purchases_extension_purchaser_index');
        });
    }
};
