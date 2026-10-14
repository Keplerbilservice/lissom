-- PHP/MySQL integration state, owner's requested Domene.no architecture (14.10.2026).
-- Prepared locally; not executed on a live server.
-- No customer, booking, price, payment or notification changes.
CREATE TABLE IF NOT EXISTS mcp_oauth_state (
  kind VARCHAR(32) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  value_json MEDIUMTEXT NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  revoked TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (kind, key_hash),
  KEY ix_mcp_state_expiry (kind, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
