# 04 User Flows

## Manual Entry
Dashboard -> Add Charging -> select vehicle -> enter station/time/energy/cost -> validate -> calculate -> save -> dashboard update.

## Receipt OCR
Scan/Upload -> file validation -> private storage -> OCR job -> AI parser -> confidence + duplicate detection -> Review -> Edit -> Confirm -> create/update charging session -> dashboard.

## Quick Entry
Quick Add -> vehicle -> station -> kWh -> amount -> date -> save.

## Monthly Review
Dashboard -> month filter -> inspect high-cost sessions -> open receipt -> compare station/network -> export.

## Admin Tariff
Admin -> Tariffs -> create new version -> set effective period -> validate overlap -> publish -> future sessions use current version; historical sessions keep snapshot.
