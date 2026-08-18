-- MySQL 8+
CREATE DATABASE IF NOT EXISTS ev_charging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ev_charging;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user','viewer') NOT NULL DEFAULT 'user',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE vehicles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  make VARCHAR(100) NOT NULL,
  model VARCHAR(100) NOT NULL,
  trim VARCHAR(100) NULL,
  model_year SMALLINT NULL,
  plate_no VARCHAR(30) NULL,
  vin VARCHAR(100) NULL,
  battery_kwh DECIMAL(8,3) NULL,
  ac_max_kw DECIMAL(8,2) NULL,
  dc_max_kw DECIMAL(8,2) NULL,
  initial_odometer_km DECIMAL(12,1) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vehicle_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE charging_networks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(60) NOT NULL UNIQUE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE charging_stations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  network_id BIGINT UNSIGNED NULL,
  name VARCHAR(200) NOT NULL,
  code VARCHAR(100) NULL,
  address VARCHAR(500) NULL,
  province VARCHAR(100) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_station_network(network_id),
  CONSTRAINT fk_station_network FOREIGN KEY (network_id) REFERENCES charging_networks(id)
) ENGINE=InnoDB;

CREATE TABLE charging_connectors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  station_id BIGINT UNSIGNED NOT NULL,
  connector_type VARCHAR(50) NOT NULL,
  charging_mode ENUM('AC','DC','OTHER') NOT NULL,
  max_power_kw DECIMAL(8,2) NULL,
  status ENUM('AVAILABLE','BUSY','OUT_OF_SERVICE','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  CONSTRAINT fk_connector_station FOREIGN KEY (station_id) REFERENCES charging_stations(id)
) ENGINE=InnoDB;

CREATE TABLE charging_tariffs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  network_id BIGINT UNSIGNED NULL,
  station_id BIGINT UNSIGNED NULL,
  name VARCHAR(200) NOT NULL,
  charging_type ENUM('HOME','PUBLIC','WORKPLACE','DESTINATION','FREE','OTHER') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tariff_network FOREIGN KEY (network_id) REFERENCES charging_networks(id),
  CONSTRAINT fk_tariff_station FOREIGN KEY (station_id) REFERENCES charging_stations(id)
) ENGINE=InnoDB;

CREATE TABLE tariff_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  charging_tariff_id BIGINT UNSIGNED NOT NULL,
  energy_rate DECIMAL(10,4) NOT NULL DEFAULT 0,
  service_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  parking_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  vat_rate DECIMAL(6,3) NULL,
  time_band ENUM('NORMAL','PEAK','OFF_PEAK','OTHER') NOT NULL DEFAULT 'NORMAL',
  power_min_kw DECIMAL(8,2) NULL,
  power_max_kw DECIMAL(8,2) NULL,
  effective_from DATETIME NOT NULL,
  effective_to DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tariff_effective(effective_from,effective_to),
  CONSTRAINT fk_tariff_version_tariff FOREIGN KEY (charging_tariff_id) REFERENCES charging_tariffs(id)
) ENGINE=InnoDB;

CREATE TABLE charging_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NULL,
  tariff_version_id BIGINT UNSIGNED NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  charging_type ENUM('HOME','PUBLIC','WORKPLACE','DESTINATION','FREE','OTHER') NOT NULL,
  charging_mode ENUM('AC','DC','OTHER') NULL,
  soc_before DECIMAL(5,2) NULL,
  soc_after DECIMAL(5,2) NULL,
  energy_kwh DECIMAL(10,3) NULL,
  energy_source ENUM('RECEIPT','CHARGER','MANUAL','SOC_ESTIMATE') NULL,
  odometer_before_km DECIMAL(12,1) NULL,
  odometer_after_km DECIMAL(12,1) NULL,
  distance_km DECIMAL(12,1) NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('DRAFT','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  INDEX idx_session_user_date(user_id,started_at),
  INDEX idx_session_vehicle_date(vehicle_id,started_at),
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_session_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_session_station FOREIGN KEY (station_id) REFERENCES charging_stations(id),
  CONSTRAINT fk_session_tariff FOREIGN KEY (tariff_version_id) REFERENCES tariff_versions(id)
) ENGINE=InnoDB;

CREATE TABLE charging_cost_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  charging_session_id BIGINT UNSIGNED NOT NULL,
  line_type VARCHAR(50) NOT NULL,
  quantity DECIMAL(12,3) NULL,
  unit_price DECIMAL(12,4) NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cost_session FOREIGN KEY (charging_session_id) REFERENCES charging_sessions(id)
) ENGINE=InnoDB;

CREATE TABLE receipts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  charging_session_id BIGINT UNSIGNED NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  receipt_number VARCHAR(150) NULL,
  status ENUM('OCR_PENDING','OCR_PROCESSING','OCR_REVIEW','VERIFIED','REJECTED') NOT NULL DEFAULT 'OCR_PENDING',
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipt_hash(sha256),
  CONSTRAINT fk_receipt_session FOREIGN KEY (charging_session_id) REFERENCES charging_sessions(id),
  CONSTRAINT fk_receipt_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE receipt_ocr_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(100) NOT NULL,
  model VARCHAR(150) NULL,
  raw_payload JSON NULL,
  extracted_data JSON NULL,
  confidence DECIMAL(5,4) NULL,
  status ENUM('SUCCESS','PARTIAL','FAILED') NOT NULL,
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ocr_receipt FOREIGN KEY (receipt_id) REFERENCES receipts(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  charging_session_id BIGINT UNSIGNED NOT NULL,
  method VARCHAR(50) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reference_no VARCHAR(150) NULL,
  paid_at DATETIME NULL,
  CONSTRAINT fk_payment_session FOREIGN KEY (charging_session_id) REFERENCES charging_sessions(id)
) ENGINE=InnoDB;

CREATE TABLE budgets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  period ENUM('MONTHLY','YEARLY','CUSTOM') NOT NULL DEFAULT 'MONTHLY',
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_budget_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(60) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  before_data JSON NULL,
  after_data JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_entity(entity_type,entity_id),
  INDEX idx_audit_user_date(user_id,created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
