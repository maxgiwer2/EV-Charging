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

    {{-- Budget, projection and anything unusual: the things worth acting on,
         placed above the charts. --}}
    {{-- Always rendered: when a projection is not yet possible the card says
         why, which both explains the gap and tells a new user the feature
         exists. Hiding it would leave them wondering. --}}
    <div class="mb-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($budgets as $budget)
                @php
                    $used = (float) ($budget['percentage_used'] ?? 0);
                    $bar = $budget['is_over_budget'] ? 'bg-rose-600'
                        : ($used >= 80 ? 'bg-amber-500' : 'bg-emerald-600');
                @endphp
                <div class="rounded-md border border-slate-200 bg-white p-4">
                    <div class="flex items-baseline justify-between">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Budget</p>
                        <p class="text-xs text-slate-400">
                            {{ \Illuminate\Support\Carbon::parse($budget['period_start'])->format('d M') }}
                            &ndash;
                            {{ \Illuminate\Support\Carbon::parse($budget['period_end'])->format('d M') }}
                        </p>
                    </div>

                    <p class="mt-1 text-xl font-semibold tabular-nums">
                        {{ $budget['spent'] }}
                        <span class="text-sm font-normal text-slate-500">of {{ $budget['amount'] }}</span>
                    </p>

                    <div class="mt-2 h-2 overflow-hidden rounded bg-slate-100">
                        {{-- Capped at 100% width so an overspend does not draw
                             outside the track; the number states the real figure. --}}
                        <div class="h-2 {{ $bar }}" style="width: {{ min(100, $used) }}%"></div>
                    </div>

                    <p class="mt-1 text-xs {{ $budget['is_over_budget'] ? 'text-rose-700' : 'text-slate-500' }}">
                        @if ($budget['is_over_budget'])
                            {{ ltrim($budget['remaining'], '-') }} over budget
                        @else
                            {{ $budget['remaining'] }} left &middot; {{ $budget['percentage_used'] }}% used
                        @endif
                    </p>
                </div>
            @endforeach

            <div class="rounded-md border border-slate-200 bg-white p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Projected this month</p>

                @if ($forecast['available'])
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ $forecast['projected_total'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $forecast['spent_to_date'] }} spent over
                        {{ $forecast['elapsed_days'] }} of {{ $forecast['total_days'] }} days
                        @if ($typicalSpend)
                            &middot; typical month {{ $typicalSpend }}
                        @endif
                    </p>

                    {{-- Caveats are shown, not buried: someone comparing this
                         with a budget should know what it rests on. --}}
                    @if (! empty($forecast['caveats']))
                        <p class="mt-1 text-xs text-amber-700">
                            {{ implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), $forecast['caveats'])) }}
                        </p>
                    @endif
                @else
                    {{-- Saying why is more useful than showing a number that
                         rests on nothing. --}}
                    <p class="mt-1 text-sm text-slate-400">
                        Not enough data yet
                        <span class="block text-xs">({{ str_replace('_', ' ', (string) $forecast['unavailable_reason']) }})</span>
                    </p>
                @endif
            </div>

            @if (! empty($anomalies))
                <div class="rounded-md border border-amber-200 bg-amber-50 p-4">
                    <p class="text-xs uppercase tracking-wide text-amber-800">Unusual charges</p>
                    <ul class="mt-2 space-y-1 text-sm text-amber-900">
                        @foreach ($anomalies as $anomaly)
                            @php $a = $anomaly->toArray(); @endphp
                            <li class="flex justify-between gap-2">
                                <span>{{ \Illuminate\Support\Carbon::parse($a['started_at'])->timezone(config('app.display_timezone'))->format('d M') }}</span>
                                <span class="font-medium tabular-nums">{{ $a['total_amount'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-amber-700">
                        Higher than your usual &mdash; worth a look, not necessarily wrong.
                    </p>
                </div>
            @endif
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
