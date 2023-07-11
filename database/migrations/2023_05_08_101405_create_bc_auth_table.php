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
        Schema::create('bc_auth', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->string('user_email', 255);
            $table->string('locale', 255)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('owner_email', 255)->nullable();            
            $table->string('store_hash', 255);
            $table->string('scope', 255)->nullable();
            $table->string('account_uuid', 255)->nullable();
            $table->string('access_token', 255)->nullable();
            $table->integer('timestamp')->nullable();
            $table->date('created_at');
            $table->date('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bc_auth');
    }
};
