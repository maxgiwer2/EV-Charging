@extends('layouts.app')

@section('title', 'Review receipt')

@section('content')
    @php
        $latest = $receipt->ocrResults->first();
        $extracted = $latest?->extracted_data ?? [];
        $lowConfidence = $latest?->lowConfidenceFields() ?? [];
        $threshold = (float) config('ocr.review_threshold');

        // Pre-fill from OCR where available. These are suggestions only: the
        // form below is what a human confirms, and nothing reaches a financial
        // record until they submit it (AT-004).
        $suggest = fn (string $field, $fallback = null) => old($field, $extracted[$field]['value'] ?? $fallback);
    @endphp

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold">{{ $receipt->original_filename }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                Uploaded {{ $receipt->uploaded_at->timezone(config('app.display_timezone'))->format('d M Y H:i') }}
                &middot; <x-receipt-status :status="$receipt->status" />
            </p>
        </div>
        <a href="{{ route('receipts.review.index') }}" class="text-sm text-slate-600 hover:underline">Back</a>
    </div>

    @if (! empty($receipt->duplicate_matches))
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">This looks like a receipt already on file.</p>
            {{-- AT-005: flagged, not blocked. The reviewer decides. --}}
            <ul class="mt-2 list-inside list-disc">
                @foreach ($receipt->duplicate_matches as $match)
                    <li>
                        Receipt #{{ $match['receipt_id'] }}
                        ({{ implode(', ', array_map(fn ($r) => strtolower(str_replace('_', ' ', $r)), $match['reasons'])) }})
                        &mdash;
                        <a href="{{ route('receipts.review.show', $match['receipt_id']) }}" class="underline">compare</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Left: the document itself, so the reviewer checks against the
             source rather than trusting the extracted values. --}}
        <div class="rounded-md border border-slate-200 bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold text-slate-700">Original</h2>

            @if (str_starts_with($receipt->mime_type, 'image/'))
                {{-- Served through the authorized download route; the storage
                     path is never exposed (docs/07). --}}
                <img src="{{ route('receipts.review.file', $receipt) }}"
                     alt="Receipt {{ $receipt->id }}"
                     class="max-h-[36rem] w-full rounded border border-slate-200 object-contain">
            @else
                <a href="{{ route('receipts.review.file', $receipt) }}"
                   class="inline-block rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                    Open {{ strtoupper(pathinfo($receipt->original_filename, PATHINFO_EXTENSION)) }}
                </a>
            @endif

            @if ($latest)
                <dl class="mt-4 space-y-1 text-xs text-slate-500">
                    <div class="flex justify-between">
                        <dt>OCR provider</dt>
                        <dd>{{ $latest->provider }}{{ $latest->model ? ' / '.$latest->model : '' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Result</dt>
                        <dd>{{ $latest->status->value }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Overall confidence</dt>
                        <dd>{{ $latest->confidence !== null ? number_format((float) $latest->confidence * 100, 1).'%' : 'n/a' }}</dd>
                    </div>
                </dl>

                @if ($latest->status === \App\Enums\OcrResultStatus::FAILED)
                    <p class="mt-3 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        OCR could not read this receipt. Enter the values manually from the image.
                    </p>
                @endif
            @endif
        </div>

        {{-- Right: the confirmation form. --}}
        <div class="rounded-md border border-slate-200 bg-white p-4">
            <h2 class="mb-1 text-sm font-semibold text-slate-700">Confirm values</h2>
            <p class="mb-4 text-xs text-slate-500">
                Highlighted fields were read with low confidence &mdash; check them against the image.
                Nothing is recorded until you confirm.
            </p>

            @if ($errors->any())
                <div class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($receipt->status->isTerminal())
                <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">
                    This receipt is {{ strtolower($receipt->status->value) }} and can no longer be changed.
                    @if ($receipt->verified_at)
                        <span class="block text-xs text-slate-500">
                            {{ $receipt->status->isVerified() ? 'Confirmed' : 'Rejected' }}
                            by {{ $receipt->verifier?->name ?? 'unknown' }}
                            on {{ $receipt->verified_at->timezone(config('app.display_timezone'))->format('d M Y H:i') }}
                        </span>
                    @endif
                </div>
            @else
                <form method="POST" action="{{ route('receipts.review.verify', $receipt) }}" class="space-y-4">
                    @csrf

                    <x-review-field name="vehicle_id" label="Vehicle" :low="false">
                        <select id="vehicle_id" name="vehicle_id" required
                                class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            @foreach ($vehicles as $vehicle)
                                <option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>
                                    {{ $vehicle->displayName() }}
                                </option>
                            @endforeach
                        </select>
                    </x-review-field>

                    <x-review-field name="charging_type" label="Charging type" :low="false">
                        <select id="charging_type" name="charging_type" required
                                class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            @foreach (\App\Enums\ChargingType::cases() as $type)
                                <option value="{{ $type->value }}" @selected(old('charging_type', 'PUBLIC') === $type->value)>
                                    {{ $type->value }}
                                </option>
                            @endforeach
                        </select>
                    </x-review-field>

                    {{-- The confidence is passed so a field read below the
                         threshold shows its percentage rather than the
                         "not read" label used for genuinely missing fields. --}}
                    <x-review-field name="station_id" label="Station"
                                    :low="in_array('station', $lowConfidence, true)"
                                    :confidence="$extracted['station']['confidence'] ?? null">
                        <select id="station_id" name="station_id"
                                class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">&mdash;</option>
                            @foreach ($stations as $station)
                                <option value="{{ $station->id }}" @selected(old('station_id') == $station->id)>
                                    {{ $station->name }}
                                </option>
                            @endforeach
                        </select>
                    </x-review-field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-review-field name="transaction_date" label="Date & time"
                                        :low="in_array('transaction_date', $lowConfidence, true)"
                                        :confidence="$extracted['transaction_date']['confidence'] ?? null">
                            <input id="transaction_date" name="transaction_date" type="datetime-local" required
                                   value="{{ old('transaction_date', $transactionDate) }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="receipt_number" label="Receipt no."
                                        :low="in_array('receipt_number', $lowConfidence, true)"
                                        :confidence="$extracted['receipt_number']['confidence'] ?? null">
                            <input id="receipt_number" name="receipt_number" type="text"
                                   value="{{ $suggest('receipt_number', $receipt->receipt_number) }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="energy_kwh" label="Energy (kWh)"
                                        :low="in_array('energy_kwh', $lowConfidence, true)"
                                        :confidence="$extracted['energy_kwh']['confidence'] ?? null">
                            <input id="energy_kwh" name="energy_kwh" type="number" step="0.001" min="0" required
                                   value="{{ $suggest('energy_kwh') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="unit_price" label="Unit price"
                                        :low="in_array('unit_price', $lowConfidence, true)"
                                        :confidence="$extracted['unit_price']['confidence'] ?? null">
                            <input id="unit_price" name="unit_price" type="number" step="0.0001" min="0"
                                   value="{{ $suggest('unit_price') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="subtotal" label="Subtotal"
                                        :low="in_array('subtotal', $lowConfidence, true)"
                                        :confidence="$extracted['subtotal']['confidence'] ?? null">
                            <input id="subtotal" name="subtotal" type="number" step="0.01" min="0" required
                                   value="{{ $suggest('subtotal') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="service_fee" label="Service fee"
                                        :low="in_array('service_fee', $lowConfidence, true)"
                                        :confidence="$extracted['service_fee']['confidence'] ?? null">
                            <input id="service_fee" name="service_fee" type="number" step="0.01" min="0"
                                   value="{{ $suggest('service_fee') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="parking_fee" label="Parking fee"
                                        :low="in_array('parking_fee', $lowConfidence, true)"
                                        :confidence="$extracted['parking_fee']['confidence'] ?? null">
                            <input id="parking_fee" name="parking_fee" type="number" step="0.01" min="0"
                                   value="{{ $suggest('parking_fee') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="discount" label="Discount"
                                        :low="in_array('discount', $lowConfidence, true)"
                                        :confidence="$extracted['discount']['confidence'] ?? null">
                            <input id="discount" name="discount" type="number" step="0.01" min="0"
                                   value="{{ $suggest('discount') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="vat" label="VAT"
                                        :low="in_array('vat', $lowConfidence, true)"
                                        :confidence="$extracted['vat']['confidence'] ?? null">
                            <input id="vat" name="vat" type="number" step="0.01" min="0"
                                   value="{{ $suggest('vat') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>

                        <x-review-field name="total" label="Total"
                                        :low="in_array('total', $lowConfidence, true)"
                                        :confidence="$extracted['total']['confidence'] ?? null">
                            <input id="total" name="total" type="number" step="0.01" min="0" required
                                   value="{{ $suggest('total') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </x-review-field>
                    </div>

                    <x-review-field name="payment_method" label="Payment method"
                                    :low="in_array('payment_method', $lowConfidence, true)"
                                    :confidence="$extracted['payment_method']['confidence'] ?? null">
                        <input id="payment_method" name="payment_method" type="text"
                               value="{{ $suggest('payment_method') }}"
                               class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    </x-review-field>

                    <div class="flex items-center gap-3 border-t border-slate-200 pt-4">
                        <button type="submit"
                                class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-600">
                            Confirm receipt
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route('receipts.review.reject', $receipt) }}"
                      class="mt-3 flex items-center gap-2 border-t border-slate-200 pt-3">
                    @csrf
                    <input type="text" name="reason" placeholder="Reason (optional)" maxlength="500"
                           class="flex-1 rounded-md border-slate-300 text-sm shadow-sm">
                    <button type="submit"
                            class="rounded-md border border-rose-300 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50">
                        Reject
                    </button>
                </form>
            @endif
        </div>
    </div>
@endsection
