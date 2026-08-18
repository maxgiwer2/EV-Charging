@props(['status'])

@php
    // Colour carries meaning here: a reviewer scanning the list needs to see
    // at a glance what still needs a decision (amber) versus what is already
    // financial fact (emerald).
    $classes = match ($status) {
        \App\Enums\ReceiptStatus::OCR_PENDING => 'bg-slate-100 text-slate-700',
        \App\Enums\ReceiptStatus::OCR_PROCESSING => 'bg-sky-100 text-sky-800',
        \App\Enums\ReceiptStatus::OCR_REVIEW => 'bg-amber-100 text-amber-800',
        \App\Enums\ReceiptStatus::VERIFIED => 'bg-emerald-100 text-emerald-800',
        \App\Enums\ReceiptStatus::REJECTED => 'bg-rose-100 text-rose-800',
    };

    $label = match ($status) {
        \App\Enums\ReceiptStatus::OCR_PENDING => 'Queued',
        \App\Enums\ReceiptStatus::OCR_PROCESSING => 'Processing',
        \App\Enums\ReceiptStatus::OCR_REVIEW => 'Needs review',
        \App\Enums\ReceiptStatus::VERIFIED => 'Verified',
        \App\Enums\ReceiptStatus::REJECTED => 'Rejected',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-block rounded-full px-2 py-0.5 text-xs font-medium {$classes}"]) }}>
    {{ $label }}
</span>
