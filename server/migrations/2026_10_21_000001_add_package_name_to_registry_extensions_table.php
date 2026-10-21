<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('registry_extensions', function (Blueprint $table) {
            $table->string('package_name')->nullable()->after('name')->index();
        });

        // Backfill package_name from the current bundle's package.json / composer.json name
        DB::table('registry_extensions')
            ->whereNull('package_name')
            ->whereNotNull('current_bundle_uuid')
            ->orderBy('id')
            ->chunkById(100, function ($extensions) {
                $bundles = DB::table('registry_extension_bundles')
                    ->whereIn('uuid', $extensions->pluck('current_bundle_uuid')->all())
                    ->get()
                    ->keyBy('uuid');

                foreach ($extensions as $extension) {
                    $bundle = $bundles->get($extension->current_bundle_uuid);
                    if (!$bundle || empty($bundle->meta)) {
                        continue;
                    }

                    $meta        = json_decode($bundle->meta, true);
                    $packageName = $meta['package.json']['name'] ?? $meta['composer.json']['name'] ?? null;
                    if (is_string($packageName) && $packageName !== '') {
                        DB::table('registry_extensions')->where('id', $extension->id)->update(['package_name' => $packageName]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registry_extensions', function (Blueprint $table) {
            $table->dropIndex(['package_name']);
            $table->dropColumn('package_name');
        });
    }
};
