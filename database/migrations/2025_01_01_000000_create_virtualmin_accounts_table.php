<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtualmin_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->string('domain');
            $table->string('username');
            // No password column — passwords are never persisted.
            // Customers use the Reset Password action to get a fresh one-time password.
            $table->string('usermin_url')->nullable();
            $table->string('status')->default('active'); // active | suspended | terminated
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();

            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtualmin_accounts');
    }
};
