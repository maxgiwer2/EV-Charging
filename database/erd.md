# Database ERD

> Corrected during M1: `effective_from` / `effective_to` were shown on
> `charging_tariffs`, which contradicted `database/schema.sql` and the
> versioning design. The effective period belongs to `tariff_versions` --
> that is what makes a historical session reproducible (AT-006).

```mermaid
erDiagram
    users ||--o{ charging_sessions : creates
    users ||--o{ receipts : uploads
    users ||--o{ audit_logs : performs
    vehicles ||--o{ charging_sessions : has
    charging_networks ||--o{ charging_stations : operates
    charging_stations ||--o{ charging_connectors : has
    charging_sessions ||--o{ receipts : supported_by
    charging_sessions ||--o{ payments : paid_by
    charging_sessions }o--|| charging_tariffs : uses
    charging_tariffs ||--o{ tariff_versions : versions
    charging_sessions ||--o{ charging_cost_lines : contains
    receipts ||--o{ receipt_ocr_results : produces
    budgets }o--|| users : owned_by
    notifications }o--|| users : sent_to

    users {
      bigint id PK
      string name
      string email UK
      string password_hash
      string role
      datetime created_at
      datetime updated_at
    }

    vehicles {
      bigint id PK
      bigint user_id FK
      string make
      string model
      string trim
      smallint model_year
      string plate_no
      string vin
      decimal battery_kwh
      decimal ac_max_kw
      decimal dc_max_kw
      decimal initial_odometer_km
      boolean is_active
    }

    charging_networks {
      bigint id PK
      string name
      string code UK
      boolean is_active
    }

    charging_stations {
      bigint id PK
      bigint network_id FK
      string name
      string code
      string address
      string province
      decimal latitude
      decimal longitude
      boolean is_active
    }

    charging_connectors {
      bigint id PK
      bigint station_id FK
      string connector_type
      string charging_mode
      decimal max_power_kw
      string status
    }

    charging_tariffs {
      bigint id PK
      bigint network_id FK
      bigint station_id FK
      string name
      string charging_type
      boolean is_active
    }

    tariff_versions {
      bigint id PK
      bigint charging_tariff_id FK
      decimal energy_rate
      decimal service_fee
      decimal parking_fee
      decimal vat_rate
      string time_band
      decimal power_min_kw
      decimal power_max_kw
      datetime effective_from
      datetime effective_to
    }

    charging_sessions {
      bigint id PK
      bigint user_id FK
      bigint vehicle_id FK
      bigint station_id FK
      bigint tariff_version_id FK
      bigint connector_id FK
      datetime started_at
      datetime ended_at
      int duration_minutes
      string charging_type
      string charging_mode
      decimal soc_before
      decimal soc_after
      decimal energy_kwh
      decimal odometer_before_km
      decimal odometer_after_km
      decimal distance_km
      decimal total_amount
      string status
    }

    charging_cost_lines {
      bigint id PK
      bigint charging_session_id FK
      string line_type
      decimal quantity
      decimal unit_price
      decimal amount
    }

    receipts {
      bigint id PK
      bigint charging_session_id FK
      bigint uploaded_by FK
      bigint verified_by FK
      string file_path
      string mime_type
      bigint file_size
      string sha256
      string status
      datetime verified_at
      datetime uploaded_at
      datetime deleted_at
    }

    receipt_ocr_results {
      bigint id PK
      bigint receipt_id FK
      string provider
      string model
      json raw_payload
      json extracted_data
      decimal confidence
      string status
      datetime processed_at
    }

    payments {
      bigint id PK
      bigint charging_session_id FK
      string method
      decimal amount
      string reference_no
      datetime paid_at
    }

    budgets {
      bigint id PK
      bigint user_id FK
      decimal amount
      string period
      date period_start
      date period_end
    }

    notifications {
      bigint id PK
      bigint user_id FK
      string type
      string title
      text body
      datetime read_at
    }

    audit_logs {
      bigint id PK
      bigint user_id FK
      string action
      string entity_type
      bigint entity_id
      json before_data
      json after_data
      string ip_address
      datetime created_at
    }
```
