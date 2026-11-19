<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The automatic update policy of an installed Registry Service extension: `none` (default), `patch` or
     * `minor`. `registry:auto-update` (scheduled) queues an update when the service has a newer compatible
     * release inside the policy.
     */
    public function up(): void
    {
        Schema::table('registry_service_installs', function (Blueprint $table) {
            $table->string('auto_update', 16)->default('none')->after('release_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registry_service_installs', function (Blueprint $table) {
            $table->dropColumn('auto_update');
        });
    }
};
