# Managing Signup Terms & Consent

The public signup form at `/signup` shows a consent checkbox with a link to your
Privacy Policy. This page explains how to manage that link and how consent is
tracked over time.

(Other legal terms like the MSA are dealt with separately at service-order time
— see Phase 2 in the project plan. Signup only collects Privacy Policy consent.)

## Where to change the URL

Log in as a superuser and go to **Admin → Settings → Signup Terms**.

Two fields are exposed:

| Field | Env var | Notes |
|---|---|---|
| Privacy Policy URL | `SIGNUP_TERMS_PRIVACY_URL` | Shown as the "Privacy Policy" link on the signup form. |
| Terms Version | `SIGNUP_TERMS_VERSION` | Identifier stamped on each customer at consent. See below. |

Save the form — IXP-Manager rewrites `.env` and clears the config cache. Changes
are live immediately, no restart required.

## The terms version — why it matters

Every time a customer accepts the privacy policy (via signup, or later at MSA
acceptance in Phase 2), IXP-Manager stamps the current `SIGNUP_TERMS_VERSION`
on their `cust.terms_version_accepted` column.

When you make a substantive change to the privacy policy:

1. Update the linked page.
2. Bump `SIGNUP_TERMS_VERSION` (e.g. `2026-07-01` → `2026-09-15`).

From that point on:

- **New signups** get stamped with the new version at consent time.
- **Existing customers** whose stamp is now older than the current version get
  prompted to re-consent at their next MSA gate or order flow (Phase 2 wires
  this in — Phase 1 just captures the stamp).

What counts as "substantive": material changes to how customer data is
collected, used or shared. Cosmetic edits and typo fixes don't need a version
bump.

## Audit trail — proving who agreed to what and when

Consent is recorded in two places:

1. **`cust.terms_version_accepted`** — the version identifier at the moment
   they last consented.
2. **The user_log / signup log entries** — timestamped record of the consent
   event including the accepting user's IP and email. Look for
   `[Signup] Created customer ...` entries.

If legal asks "who agreed to the July 2026 terms?", it's a one-query answer:

```sql
SELECT id, name, autsys, terms_version_accepted, datejoin
FROM cust
WHERE terms_version_accepted = '2026-07-01';
```

## Emergency: fixing a mis-consented customer

If someone accepts the wrong version (e.g. they signed up while a version was
mid-rollout), a superuser can update `cust.terms_version_accepted` directly.
No UI for this — deliberately. It's a rare, deliberate action that should show
up in database logs.

## Where the code lives

- `config/signup.php` — the default values and env var mapping.
- `config/ixp_fe_settings.php` (panel `signup_terms`) — the admin UI field
  definitions.
- `resources/skins/edgeix/signup/create.foil.php` — the signup form, which
  pulls the Privacy Policy URL from `config('signup.terms.privacy_url')`.
- `resources/skins/edgeix/signup/pick-asn.foil.php` — the OAuth-path
  confirmation screen; same consent block.
- `app/Services/EdgeIX/CustomerCreatorService.php` — stamps
  `config('signup.terms.version')` onto the customer at signup (both paths).

## PeeringDB OAuth signup path

When `AUTH_PEERINGDB_ENABLED=true` in `.env` (see the "Enable PeeringDB OAuth"
section below), an additional "Sign up with PeeringDB" button appears on the
signup form. It uses the same Privacy Policy URL and version stamping as the
manual form — the consent checkbox is preserved on the OAuth confirmation page,
so version stamping and audit work identically for both paths.

### Enable PeeringDB OAuth

The infrastructure ships with IXP-Manager; enabling it is a config-only step.

1. Register an OAuth application at
   [peeringdb.com](https://www.peeringdb.com) → Account → OAuth Applications.
2. Set the redirect URIs to include both:
   - `https://<your-domain>/auth/login/peeringdb/callback` (login)
   - `https://<your-domain>/signup/peeringdb/callback` (signup)
3. Add to `.env`:
   ```
   AUTH_PEERINGDB_ENABLED=true
   PEERINGDB_OAUTH_CLIENT_ID=...
   PEERINGDB_OAUTH_CLIENT_SECRET=...
   PEERINGDB_OAUTH_REDIRECT=https://<your-domain>/auth/login/peeringdb/callback
   ```
4. `php artisan config:clear` and restart Apache.

The scope requested (`profile email networks`) is already configured in the
Socialite provider — no changes needed there.

### What the OAuth path does

1. User clicks "Sign up with PeeringDB" on `/signup`.
2. Redirected to PeeringDB; authorises the app.
3. Callback receives their profile + list of networks they administer.
4. For each network:
   - Already in IXP-M with `peeringdb_oauth=1` → shown as "log in with PeeringDB instead".
   - Already in IXP-M without `peeringdb_oauth=1` → shown as "contact your account admin".
   - Not in IXP-M → offered as a signup option.
5. User picks an ASN (or the only eligible one is auto-selected), ticks the
   T&Cs consent, submits.
6. Customer + User created via the same `CustomerCreatorService` the manual
   form uses. `cust.peeringdb_oauth = 1` and `user.peeringdb_id` set. The
   welcome-email path is skipped — the user is already authenticated.
7. User is logged straight into the dashboard.

Only networks where the OAuth user has update permission (`perms & 0x02`) are
offered — a read-only affiliate can't create an account on the network's behalf.

## Future work (Phase 2 — MSA gate)

When the MSA e-signature flow is added, it will:

1. Compare the customer's `terms_version_accepted` against the current
   `SIGNUP_TERMS_VERSION` at every order attempt.
2. If out of date, redirect to the re-consent screen before the order can
   proceed.
3. Refresh the stamp to the current version on successful re-consent.

Nothing to configure for this in advance — bumping `SIGNUP_TERMS_VERSION`
today already produces the right data shape for the Phase 2 flow to use.
