-- Halloween "The Scare Path" multiplayer schema
-- 1) users: authenticated Scarers and optionally walkers
-- 2) game_instances: active Room IDs created by Scarers
-- 3) signaling_queue: WebRTC SDP/ICE exchange for P2P setup

CREATE DATABASE IF NOT EXISTS scarepath CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE scarepath;

-- authenticated users
CREATE TABLE IF NOT EXISTS users (
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
INSERT INTO users (username, password_hash, role) VALUES
  ('scarer', '$2b$12$z0ygFBCBSBfrTmQVQTDI1ej6D8aMGIaaHoVX3G39DIpWjDYGlHjHK', 'scarer');

-- game session metadata used as a lobby and signaling anchor
CREATE TABLE IF NOT EXISTS game_instances (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id VARCHAR(64) NOT NULL UNIQUE,
  host_user_id INT UNSIGNED NOT NULL,
  status ENUM('waiting','active','closed') NOT NULL DEFAULT 'waiting',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  INDEX idx_status (status),
  FOREIGN KEY (host_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- signaling queue for offer/answer/ICE exchange
CREATE TABLE IF NOT EXISTS signaling_queue (
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
  FOREIGN KEY (instance_id) REFERENCES game_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- optional cleanup helper: remove stale queue entries older than 1 hour
CREATE EVENT IF NOT EXISTS ev_cleanup_signaling_queue
ON SCHEDULE EVERY 1 HOUR
DO
  DELETE FROM signaling_queue WHERE created_at < (NOW() - INTERVAL 1 HOUR);
