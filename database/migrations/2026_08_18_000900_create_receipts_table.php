<?php

declare(strict_types=1);

use App\Enums\ReceiptStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('charging_session_id')->nullable()
                ->constrained('charging_sessions')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();

            // Path on the private `receipts` disk. Never a URL, and never
            // rendered to the client (docs/07 -> never expose private paths).
            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');

            // Content hash, used for exact-duplicate detection (docs/05).
            //
            // database/schema.sql declared this UNIQUE. That is deliberately
            // NOT reproduced here: a unique index makes the second upload fail
            // with an integrity error, whereas AT-005 requires the system to
            // *flag a probable duplicate* and let a human decide. A legitimate
            // case exists too -- the same receipt image may be re-uploaded to
            // correct a mis-keyed session. Detection therefore lives in
            // DuplicateDetectionService, and this index only makes the lookup
            // fast. database/schema.sql has been updated to match.
            $table->char('sha256', 64);

            $table->string('receipt_number', 150)->nullable();

            $table->enum('status', array_column(ReceiptStatus::cases(), 'value'))
                ->default(ReceiptStatus::OCR_PENDING->value);

            // Set when a human confirms the extracted values (AT-004). Kept
            // alongside the actor so the audit trail answers "who verified
            // this and when" without joining audit_logs.
            $table->foreignId('verified_by')->nullable()
                ->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('verified_at')->nullable();

            $table->dateTime('uploaded_at')->useCurrent();
            $table->timestamps();
            // Financial record (docs/10 rule 15).
            $table->softDeletes();

            $table->index('sha256');
            $table->index(['uploaded_by', 'status']);
            $table->index(['status', 'uploaded_at']);
            $table->index('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
