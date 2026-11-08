#!/usr/bin/env bash
set -euo pipefail

FILTER="${1:?filter required}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
if [ "$USE_SUDO_DOCKER" = "1" ]; then
  DOCKER_BIN="sudo docker"
fi

PHP_LOCAL_TAG="statsbest-php56:local"
CONTAINER_NAME="statsbest-mariadb-proof-$$"
WORKDIR="$(mktemp -d)"
chmod 0700 "$WORKDIR"
cleanup() {
  $DOCKER_BIN rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

curl --silent --show-error --fail --output "$WORKDIR/phpunit-4.8.36.phar" https://phar.phpunit.de/phpunit-4.8.36.phar

MARIADB_IMAGE="mariadb@sha256:8f96ddc0393e48416ef285acfec62a7c40be760968a72a6144395cccec8a1c24"
$DOCKER_BIN run -d --name "$CONTAINER_NAME" \
  -e MARIADB_ROOT_PASSWORD=statsbest \
  -e MARIADB_DATABASE=statsbest \
  "$MARIADB_IMAGE" \
  --bind-address=127.0.0.1 >/dev/null

deadline=$((SECONDS + 60))
until $DOCKER_BIN exec "$CONTAINER_NAME" mysqladmin --protocol=TCP -h 127.0.0.1 -uroot -pstatsbest ping --silent; do
  if [ "$SECONDS" -ge "$deadline" ]; then
    echo "mariadb not ready" >&2
    exit 1
  fi
  sleep 1
done

$DOCKER_BIN run --rm --network "container:$CONTAINER_NAME" \
  -v "$ROOT":/src:ro \
  -v "$WORKDIR/phpunit-4.8.36.phar":/tmp/phpunit-4.8.36.phar:ro \
  -w /src \
  -e DB_HOST=127.0.0.1 \
  -e DB_USER=root \
  -e DB_PASSWORD=statsbest \
  -e DB_NAME=statsbest \
  "$PHP_LOCAL_TAG" \
  php -d error_reporting=-1 /tmp/phpunit-4.8.36.phar \
    --bootstrap /src/tests/bootstrap.php \
    --filter "$FILTER" \
    /src/tests
