<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtualmin_accounts', function (Blueprint $table) {
            // The worker node this account is provisioned on.
            // Stored separately from the master so lifecycle operations
            // (suspend, terminate, upgrade) go directly to the right node.
            $table->string('node_host')->nullable()->after('usermin_url');
            $table->integer('node_port')->default(10000)->after('node_host');
        });
    }

    public function down(): void
    {
        Schema::table('virtualmin_accounts', function (Blueprint $table) {
            $table->dropColumn(['node_host', 'node_port']);
        });
    }
};
