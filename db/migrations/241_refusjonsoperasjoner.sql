-- Varig operasjon lagres foer Vipps-kallet, saa et usikkert svar kan proeves
-- igjen med samme idempotensnoekkel uten en ny refusjon.
CREATE TABLE IF NOT EXISTS payment_refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  before_ore INT NOT NULL,
  amount_ore INT NOT NULL,
  client_operation_id VARCHAR(64) NULL,
  requested_ore INT NOT NULL DEFAULT 0,
  result_refunded_ore INT NULL,
  result_remaining_ore INT NULL,
  status ENUM('pending','done') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY payment_offset (payment_id, before_ore),
  UNIQUE KEY client_operation (payment_id, client_operation_id),
  KEY pending_payment (payment_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
