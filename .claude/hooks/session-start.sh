#!/bin/bash
# Prepares a Claude Code cloud session to run Composer, Pint, PHPStan and Pest here.
#
# PHP 8.4 with pgsql, mbstring, xml and intl is used as found. Only when it is missing does this
# fall back to the ondrej/php PPA, as the main project's hook does. Tests run on Postgres
# (phpunit.xml: host "pgsql", user sail/password, database "testing"); when no server answers at
# "pgsql", a local cluster stands in. Composer falls back to git when GitHub zipballs are refused.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

PHP_VERSION=8.4
PHP_MODULES="pdo_pgsql mbstring xml intl curl zip gd"

php_ready() {
  command -v php >/dev/null 2>&1 || return 1
  php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' || return 1
  local module
  for module in $PHP_MODULES; do
    php -m | grep -qix "$module" || return 1
  done
}

if ! php_ready; then
  echo "PHP $PHP_VERSION with $PHP_MODULES not found; installing from the ondrej/php PPA..." >&2
  packages=""
  for ext in cli common curl gd intl mbstring pgsql xml zip; do
    packages="$packages php$PHP_VERSION-$ext"
  done
  if [ ! -f /etc/apt/sources.list.d/ondrej-php.list ]; then
    install -d /etc/apt/keyrings
    curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C" \
      | gpg --dearmor --yes -o /etc/apt/keyrings/ondrej-php.gpg
    echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] http://ppa.launchpad.net/ondrej/php/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") main" \
      > /etc/apt/sources.list.d/ondrej-php.list
  fi
  apt-get update -qq -o Dir::Etc::sourcelist=sources.list.d/ondrej-php.list -o Dir::Etc::sourceparts=- -o APT::Get::List-Cleanup=0 >&2
  # shellcheck disable=SC2086
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends $packages >&2
  update-alternatives --set php "/usr/bin/php$PHP_VERSION" >/dev/null
fi

# Postgres for the test suite.
grep -qE '\spgsql$' /etc/hosts || echo '127.0.0.1 pgsql' >> /etc/hosts
if ! PGPASSWORD=password psql -h pgsql -U sail -d postgres -tAc 'select 1' >/dev/null 2>&1; then
  PG_BIN="$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | sort -V | tail -n 1)"
  if [ -n "$PG_BIN" ]; then
    PG_DATA=/var/lib/postgresql/help
    if [ ! -f "$PG_DATA/PG_VERSION" ]; then
      install -d -o postgres -g postgres "$PG_DATA"
      su postgres -c "$PG_BIN/initdb -D $PG_DATA -A trust" >/dev/null
    fi
    if ! su postgres -c "$PG_BIN/pg_ctl -D $PG_DATA status" >/dev/null 2>&1; then
      su postgres -c "$PG_BIN/pg_ctl -D $PG_DATA -l $PG_DATA/server.log -o '-k /tmp' -w start" >/dev/null
    fi
    psql_admin() { su postgres -c "psql -h /tmp -tAc \"$1\""; }
    [ "$(psql_admin "select 1 from pg_roles where rolname = 'sail'")" = 1 ] \
      || psql_admin "create user sail superuser password 'password'" >/dev/null
  else
    echo "No Postgres server found at pgsql and none installed; Pest will not reach its database." >&2
  fi
fi
if PGPASSWORD=password psql -h pgsql -U sail -d postgres -tAc 'select 1' >/dev/null 2>&1; then
  [ "$(PGPASSWORD=password psql -h pgsql -U sail -d postgres -tAc "select 1 from pg_database where datname = 'testing'")" = 1 ] \
    || PGPASSWORD=password psql -h pgsql -U sail -d postgres -c 'create database testing' >/dev/null
fi

