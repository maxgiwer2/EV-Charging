@props([
    'href',
    'label',
    'active' => false,
])

{{--
    One item in the mobile tab bar.

    The whole tab is the tap target and it is at least 56px tall: the previous
    header links measured 17px, which is well under the 44px minimum in WCAG
    2.5.5 and misses often enough to be annoying on a phone.

    An icon alone would be ambiguous for these destinations, so each keeps its
    label; the icon is decorative and hidden from assistive technology.
--}}

@php
    $icons = [
        'Dashboard' => 'M3 12l9-9 9 9M5 10v10h14V10',
        'Receipts' => 'M6 3h12v18l-3-2-3 2-3-2-3 2V3zm3 5h6M9 12h6',
        'Vehicles' => 'M5 17h14M6 17l1-5 2-4h6l2 4 1 5M7 17v2M17 17v2',
        'Budgets' => 'M12 3v18M8 7h6a2 2 0 010 4H9a2 2 0 000 4h7',
    ];
@endphp

<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => 'flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] '.($active ? 'font-medium text-slate-900' : 'text-slate-500 active:text-slate-900')]) }}>
    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="{{ $active ? '2.2' : '1.7' }}" stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true">
        <path d="{{ $icons[$label] ?? $icons['Dashboard'] }}" />
    </svg>
    {{ $label }}
</a>
