<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('notifications', function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('type');$t->string('title');$t->text('message');$t->string('action_url')->nullable();$t->json('data_json')->nullable();$t->timestamp('read_at')->nullable();$t->timestamps();}); } public function down(): void { Schema::dropIfExists('notifications'); } };
