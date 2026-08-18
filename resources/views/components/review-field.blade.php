@props([
    'name',
    'label',
    'low' => false,
    'confidence' => null,
])

{{--
    One field on the review form.

    docs/05 requires low-confidence fields to be highlighted so a reviewer
    knows where to look. The highlight is advisory only: it never blocks
    submission and never decides verification (FR-005, AT-004).

    The confidence percentage is shown as well as the colour, so the cue does
    not depend on colour perception alone.
--}}

<div>
    <div class="flex items-baseline justify-between gap-2">
        <label for="{{ $name }}" class="block text-sm font-medium text-slate-700">
            {{ $label }}
        </label>

        @if ($low)
            <span class="text-xs font-medium text-amber-700">
                @if ($confidence !== null)
                    check &middot; {{ number_format((float) $confidence * 100, 0) }}%
                @else
                    not read
                @endif
            </span>
        @elseif ($confidence !== null)
            <span class="text-xs text-slate-400">{{ number_format((float) $confidence * 100, 0) }}%</span>
        @endif
    </div>

    <div class="{{ $low ? 'rounded-md ring-2 ring-amber-300' : '' }}">
        {{ $slot }}
    </div>

    @error($name)
        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
