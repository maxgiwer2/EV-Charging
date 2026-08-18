@props([
    'label',
    'value' => null,
    'unit' => null,
    'delta' => null,
])

{{--
    One KPI tile.

    A null value renders as an em dash, never as 0. docs/06 forbids computing a
    metric with a zero or null denominator, and showing "0.00" for one would
    misreport an unknown as a measured fact.
--}}

<div class="rounded-md border border-slate-200 bg-white p-4">
    <p class="text-xs uppercase tracking-wide text-slate-500">{{ $label }}</p>

    <p class="mt-1 text-xl font-semibold tabular-nums">
        @if ($value === null || $value === '')
            <span class="text-slate-300" title="Not enough data to calculate">&mdash;</span>
        @else
            {{ $value }}@if ($unit)<span class="ml-1 text-sm font-normal text-slate-500">{{ $unit }}</span>@endif
        @endif
    </p>

    @if ($delta !== null)
        @php $rising = (float) $delta > 0; @endphp
        {{-- Spending more is not automatically bad, so this is stated
             neutrally with a direction rather than a good/bad colour. --}}
        <p class="mt-1 text-xs {{ $rising ? 'text-amber-700' : 'text-emerald-700' }}">
            {{ $rising ? '▲' : '▼' }} {{ ltrim($delta, '-') }}% vs previous period
        </p>
    @endif
</div>
