#!/usr/bin/env bash
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec "$ROOT/bin/run-tests-if-relevant" stop --format=cursor
