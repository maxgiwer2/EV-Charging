<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChargingMode;
use App\Enums\ChargingType;
use App\Models\ChargingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The filter applied to every analytics query (docs/06 -> Dimensions).
 *
 * Built once from a request and passed around, so the ownership scope and the
 * date window cannot be forgotten by an individual query. AT-008 requires an
 * export to match the filtered records exactly, which only holds if reports and
 * exports share one filter implementation -- hence this object rather than
 * per-endpoint `when()` chains.
 */
final readonly class AnalyticsFilter
{
    public function __construct(
        public ?int $userId = null,
        public ?Carbon $from = null,
        public ?Carbon $to = null,
        public ?int $vehicleId = null,
        public ?int $stationId = null,
        public ?ChargingType $chargingType = null,
        public ?ChargingMode $chargingMode = null,
    ) {}

    /**
     * Build from request input, scoped to what the caller may see.
     *
     * A non-admin is pinned to their own records regardless of any `user_id`
     * they send; an admin may look at one user or at everyone (AT-007).
     */
    public static function fromRequest(Request $request, User $user): self
    {
        $userId = $user->isAdmin()
            ? ($request->filled('user_id') ? $request->integer('user_id') : null)
            : $user->id;

        // The date window defaults to the current month, but only the window:
        // defaulting the whole filter would silently discard vehicle, station
        // and user_id parameters sent without dates.
        $default = self::currentMonth($user);

        return new self(
            userId: $userId,
            from: $request->filled('from')
                ? Carbon::parse($request->string('from')->value())
                : $default->from,
            to: $request->filled('to')
                ? Carbon::parse($request->string('to')->value())
                : $default->to,
            vehicleId: $request->filled('vehicle_id') ? $request->integer('vehicle_id') : null,
            stationId: $request->filled('station_id') ? $request->integer('station_id') : null,
            chargingType: $request->enum('charging_type', ChargingType::class),
            chargingMode: $request->enum('charging_mode', ChargingMode::class),
        );
    }

    /**
     * Default window: the current calendar month in the display timezone, then
     * converted to UTC for the query.
     *
     * The user's month is what they mean by "this month"; querying a UTC month
     * for a Bangkok user would shift the boundary by seven hours and move
     * late-evening charges into the wrong month.
     */
    public static function currentMonth(User $user): self
    {
        $tz = (string) config('app.display_timezone');
        $start = Carbon::now($tz)->startOfMonth();

        return new self(
            userId: $user->isAdmin() ? null : $user->id,
            from: $start->copy()->utc(),
            to: $start->copy()->addMonth()->utc(),
        );
    }

    /**
     * The window immediately before this one, of the same length, for
     * month-on-month comparison (docs/06 -> Comparisons).
     */
    public function previousPeriod(): self
    {
        if ($this->from === null || $this->to === null) {
            return $this;
        }

        $length = $this->from->diffInSeconds($this->to);

        return new self(
            userId: $this->userId,
            from: $this->from->copy()->subSeconds($length),
            to: $this->from->copy(),
            vehicleId: $this->vehicleId,
            stationId: $this->stationId,
            chargingType: $this->chargingType,
            chargingMode: $this->chargingMode,
        );
    }

    /**
     * @param  Builder<ChargingSession>  $query
     * @return Builder<ChargingSession>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->userId !== null, fn (Builder $q): Builder => $q->where('charging_sessions.user_id', $this->userId))
            ->when($this->from !== null, fn (Builder $q): Builder => $q->where('started_at', '>=', $this->from))
            // Exclusive upper bound, so a session exactly on a period boundary
            // is counted once rather than in both adjacent periods.
            ->when($this->to !== null, fn (Builder $q): Builder => $q->where('started_at', '<', $this->to))
            ->when($this->vehicleId !== null, fn (Builder $q): Builder => $q->where('vehicle_id', $this->vehicleId))
            ->when($this->stationId !== null, fn (Builder $q): Builder => $q->where('station_id', $this->stationId))
            ->when($this->chargingType !== null, fn (Builder $q): Builder => $q->where('charging_type', $this->chargingType))
            ->when($this->chargingMode !== null, fn (Builder $q): Builder => $q->where('charging_mode', $this->chargingMode));
    }

    /**
     * Human-readable description, used in export headers so a spreadsheet
     * states what it contains (AT-008).
     *
     * @return array<string, string|null>
     */
    public function describe(): array
    {
        return [
            'from' => $this->from?->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
            'vehicle_id' => $this->vehicleId === null ? null : (string) $this->vehicleId,
            'station_id' => $this->stationId === null ? null : (string) $this->stationId,
            'charging_type' => $this->chargingType?->value,
            'charging_mode' => $this->chargingMode?->value,
        ];
    }
}
