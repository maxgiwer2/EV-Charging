<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shared reference data (docs/02 FR-006): managed by admins, readable
        // by every authenticated user. Not owned by any single user, so
        // authorization is by role rather than by ownership.
        Schema::create('charging_networks', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 60)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_networks');
    }
};
