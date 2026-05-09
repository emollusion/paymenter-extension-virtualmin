<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtualmin_accounts', function (Blueprint $table) {
            $table->string('control_panel_url')->nullable()->after('usermin_url');
        });
    }

    public function down(): void
    {
        Schema::table('virtualmin_accounts', function (Blueprint $table) {
            $table->dropColumn('control_panel_url');
        });
    }
};
