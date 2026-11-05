<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extensions installed on this instance from the Registry Service.
     *
     * The service is the system of record for listings and licences; this table keeps only what is
     * installed here, so the console can load the engine and the Installed page works offline.
     */
    public function up(): void
    {
        Schema::create('registry_service_installs', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('installed_by_uuid')->nullable();
            $table->string('extension_id')->index();
            $table->string('slug')->nullable();
            $table->string('name')->nullable();
            $table->string('npm_package')->nullable()->index();
            $table->string('composer_package')->nullable();
            $table->string('version')->nullable();
            $table->string('release_id')->nullable();
            $table->string('service_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
            $table->foreign('installed_by_uuid')->references('uuid')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registry_service_installs');
    }
};
