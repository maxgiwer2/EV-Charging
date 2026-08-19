@extends('layouts.app')

@section('title', $budget->exists ? 'Edit budget' : 'Add budget')

@section('content')
    @php
        $editing = $budget->exists;
        $tz = config('app.display_timezone');
        $defaultStart = now()->timezone($tz)->startOfMonth()->toDateString();
        $defaultEnd = now()->timezone($tz)->endOfMonth()->toDateString();
    @endphp

    <div class="mx-auto max-w-lg">
        <h1 class="mb-6 text-lg font-semibold">{{ $editing ? 'Edit budget' : 'Add budget' }}</h1>

        @if ($errors->any())
            <div class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
              action="{{ $editing ? route('budgets.manage.update', $budget) : route('budgets.manage.store') }}"
              class="space-y-4 rounded-md border border-slate-200 bg-white p-5">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700">Amount</label>
                <input id="amount" name="amount" type="number" step="0.01" min="0.01" required
                       value="{{ old('amount', $budget->amount) }}"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="period" class="block text-sm font-medium text-slate-700">Period</label>
                <select id="period" name="period" required
                        class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    @foreach ($periods as $period)
                        <option value="{{ $period->value }}"
                            @selected(old('period', $budget->period?->value ?? 'MONTHLY') === $period->value)>
                            {{ $period->value }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="period_start" class="block text-sm font-medium text-slate-700">From</label>
                    <input id="period_start" name="period_start" type="date" required
                           value="{{ old('period_start', $budget->period_start?->toDateString() ?? $defaultStart) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
                <div>
                    <label for="period_end" class="block text-sm font-medium text-slate-700">To</label>
                    <input id="period_end" name="period_end" type="date" required
                           value="{{ old('period_end', $budget->period_end?->toDateString() ?? $defaultEnd) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
            </div>

            <div>
                <label for="thresholds_csv" class="block text-sm font-medium text-slate-700">
                    Alert at (%)
                </label>
                <input id="thresholds_csv" name="thresholds_csv" type="text"
                       placeholder="50, 80, 100"
                       value="{{ old('thresholds_csv', implode(', ', $budget->thresholds())) }}"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                <p class="mt-1 text-xs text-slate-500">
                    You are told once per level, the first time spending passes it &mdash;
                    repeat alerts are the ones people learn to ignore.
                    Leave blank for 50, 80 and 100.
                </p>
            </div>

            <div class="flex items-center gap-3 border-t border-slate-200 pt-4">
                <button type="submit"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    {{ $editing ? 'Save changes' : 'Add budget' }}
                </button>
                <a href="{{ route('budgets.manage.index') }}"
                   class="inline-flex min-h-11 items-center px-2 text-sm text-slate-600 hover:underline">Cancel</a>
            </div>
        </form>
    </div>
@endsection
