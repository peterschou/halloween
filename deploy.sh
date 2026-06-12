#!/bin/bash

# Exit immediately if a command exits with a non-zero status
set -e

# Locate the directory where the script is running
BASE_DIR=$(dirname "$(readlink -f "$0")")
CONFIG_PATH="$BASE_DIR/deploy.conf"

# Check if config file exists
if [ ! -f "$CONFIG_PATH" ]; then
    echo "Error: Configuration file $CONFIG_PATH not found."
    exit 1
fi

# Optional: Define the target URL to trigger migration automatically
# MIGRATION_URL="http://your-domain.com/migrate.php?key=migrate_me_halloween&cleanup=1"

# Load the configuration
source "$CONFIG_PATH"

# Check for required variables
if [[ -z "$FTP_HOST" || -z "$FTP_USER" || -z "$FTP_PASS" ]]; then
    echo "Error: Missing FTP credentials in $CONFIG_PATH"
    exit 1
fi

# Check if lftp is installed
if ! command -v lftp &> /dev/null; then
    echo "Error: 'lftp' is not installed. Please install it (e.g., sudo apt install lftp)."
    exit 1
fi

# Prepare directory commands only if REMOTE_DIR is provided
REMOTE_SETUP_CMDS=""
if [ -n "$REMOTE_DIR" ]; then
    REMOTE_SETUP_CMDS="mkdir -p $REMOTE_DIR; cd $REMOTE_DIR"
fi

echo "Starting deployment to $FTP_HOST..."

# Execute lftp
# --reverse (-R): Upload local to remote
# --delete: Optional - remove files on remote that are not present locally
lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<EOF
set net:timeout 10
set net:max-retries 2
$REMOTE_SETUP_CMDS
mirror -R \
  --exclude .git/ \
  --exclude deploy.sh \
  --exclude deploy.conf \
  --exclude Dockerfile \
  --exclude docker-compose.yml \
  --exclude README.md \
  --exclude db_credentials.php \
  $BASE_DIR ./
quit
EOF

echo "Deployment successful!"

if [[ -n "$MIGRATION_URL" ]]; then
    echo "Triggering database migration..."
    curl -s "$MIGRATION_URL"
    echo ""
fi