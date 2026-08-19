@extends('layouts.app')

@section('title', 'Vehicles')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold tracking-tight">Vehicles</h1>

        <a href="{{ route('vehicles.manage.create') }}"
           class="inline-flex min-h-11 items-center gap-1.5 rounded-md bg-slate-900 px-3 text-sm font-medium text-white active:bg-slate-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="M12 5v14M5 12h14" />
            </svg>
            Add vehicle
        </a>
    </div>

    @if ($vehicles->isEmpty())
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-12 text-center">
            <p class="text-sm text-slate-500">No vehicles yet. Add one to start recording charges.</p>
            <a href="{{ route('vehicles.manage.create') }}"
               class="mt-3 inline-flex min-h-11 items-center rounded-md border border-slate-300 px-4 text-sm font-medium active:bg-slate-50">
                Add a vehicle
            </a>
        </div>
    @else
        {{-- Cards on a phone: the table's six columns left the actions off
             screen, which is where Edit and Remove live. --}}
        <ul class="space-y-3 md:hidden">
            @foreach ($vehicles as $vehicle)
                <li class="rounded-lg border border-slate-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $vehicle->displayName() }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ $vehicle->model_year ?? '—' }}
                                @if ($vehicle->plate_no) &middot; {{ $vehicle->plate_no }} @endif
                            </p>
                        </div>

                        @if ($vehicle->is_active)
                            <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                        @else
                            <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                        @endif
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-slate-500">Battery</dt>
                            <dd class="tabular-nums">{{ $vehicle->battery_kwh ? $vehicle->battery_kwh.' kWh' : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Sessions</dt>
                            <dd class="tabular-nums">{{ $vehicle->charging_sessions_count }}</dd>
                        </div>
                    </dl>

                    <div class="mt-3 flex gap-2 border-t border-slate-100 pt-3">
                        <a href="{{ route('vehicles.manage.edit', $vehicle) }}"
                           class="flex min-h-11 flex-1 items-center justify-center rounded-md border border-slate-300 text-sm font-medium active:bg-slate-50">
                            Edit
                        </a>
                        <form method="POST" action="{{ route('vehicles.manage.destroy', $vehicle) }}" class="flex-1"
                              onsubmit="return confirm('Remove this vehicle? Past charging sessions are kept.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="min-h-11 w-full rounded-md border border-rose-300 text-sm font-medium text-rose-700 active:bg-rose-50">
                                Remove
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="hidden overflow-x-auto rounded-lg border border-slate-200 bg-white md:block">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Vehicle</th>
                        <th scope="col" class="px-4 py-3">Year</th>
                        <th scope="col" class="px-4 py-3">Plate</th>
                        <th scope="col" class="px-4 py-3">Battery</th>
                        <th scope="col" class="px-4 py-3">Sessions</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($vehicles as $vehicle)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $vehicle->displayName() }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $vehicle->model_year ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $vehicle->plate_no ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $vehicle->battery_kwh ? $vehicle->battery_kwh.' kWh' : '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $vehicle->charging_sessions_count }}</td>
                            <td class="px-4 py-3">
                                @if ($vehicle->is_active)
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('vehicles.manage.edit', $vehicle) }}"
                                   class="font-medium text-slate-900 hover:underline">Edit</a>

                                <form method="POST" action="{{ route('vehicles.manage.destroy', $vehicle) }}"
                                      class="ml-3 inline"
                                      onsubmit="return confirm('Remove this vehicle? Past charging sessions are kept.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-rose-700 hover:underline">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $vehicles->links() }}</div>
    @endif
@endsection
