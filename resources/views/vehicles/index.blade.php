@extends('layouts.app')

@section('title', 'Vehicles')

@section('content')
    <div class="mb-6 flex items-center justify-between gap-3">
        <h1 class="text-lg font-semibold">Vehicles</h1>
        <a href="{{ route('vehicles.manage.create') }}"
           class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
            Add vehicle
        </a>
    </div>

    @if ($vehicles->isEmpty())
        <p class="rounded-md border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
            No vehicles yet. Add one to start recording charges.
        </p>
    @else
        <div class="overflow-x-auto rounded-md border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Vehicle</th>
                        <th class="px-4 py-3">Year</th>
                        <th class="px-4 py-3">Plate</th>
                        <th class="px-4 py-3">Battery</th>
                        <th class="px-4 py-3">Sessions</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
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
