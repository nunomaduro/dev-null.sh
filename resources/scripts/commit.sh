#!/usr/bin/env bash
#
# /dev/null commit: commit to /dev/null from your terminal.
#
#   curl -fsSL __DEVNULL_URL__/commit | bash -s -- 'Hello world!'
#   curl -fsSL __DEVNULL_URL__/commit | bash -s -- --reply 0be0130 'Agreed!'
#   curl -fsSL __DEVNULL_URL__/commit | bash -s -- --image shipped.png 'Shipped!'
#
# `--reply` takes the hash of a commit (as shown on /dev/null) or its link. `--image` takes the
# path of a JPEG, PNG, GIF or WebP file of up to 10 MB, up to 3 times. A `--` ends the options,
# for a message that starts with one of them.
#
# There is no login: your commit is signed locally with an SSH key that is on your GitHub
# account, and /dev/null checks the signature against the public keys GitHub publishes for you.
#
# What this script does, and nothing else:
#   1. Lists your public keys (`ssh-add -L` and ~/.ssh/*.pub) and asks /dev/null which one is
#      on your GitHub account. Public keys are public: GitHub publishes them too.
#   2. Signs your message (and the SHA-256 of each image) with that key, using `ssh-keygen -Y sign`
#      with the "devnull-commit" namespace ("devnull-commit-images" when images are attached): the
#      signature is only valid for /dev/null commits, it can't be used to sign git commits or to
#      log in anywhere.
#   3. Sends the message, the images, and the signature to /dev/null.
#
# It never reads or sends private keys (ssh-keygen or your SSH agent uses them), never changes
# ~/.ssh or your SSH agent, never overwrites a file, and only writes a few files in a private
# temporary directory, which it deletes one by one, by name, when it is done.
#
# Everything happens in `main`, called on the very last line: if the download is cut short,
# nothing runs.

set -euo pipefail
set -o noclobber
umask 077

