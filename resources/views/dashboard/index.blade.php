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

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Dashboard</h1>
            <p class="mt-0.5 text-sm text-slate-500">
                @if ($periodStart)
                    {{ $periodStart->format('d M') }} &ndash; {{ $periodEnd->format('d M Y') }}
                @else
                    All time
                @endif
            </p>
        </div>

        {{-- Filter and exports collapse behind a disclosure on phones: they are
             occasional actions and were pushing the figures below the fold. --}}
        <details class="w-full sm:w-auto" @if(request()->hasAny(['from','to'])) open @endif>
            <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 active:bg-slate-50">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 6h16M7 12h10M10 18h4" />
                </svg>
                Period &amp; export
            </summary>

            <div class="mt-2 space-y-3 rounded-md border border-slate-200 bg-white p-3 sm:absolute sm:right-4 sm:z-20 sm:w-80 sm:shadow-lg">
                <form method="GET" class="space-y-2">
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block text-xs font-medium text-slate-600">
                            From
                            <input type="date" name="from" value="{{ $periodStart?->format('Y-m-d') }}"
                                   class="mt-1 block min-h-11 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </label>
                        <label class="block text-xs font-medium text-slate-600">
                            To
                            <input type="date" name="to" value="{{ $filter->to?->copy()->timezone($tz)->format('Y-m-d') }}"
                                   class="mt-1 block min-h-11 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </label>
                    </div>
                    <button type="submit"
                            class="min-h-11 w-full rounded-md bg-slate-900 px-3 text-sm font-medium text-white active:bg-slate-700">
                        Apply
                    </button>
                </form>

                <div class="border-t border-slate-100 pt-3">
                    <p class="mb-2 text-xs font-medium text-slate-600">Export this period</p>
                    {{-- Exports carry the same filter, so the file matches what
                         is on screen (AT-008). --}}
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
                            <a href="{{ url('/api/v1/reports/export?'.http_build_query([...$exportQuery, 'format' => $format])) }}"
                               class="flex min-h-11 items-center justify-center rounded-md border border-slate-300 text-sm active:bg-slate-50">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </details>
    </div>

    {{-- Headline figure first. On a phone the one number most people open the
         app for should not be one tile among eight. --}}
    <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4">
        <p class="text-xs uppercase tracking-wide text-slate-500">Total spend</p>
        <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <p class="text-3xl font-semibold tabular-nums">{{ $summary['total_cost'] }}</p>
            @if ($comparison['change']['total_cost_pct'] !== null)
                @php $rising = (float) $comparison['change']['total_cost_pct'] > 0; @endphp
                {{-- Spending more is not automatically bad, so this states a
                     direction rather than a verdict. --}}
                <span class="text-sm {{ $rising ? 'text-amber-700' : 'text-emerald-700' }}">
                    {{ $rising ? '▲' : '▼' }} {{ ltrim($comparison['change']['total_cost_pct'], '-') }}% vs previous
                </span>
            @endif
        </div>
        <p class="mt-1 text-sm text-slate-500">
            {{ $summary['session_count'] }} {{ Str::plural('session', $summary['session_count']) }}
            @if ($summary['total_kwh']) &middot; {{ $summary['total_kwh'] }} kWh @endif
        </p>
    </div>

    {{-- Two columns on a phone rather than one: these are short numbers, and a
         single column pushed everything else off the screen. --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat label="Cost / kWh" :value="$summary['cost_per_kwh']" />
        <x-stat label="Cost / km" :value="$summary['cost_per_km']" />
        <x-stat label="Distance" :value="$summary['total_distance_km']" unit="km" />
        <x-stat label="kWh / 100 km" :value="$summary['kwh_per_100km']" />
    </div>

    {{-- Budget, projection and anything unusual: the things worth acting on. --}}
    <div class="mb-6 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
        @foreach ($budgets as $budget)
            @php
                $used = (float) ($budget['percentage_used'] ?? 0);
                $bar = $budget['is_over_budget'] ? 'bg-rose-600'
                    : ($used >= 80 ? 'bg-amber-500' : 'bg-emerald-600');
            @endphp
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="flex items-baseline justify-between gap-2">
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

                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                    {{-- Capped at 100% width so an overspend does not draw
                         outside the track; the number states the real figure. --}}
                    <div class="h-2 rounded-full {{ $bar }}" style="width: {{ min(100, $used) }}%"></div>
                </div>

                <p class="mt-1.5 text-xs {{ $budget['is_over_budget'] ? 'text-rose-700' : 'text-slate-500' }}">
                    @if ($budget['is_over_budget'])
                        {{ ltrim($budget['remaining'], '-') }} over budget
                    @else
                        {{ $budget['remaining'] }} left &middot; {{ $budget['percentage_used'] }}% used
                    @endif
                </p>
            </div>
        @endforeach

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Projected this month</p>

            @if ($forecast['available'])
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $forecast['projected_total'] }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    {{ $forecast['spent_to_date'] }} over
                    {{ $forecast['elapsed_days'] }} of {{ $forecast['total_days'] }} days
                    @if ($typicalSpend)
                        &middot; typical {{ $typicalSpend }}
                    @endif
                </p>

                {{-- Caveats are shown, not buried: someone comparing this with
                     a budget should know what it rests on. --}}
                @if (! empty($forecast['caveats']))
                    <p class="mt-1 text-xs text-amber-700">
                        {{ implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), $forecast['caveats'])) }}
                    </p>
                @endif
            @else
                {{-- Saying why is more useful than a number resting on nothing. --}}
                <p class="mt-1 text-sm text-slate-400">
                    Not enough data yet
                    <span class="block text-xs">({{ str_replace('_', ' ', (string) $forecast['unavailable_reason']) }})</span>
                </p>
            @endif
        </div>

        @if (! empty($anomalies))
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs uppercase tracking-wide text-amber-800">Unusual charges</p>
                <ul class="mt-2 space-y-1 text-sm text-amber-900">
                    @foreach ($anomalies as $anomaly)
                        @php $a = $anomaly->toArray(); @endphp
                        <li class="flex justify-between gap-2">
                            <span>{{ \Illuminate\Support\Carbon::parse($a['started_at'])->timezone($tz)->format('d M') }}</span>
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

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-white p-4 lg:col-span-2">
            <h2 class="mb-4 text-sm font-semibold text-slate-700">Daily spend</h2>

            @if (empty($trends))
                <p class="py-8 text-center text-sm text-slate-500">No charging recorded in this period.</p>
            @else
                {{-- A plain CSS bar chart: one series needs no charting library,
                     and it stays readable without JavaScript. --}}
                <div class="flex h-32 items-end gap-1 sm:h-40">
                    @foreach ($trends as $point)
                        <div class="flex flex-1 flex-col justify-end" style="height: 100%">
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

        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="mb-4 text-sm font-semibold text-slate-700">By charging type</h2>

            @forelse ($byType as $slice)
                <div class="mb-3">
                    <div class="flex justify-between text-sm">
                        <span>{{ $slice['label'] }}</span>
                        <span class="font-medium tabular-nums">{{ $slice['total_cost'] }}</span>
                    </div>
                    <div class="mt-1 h-1.5 rounded-full bg-slate-100">
                        <div class="h-1.5 rounded-full bg-slate-700"
                             style="width: {{ (float) $summary['total_cost'] > 0 ? min(100, (float) $slice['total_cost'] / (float) $summary['total_cost'] * 100) : 0 }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">No data.</p>
            @endforelse

            @if (! empty($byStation))
                <h2 class="mb-3 mt-6 text-sm font-semibold text-slate-700">Top stations</h2>
                <ul class="space-y-1.5 text-sm">
                    @foreach ($byStation as $station)
                        <li class="flex justify-between gap-2">
                            <span class="truncate text-slate-600">{{ $station['label'] }}</span>
                            <span class="whitespace-nowrap font-medium tabular-nums">{{ $station['total_cost'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700">
            Recent sessions
        </h2>

        @if (empty($recent))
            <p class="px-4 py-8 text-center text-sm text-slate-500">Nothing confirmed in this period.</p>
        @else
            {{-- Phones get cards, not the table. The table is 15 columns and
                 877px wide: in a 359px viewport 518px of every row was off
                 screen, so the numbers people came for were the ones hidden. --}}
            <ul class="divide-y divide-slate-100 md:hidden">
                @foreach ($recent as $row)
                    <li class="px-4 py-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium">{{ $row['started_at'] }}</span>
                            <span class="text-base font-semibold tabular-nums">{{ $row['total_amount'] }}</span>
                        </div>

                        <p class="mt-0.5 truncate text-sm text-slate-600">
                            {{ $row['station'] ?? 'Home / unlisted' }}
                            @if ($row['charging_mode'])
                                <span class="text-slate-400">&middot; {{ $row['charging_mode'] }}</span>
                            @endif
                        </p>

                        <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-slate-500">
                            <span>{{ $row['energy_kwh'] ?? '—' }} kWh</span>
                            <span>{{ $row['distance_km'] ?? '—' }} km</span>
                            {{-- An em dash where a metric could not be computed;
                                 docs/06 requires that to differ from zero. --}}
                            <span>{{ $row['cost_per_kwh'] ?? '—' }} /kWh</span>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            @foreach ($columns as $heading)
                                <th scope="col" class="whitespace-nowrap px-3 py-2">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recent as $row)
                            <tr>
                                @foreach (array_keys($columns) as $key)
                                    <td class="whitespace-nowrap px-3 py-2">{{ $row[$key] ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
