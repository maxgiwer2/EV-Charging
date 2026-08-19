<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover lets the safe-area insets below work on notched
         phones; without it the bottom bar sits under the home indicator. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>

    {{-- Receipt pages show private financial documents; keep them out of
         shared caches and out of search results. --}}
    <meta name="robots" content="noindex, nofollow">

    {{-- Colours the phone's browser chrome to match the app. --}}
    <meta name="theme-color" content="#0f172a">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">

@php
    // Primary destinations. Shared by the desktop bar and the mobile tab bar so
    // the two can never drift apart.
    $nav = [
        ['route' => 'dashboard', 'label' => 'Dashboard', 'match' => 'dashboard'],
        ['route' => 'receipts.review.index', 'label' => 'Receipts', 'match' => 'receipts*'],
        ['route' => 'vehicles.manage.index', 'label' => 'Vehicles', 'match' => 'vehicles*'],
        ['route' => 'budgets.manage.index', 'label' => 'Budgets', 'match' => 'budgets*'],
    ];
@endphp

<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
        <a href="{{ route('dashboard') }}"
           class="flex min-h-11 items-center text-sm font-semibold tracking-tight">
            {{ config('app.name') }}
        </a>

        @auth
            {{-- Desktop navigation. Hidden on phones, where the bottom tab bar
                 takes over: nav links at the top of a tall page are out of
                 thumb reach. --}}
            <nav class="hidden items-center gap-1 text-sm md:flex">
                @foreach ($nav as $item)
                    @php $active = request()->routeIs($item['route']) || request()->is($item['match']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @if ($active) aria-current="page" @endif
                       class="flex min-h-11 items-center rounded-md px-3 {{ $active ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach

                <a href="{{ route('sessions.quick-entry') }}"
                   class="ml-1 flex min-h-11 items-center rounded-md bg-slate-900 px-3 font-medium text-white hover:bg-slate-700">
                    + Add
                </a>
            </nav>

            {{-- Account menu. Collapsed into a button so the header stays one
                 row at 375px, where six inline links used to wrap. --}}
            <div x-data="{ open: false }" class="relative">
                <button type="button" @click="open = !open" @click.outside="open = false"
                        :aria-expanded="open.toString()" aria-haspopup="menu"
                        class="flex min-h-11 min-w-11 items-center justify-center rounded-full bg-slate-100 px-3 text-sm font-medium text-slate-700 hover:bg-slate-200">
                    <span class="sr-only">Account menu</span>
                    {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                </button>

                <div x-show="open" x-cloak x-transition.opacity role="menu"
                     class="absolute right-0 mt-2 w-56 rounded-md border border-slate-200 bg-white py-1 shadow-lg">
                    <div class="border-b border-slate-100 px-4 py-2">
                        <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ auth()->user()->role->label() }}</p>
                    </div>

                    <form method="POST" action="{{ route('web.logout') }}">
                        @csrf
                        <button type="submit" role="menuitem"
                                class="flex min-h-11 w-full items-center px-4 text-left text-sm text-slate-700 hover:bg-slate-50">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        @endauth
    </div>
</header>

{{-- pb-28 on mobile clears the fixed bottom bar; md:pb-10 drops it again. --}}
<main class="mx-auto max-w-6xl px-4 pb-28 pt-6 md:pb-10">
    @if (session('status'))
        <div role="status"
             class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div role="alert"
             class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ session('error') }}
        </div>
    @endif

    @yield('content')
</main>

@auth
    {{-- Mobile tab bar. The primary action sits in the middle, where a thumb
         naturally rests -- on the old layout "+ Add" was a 17px link at the top
         of a 1800px page. --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
         aria-label="Primary">
        <div class="mx-auto grid max-w-md grid-cols-5">
            @foreach (array_slice($nav, 0, 2) as $item)
                <x-nav-tab :href="route($item['route'])" :label="$item['label']"
                           :active="request()->routeIs($item['route']) || request()->is($item['match'])" />
            @endforeach

            <div class="flex items-center justify-center">
                <a href="{{ route('sessions.quick-entry') }}"
                   class="-mt-5 flex h-14 w-14 items-center justify-center rounded-full bg-slate-900 text-white shadow-lg ring-4 ring-slate-50 active:bg-slate-700">
                    <span class="sr-only">Add a charge</span>
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                    </svg>
                </a>
            </div>

            @foreach (array_slice($nav, 2, 2) as $item)
                <x-nav-tab :href="route($item['route'])" :label="$item['label']"
                           :active="request()->routeIs($item['route']) || request()->is($item['match'])" />
            @endforeach
        </div>
    </nav>
@endauth

</body>
</html>
