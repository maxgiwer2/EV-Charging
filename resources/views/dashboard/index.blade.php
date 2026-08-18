@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $tz = config('app.display_timezone');
        $periodStart = $filter->from?->copy()->timezone($tz);
        $periodEnd = $filter->to?->copy()->timezone($tz)->subSecond();
        $exportQuery = array_filter([
            'from' => $filter->from?->toIso8601String(),
            'to' => $filter->to?->toIso8601String(),
        ]);
        $maxTrend = collect($trends)->max(fn ($t) => (float) $t['total_cost']) ?: 1;
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if ($periodStart)
                    {{ $periodStart->format('d M Y') }} &ndash; {{ $periodEnd->format('d M Y') }}
                @else
                    All time
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" class="flex items-center gap-2 text-sm">
                <input type="date" name="from" value="{{ $periodStart?->format('Y-m-d') }}"
                       class="rounded-md border-slate-300 text-sm shadow-sm">
                <span class="text-slate-400">&ndash;</span>
                <input type="date" name="to" value="{{ $filter->to?->copy()->timezone($tz)->format('Y-m-d') }}"
                       class="rounded-md border-slate-300 text-sm shadow-sm">
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
                    Apply
                </button>
            </form>

            {{-- Exports carry the same filter, so the file matches what is on
                 screen (AT-008). --}}
            @foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
                <a href="{{ url('/api/v1/reports/export?'.http_build_query([...$exportQuery, 'format' => $format])) }}"
                   class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm hover:bg-slate-50">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- KPIs. An em dash marks a metric that cannot be computed from the data,
         which docs/06 requires to be distinct from zero. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Total spend" :value="$summary['total_cost']"
                :delta="$comparison['change']['total_cost_pct']" />
        <x-stat label="Sessions" :value="(string) $summary['session_count']" />
        <x-stat label="Energy" :value="$summary['total_kwh']" unit="kWh" />
        <x-stat label="Distance" :value="$summary['total_distance_km']" unit="km" />
        <x-stat label="Cost / kWh" :value="$summary['cost_per_kwh']" />
        <x-stat label="Cost / km" :value="$summary['cost_per_km']" />
        <x-stat label="kWh / 100 km" :value="$summary['kwh_per_100km']" />
        <x-stat label="km / kWh" :value="$summary['km_per_kwh']" />
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        {{-- Daily spend. A plain CSS bar chart: no charting library is needed
             for a single series, and it stays readable without JavaScript. --}}
        <div class="rounded-md border border-slate-200 bg-white p-4 lg:col-span-2">
            <h2 class="mb-4 text-sm font-semibold text-slate-700">Daily spend</h2>

            @if (empty($trends))
                <p class="py-8 text-center text-sm text-slate-500">No charging recorded in this period.</p>
            @else
                <div class="flex h-40 items-end gap-1">
                    @foreach ($trends as $point)
                        <div class="group relative flex flex-1 flex-col justify-end"
                             style="height: 100%">
                            <div class="rounded-t bg-slate-800"
                                 style="height: {{ max(2, (float) $point['total_cost'] / $maxTrend * 100) }}%"
                                 title="{{ $point['bucket'] }}: {{ $point['total_cost'] }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex justify-between text-xs text-slate-400">
                    <span>{{ $trends[0]['bucket'] }}</span>
                    <span>{{ $trends[count($trends) - 1]['bucket'] }}</span>
                </div>
            @endif
        </div>

        <div class="rounded-md border border-slate-200 bg-white p-4">
            <h2 class="mb-4 text-sm font-semibold text-slate-700">By charging type</h2>

            @forelse ($byType as $slice)
                <div class="mb-3">
                    <div class="flex justify-between text-sm">
                        <span>{{ $slice['label'] }}</span>
                        <span class="font-medium">{{ $slice['total_cost'] }}</span>
                    </div>
                    <div class="mt-1 h-1.5 rounded bg-slate-100">
                        <div class="h-1.5 rounded bg-slate-700"
                             style="width: {{ (float) $summary['total_cost'] > 0 ? min(100, (float) $slice['total_cost'] / (float) $summary['total_cost'] * 100) : 0 }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No data.</p>
            @endforelse

            @if (! empty($byStation))
                <h2 class="mb-3 mt-6 text-sm font-semibold text-slate-700">Top stations</h2>
                <ul class="space-y-1 text-sm">
                    @foreach ($byStation as $station)
                        <li class="flex justify-between">
                            <span class="truncate pr-2 text-slate-600">{{ $station['label'] }}</span>
                            <span class="whitespace-nowrap font-medium">{{ $station['total_cost'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="rounded-md border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">
            Recent sessions
        </h2>

        @if (empty($recent))
            <p class="px-4 py-8 text-center text-sm text-slate-500">Nothing confirmed in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            @foreach ($columns as $heading)
                                <th class="whitespace-nowrap px-3 py-2">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recent as $row)
                            <tr>
                                @foreach (array_keys($columns) as $key)
                                    <td class="whitespace-nowrap px-3 py-2">
                                        {{-- Blank, not zero, when unknown. --}}
                                        {{ $row[$key] ?? '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
