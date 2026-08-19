@extends('layouts.app')

@section('title', $vehicle->exists ? 'Edit vehicle' : 'Add vehicle')

@section('content')
    @php $editing = $vehicle->exists; @endphp

    <div class="mx-auto max-w-lg">
        <h1 class="mb-6 text-lg font-semibold">{{ $editing ? 'Edit vehicle' : 'Add vehicle' }}</h1>

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
              action="{{ $editing ? route('vehicles.manage.update', $vehicle) : route('vehicles.manage.store') }}"
              class="space-y-4 rounded-md border border-slate-200 bg-white p-5">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="make" class="block text-sm font-medium text-slate-700">Make</label>
                    <input id="make" name="make" type="text" required maxlength="100"
                           value="{{ old('make', $vehicle->make) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
                <div>
                    <label for="model" class="block text-sm font-medium text-slate-700">Model</label>
                    <input id="model" name="model" type="text" required maxlength="100"
                           value="{{ old('model', $vehicle->model) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
                <div>
                    <label for="trim" class="block text-sm font-medium text-slate-700">Trim</label>
                    <input id="trim" name="trim" type="text" maxlength="100"
                           value="{{ old('trim', $vehicle->trim) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
                <div>
                    <label for="model_year" class="block text-sm font-medium text-slate-700">Year</label>
                    <input id="model_year" name="model_year" type="number" min="1990" max="{{ (int) date('Y') + 1 }}"
                           value="{{ old('model_year', $vehicle->model_year) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
                <div>
                    <label for="plate_no" class="block text-sm font-medium text-slate-700">Plate</label>
                    <input id="plate_no" name="plate_no" type="text" maxlength="30"
                           value="{{ old('plate_no', $vehicle->plate_no) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
                <div>
                    <label for="vin" class="block text-sm font-medium text-slate-700">VIN</label>
                    <input id="vin" name="vin" type="text" maxlength="100"
                           value="{{ old('vin', $vehicle->vin) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
            </div>

            <div>
                <label for="battery_kwh" class="block text-sm font-medium text-slate-700">
                    Usable battery (kWh)
                </label>
                <input id="battery_kwh" name="battery_kwh" type="number" step="0.001" min="0.001"
                       value="{{ old('battery_kwh', $vehicle->battery_kwh) }}"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                {{-- Explains why the field is worth filling in: it is what
                     makes a SOC-based energy estimate possible (FR-009). --}}
                <p class="mt-1 text-xs text-slate-500">
                    Lets the system work out energy from a state-of-charge reading when
                    no kWh figure is available. Left blank, energy stays unknown rather
                    than being guessed.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="ac_max_kw" class="block text-sm font-medium text-slate-700">Max AC (kW)</label>
                    <input id="ac_max_kw" name="ac_max_kw" type="number" step="0.01" min="0.01"
                           value="{{ old('ac_max_kw', $vehicle->ac_max_kw) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
                <div>
                    <label for="dc_max_kw" class="block text-sm font-medium text-slate-700">Max DC (kW)</label>
                    <input id="dc_max_kw" name="dc_max_kw" type="number" step="0.01" min="0.01"
                           value="{{ old('dc_max_kw', $vehicle->dc_max_kw) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>
            </div>

            <div>
                <label for="initial_odometer_km" class="block text-sm font-medium text-slate-700">
                    Current odometer (km)
                </label>
                <input id="initial_odometer_km" name="initial_odometer_km" type="number" step="0.1" min="0"
                       value="{{ old('initial_odometer_km', $vehicle->initial_odometer_km) }}"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $vehicle->exists ? $vehicle->is_active : true))
                       class="rounded border-slate-300">
                Active
            </label>

            <div class="flex items-center gap-3 border-t border-slate-200 pt-4">
                <button type="submit"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    {{ $editing ? 'Save changes' : 'Add vehicle' }}
                </button>
                <a href="{{ route('vehicles.manage.index') }}"
                   class="inline-flex min-h-11 items-center px-2 text-sm text-slate-600 hover:underline">Cancel</a>
            </div>
        </form>
    </div>
@endsection
