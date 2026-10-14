-- LISSOM TEST ONLY: import only into a new empty dedicated database.
-- No production data, prices, templates, payments or notifications.
USE `rbvapxvz_lissom_mcp_test`;
SET NAMES utf8mb4;
CREATE TABLE members (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 navn VARCHAR(191) NOT NULL DEFAULT '', telefon VARCHAR(32) NULL,
 brukernavn VARCHAR(191) NOT NULL UNIQUE, passord_hash VARCHAR(255) NOT NULL,
 rolle VARCHAR(32) NOT NULL, anonymisert_at DATETIME NULL, siste_innlogging DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE sessions (
 token_hash CHAR(64) PRIMARY KEY, member_id BIGINT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL, siste_bruk DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ip VARBINARY(16) NULL, user_agent VARCHAR(255) NOT NULL DEFAULT '', maate VARCHAR(16) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE courses (
 id BIGINT UNSIGNED PRIMARY KEY, tittel VARCHAR(191) NOT NULL,
 slug VARCHAR(191) NOT NULL, status VARCHAR(32) NOT NULL, bilde VARCHAR(191) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE rate_limits (
 nokkel VARCHAR(191) NOT NULL, vindu_start DATETIME NOT NULL,
 antall BIGINT NOT NULL, PRIMARY KEY(nokkel,vindu_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE audit_log (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, member_id BIGINT UNSIGNED NULL,
 handling VARCHAR(191) NOT NULL, objekt_type VARCHAR(191) NULL, objekt_id BIGINT UNSIGNED NULL,
 detaljer MEDIUMTEXT NULL, ip VARBINARY(16) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO courses VALUES (7,'TEST – syntetisk kurs','syntetisk-testkurs','utkast',NULL);
-- The packaging script appends the actual additive MCP migration 278.
