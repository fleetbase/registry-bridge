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
        Schema::table('registry_users', function (Blueprint $table) {
            $table->enum('account_type', ['cloud', 'developer'])->default('cloud')->after('uuid');
            $table->uuid('developer_account_uuid')->nullable()->after('account_type');
            
            $table->foreign('developer_account_uuid')
                  ->references('uuid')
                  ->on('registry_developer_accounts')
                  ->onDelete('cascade');
            
            $table->index(['account_type', 'developer_account_uuid']);
        });
        
        // Make company_uuid and user_uuid nullable since developer accounts won't have them
        Schema::table('registry_users', function (Blueprint $table) {
            $table->uuid('company_uuid')->nullable()->change();
            $table->uuid('user_uuid')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registry_users', function (Blueprint $table) {
            $table->dropForeign(['developer_account_uuid']);
            $table->dropColumn(['account_type', 'developer_account_uuid']);
        });
        
        Schema::table('registry_users', function (Blueprint $table) {
            $table->uuid('company_uuid')->nullable(false)->change();
            $table->uuid('user_uuid')->nullable(false)->change();
        });
    }
};
