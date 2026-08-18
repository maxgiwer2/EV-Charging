<?php

declare(strict_types=1);

use App\Enums\OcrResultStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw output of one OCR provider run.
        //
        // Append-only: each attempt inserts a new row rather than updating the
        // previous one. docs/05 requires the raw provider payload to be
        // preserved and never overwritten, so a re-run after a failure keeps
        // the earlier evidence intact and the review UI can show what changed.
        Schema::create('receipt_ocr_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')
                ->constrained('receipts')->cascadeOnUpdate()->cascadeOnDelete();

            // Which adapter and model produced this (docs/05 -> log AI
            // provider/model/version). Required for reproducing a result.
            $table->string('provider', 100);
            $table->string('model', 150)->nullable();

            // Verbatim provider response. Never mutated.
            $table->json('raw_payload')->nullable();
            // Normalised fields per docs/05, each with its own confidence.
            $table->json('extracted_data')->nullable();

            // Overall confidence 0..1. Per-field confidences live inside
            // extracted_data. This never authorises verification by itself
            // (FR-005, AT-004).
            $table->decimal('confidence', 5, 4)->nullable();

            $table->enum('status', array_column(OcrResultStatus::cases(), 'value'));
            $table->dateTime('processed_at')->useCurrent();
            $table->timestamps();

            $table->index(['receipt_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_ocr_results');
    }
};
