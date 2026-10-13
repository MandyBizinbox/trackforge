<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('equipment_categories', function(Blueprint $t){$t->id();$t->string('name');$t->string('slug')->unique();$t->text('description')->nullable();$t->integer('sort_order')->default(0);$t->timestamps();}); } public function down(): void { Schema::dropIfExists('equipment_categories'); } };