main() {
  DEVNULL_URL="${DEVNULL_URL:-__DEVNULL_URL__}"
  NAMESPACE="devnull-commit"

  case "$DEVNULL_URL" in
    https://*) PROTOCOLS="=https" ;;
    http://localhost | http://localhost:* | http://127.0.0.1 | http://127.0.0.1:*) PROTOCOLS="=http" ;;
    *) fail "Refusing to send your commit to $DEVNULL_URL: it is not an https:// URL." ;;
  esac

  local command
  for command in awk curl date grep head mktemp od rm rmdir ssh-keygen tr; do
    command -v "$command" > /dev/null || fail "$command is required."
  done

  local reply_to=""
  local images=()
  while :; do
    case "${1:-}" in
      --reply)
        [ -n "${2:-}" ] || fail "Usage: curl -fsSL $DEVNULL_URL/commit | bash -s -- --reply <commit hash or link> 'Your reply'"
        [ -z "$reply_to" ] || fail "--reply can only be given once."
        reply_to="$2"
        shift 2
        ;;
      --image)
        [ -n "${2:-}" ] || fail "Usage: curl -fsSL $DEVNULL_URL/commit | bash -s -- --image <path> 'Your commit'"
        images+=("$2")
        shift 2
        ;;
      --)
        shift
        break
        ;;
      *) break ;;
    esac
  done

  local has_images=false
  if [ "${#images[@]}" -gt 0 ]; then
    has_images=true
    NAMESPACE="devnull-commit-images"
  fi

  # It goes on its own line of the signed payload.
  case "$reply_to" in
    *[!A-Za-z0-9:/._@-]*) fail "--reply takes the hash of a commit or its link." ;;
  esac

  [ "${#images[@]}" -le 3 ] || fail "A commit can carry up to 3 images."

  local image size
  if $has_images; then
    command -v cp > /dev/null || fail "cp is required."
    command -v wc > /dev/null || fail "wc is required."
    command -v shasum > /dev/null || command -v sha256sum > /dev/null || fail "shasum or sha256sum is required."
    for image in "${images[@]}"; do
      [ -f "$image" ] && [ -r "$image" ] || fail "Could not read the image $image."
      size="$(wc -c < "$image" | tr -d ' ')"
      [ "$size" -le 10240000 ] || fail "The image $image is larger than 10 MB."
    done
  fi

  local message="$*"

  if [ -z "$message" ]; then
    { printf 'Commit message: ' > /dev/tty && IFS= read -r message < /dev/tty; } 2> /dev/null \
      || fail "Usage: curl -fsSL $DEVNULL_URL/commit | bash -s -- 'Hello world!'"
  fi

  [ -n "$message" ] || fail "Aborting commit due to empty commit message."

  # Only a fresh directory created by mktemp, owned by us, is ever used (and cleaned up).
  local tmp_dir
  tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/devnull.XXXXXXXXXX")"
  case "$tmp_dir" in
    /*/devnull.??????????) ;;
    *) fail "Could not create a private temporary directory." ;;
  esac
  [ -d "$tmp_dir" ] && [ ! -L "$tmp_dir" ] && [ -O "$tmp_dir" ] || fail "Could not create a private temporary directory."

  TMP_DIR="$tmp_dir"
  trap cleanup EXIT
  trap 'exit 130' HUP INT TERM

  # 1. Find a local key that is on the GitHub account of a /dev/null user.
  local public_key
  {
    agent_keys
    for public_key in "$HOME"/.ssh/*.pub; do
      [ -f "$public_key" ] && [ -f "${public_key%.pub}" ] && head -n 1 -- "$public_key"
    done
  } | grep -E '^(ssh-ed25519|ssh-rsa) ' | key_id | awk '!seen[$0]++' > "$TMP_DIR/candidates" || true

  [ -s "$TMP_DIR/candidates" ] || fail "No Ed25519 or RSA SSH key found in your SSH agent or in ~/.ssh."

  request --header 'Content-Type: text/plain; charset=utf-8' --data-binary @"$TMP_DIR/candidates" "$DEVNULL_URL/api/cli/key-lookups"

  # Only ever sign with one of our own keys, whatever /dev/null answers.
  local key
  key="$(key_id < "$TMP_DIR/response")"
  grep -qxF -- "$key" "$TMP_DIR/candidates" || fail "Unexpected answer from $DEVNULL_URL."

  # 2. Sign with that key: through the agent if it has it, otherwise with the private key file.
  local signing_key=""
  if agent_keys | key_id | grep -qxF -- "$key"; then
    signing_key="$TMP_DIR/key.pub"
    printf '%s\n' "$key" > "$signing_key"
  else
    for public_key in "$HOME"/.ssh/*.pub; do
      if [ -f "$public_key" ] && [ "$(head -n 1 -- "$public_key" | key_id)" = "$key" ]; then
        signing_key="${public_key%.pub}"
        break
      fi
    done
  fi

  [ -n "$signing_key" ] || fail "Could not find the private key of $key."

  local hashes="" position=1 image_fields=() image_hash
  if $has_images; then
    for image in "${images[@]}"; do
      TMP_FILES+=("image-$position")
      cp -- "$image" "$TMP_DIR/image-$position"
      image_hash="$(sha256 "$TMP_DIR/image-$position")" || fail "Could not hash the image $image."
      hashes="$hashes${hashes:+,}$image_hash"
      image_fields+=(--form "images[]=@image-$position")
      position=$((position + 1))
    done
  fi

  local nonce
  nonce="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
  if $has_images; then
    printf '%s\n%s\n%s\n%s\n%s' "$(date +%s)" "$nonce" "$reply_to" "$hashes" "$message" > "$TMP_DIR/commit"
  else
    printf '%s\n%s\n%s\n%s' "$(date +%s)" "$nonce" "$reply_to" "$message" > "$TMP_DIR/commit"
  fi

  # Passphrase prompts go to /dev/tty: keep ssh-keygen away from stdin, which is this script.
  if ! ssh-keygen -Y sign -n "$NAMESPACE" -f "$signing_key" "$TMP_DIR/commit" < /dev/null 2> "$TMP_DIR/ssh-keygen.log"; then
    sanitize < "$TMP_DIR/ssh-keygen.log" >&2
    fail "Could not sign your commit with $signing_key."
  fi

  local signature
  signature="$(grep -v -- '-----' "$TMP_DIR/commit.sig" | tr -d '\n')"

  # Nothing but base64 goes into the HTTP header.
  case "$signature" in
    '' | *[!A-Za-z0-9+/=]*) fail "ssh-keygen produced an unexpected signature." ;;
  esac

  # 3. Commit: the payload is the raw body, or a file next to the image files. The form request
  # runs from inside the private directory, so curl's --form parser, which gives , ; and " special
  # meaning, only ever sees the fixed file names, never a path.
  if $has_images; then
    (cd "$TMP_DIR" && request --max-time 600 --speed-limit 1024 --speed-time 60 --header "X-SSH-Signature: $signature" \
      --form "payload=@commit" "${image_fields[@]}" "$DEVNULL_URL/api/cli/commits") || exit 1
  else
    request --header 'Content-Type: text/plain; charset=utf-8' --header "X-SSH-Signature: $signature" \
      --data-binary @"$TMP_DIR/commit" "$DEVNULL_URL/api/cli/commits"
  fi
  sanitize < "$TMP_DIR/response"
}

# The only files this script creates, all inside its own temporary directory.
TMP_FILES=(candidates commit commit.sig key.pub response ssh-keygen.log)
TMP_DIR=""
PROTOCOLS="=https"

# Deletes the files above one by one, then the directory itself, which `rmdir` only does when it
# is empty: nothing is ever deleted recursively.
cleanup() {
  [ -n "$TMP_DIR" ] && [ -d "$TMP_DIR" ] || return 0

  local file
  for file in "${TMP_FILES[@]}"; do
    rm -f -- "$TMP_DIR/$file"
  done

  rmdir -- "$TMP_DIR" 2> /dev/null || true
}

fail() {
  printf 'devnull: %s\n' "$*" | sanitize >&2
  exit 1
}

# Removes control characters (such as terminal escape sequences) from text we print.
sanitize() {
  LC_ALL=C tr -d '\000-\010\013-\037\177'
}

# Sends a request to /dev/null and fails with its answer (always plain text) unless it succeeded.
# `--disable` (first) ignores ~/.curlrc, so no local curl config can relax these options, and
# redirects are never followed.
request() {
  local status
  status="$(curl --disable --silent --show-error --proto "$PROTOCOLS" --tlsv1.2 --max-time 30 \
    --output "$TMP_DIR/response" --write-out '%{http_code}' "$@")" \
    || fail "Could not reach $DEVNULL_URL."

  if [ "$status" -lt 200 ] || [ "$status" -gt 299 ]; then
    [ -s "$TMP_DIR/response" ] || fail "Unexpected answer from $DEVNULL_URL (HTTP $status)."
    fail "$(cat "$TMP_DIR/response")"
  fi
}

sha256() {
  if command -v shasum > /dev/null; then
    shasum -a 256 -- "$1"
  else
    sha256sum -- "$1"
  fi | awk '{ print $1 }'
}

# "<type> <base64>", without the comment.
key_id() {
  awk '{ print $1 " " $2 }'
}

agent_keys() {
  ssh-add -L 2> /dev/null | grep -E '^(ssh-ed25519|ssh-rsa) ' || true
}

main "$@"
