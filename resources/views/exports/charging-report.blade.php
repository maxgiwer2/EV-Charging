<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Charging report</title>
    <style>
        /* dompdf supports a limited CSS subset, so this is deliberately plain:
           no flexbox, no custom fonts, fixed table layout. */
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 14px; margin: 0 0 4px; }
        .meta { font-size: 8px; color: #64748b; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #cbd5e1; padding: 3px 4px; word-wrap: break-word; }
        th { background: #f1f5f9; text-align: left; font-size: 8px; }
        td.num { text-align: right; }
        .summary { margin-bottom: 12px; }
        .summary td { border: none; padding: 2px 8px 2px 0; }
        .summary .label { color: #64748b; }
        .note { margin-top: 8px; font-size: 8px; color: #b45309; }
    </style>
</head>
<body>

<h1>Charging report</h1>

<div class="meta">
    Generated {{ $generatedAt->format('d M Y H:i') }} ({{ config('app.display_timezone') }})
    @if ($filter['from'] ?? null)
        &middot; Period {{ \Illuminate\Support\Carbon::parse($filter['from'])->timezone(config('app.display_timezone'))->format('d M Y') }}
        &ndash; {{ \Illuminate\Support\Carbon::parse($filter['to'])->timezone(config('app.display_timezone'))->subSecond()->format('d M Y') }}
    @endif
</div>

<table class="summary">
    <tr>
        <td class="label">Sessions</td>
        <td>{{ $summary['session_count'] }}</td>
        <td class="label">Total spend</td>
        <td>{{ $summary['total_cost'] }}</td>
        <td class="label">Total energy</td>
        {{-- An em dash, not 0: the figure is unknown, not zero (docs/06). --}}
        <td>{{ $summary['total_kwh'] ?? '—' }} kWh</td>
    </tr>
    <tr>
        <td class="label">Cost / kWh</td>
        <td>{{ $summary['cost_per_kwh'] ?? '—' }}</td>
        <td class="label">Cost / km</td>
        <td>{{ $summary['cost_per_km'] ?? '—' }}</td>
        <td class="label">kWh / 100 km</td>
        <td>{{ $summary['kwh_per_100km'] ?? '—' }}</td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            @foreach ($columns as $heading)
                <th>{{ $heading }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                @foreach (array_keys($columns) as $key)
                    <td class="{{ in_array($key, ['started_at', 'vehicle', 'station', 'network', 'charging_type', 'charging_mode', 'energy_source'], true) ? '' : 'num' }}">
                        {{ $row[$key] ?? '' }}
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

@if ($truncated)
    <p class="note">
        This document was truncated. Export CSV or XLSX for the complete set of records.
    </p>
@endif

</body>
</html>
