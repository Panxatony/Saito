#!/bin/sh
#
# Run the PHPUnit suite against a throwaway MariaDB container.
#
#     sh scripts/test-with-mariadb.sh               # whole suite
#     sh scripts/test-with-mariadb.sh --filter Foo  # extra arguments go to phpunit
#
# The suite needs a real MySQL/MariaDB (tests/bootstrap.php builds the schema by
# running the migrations), and pointing it at a database that holds anything
# else is how test data ends up somewhere it should not. This starts a fresh
# mariadb:11 on 127.0.0.1:33306, runs phpunit, and removes the container again,
# also when phpunit fails or the run is interrupted.
#
# The whole suite takes about six minutes.

set -eu

NAME="saito-testdb"
PORT="33306"

cleanup() {
    docker rm -f "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

cleanup
docker run -d --name "$NAME" -p "127.0.0.1:$PORT:3306" \
    -e MARIADB_ROOT_PASSWORD=root \
    -e MARIADB_DATABASE=saito_test \
    -e MARIADB_USER=saito \
    -e MARIADB_PASSWORD=saito \
    mariadb:11 >/dev/null

printf 'waiting for the database'
i=0
until docker exec "$NAME" mariadb -usaito -psaito -e 'SELECT 1' saito_test >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then
        echo ' — gave up after 120 s' >&2
        exit 1
    fi
    printf '.'
    sleep 2
done
echo ' ready'

DATABASE_TEST_URL="mysql://saito:saito@127.0.0.1:$PORT/saito_test?encoding=utf8mb4&timezone=UTC" \
    vendor/bin/phpunit "$@"
