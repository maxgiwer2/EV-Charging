<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('charging_session_id')
                ->constrained('charging_sessions')->cascadeOnUpdate()->restrictOnDelete();

            // Free-form: real receipts carry an open-ended set of methods
            // (credit card, e-wallet, app credit, member card). Kept as a
            // string so a new method never needs a migration. It is a
            // reporting dimension (docs/06 -> payment method).
            $table->string('method', 50);
            $table->decimal('amount', 12, 2);
            $table->string('reference_no', 150)->nullable();
            $table->dateTime('paid_at')->nullable();

            $table->timestamps();
            // Financial record (docs/10 rule 15).
            $table->softDeletes();

            $table->index(['charging_session_id', 'paid_at']);
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
