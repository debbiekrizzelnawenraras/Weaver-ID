# Weaver-ID API: NFC verification and tag issuance

All endpoints are under `/api`. Send and accept JSON (`Accept: application/json`).

## Public: verify a scanned tag

`POST /api/verify`  (no login, limited to 30 requests per minute per IP)

Request body:

```json
{ "uid": "04:A1:B2:C3:D4:E5:F6", "device_id": "optional-install-id" }
```

- `uid`: hex UID read from the tag. Colons, spaces, dashes and lowercase are accepted. 8 to 20 hex characters.
- `device_id`: optional stable id for the phone's app install. Used by the suspicious-scan rule. If omitted, the IP address is used.

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
| `suspicious` | Tag is revoked, the product or weaver is inactive, or too many distinct devices scanned it in the window (see `config/weaver.php`). No profile is returned. |

Invalid `uid` returns HTTP 422. Every valid scan is written to `scan_logs`, including unregistered ones.

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
| `POST /api/admin/users` | Create `{ name, email, password (10+ chars), role, municipality (staff), association_id (association) }`. |
| `PUT /api/admin/users/{id}` | Edit name, email, status, municipality or association; optional new password. The role cannot change. Deactivating a user or resetting a password signs them out everywhere. An admin cannot deactivate their own account. |

## Auth changes (patch 1)

- `POST /api/login` is limited to 5 attempts per minute per email and IP.
- `POST /api/logout` deletes the current token.
- Tokens expire after `SANCTUM_EXPIRATION` minutes (default 480).
- A deactivated user is blocked on every role-protected route, even with a valid token.

## Known limits (say these out loud in the defense)

- Authentication is by UID only. Cheap rewritable tags can copy a UID, so a clone scanned from one phone looks genuine. The suspicious rule is a heuristic, not proof.
- The public response names the association (business) and its municipality only. Member names are never returned to the public (data minimisation, RA 10173). The old `weavers.proprietor` column is kept as an optional, unused column and is no longer collected.
- Tag linking is self-service for staff and associations, so a UID's origin is no longer proven by DTI. The controls are the `issued_by` audit trail, revocation, municipality scoping and the admin summary of tags issued per role.
- Staff scope follows the association's municipality, not the weaver's. A weaver in another town under a Sagada association is managed by Sagada staff.
