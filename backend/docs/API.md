# Weaver-ID API: NFC verification and tag issuance

All endpoints are under `/api`. Send and accept JSON (`Accept: application/json`).

## Public: verify a scanned tag

`POST /api/verify`  (no login, limited to 30 requests per minute per IP)

Request body:

```json
{ "uid": "04:A1:B2:C3:D4:E5:F6", "device_id": "optional-install-id", "counter": 42 }
```

- `uid`: hex UID read from the tag. Colons, spaces, dashes and lowercase are accepted. 8 to 20 hex characters.
- `device_id`: optional stable id for the phone's app install. Used by the suspicious-scan rule. If omitted, the IP address is used.
- `counter`: optional. The tag's own read counter (0 to 16777215), sent by the app for tags that were secured. A secured tag without a counter, with a counter lower than the saved one, or with one more than `WEAVER_COUNTER_WINDOW` above it is `suspicious`. Flagged counters are not saved. See `docs/TAG-SECURITY.md`.
- `code`: optional. The tag code (16 hex characters) the app read from the tag's memory. A tag that was secured with a tag code and arrives without one, or with one that does not match the UID, is `suspicious` (and its counter is not saved). The code is never stored in the scan log or returned.

Response (HTTP 200 for all three outcomes):

```json
{
  "result": "authenticated",
  "message": "This product is registered and authenticated by DTI-Mountain Province.",
  "product": { "title": "Inabel Table Runner", "category": "Home Decor" },
  "association": { "name": "Rose Ann’s Loom Art Gallery", "municipality": "Sagada" }
}
```

| `result` | Meaning |
|---|---|
| `authenticated` | UID is registered, tag is active, product and weaver are active. Profile fields are included. |
| `unregistered` | UID is unknown, or the tag is not linked to a product. |
| `suspicious` | Tag is revoked, the product or weaver is inactive, too many distinct devices scanned it in the window (see `config/weaver.php`), or a secured tag failed the counter rule. No profile is returned. |

Invalid `uid` returns HTTP 422. Every valid scan is written to `scan_logs`, including unregistered ones.

A `suspicious` response also carries `can_report` (true when this phone may report the tag, see below). A secured tag that was read without its counter or tag code is most often a read that was cut short, so the first such flag from a phone returns `can_report: false`, `retry: true` and a message asking for another tap. A second flag from the same phone with no authenticated scan in between opens reporting. The reason for the flag is never returned.

## Public: report a suspicious tag

`POST /api/reports`  (no login, limited to 10 requests per hour per IP)

```json
{ "uid": "04A1B2C3D4E5F6", "device_id": "app-3f2a...", "app_version": "1.0.0",
  "location": "Souvenir stall, Bontoc public market", "seen_on": "2026-10-21",
  "note": "optional, up to 1000 characters", "contact": "optional, up to 120 characters" }
```

- `uid` and `location` (3 to 150 characters) are required. `seen_on` is `YYYY-MM-DD`, not later than tomorrow. Leave `contact` out to report anonymously.
- The server only accepts a report when the same phone (`device_id`, or the IP address when none is sent) has a flagged scan of that tag within the last `WEAVER_REPORT_WINDOW` minutes (default 60) and the rule above allows reporting. Otherwise it returns 422 asking the person to tap again. The flag reason is copied from that scan on the server; the app cannot send it.
- One report per phone and tag per `WEAVER_REPORT_DUPLICATE_HOURS` hours (default 24). A repeat returns 200 with `"duplicate": true` and the first reference. A second phone reporting the same tag is stored as its own report.
- Returns 201 `{ "reference": "SR-20261021-AB12C", "duplicate": false, "message": "..." }`.
- A report never changes the tag. Revoking a tag stays a separate decision of DTI-Mountain Province.

## Tag linking (admin, staff and association)

Requires `Authorization: Bearer <token>` from `POST /api/login`. Each role works inside its own scope:

| Role | Scope |
|---|---|
| `admin` | everything |
| `staff` | associations whose `municipality` equals the staff user's `municipality` (a staff account with no municipality sees nothing) |
| `association` | its own association's weavers and products |

| Method and path | Purpose |
|---|---|
| `GET /api/nfc-tags` | List tags in scope with their product, weaver and association. |
| `POST /api/nfc-tags` | Issue a tag: `{ "uid": "...", "product_id": 1 }`. Returns 201. |
| `PATCH /api/nfc-tags/{id}/revoke` | Revoke a tag in scope. Later scans return `suspicious`. |

Issuing fails with 422 when the UID is already registered, the product is inactive, the product's weaver is inactive, or the product already has an active tag (revoke it first to replace it). A product or tag outside the caller's scope returns 403. Every tag stores `issued_by`, which feeds the admin summary below.

## Staff registry (staff and admin)

Staff see and edit only their municipality; admin sees all. Out-of-scope records return 403; an inactive weaver returns 422.

