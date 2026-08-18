<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable audit trail (docs/02 FR-015, AT-010).
        //
        // Write-once by design: nothing in the application updates or deletes
        // these rows, and there is no soft delete, because an audit record
        // that can be removed is not an audit record. user_id is nullable so
        // system-initiated actions (queue jobs, scheduled tasks) are still
        // recorded, and it survives user deletion via nullOnDelete.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();

            // CREATE, UPDATE, DELETE, VERIFY, REJECT, LOGIN, EXPORT, ...
            $table->string('action', 60);
            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id')->nullable();

            // Attribute snapshots. Secrets and receipt contents are stripped
            // before writing (docs/10 rule 13) -- the logger redacts by key.
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Only created_at: an audit row is never updated, so an
            // updated_at column would be misleading.
            $table->dateTime('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id'], 'idx_audit_entity');
            $table->index(['user_id', 'created_at'], 'idx_audit_user_date');
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
