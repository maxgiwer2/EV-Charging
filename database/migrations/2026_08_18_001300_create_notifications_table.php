<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notifications (docs/02 FR-014).
        //
        // This is the project's own table per database/schema.sql, not
        // Laravel's `database` notification channel table (which uses a UUID
        // primary key and a polymorphic notifiable). If the framework channel
        // is ever adopted, it must be given a different table name.
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            // e.g. OCR_REVIEW, DUPLICATE_RECEIPT, ANOMALOUS_EXPENSE,
            // BUDGET_THRESHOLD.
            $table->string('type', 60);
            $table->string('title', 255);
            $table->text('body')->nullable();
            // Ids of the records this refers to, so the UI can deep-link
            // without parsing the body text.
            $table->json('context')->nullable();

            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            // Unread badge query.
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
