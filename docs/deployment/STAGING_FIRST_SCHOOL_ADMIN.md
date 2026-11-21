# First School Admin in staging

The owner controls the approval digest through the staging deployment configuration. The hosting operator needs authenticated, interactive console access and the exact manifest the owner approved. The command is disabled by default and only runs with the staging application environment. The browser uses the existing activation form and MFA enrollment.

## Owner handoff

1. Confirm the isolated deployment and database target through the normal staging approval workflow. Apply the reviewed schema before running the command.
2. Use a password manager on a trusted device to generate and retain 32 random bytes encoded as 64 lowercase hexadecimal characters. Keep the activation secret in the password manager until redemption. Hash its 64-character text as SHA-256 without a trailing newline.
3. Prepare a single-line JSON manifest with exactly four fields. Use a fresh ULID for `proof_id`, a unique `EDU-STAGE-` school code followed by 4–22 uppercase letters or digits for `school_code`, the lowercase `secret_hash`, and a UTC Unix timestamp in seconds for `expires_at`. Allow at most 30 minutes from issuance.
4. Approve the exact manifest by placing the SHA-256 of its unmodified bytes in `EDU_STAGING_BOOTSTRAP_APPROVAL_SHA256` on the staging deployment. Refresh cached config through the approved deployment workflow. Give the operator the manifest through a private channel. The manifest contains only the hash commitment and public proof fields. The owner retains the activation secret.
5. Run `php artisan edu:initialize-staging-school-admin` in an approved interactive staging console and supply the manifest at the hidden prompt. Remove the approval digest and refresh cached config after issuance.
6. Open the normal HTTPS activation form. Enter the approved school code, proof ID, and the secret from the password manager in their form fields. Set a distinct password there. Sign in with `STAGE-ADMIN` and complete the existing MFA enrollment before opening protected School Admin pages.

The command creates one new provisional synthetic school for up to one day. Its unique school code and proof ID prevent duplicate or replayed issuance. The proof remains redeemable until expiry or first successful redemption after the approval digest is removed. An inactive service identity with zero memberships records offline issuance. The activated identity records redemption. Both events commit in the same database transaction as their respective writes.

## Reissue an unused proof

The owner can approve a replacement for the existing pending School Admin. Use the same private handoff, a fresh 32-byte activation secret, a fresh proof ULID, and a new expiry within 30 minutes. Include the current proof ULID in an additional `previous_proof_id` manifest field. Set the approval digest to the SHA-256 of the exact replacement manifest. Run `php artisan edu:reissue-staging-school-admin-proof` on the isolated staging console and supply the approved manifest as hidden input. On hosting where Symfony cannot hide terminal input, pipe the locally prepared manifest to the same Artisan command and supply the approval digest as a command-scoped environment variable. Keep the plaintext activation secret in the owner's password manager and use only the new proof for activation.

The command requires an eligible synthetic school and a pending, never-activated School Admin. It replaces the current proof and retains the original tenant, user and membership. The replacement and immutable audit event commit together. Each manifest is bound to the proof it replaces. The operator cannot repurpose it after another reissue. This command is limited to the initial School Admin.

## Synthetic Principal and Teacher access

The separate staff handoff is off by default. After explicit staging deployment approval, configure the API in the `staging` environment with PHP Sodium available and `APP_DEBUG=false`. Set `EDU_STAGING_STAFF_ACTIVATION_SCHOOL` to the exact approved synthetic school code (for current QA, `EDU-STAGE-ACADEMIC`) and `EDU_STAGING_STAFF_ACTIVATION_PUBLIC_KEY` to the owner's 64-character lowercase hex Sodium box public key. The API accepts only the configured `EDU-STAGE-` school while it is an eligible provisional demo. Expose the School Admin form by setting `NEXT_PUBLIC_EDU_STAGING_STAFF_ACTIVATION=true` when building the staging web app.

Generate the keypair on the owner's trusted machine, outside the repository, using PHP CLI with Sodium. The second line outputs only the public key. Keep the private keypair on that machine and out of any chat, environment variable, shared folder or server.

```sh
umask 077
edu_staff_private_dir=$(mktemp -d "${TMPDIR:-/tmp}/edu-staff.XXXXXXXX")
php -r '$pair = sodium_crypto_box_keypair(); file_put_contents($argv[1], $pair); echo bin2hex(sodium_crypto_box_publickey($pair)), PHP_EOL;' "$edu_staff_private_dir/keypair"
```

Sign in as School Admin and complete MFA. Under **Staging staff accounts**, create one Principal and one Teacher individually using synthetic names and distinct login IDs. Each successful issuance returns a ciphertext. Copy it to the owner machine and, with the same local shell, run the following command. Paste one ciphertext line when it waits for input. Repeat with a different output filename for the other role.

```sh
test ! -e "$edu_staff_private_dir/activation.json" &&
php -r '$box = base64_decode(trim(fgets(STDIN)), true); $pair = file_get_contents($argv[1]); $proof = $box === false ? false : sodium_crypto_box_seal_open($box, $pair); if ($proof === false) { fwrite(STDERR, "Invalid encrypted proof.\n"); exit(1); } if (file_put_contents($argv[2], $proof) === false) { exit(1); }' "$edu_staff_private_dir/keypair" "$edu_staff_private_dir/activation.json"
```

Open the resulting private file locally. Use its school code, proof ID and secret in the existing HTTPS activation form in a signed-out browser session. Set a distinct password for each synthetic account with a password manager. Proofs expire after 30 minutes and are single-use. Discard the plaintext file and clear copied secrets after use. For an expired or lost handoff, use **Replace proof** with the pending membership ID from the School Admin screen or **School permissions**. A replacement invalidates the previous proof. After acceptance, disable the API settings and the staging web flag before the next build. Disable the API settings immediately if the private key is exposed. Existing outstanding proofs still follow their normal expiry and invalidation rules.
