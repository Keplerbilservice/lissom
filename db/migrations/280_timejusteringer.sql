-- Juster timer (eieren, 20. oktober 2026): verkstedet trekker eller legger
-- til timer paa ett medlem, f.eks. naar noen har vaert der uten aa stemple.
-- Gjelder maaneden den er foert i (norsk tid). Visningen 9AkLi1C6b5AtFhUx19QDj3.
CREATE TABLE IF NOT EXISTS timejusteringer (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id BIGINT UNSIGNED NOT NULL,
  maaned CHAR(7) NOT NULL,
  minutter INT NOT NULL,
  grunn VARCHAR(255) NULL,
  av BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_timejustering_medlem (member_id, maaned),
  CONSTRAINT fk_timejustering_medlem FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
