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
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('user_uuid')->references('uuid')->on('users')->cascadeOnDelete();
            $table->foreignUuid('drop_uuid')->references('uuid')->on('drops')->cascadeOnDelete();
            $table->unsignedTinyInteger('quantity');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['user_uuid', 'drop_uuid', 'status']);
        });

        DB::statement('ALTER TABLE reservations ADD CONSTRAINT check_reservations_quantity CHECK (quantity BETWEEN 1 AND 2)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
