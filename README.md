# The Scare Path

A browser-based P2P multiplayer Halloween game built for classic PHP/MySQL hosting.

## Local Development with Docker Compose

This repository includes a Docker Compose setup for a local test environment.

### Start the environment

```bash
docker compose up -d --build
```

### Services

- `web` - PHP 8.2 + Apache serving the game files
- `db` - MySQL 8.0 for user authentication and WebRTC signaling
- `phpmyadmin` - phpMyAdmin UI on port `8081`

### Access URLs

- App: `http://localhost:8080/lobby.php`
- phpMyAdmin: `http://localhost:8081`

## Database Setup

After Docker is running, import the schema and sample data into MySQL.

The `db.sql` file is already available inside the web container at `/var/www/html/db.sql` because the repository is mounted.

```bash
docker compose exec db mysql -u root -psecret scarepath < /var/www/html/db.sql
```

If the schema import fails, you can run the SQL file from phpMyAdmin using the import UI.

## Local Test Credentials

A sample Scarer account is included in `db.sql`:

- username: `scarer`
- password: `scarer123`

## Files of Interest

- `docker-compose.yml` - local environment definition
- `Dockerfile` - PHP image configuration with PDO/MySQL support
- `db.sql` - schema and sample Scarer account
- `lobby.php` - Scarer dashboard and public lobby UI
- `signal.php` - PHP signaling endpoint for WebRTC SDP/ICE exchange
- `config.php` - shared DB configuration and helper
- `game.js` - host/peer WebRTC engine and gameplay flow

## Quick Start

1. Run `docker compose up -d --build`
2. Open `http://localhost:8080/lobby.php`
3. Login as Scarer and create a room
4. Open a second browser or incognito window, join the room as a walker
5. Use arrow keys to move and watch for scare events

## Troubleshooting

If the app does not load, verify the containers are running and healthy:

```bash
docker compose ps
```

If MySQL is not ready yet, inspect logs:

```bash
docker compose logs db
```

If the web container cannot connect to the database, the `db` service should show as healthy and the `web` service should be able to reach the host `db`.
