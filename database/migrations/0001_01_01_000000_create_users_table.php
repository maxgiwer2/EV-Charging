<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->timestamp('email_verified_at')->nullable();

            // database/schema.sql names this `password_hash`. Laravel's
            // Authenticatable contract, password broker and `hashed` cast all
            // key on `password`, and docs/10 rule 1 says prefer framework
            // conventions, so the column is named `password` here. The stored
            // value is still a bcrypt hash, never a plaintext password.
            $table->string('password');

            // RBAC (docs/02 FR-001). Values come from the PHP enum so the
            // database constraint and application code cannot drift apart.
            $table->enum('role', array_column(UserRole::cases(), 'value'))
                ->default(UserRole::USER->value);

            $table->rememberToken();
            $table->timestamps();

            $table->index('role');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
