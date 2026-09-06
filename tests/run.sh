#!/bin/sh
# Every test in this repository, in containers. One command, one exit code.
#
#   tests/run.sh                  lint, unit, frontend-js, deploy and conformance
#   tests/run.sh unit             one of them
#   tests/run.sh unit conformance
#
# The images are rebuilt only when their Dockerfiles or composer.lock
# change; Docker's layer cache decides that.
set -eu

here=$(cd "$(dirname "$0")" && pwd)
compose="docker compose -f $here/docker/compose.yml"

# weewx-evo beside this repository lets the uploads check compare with its
# modules as well; see compose.yml.
if [ -z "${WEEWX_EVO_SRC:-}" ] && [ -d "$here/../../weewx-evo/src/weewx_evo" ]; then
    WEEWX_EVO_SRC=$(cd "$here/../../weewx-evo/src" && pwd)
    export WEEWX_EVO_SRC
fi

$compose build --quiet

if [ $# -eq 0 ]; then
    set -- lint unit frontend-js deploy conformance
fi

status=0
for service in "$@"; do
    echo "== $service"
    $compose run --rm "$service" || status=1
done
exit $status
