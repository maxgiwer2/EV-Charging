@extends('layouts.app')

@section('title', 'Budgets')

@section('content')
    <div class="mb-6 flex items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold">Budgets</h1>
            <p class="mt-1 text-sm text-slate-500">
                Spend is measured against confirmed charging sessions, so a budget always
                matches the dashboard.
            </p>
        </div>
        <a href="{{ route('budgets.manage.create') }}"
           class="whitespace-nowrap rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Add budget
        </a>
    </div>

    @if ($budgets->isEmpty())
        <p class="rounded-md border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
            No budgets yet. Set one to get alerts as you approach it.
        </p>
    @else
        <div class="space-y-3">
            @foreach ($budgets as $budget)
                @php
                    $evaluation = $evaluations[$budget->id] ?? null;
                    $used = (float) ($evaluation['percentage_used'] ?? 0);
                    $over = $evaluation['is_over_budget'] ?? false;
                    $bar = $over ? 'bg-rose-600' : ($used >= 80 ? 'bg-amber-500' : 'bg-emerald-600');
                @endphp

                <div class="rounded-md border border-slate-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">
                                {{ $budget->amount }}
                                <span class="text-sm font-normal text-slate-500">
                                    / {{ strtolower($budget->period->value) }}
                                </span>
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ $budget->period_start->format('d M Y') }}
                                &ndash;
                                {{ $budget->period_end->format('d M Y') }}
                                &middot; alerts at {{ implode('%, ', $budget->thresholds()) }}%
                            </p>
                        </div>

                        <div class="flex items-center gap-3 text-sm">
                            <a href="{{ route('budgets.manage.edit', $budget) }}"
                               class="font-medium text-slate-900 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('budgets.manage.destroy', $budget) }}"
                                  onsubmit="return confirm('Remove this budget?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-rose-700 hover:underline">Remove</button>
                            </form>
                        </div>
                    </div>

                    @if ($evaluation)
                        <div class="mt-3 h-2 overflow-hidden rounded bg-slate-100">
                            {{-- Width capped at 100% so an overspend does not draw past
                                 the track; the figure below states the real number. --}}
                            <div class="h-2 {{ $bar }}" style="width: {{ min(100, $used) }}%"></div>
                        </div>

                        <p class="mt-1 text-xs {{ $over ? 'text-rose-700' : 'text-slate-500' }}">
                            {{ $evaluation['spent'] }} spent
                            @if ($over)
                                &middot; {{ ltrim($evaluation['remaining'], '-') }} over budget
                            @else
                                &middot; {{ $evaluation['remaining'] }} left
                                ({{ $evaluation['percentage_used'] }}%)
                            @endif
                        </p>
                    @else
                        {{-- Only budgets covering today are evaluated; a past or future
                             one is shown without a bar rather than with a misleading
                             zero. --}}
                        <p class="mt-3 text-xs text-slate-400">Not the current period.</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $budgets->links() }}</div>
    @endif
@endsection
