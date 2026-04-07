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
        Schema::create('video_distributions', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id');
            $table->string('platform');
            $table->string('status');
            $table->string('platform_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['entry_id', 'platform']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_distributions');
    }
};
