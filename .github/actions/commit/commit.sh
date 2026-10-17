#!/usr/bin/env bash
#
# The devnull GitHub Action: commits DEVNULL_BODY (replying to DEVNULL_REPLY_TO and attaching the
# DEVNULL_IMAGES, if any) with resources/scripts/commit.sh, the
# `curl -fsSL https://dev-null.sh/commit | bash` script, so it is signed exactly like a commit
# from a terminal.
#
# The private key in DEVNULL_SSH_KEY never touches the disk: it goes straight into an SSH agent
# started for this commit and stopped right after. The script runs with an empty HOME, so that key
# is the only one it can find and sign with.

set -euo pipefail
umask 077

main() {
  [ -n "${DEVNULL_BODY:-}" ] || fail "The body input is empty."
  [ -n "${DEVNULL_SSH_KEY:-}" ] || fail "The ssh-key input is empty: pass it a secret that holds a private SSH key."

  # In /tmp rather than TMPDIR: the agent's socket goes in it, and socket paths are limited to 104 bytes.
  PRIVATE_DIR="$(mktemp -d /tmp/devnull-action.XXXXXXXXXX)"
  trap cleanup EXIT

  unset SSH_AUTH_SOCK SSH_AGENT_PID
  eval "$(ssh-agent -s -a "$PRIVATE_DIR/agent.sock" 2> /dev/null)" > /dev/null
  [ -n "${SSH_AGENT_PID:-}" ] || fail "Could not start an SSH agent."

  printf '%s\n' "$DEVNULL_SSH_KEY" | SSH_ASKPASS_REQUIRE=never ssh-add -q - > /dev/null 2>&1 \
    || fail "Could not load the ssh-key input: it must be a private Ed25519 or RSA key without a passphrase."
  unset DEVNULL_SSH_KEY

  local arguments=()
  if [ -n "${DEVNULL_REPLY_TO:-}" ]; then
    arguments+=(--reply "$DEVNULL_REPLY_TO")
  fi

  local image
  while IFS=$' \t' read -r image; do
    [ -n "$image" ] && arguments+=(--image "$image")
  done <<< "$(printf '%s' "${DEVNULL_IMAGES:-}" | tr -d '\r')"

  arguments+=(-- "$DEVNULL_BODY")

  local script output
  script="$(dirname -- "${BASH_SOURCE[0]}")/../../../resources/scripts/commit.sh"

  if output="$(HOME="$PRIVATE_DIR" bash "$script" "${arguments[@]}" 2>&1)"; then
    report notice "$output"
  else
    fail "${output#devnull: }"
  fi
}

PRIVATE_DIR=""

cleanup() {
  [ -z "${SSH_AGENT_PID:-}" ] || ssh-agent -k > /dev/null 2>&1 || true
  [ -n "$PRIVATE_DIR" ] || return 0
  rm -f -- "$PRIVATE_DIR/agent.sock"
  rmdir -- "$PRIVATE_DIR" 2> /dev/null || true
}

fail() {
  report error "$*"
  exit 1
}

# Prints an annotation on GitHub Actions, so it shows up in the summary of the run, and plain text elsewhere.
report() {
  if [ "${GITHUB_ACTIONS:-}" != true ]; then
    if [ "$1" = error ]; then
      printf 'devnull: %s\n' "$2" >&2
    else
      printf 'devnull: %s\n' "$2"
    fi
    return
  fi

  local message="$2"
  message="${message//'%'/%25}"
  message="${message//$'\r'/%0D}"
  message="${message//$'\n'/%0A}"
  printf '::%s title=devnull::%s\n' "$1" "$message"
}

main "$@"
