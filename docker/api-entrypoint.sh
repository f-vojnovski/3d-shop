#!/bin/sh
set -e

# Only the web container migrates; the worker and reverb wait rather than
# racing it. The catalogue starts empty on purpose: no model ships with the
# repository, so no licence travels with it either.
if [ "${RUN_MIGRATIONS}" = "true" ]; then
  until php artisan db:monitor --max=1 >/dev/null 2>&1; do
    echo "waiting for postgres..."
    sleep 2
  done

  php artisan migrate --force
fi

# Written into the image's storage, which is a volume, so a rebuild does not
# orphan what a previous run stored.
php artisan storage:link >/dev/null 2>&1 || true

exec "$@"
