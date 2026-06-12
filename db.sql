-- Halloween "The Scare Path" multiplayer schema
-- Table names are prefixed by migrate.php
-- {{PREFIX}} is the placeholder replaced by the configured token

-- authenticated users
DROP TABLE IF EXISTS `{{PREFIX}}users`;
CREATE TABLE `{{PREFIX}}users` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('scarer','walker') NOT NULL DEFAULT 'walker',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sample scarer account for local testing:
-- username: scarer
-- password: scarer123
INSERT INTO `{{PREFIX}}users` (username, password_hash, role) VALUES
  ('scarer', '$2y$12$z0ygFBCBSBfrTmQVQTDI1ej6D8aMGIaaHoVX3G39DIpWjDYGlHjHK', 'scarer');

-- game session metadata used as a lobby and signaling anchor
DROP TABLE IF EXISTS `{{PREFIX}}game_instances`;
CREATE TABLE `{{PREFIX}}game_instances` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id VARCHAR(64) NOT NULL UNIQUE,
  host_user_id INT UNSIGNED NOT NULL,
  status ENUM('waiting','active','closed') NOT NULL DEFAULT 'waiting',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  INDEX idx_status (status),
  FOREIGN KEY (host_user_id) REFERENCES `{{PREFIX}}users`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- signaling queue for offer/answer/ICE exchange
DROP TABLE IF EXISTS `{{PREFIX}}signaling_queue`;
CREATE TABLE `{{PREFIX}}signaling_queue` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  instance_id INT UNSIGNED NOT NULL,
  from_peer VARCHAR(64) NOT NULL,
  to_peer VARCHAR(64) NOT NULL,
  kind ENUM('offer','answer','ice') NOT NULL,
  payload JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  INDEX idx_instance_to_peer (instance_id, to_peer, consumed_at),
  FOREIGN KEY (instance_id) REFERENCES `{{PREFIX}}game_instances`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- optional cleanup helper: remove stale queue entries older than 1 hour
CREATE EVENT IF NOT EXISTS `{{PREFIX}}ev_cleanup_signaling_queue`
ON SCHEDULE EVERY 1 HOUR
DO
  DELETE FROM `{{PREFIX}}signaling_queue` WHERE created_at < (NOW() - INTERVAL 1 HOUR);
