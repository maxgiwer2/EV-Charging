<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table): void {
            // What the human confirmed at review, kept separate from the OCR
            // output in receipt_ocr_results.extracted_data.
            //
            // This is the mechanism behind docs/05 ("preserve raw OCR, never
            // overwrite original receipt values") and README rule 1. Writing
            // corrections back over the extracted data would destroy the
            // evidence of what the provider actually read, leaving no way to
            // audit a disputed figure or measure provider accuracy.
            $table->json('verified_data')->nullable()->after('status');

            // Duplicate candidates found at upload (AT-005). Persisted rather
            // than recomputed so the reviewer sees exactly what was flagged at
            // the time, even after the comparison set has changed.
            $table->json('duplicate_matches')->nullable()->after('verified_data');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table): void {
            $table->dropColumn(['verified_data', 'duplicate_matches']);
        });
    }
};
