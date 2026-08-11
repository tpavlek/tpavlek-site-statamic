#!/usr/bin/env bash
# Push the local sold-out report snapshot to production, but only when it has changed
# since the last successful push — the scheduler calls this after every
# fringe:sold-out-report run (see routes/console.php), including "nothing due" runs.
# Safe to run by hand from anywhere.
set -euo pipefail
cd "$(dirname "$0")/.."

FILE="storage/app/private/fringe/sold-out-report.json"
STAMP="storage/app/private/fringe/.sold-out-report.last-synced"

[ -f "$FILE" ] || exit 0

sum="$(shasum -a 256 "$FILE" | cut -d' ' -f1)"
if [ -f "$STAMP" ] && [ "$(cat "$STAMP")" = "$sum" ]; then
    exit 0
fi

scp "$FILE" do-something:troypavlek.ca/storage/app/private/fringe/
echo "$sum" > "$STAMP"
