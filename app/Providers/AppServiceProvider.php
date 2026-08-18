<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Contracts\OcrProviderInterface;
use App\Models\ChargingConnector;
use App\Models\ChargingNetwork;
use App\Models\ChargingSession;
use App\Models\ChargingStation;
use App\Models\ChargingTariff;
use App\Models\Receipt;
use App\Models\Vehicle;
use App\Policies\ChargingConnectorPolicy;
use App\Policies\ChargingNetworkPolicy;
use App\Policies\ChargingSessionPolicy;
use App\Policies\ChargingStationPolicy;
use App\Policies\ChargingTariffPolicy;
use App\Policies\ReceiptPolicy;
use App\Policies\VehiclePolicy;
use App\Services\Ai\AiProviderManager;
use App\Services\Ocr\OcrProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Domain code depends on the interface; the concrete adapter is chosen
        // by config('ocr.driver') so no vendor SDK reaches the receipt logic
        // (architecture/system-architecture.md -> OCR Provider Adapter).
        $this->app->singleton(OcrProviderManager::class);

        $this->app->bind(
            OcrProviderInterface::class,
            fn ($app): OcrProviderInterface => $app->make(OcrProviderManager::class)->driver(),
        );

        // Same pattern for AI: the assistant depends on the interface, so the
        // provider can be swapped without touching domain code.
        $this->app->singleton(AiProviderManager::class);

        $this->app->bind(
            AiProviderInterface::class,
            fn ($app): AiProviderInterface => $app->make(AiProviderManager::class)->driver(),
        );
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureRateLimiting();
        $this->configurePolicies();
    }

    private function configureModels(): void
    {
        // Fail loudly when code reads an attribute that was not loaded, or
        // assigns one that is not fillable. Both are silent data bugs
        // otherwise, and on financial records a silently dropped attribute is
        // a wrong total. Disabled outside local/testing so a missed edge case
        // never takes production down.
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal() || $this->app->runningUnitTests());
        Model::preventAccessingMissingAttributes($this->app->isLocal() || $this->app->runningUnitTests());

        // N+1 detection in development (docs/03 -> avoid N+1 queries).
        Model::preventLazyLoading($this->app->isLocal() || $this->app->runningUnitTests());
    }

    private function configureRateLimiting(): void
    {
        // General API budget, per authenticated user (or per IP when
        // unauthenticated) -- docs/03 -> rate limiting.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) (Auth::id() ?? $request->ip())));

        // Login is throttled far harder and keyed on email+IP, so an attacker
        // cannot spread a password-guessing run across the general budget.
        // A local model takes seconds per call, so the assistant gets its own
        // small budget rather than draining the general API allowance.
        RateLimiter::for('assistant', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) (Auth::id() ?? $request->ip())));

        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
    }

    private function configurePolicies(): void
    {
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(ChargingSession::class, ChargingSessionPolicy::class);
        Gate::policy(ChargingNetwork::class, ChargingNetworkPolicy::class);
        Gate::policy(ChargingStation::class, ChargingStationPolicy::class);
        Gate::policy(ChargingConnector::class, ChargingConnectorPolicy::class);
        Gate::policy(Receipt::class, ReceiptPolicy::class);
        Gate::policy(ChargingTariff::class, ChargingTariffPolicy::class);
    }
}
