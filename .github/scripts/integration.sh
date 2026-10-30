#!/bin/bash
# Usage: .github/scripts/integration.sh <mautic image tag> <mysql image>
# Installs Mautic from the official Docker image, adds the plugin as the release zip has it (git archive),
# reloads the plugins and runs Tests/Integration/smoke.php, including the condition with the sandbox key.
set -euo pipefail
TAG=$1
DB=$2
N=eev-it
ROOT=$(git rev-parse --show-toplevel)
WORK=$(mktemp -d)

cleanup() { docker rm -f "$N-web" "$N-db" >/dev/null 2>&1 || true; docker network rm "$N" >/dev/null 2>&1 || true; }
cleanup
trap cleanup EXIT

docker network create "$N" >/dev/null
docker run -d --name "$N-db" --network "$N" -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=mautic "$DB" >/dev/null
docker run -d --name "$N-web" --network "$N" --entrypoint sleep "mautic/mautic:$TAG" infinity >/dev/null
for _ in $(seq 90); do docker exec "$N-db" mysqladmin ping -uroot -proot --silent >/dev/null 2>&1 && break; sleep 2; done
sleep 5

web() { docker exec -u www-data -w /var/www/html "$N-web" "$@"; }
docker exec "$N-web" sh -c 'mkdir -p /var/www/html/var/logs /var/www/html/var/cache && chown -R www-data:www-data /var/www/html/var /var/www/html/config'

web sh -c 'php -v | head -1; grep -o "\"version\": *\"[^\"]*\"" docroot/app/release_metadata.json'

echo "::group::Install Mautic"
web php bin/console mautic:install http://localhost --force \
  --db_driver=pdo_mysql --db_host="$N-db" --db_port=3306 --db_name=mautic \
  --db_user=root --db_password=root --db_backup_tables=false \
  --admin_username=admin --admin_email=admin@example.com --admin_password=CI-Admin-123 \
  --admin_firstname=CI --admin_lastname=Admin | tee "$WORK/install.log"
grep -q 'Install complete' "$WORK/install.log"
echo "::endgroup::"

# Only what is logged from here on is checked: Mautic's installer logs errors of its own
logged=$(web sh -c 'cat var/logs/*.php 2>/dev/null | wc -l')

echo "Add the plugin as in the release zip"
mkdir -p "$WORK/MauticEasyBundle"
git -C "$ROOT" archive HEAD | tar -x -C "$WORK/MauticEasyBundle"
docker cp "$WORK/MauticEasyBundle" "$N-web:/var/www/html/docroot/plugins/"
docker exec "$N-web" chown -R www-data:www-data /var/www/html/docroot/plugins/MauticEasyBundle

echo "cache:clear and mautic:plugins:reload"
web php bin/console cache:clear
web php bin/console mautic:plugins:reload
listeners=$(web php bin/console debug:event-dispatcher mautic.plugin.easy.on_email_verification_condition)
grep -q 'CheckEmailVerificationCondition::onConditionValidate' <<<"$listeners" || { echo "$listeners"; exit 1; }

expected=$(php -r '$c = include $argv[1]; echo $c["version"];' "$ROOT/Config/config.php")
installed=$(docker exec "$N-db" mysql -uroot -proot mautic -N -e "select version from plugins where bundle = 'MauticEasyBundle' and is_missing = 0" 2>/dev/null)
echo "plugin version: expected $expected, installed '$installed'"
test "$installed" = "$expected"

echo "Smoke test"
docker cp "$ROOT/Tests/Integration/smoke.php" "$N-web:/tmp/smoke.php"
web php /tmp/smoke.php /var/www/html --sandbox

# The smoke test logs one API error on purpose (wrong API key); anything else fails the job
web sh -c 'cat var/logs/*.php 2>/dev/null' | tail -n +"$((logged + 1))" > "$WORK/plugin.log"
if grep -E 'CRITICAL|ERROR' "$WORK/plugin.log" | grep -v 'API error (HTTP 401)'; then
  echo "Unexpected errors in Mautic's log"
  exit 1
fi
