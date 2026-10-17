# Portable SSH proofs

A /dev/null commit sent from the terminal is authenticated with an SSHSIG
signature over this **exact binary payload**:

    unix_timestamp + "\n" + nonce + "\n" + reply_reference + "\n" + submitted_body

For public, approved CLI commits, the app now preserves that signature as a
portable receipt. This is not a new login method and does not require new keys.

## Verify a receipt independently

Prerequisites: `curl`, `jq`, `openssl`, `ssh-keygen` (OpenSSH 8.2+).

From an approved commit, click the small **SSH-signed** proof label or visit:

    https://dev-null.sh/api/v1/commits/COMMIT_UUID/proof

Save the JSON receipt, then use the checked-in script:

```bash
bin/verify-devnull-proof receipt.json

# Or fetch it directly:
bin/verify-devnull-proof 'https://dev-null.sh/api/v1/commits/COMMIT_UUID/proof'
```

For transparency, this is what the script does, without calling the site
again once the JSON receipt has been saved:

```bash
jq -r .payload_base64 receipt.json | openssl base64 -d -A > submitted.txt
jq -r .ssh_signature receipt.json > proof.sig
printf 'devnull %s\n' "$(jq -r .public_key receipt.json)" > allowed_signers
ssh-keygen -Y verify -n devnull-commit -I devnull -f allowed_signers \
    -s proof.sig < submitted.txt
```

OpenSSH checks the signature with the **public key embedded in the SSHSIG**
and requires that key to appear in `allowed_signers`. No private key,
API key, or network access is needed to verify a saved receipt.

## What is (and is not) proved

- Cryptographically proves the signer controlled the included private SSH
  key when signing the submitted bytes.
- The server already checks that the public key belongs to the submitting
  user's GitHub account before accepting a CLI post. **That historic
  GitHub-to-key binding is a claim by the app**, not independently proven
  by the standalone SSH signature.
- The `payload_base64` field preserves **exact signed bytes**.
  `displayed_body` is the text after the app's normal body normalization
  and emoji processing, and may not match byte-for-byte.
- A key later removed from GitHub does not invalidate an old signature.
- A receipt for pending, rejected, deleted, or unsigned content returns 404.
- Receipts are not stored in shared HTTP caches, because approved posts
  may later be deleted or rejected. A proof previously saved by another
  person cannot be revoked.
- The public key and signed payload are intentionally public for an approved
  post. User/account deletion erases stored proofs, including those that
  otherwise could survive in a reply tombstone.
- Receipts are generated and verified on request. Regular timeline feeds
  only carry a small `ssh_signed` boolean marker, not proof blobs.

## Development

The signature route is rate-limited with the existing public API limiter.
No new dependency, external crypto service, or signing credentials are needed.

Feature tests cover signature verification, modified payload rejection,
publication visibility, normalized text, deletion and user export.
