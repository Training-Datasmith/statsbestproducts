#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
if [ "$USE_SUDO_DOCKER" = "1" ]; then
  DOCKER_BIN="sudo docker"
fi

PHP_IMAGE="php@sha256:6ce95208609dc66df163ab936c970b3b34cd901b85c747102c5999f08ade9143"
MARIADB_IMAGE="mariadb@sha256:8f96ddc0393e48416ef285acfec62a7c40be760968a72a6144395cccec8a1c24"
PHP_LOCAL_TAG="statsbest-php56:local"
CONTAINER_NAME="statsbest-mariadb-$$"

WORKDIR="$(mktemp -d)"
chmod 0700 "$WORKDIR"
cleanup() {
  $DOCKER_BIN rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  rm -rf "$WORKDIR"
}
trap cleanup EXIT

curl --silent --show-error --fail --output "$WORKDIR/gpg.asc" https://sebastian-bergmann.de/gpg.asc
curl --silent --show-error --fail --output "$WORKDIR/phpunit-4.8.36.phar" https://phar.phpunit.de/phpunit-4.8.36.phar
curl --silent --show-error --fail --output "$WORKDIR/phpunit-4.8.36.phar.asc" https://phar.phpunit.de/phpunit-4.8.36.phar.asc

GNUPGHOME="$(mktemp -d)"
chmod 0700 "$GNUPGHOME"
gpg --homedir "$GNUPGHOME" --batch --import "$WORKDIR/gpg.asc"
echo "ab8bc3017a64cf75a2112f5e089a1d5b0c4cc2116556d60c1af5e3f584e85c5e  $WORKDIR/phpunit-4.8.36.phar" | sha256sum -c -
gpg --homedir "$GNUPGHOME" --batch --status-fd 1 --verify "$WORKDIR/phpunit-4.8.36.phar.asc" "$WORKDIR/phpunit-4.8.36.phar" >"$WORKDIR/gpg-status.txt"
awk '$1=="[GNUPG:]" && $2=="VALIDSIG" && $NF=="D8406D0D82947747293778314AA394086372C20A" { ok=1 } END { exit !ok }' "$WORKDIR/gpg-status.txt"
rm -rf "$GNUPGHOME"

$DOCKER_BIN rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
$DOCKER_BIN run -d --name "$CONTAINER_NAME" \
  -e MARIADB_ROOT_PASSWORD=statsbest \
  -e MARIADB_DATABASE=statsbest \
  "$MARIADB_IMAGE" \
  --bind-address=127.0.0.1 >/dev/null

deadline=$((SECONDS + 60))
until $DOCKER_BIN exec "$CONTAINER_NAME" mysqladmin --protocol=TCP -h 127.0.0.1 -uroot -pstatsbest ping --silent; do
  if [ "$SECONDS" -ge "$deadline" ]; then
    echo "mariadb did not become ready" >&2
    exit 1
  fi
  sleep 1
done

DOCKERFILE_DIR="$(mktemp -d)"
chmod 0700 "$DOCKERFILE_DIR"
cat > "$DOCKERFILE_DIR/Dockerfile" <<'EOF'
FROM php@sha256:6ce95208609dc66df163ab936c970b3b34cd901b85c747102c5999f08ade9143
RUN sed -i 's/deb.debian.org/archive.debian.org/g; s|security.debian.org|archive.debian.org|g' /etc/apt/sources.list \
    && sed -i '/stretch-updates/d' /etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "false";\nAcquire::AllowInsecureRepositories "true";\nAcquire::AllowDowngradeToInsecureRepositories "true";\n' > /etc/apt/apt.conf.d/99archive \
    && apt-get update \
    && apt-get install -y --no-install-recommends --allow-unauthenticated \
       libpng-dev zlib1g-dev gnupg ca-certificates \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
EOF
$DOCKER_BIN build -t "$PHP_LOCAL_TAG" "$DOCKERFILE_DIR"
rm -rf "$DOCKERFILE_DIR"

$DOCKER_BIN run --rm --network "container:$CONTAINER_NAME" \
  -v "$ROOT":/src:ro \
  -v "$WORKDIR/phpunit-4.8.36.phar":/tmp/phpunit-4.8.36.phar:ro \
  -w /src \
  -e DB_HOST=127.0.0.1 \
  -e DB_PORT=3306 \
  -e DB_USER=root \
  -e DB_PASSWORD=statsbest \
  -e DB_NAME=statsbest \
  -e STATS_TEST_SEED="${STATS_TEST_SEED:-20261108}" \
  "$PHP_LOCAL_TAG" \
  php -d error_reporting=-1 -d display_errors=1 -d display_startup_errors=1 \
    /src/tests/run-suite.php /tmp/phpunit-4.8.36.phar
