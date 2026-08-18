@extends('layouts.app')

@section('title', 'Quick add')

@section('content')
    <div class="mx-auto max-w-lg">
        <h1 class="mb-1 text-lg font-semibold">Quick add</h1>
        <p class="mb-6 text-sm text-slate-500">
            Record a charge you just finished. It counts toward your totals immediately.
        </p>

        @if ($errors->any())
            <div class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($vehicles->isEmpty())
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Add a vehicle first &mdash;
                <a href="{{ route('vehicles.manage.create') }}" class="font-medium underline">add one now</a>.
            </div>
        @else
            {{-- Alpine keeps the derived total in front of the user as they
                 type. It is a preview only: the stored figure is recomputed
                 server-side by the cost engine, so a tampered field cannot
                 change what is recorded. --}}
            <form method="POST" action="{{ route('sessions.quick-entry.store') }}"
                  x-data="{
                      energy: '{{ old('energy_kwh') }}',
                      unitPrice: '{{ old('unit_price') }}',
                      total: '{{ old('total') }}',
                      get preview() {
                          const e = parseFloat(this.energy), p = parseFloat(this.unitPrice);
                          if (!isFinite(e) || !isFinite(p)) return null;
                          return (e * p).toFixed(2);
                      }
                  }"
                  class="space-y-4 rounded-md border border-slate-200 bg-white p-5">
                @csrf

                <div>
                    <label for="vehicle_id" class="block text-sm font-medium text-slate-700">Vehicle</label>
                    <select id="vehicle_id" name="vehicle_id" required
                            class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}" @selected(old('vehicle_id') == $vehicle->id)>
                                {{ $vehicle->displayName() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="charging_type" class="block text-sm font-medium text-slate-700">Type</label>
                        <select id="charging_type" name="charging_type" required
                                class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            @foreach (\App\Enums\ChargingType::cases() as $type)
                                <option value="{{ $type->value }}" @selected(old('charging_type', 'PUBLIC') === $type->value)>
                                    {{ $type->value }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="charging_mode" class="block text-sm font-medium text-slate-700">AC / DC</label>
                        <select id="charging_mode" name="charging_mode"
                                class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">&mdash;</option>
                            @foreach (\App\Enums\ChargingMode::cases() as $mode)
                                <option value="{{ $mode->value }}" @selected(old('charging_mode') === $mode->value)>
                                    {{ $mode->value }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label for="station_id" class="block text-sm font-medium text-slate-700">Station</label>
                    <select id="station_id" name="station_id"
                            class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <option value="">&mdash; (home or unlisted)</option>
                        @foreach ($stations as $station)
                            <option value="{{ $station->id }}" @selected(old('station_id') == $station->id)>
                                {{ $station->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="started_at" class="block text-sm font-medium text-slate-700">When</label>
                    <input id="started_at" name="started_at" type="datetime-local" required
                           value="{{ old('started_at', $now) }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="energy_kwh" class="block text-sm font-medium text-slate-700">Energy (kWh)</label>
                        <input id="energy_kwh" name="energy_kwh" type="number" step="0.001" min="0" inputmode="decimal"
                               x-model="energy" value="{{ old('energy_kwh') }}"
                               class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    </div>

                    <div>
                        <label for="unit_price" class="block text-sm font-medium text-slate-700">Rate (per kWh)</label>
                        <input id="unit_price" name="unit_price" type="number" step="0.0001" min="0" inputmode="decimal"
                               x-model="unitPrice" value="{{ old('unit_price') }}"
                               class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    </div>
                </div>

                <div>
                    <label for="total" class="block text-sm font-medium text-slate-700">
                        Amount paid
                        <span class="font-normal text-slate-400">&mdash; leave blank to use energy × rate</span>
                    </label>
                    <input id="total" name="total" type="number" step="0.01" min="0" inputmode="decimal"
                           x-model="total" value="{{ old('total') }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">

                    <p x-show="preview && !total" x-cloak class="mt-1 text-xs text-slate-500">
                        Will be recorded as <span class="font-medium" x-text="preview"></span>
                    </p>
                </div>

                <details class="text-sm">
                    <summary class="cursor-pointer text-slate-600">Odometer (optional)</summary>
                    <p class="mt-2 text-xs text-slate-500">
                        Recording distance is what makes cost/km and efficiency available &mdash;
                        without it those metrics stay blank rather than showing zero.
                    </p>
                    <div class="mt-2 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="odometer_before_km" class="block text-xs font-medium text-slate-600">Before (km)</label>
                            <input id="odometer_before_km" name="odometer_before_km" type="number" step="0.1" min="0"
                                   value="{{ old('odometer_before_km') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                        <div>
                            <label for="odometer_after_km" class="block text-xs font-medium text-slate-600">After (km)</label>
                            <input id="odometer_after_km" name="odometer_after_km" type="number" step="0.1" min="0"
                                   value="{{ old('odometer_after_km') }}"
                                   class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                        </div>
                    </div>
                </details>

                <div class="flex items-center gap-3 border-t border-slate-200 pt-4">
                    <button type="submit"
                            class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Save charge
                    </button>
                    <a href="{{ route('receipts.upload') }}" class="text-sm text-slate-600 hover:underline">
                        Have a receipt instead?
                    </a>
                </div>
            </form>
        @endif
    </div>
@endsection
