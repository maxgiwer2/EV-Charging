@extends('layouts.app')

@section('title', 'Upload receipt')

@section('content')
    <div class="mx-auto max-w-lg">
        <h1 class="mb-1 text-lg font-semibold">Upload receipt</h1>
        <p class="mb-6 text-sm text-slate-500">
            JPG, PNG, WEBP or PDF, up to {{ $maxSizeMb }} MB.
            Nothing is recorded until you confirm the values.
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

        @unless ($ocrEnabled)
            {{-- Say so plainly rather than letting the user wonder why every
                 field came back empty. --}}
            <div class="mb-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                No OCR provider is configured, so values will not be extracted automatically.
                You will enter them yourself on the review screen.
            </div>
        @endunless

        <form method="POST" action="{{ route('receipts.upload.store') }}" enctype="multipart/form-data"
              x-data="{
                  name: null,
                  preview: null,
                  pick(event) {
                      const file = event.target.files[0];
                      if (!file) { this.name = null; this.preview = null; return; }
                      this.name = file.name;
                      // Local preview only; the file is not sent until submit.
                      this.preview = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
                  }
              }"
              class="space-y-4 rounded-md border border-slate-200 bg-white p-5">
            @csrf

            <div>
                <label for="file"
                       class="flex cursor-pointer flex-col items-center justify-center rounded-md border-2 border-dashed border-slate-300 px-6 py-10 text-center hover:border-slate-400">
                    <span class="text-sm font-medium text-slate-700">
                        {{-- capture="environment" opens the rear camera on a
                             phone, which is how docs/04 "Scan" is used. --}}
                        Choose a file or take a photo
                    </span>
                    <span class="mt-1 text-xs text-slate-500" x-text="name || 'No file selected'"></span>
                    <input id="file" name="file" type="file" required class="sr-only"
                           accept="{{ $accept }}" capture="environment"
                           @change="pick($event)">
                </label>
            </div>

            <template x-if="preview">
                <img :src="preview" alt="Selected receipt"
                     class="max-h-72 w-full rounded border border-slate-200 object-contain">
            </template>

            <div class="flex items-center gap-3 border-t border-slate-200 pt-4">
                <button type="submit"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Upload
                </button>
                <a href="{{ route('sessions.quick-entry') }}"
                   class="inline-flex min-h-11 items-center px-2 text-sm text-slate-600 hover:underline">
                    No receipt? Quick add
                </a>
            </div>
        </form>
    </div>
@endsection