# Meilisearch for search and the test suite (spec #30 runs search tests against the real
# engine), as CI's service container does: same version, port 7700, a local-only master key.
MEILI_VERSION=v1.52.4
MEILI_KEY=local-meilisearch-master-key
if ! curl --silent --fail http://127.0.0.1:7700/health >/dev/null 2>&1; then
  MEILI_BIN="$HOME/.local/bin/meilisearch-$MEILI_VERSION"
  case "$(uname -m)" in x86_64) MEILI_ARCH=amd64 ;; aarch64 | arm64) MEILI_ARCH=aarch64 ;; *) MEILI_ARCH="" ;; esac
  if [ ! -x "$MEILI_BIN" ] && [ -n "$MEILI_ARCH" ]; then
    mkdir -p "$(dirname "$MEILI_BIN")"
    curl --silent --fail --location -o "$MEILI_BIN" \
      "https://github.com/meilisearch/meilisearch/releases/download/$MEILI_VERSION/meilisearch-linux-$MEILI_ARCH" \
      && chmod +x "$MEILI_BIN" || rm -f "$MEILI_BIN"
  fi
  if [ -x "$MEILI_BIN" ]; then
    MEILI_DATA=/var/lib/meilisearch/help
    mkdir -p "$MEILI_DATA"
    nohup "$MEILI_BIN" --db-path "$MEILI_DATA/data.ms" --dump-dir "$MEILI_DATA/dumps" --snapshot-dir "$MEILI_DATA/snapshots" \
      --http-addr 127.0.0.1:7700 --master-key "$MEILI_KEY" --no-analytics >"$MEILI_DATA/meilisearch.log" 2>&1 &
  else
    echo "Meilisearch $MEILI_VERSION could not be downloaded; search tests will fail until one runs at 127.0.0.1:7700." >&2
  fi
fi

# Real env vars beat phpunit.xml and .env, so drop any the environment exports.
export COMPOSER_ALLOW_SUPERUSER=1
{
  echo 'export COMPOSER_ALLOW_SUPERUSER=1'
  echo 'unset APP_KEY APP_ENV DB_CONNECTION DB_DATABASE'
  echo 'export MEILISEARCH_HOST=http://127.0.0.1:7700'
  echo "export MEILISEARCH_KEY=$MEILI_KEY"
} >> "${CLAUDE_ENV_FILE:-/dev/null}"

# api.github.com zipballs can return 403 here, and Composer then clones each package with git.
# phpstan/phpstan has no git source in composer.lock, so build its zip from the same commit
# and put it where Composer's cache looks (sha1 of the dist URL).
seed_phpstan_cache() {
  local url ref target tmp
  url="$(php -r '$l = json_decode(file_get_contents("composer.lock"), true);
    foreach ($l["packages-dev"] as $p) { if ($p["name"] === "phpstan/phpstan") echo $p["dist"]["url"]; }')"
  [ -n "$url" ] || return 0
  ref="${url##*/}"
  target="$(composer config cache-files-dir)/phpstan/phpstan/$(printf '%s' "$url" | sha1sum | cut -d' ' -f1).zip"
  [ -f "$target" ] && return 0
  tmp="$(mktemp -d)"
  git -C "$tmp" init -q
  git -C "$tmp" fetch -q --depth 1 https://github.com/phpstan/phpstan.git "$ref"
  mkdir -p "$(dirname "$target")"
  git -C "$tmp" archive --format=zip --prefix=phpstan/ -o "$target" FETCH_HEAD
  rm -rf "$tmp"
}

seed_phpstan_cache || echo "Could not seed PHPStan's zip; composer install may fail on it." >&2
composer install --no-interaction --no-progress >&2

# The content rebuild draws Mermaid diagrams with tools/diagrams (Mermaid and Playwright, pinned),
# in the Chromium Playwright already has here.
if command -v npm >/dev/null 2>&1; then
  npm ci --prefix tools/diagrams --no-audit --no-fund >&2 || echo "Could not install tools/diagrams; diagrams won't draw." >&2
fi

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --ansi >&2
