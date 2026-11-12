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
        Schema::create('drops', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('title');
            $table->string('sku')->unique();
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('total_stock');
            $table->unsignedInteger('available_stock');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE drops ADD CONSTRAINT check_drops_available_stock CHECK (available_stock >= 0 AND available_stock <= total_stock)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drops');
    }
};
