<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ChargingConnector;
use App\Models\ChargingNetwork;
use App\Models\ChargingSession;
use App\Models\ChargingStation;
use App\Models\Vehicle;
use App\Policies\ChargingConnectorPolicy;
use App\Policies\ChargingNetworkPolicy;
use App\Policies\ChargingSessionPolicy;
use App\Policies\ChargingStationPolicy;
use App\Policies\VehiclePolicy;
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
        //
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
    }
}
