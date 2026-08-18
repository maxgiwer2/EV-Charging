# 06 Reporting & Analytics

## KPIs
- total spend
- sessions
- total kWh
- average cost/kWh
- average cost/km
- km/kWh
- kWh/100km
- cost/100km
- distance
- home/public ratio
- AC/DC ratio

## Dimensions
date, month, vehicle, network, station, charging type, AC/DC, peak/off-peak, payment method.

## Comparisons
- current vs previous month
- home vs public
- station ranking
- network ranking
- peak vs off-peak

## Derived Metrics
`cost_per_kwh = total_cost / energy_kwh`
`cost_per_km = total_cost / distance_km`
`kwh_per_100km = energy_kwh / distance_km * 100`
`km_per_kwh = distance_km / energy_kwh`
`cost_per_100km = total_cost / distance_km * 100`

Do not calculate metrics when denominator is zero/null.
