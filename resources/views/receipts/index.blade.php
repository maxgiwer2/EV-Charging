@extends('layouts.app')

@section('title', 'Receipts')

@section('content')
    @php $tz = config('app.display_timezone'); @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold tracking-tight">Receipts</h1>

        <a href="{{ route('receipts.upload') }}"
           class="inline-flex min-h-11 items-center gap-1.5 rounded-md bg-slate-900 px-3 text-sm font-medium text-white active:bg-slate-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="M12 5v14M5 12h14" />
            </svg>
            Upload
        </a>
    </div>

    {{-- Segmented filter. Full-width on a phone so both options are easy to
         hit; the old links were 17px tall. --}}
    <div class="mb-4 grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 sm:inline-grid sm:w-auto">
        @php $awaiting = request('filter') === 'awaiting'; @endphp
        <a href="{{ route('receipts.review.index') }}"
           class="flex min-h-11 items-center justify-center rounded-md px-4 text-sm {{ $awaiting ? 'text-slate-600' : 'bg-white font-medium text-slate-900 shadow-sm' }}">
            All
        </a>
        <a href="{{ route('receipts.review.index', ['filter' => 'awaiting']) }}"
           class="flex min-h-11 items-center justify-center rounded-md px-4 text-sm {{ $awaiting ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600' }}">
            Awaiting review
        </a>
    </div>

    @if ($receipts->isEmpty())
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-12 text-center">
            <p class="text-sm text-slate-500">No receipts yet.</p>
            <a href="{{ route('receipts.upload') }}"
               class="mt-3 inline-flex min-h-11 items-center rounded-md border border-slate-300 px-4 text-sm font-medium active:bg-slate-50">
                Upload your first receipt
            </a>
        </div>
    @else
        {{-- The whole row is the link on a phone, rather than a small "Review"
             target at the far right of a scrolling table. --}}
        <ul class="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200 bg-white md:hidden">
            @foreach ($receipts as $receipt)
                <li>
                    <a href="{{ route('receipts.review.show', $receipt) }}"
                       class="flex min-h-16 items-center gap-3 px-4 py-3 active:bg-slate-50">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $receipt->original_filename }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ $receipt->uploaded_at->timezone($tz)->format('d M Y H:i') }}
                                @if ($receipt->receipt_number)
                                    &middot; {{ $receipt->receipt_number }}
                                @endif
                            </p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <x-receipt-status :status="$receipt->status" />
                                @if (! empty($receipt->duplicate_matches))
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                        Possible duplicate
                                    </span>
                                @endif
                            </div>
                        </div>

                        <svg class="h-5 w-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="hidden overflow-x-auto rounded-lg border border-slate-200 bg-white md:block">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Uploaded</th>
                        <th scope="col" class="px-4 py-3">File</th>
                        <th scope="col" class="px-4 py-3">Receipt no.</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Flags</th>
                        <th scope="col" class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($receipts as $receipt)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                {{-- Stored UTC, displayed local (docs/10 rule 7). --}}
                                {{ $receipt->uploaded_at->timezone($tz)->format('d M Y H:i') }}
                            </td>
                            <td class="px-4 py-3">{{ $receipt->original_filename }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $receipt->receipt_number ?? '—' }}</td>
                            <td class="px-4 py-3"><x-receipt-status :status="$receipt->status" /></td>
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