| Method and path | Purpose |
|---|---|
| `GET /api/staff/associations` | Associations in scope, with weaver counts. |
| `POST /api/staff/associations` | Register an association `{ name, municipality }`. Staff may only use their own municipality. |
| `GET/POST /api/staff/weavers`, `PUT /api/staff/weavers/{id}` | Weavers in scope. |
| `GET/POST /api/staff/products`, `PUT /api/staff/products/{id}` | Products in scope. A product of an inactive weaver can be edited or deactivated but not reactivated or moved. |

## Reports of suspicious tags (staff and admin)

Staff see reports about tags in their own municipality; admin sees all. Association users get 403.

| Method and path | Purpose |
|---|---|
| `GET /api/reports` | Reports, newest first, 25 per page, with product, association, the internal flag reason, how many reports exist for the same tag (`same_tag_reports`) and the review state. Optional `?status=new\|under_review\|verified\|dismissed\|referred`. |
| `PATCH /api/reports/{id}` | Review a report `{ "status": "under_review", "review_note": "optional" }`. Saves who reviewed it and when. A report outside the caller's scope returns 403. It does not revoke or change the tag. |

## What needs attention (admin, staff and association)

`GET /api/attention` feeds the dashboard's "Needs attention" panel, the badge on the Reports tab and the check the page makes every 60 seconds. It adds no new data: each caller gets only numbers for what they can already open in the tabs.

```json
{
  "window_hours": 24,
  "since": "2026-10-20T11:00:00.000000Z",
  "new_reports": { "count": 2, "latest": [ { "id": 7, "reference": "SR-...", "uid": "04...", "product": "Table runner", "flag_reason": "many_devices", "location": "...", "created_at": "..." } ] },
  "pending_applications": { "count": 1, "latest": [ { "id": 3, "reference": "AP-...", "association_name": "...", "municipality": "Sagada", "created_at": "..." } ] },
  "suspicious_scans": { "count": 3, "latest": [ { "id": 41, "uid": "04...", "product": "Table runner", "flag_reason": "many_devices", "created_at": "..." } ] }
}
```

| Caller | `new_reports` | `pending_applications` | `suspicious_scans` |
|---|---|---|---|
| admin | every report with status `new`, however old | every application still waiting | every scan flagged suspicious in the last `WEAVER_ATTENTION_HOURS` (default 24) |
| staff | `new` reports about tags in their own municipality | waiting applications of their own municipality | `null` (staff have no scan log view) |
| association | `null` (no Reports tab) | `null` | flagged scans of their own association's tags |

- `latest` holds the 5 newest items, newest first; `count` is the full number. The reporter's note, contact and device are never included.
- An association account that is not linked to an association gets 403, like its scan log.
- Reviewing a report (any status other than `new`) removes it from the count; deciding an application does the same for applications. Applicant names, phone numbers and emails are never included.

## Association applications (public apply, staff and admin decide)

An association applies on `register.html`; nothing exists in `associations` or `users` until staff approve. Weaver-ID sends no emails or text messages: the applicant keeps the reference number and looks up the result on the same page.

**Public, no login**

| Method and path | Purpose |
|---|---|
| `GET /api/municipalities` | The 10 municipalities of Mountain Province (`config/weaver.php`), for the form's dropdown. |
| `POST /api/association-applications` | Apply: `{ association_name, municipality, contact_name, contact_phone, email, password, password_confirmation, registration_details (optional), privacy_consent: true }`. Returns 201 `{ reference: "AP-YYYYMMDD-XXXXX", status: "pending" }`. Limited to 10 tries an hour per IP address (every try counts, valid or not). |
| `POST /api/association-applications/status` | `{ reference, email }` returns the status, the reason when rejected, and a message. A wrong reference and a wrong email get the same 404. Limited to 10 a minute per IP address. |

Rules on the apply call:

- The municipality must be one of the listed ones. The mobile number must be a Philippine mobile number and is saved as `09XXXXXXXXX` (`+63 917 ...`, `63917...` and spaces or dashes are accepted).
- The password follows the same rules as every other account (12+ characters, upper, lower, number, symbol, not common, not containing the contact name or email) and must be confirmed. It is saved only as a hash, kept only while the application is waiting, and deleted when it is decided. It is never returned, not even to administrators.
- The association name may not match a registered association or a waiting application (capitals, spaces and punctuation ignored). The email may not belong to an account or a waiting application. The email answer is the same words for both reasons, so the form does not say which emails have accounts.
- `privacy_consent` must be `true`. The time and `config('weaver.application_notice_version')` (env `WEAVER_NOTICE_VERSION`) are saved with the application.
- A hidden field `website` is a bot trap: if it is filled the call answers 201 and saves nothing.
- A rejected association may apply again with the same name and email.

**Staff (their own municipality) and admin (all)**. Association users get 403.

| Method and path | Purpose |
|---|---|
| `GET /api/applications` | Applications, newest first, 25 per page, with contact details. Optional `?status=pending\|approved\|rejected`. Each waiting one carries `conflict`: reasons an approval would now fail (the name was registered or the email got an account since). The password hash is never included. |
| `POST /api/applications/{id}/approve` | Creates the association (`active`) and an `association` account for the contact person, with the password the applicant chose (no forced change), in one transaction. 422 if already decided or if the name or email is now taken (nothing is created); 403 outside the caller's municipality. |
| `POST /api/applications/{id}/reject` | `{ "note": "reason, 5 to 500 characters" }`. The applicant can read the note. 422 if already decided; 403 outside the caller's municipality. |

