<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity_quarantined')->default(0);
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity_damaged')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('booking_items', fn (Blueprint $table) => $table->dropColumn('quantity_damaged'));
        Schema::table('equipment_items', fn (Blueprint $table) => $table->dropColumn('quantity_quarantined'));
    }
};
