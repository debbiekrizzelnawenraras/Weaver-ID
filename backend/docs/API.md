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
  "weaver": { "name": "Gandang Handwoven", "municipality": "Sagada" },
  "association": { "name": "Montañosa Weavers" }
}
```

| `result` | Meaning |
|---|---|
| `authenticated` | UID is registered, tag is active, product and weaver are active. Profile fields are included. |
| `unregistered` | UID is unknown, or the tag is not linked to a product. |
| `suspicious` | Tag is revoked, the product or weaver is inactive, or too many distinct devices scanned it in the window (see `config/weaver.php`). No profile is returned. |

Invalid `uid` returns HTTP 422. Every valid scan is written to `scan_logs`, including unregistered ones.

## Staff and admin: tag management

Requires `Authorization: Bearer <token>` from `POST /api/login`, with role `staff` or `admin`.

| Method and path | Purpose |
|---|---|
| `GET /api/nfc-tags` | List tags with their product, weaver and association. |
| `POST /api/nfc-tags` | Issue a tag: `{ "uid": "...", "product_id": 1 }`. Returns 201. |
| `PATCH /api/nfc-tags/{id}/revoke` | Revoke a tag. Later scans return `suspicious`. |

Issuing fails with 422 when the UID is already registered, the product is inactive, the product's weaver is inactive, or the product already has an active tag (revoke it first to replace it).

## Auth changes in this patch

- `POST /api/login` is limited to 5 attempts per minute per email and IP.
- `POST /api/logout` deletes the current token.
- Tokens expire after `SANCTUM_EXPIRATION` minutes (default 480).
- A deactivated user is blocked on every role-protected route, even with a valid token.

## Known limits (say these out loud in the defense)

- Authentication is by UID only. Cheap rewritable tags can copy a UID, so a clone scanned from one phone looks genuine. The suspicious rule is a heuristic, not proof.
- The `proprietor` name is not returned to the public app by design (personal data).