Municipality scope for applications ignores capitals (`sagada` matches `Sagada`).

## Association registry

`GET /api/association/weavers` and `GET /api/association/products` accept `?status=active|inactive|all` (default `active`) so deactivated records can be found and reactivated.

`PUT /api/association/products/{id}` edits `{ title, category, status }` of one of the association's products (403 for another association's product; 422 when reactivating a product of an inactive weaver).

`GET /api/association/scan-logs` lists scans of the association's own tags, newest first, 50 per page, with optional `?result=`. Scans of unknown tags are not shown.

## Admin only

| Method and path | Purpose |
|---|---|
| `GET /api/admin/summary` | Counts: weavers, products, products with an active tag, tags (active, revoked, issued by each role), scans by result. |
| `GET /api/admin/scan-logs` | Scan history, 50 per page, newest first. Optional `?result=authenticated\|unregistered\|suspicious`. |
| `PATCH /api/admin/nfc-tags/{id}/reassign` | Fix a mistake: move a tag to another active product `{ "product_id": 2 }`. 422 if the product is inactive, its weaver is inactive, it already has an active tag, or it is the same product. A revoked tag stays revoked unless `"reactivate": true` is sent. Every change is saved in `nfc_tag_events` with who did it. |
| `GET /api/admin/users` | All accounts. |
| `POST /api/admin/users` | Create `{ name, email, password (see Password rules), role, municipality (staff), association_id (association) }`. The new account must change its password at first sign-in. |
| `PUT /api/admin/users/{id}` | Edit name, email, status, municipality or association; optional new password. The role cannot change. Deactivating a user or resetting a password signs them out everywhere. A reset password must be changed at the next sign-in, unless the admin reset their own. An admin cannot deactivate their own account. |

## Auth changes (patch 1)

- `POST /api/login` is limited to 5 attempts per minute per email and IP.
- `POST /api/logout` deletes the current token.
- Tokens expire after `SANCTUM_EXPIRATION` minutes (default 480).
- A deactivated user is blocked on every role-protected route, even with a valid token.

## Passwords

**Password rules** (checked by the server for every new password; the dashboards show the same rules as a Weak / Medium / Strong meter):

- at least 12 characters, with a lowercase letter, an uppercase letter, a number and a symbol;
- not a common password or pattern (password, 123456, qwerty and similar, or the same character four times in a row);
- does not contain a word of four or more letters from the account's name or the part of its email before the @.

Weak means a rule is not met. Medium means every rule is met (12 to 15 characters). Strong means every rule is met and the password has 16 characters or more. The level measures rule compliance and length, not how fast a password could be cracked. The rules live in `app/Support/PasswordPolicy.php` and, for the live meter, `public/js/password-policy.js`; `tests/fixtures/password-vectors.json` is run against both.

`POST /api/change-password` (any signed-in role, limited to 5 requests per minute per account)

```json
{ "current_password": "...", "password": "...", "password_confirmation": "..." }
```

- 422 if the current password is wrong, the new one breaks a rule (every broken rule is listed in `errors.password`), the confirmation differs, or the new password equals the current one.
- On success the password changes, `must_change_password` is cleared, and every other token of the account is deleted. The token that made the request stays valid.

**Forced change at first sign-in.** An account created by an admin, or whose password an admin reset (except the admin's own), has `must_change_password: true` (returned by `POST /api/login` and `GET /api/user`). Until it changes its password, every route except `GET /api/user`, `POST /api/change-password` and `POST /api/logout` answers 403 with `"code": "password_change_required"`. The dashboards open a window that cannot be closed until the password is changed.

## Known limits (say these out loud in the defense)

- Authentication is by UID only. Cheap rewritable tags can copy a UID, so a clone scanned from one phone looks genuine. The suspicious rule is a heuristic, not proof.
- The public response names the association (business) and its municipality only. Member names are never returned to the public (data minimisation, RA 10173). The old `weavers.proprietor` column is kept as an optional, unused column and is no longer collected.
- Tag linking is self-service for staff and associations, so a UID's origin is no longer proven by DTI. The controls are the `issued_by` audit trail, revocation, municipality scoping and the admin summary of tags issued per role.
- Staff scope follows the association's municipality, not the weaver's. A weaver in another town under a Sagada association is managed by Sagada staff.
- Association applications: the email address is not verified (no email or OTP step), so the staff member who clicks Approve is the real control. Check the association against its registration before approving. The form still tells a visitor that an email "cannot be used", which lets someone test whether an address is already taken; the throttle (10 tries an hour per IP address) slows that down but does not stop it. A bot with many IP addresses could fill the Applications list with junk rows; staff reject them. The applicant is not told the result by message; they must look it up with the reference number or be told by staff.
