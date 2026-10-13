<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('units', function(Blueprint $t){$t->id();$t->string('name');$t->string('region')->nullable();$t->string('district')->nullable();$t->string('meeting_place')->nullable();$t->string('contact_email')->nullable();$t->timestamps();}); } public function down(): void { Schema::dropIfExists('units'); } };
