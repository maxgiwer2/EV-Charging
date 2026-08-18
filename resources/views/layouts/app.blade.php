<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>

    {{-- Receipt pages show private financial documents; keep them out of
         shared caches and out of search results. --}}
    <meta name="robots" content="noindex, nofollow">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

<nav class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
        <a href="{{ route('dashboard') }}" class="text-sm font-semibold tracking-tight">
            {{ config('app.name') }}
        </a>

        @auth
            <div class="flex items-center gap-4 text-sm">
                <a href="{{ route('dashboard') }}" class="text-slate-600 hover:text-slate-900">
                    Dashboard
                </a>
                <a href="{{ route('receipts.review.index') }}" class="text-slate-600 hover:text-slate-900">
                    Receipts
                </a>
                <a href="{{ route('vehicles.manage.index') }}" class="text-slate-600 hover:text-slate-900">
                    Vehicles
                </a>
                <a href="{{ route('sessions.quick-entry') }}"
                   class="rounded-md bg-slate-900 px-2.5 py-1 text-white hover:bg-slate-700">
                    + Add
                </a>
                <span class="hidden text-slate-400 sm:inline">
                    {{ auth()->user()->name }} ({{ auth()->user()->role->label() }})
                </span>
                <form method="POST" action="{{ route('web.logout') }}">
                    @csrf
                    <button type="submit" class="text-slate-600 hover:text-slate-900">Sign out</button>
                </form>
            </div>
        @endauth
    </div>
</nav>

<main class="mx-auto max-w-6xl px-4 py-8">
    @if (session('status'))
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ session('error') }}
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
