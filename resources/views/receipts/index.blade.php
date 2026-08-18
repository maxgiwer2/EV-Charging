@extends('layouts.app')

@section('title', 'Receipts')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-lg font-semibold">Receipts</h1>

        <div class="flex gap-2 text-sm">
            <a href="{{ route('receipts.review.index') }}"
               class="rounded-md border px-3 py-1.5 {{ request('filter') ? 'border-slate-300 bg-white text-slate-700' : 'border-slate-900 bg-slate-900 text-white' }}">
                All
            </a>
            <a href="{{ route('receipts.review.index', ['filter' => 'awaiting']) }}"
               class="rounded-md border px-3 py-1.5 {{ request('filter') === 'awaiting' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700' }}">
                Awaiting review
            </a>
        </div>
    </div>

    @if ($receipts->isEmpty())
        <p class="rounded-md border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-500">
            No receipts yet.
        </p>
    @else
        <div class="overflow-x-auto rounded-md border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Uploaded</th>
                        <th class="px-4 py-3">File</th>
                        <th class="px-4 py-3">Receipt no.</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Flags</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($receipts as $receipt)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                {{-- Stored UTC, displayed in the configured
                                     local zone (docs/10 rule 7). --}}
                                {{ $receipt->uploaded_at->timezone(config('app.display_timezone'))->format('d M Y H:i') }}
                            </td>
                            <td class="px-4 py-3">{{ $receipt->original_filename }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $receipt->receipt_number ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <x-receipt-status :status="$receipt->status" />
                            </td>
                            <td class="px-4 py-3">
                                @if (! empty($receipt->duplicate_matches))
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                        Possible duplicate
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('receipts.review.show', $receipt) }}"
                                   class="font-medium text-slate-900 hover:underline">
                                    {{ $receipt->status->awaitsReview() ? 'Review' : 'View' }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $receipts->withQueryString()->links() }}
        </div>
    @endif
@endsection
