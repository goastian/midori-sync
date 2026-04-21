# Midori Sync Protocol (MSP) — API Reference

Base URL: `https://your-server.com/api/v1`

## Authentication

All API endpoints (except token exchange) require a Bearer token in the `Authorization` header.

### Exchange OAuth Token for Sync Token

```
POST /auth/token
Content-Type: application/json

{ "token": "<authentik_oauth_token>" }
```

**Response (200):**
```json
{
    "token": "<64-char-sync-session-token>",
    "expires_at": "2024-01-01T00:00:00Z",
    "expires_in": 3600
}
```

### Revoke Token

```
DELETE /auth/token
Authorization: Bearer <sync-token>
```

**Response:** `204 No Content`

---

## Sync Info

### Get Sync Status

```
GET /sync/info
Authorization: Bearer <sync-token>
```

**Response (200):**
```json
{
    "quota_bytes": 104857600,
    "used_bytes": 1048576,
    "last_modified": 1704067200.123456
}
```

### Get Collection Status

```
GET /sync/status
Authorization: Bearer <sync-token>
```

**Response (200):**
```json
{
    "collections": {
        "bookmarks": {
            "last_modified": 1704067200.123456,
            "record_count": 42,
            "size_bytes": 8192
        }
    }
}
```

---

## Collections & Records

### List Records (with Delta Sync)

```
GET /collections/{name}?since={timestamp}&limit={n}&sort={newest|oldest}&include_deleted={true|false}
Authorization: Bearer <sync-token>
```

**Response (200):**
```json
{
    "records": [
        {
            "id": "bookmark-uuid",
            "version": 3,
            "payload": "<base64-encrypted-data>",
            "modified_at": 1704067200.123456,
            "ttl": null,
            "deleted": false
        }
    ],
    "count": 1
}
```

### Get Single Record

```
GET /collections/{name}/{id}
Authorization: Bearer <sync-token>
```

**Response (200):** Single record object.

### Upsert Record

```
PUT /collections/{name}/{id}
Authorization: Bearer <sync-token>
Content-Type: application/json
X-If-Unmodified-Since: 1704067200.123456   (optional, for conflict detection)

{
    "payload": "<base64-encrypted-data>",
    "ttl": "2024-02-01T00:00:00Z" (optional)
}
```

**Response (200):** Updated record object.
**Response (412):** Conflict — record was modified after `X-If-Unmodified-Since`.
**Response (413):** Payload exceeds max record size (default 256 KB).

### Batch Upsert

```
POST /collections/{name}
Authorization: Bearer <sync-token>
Content-Type: application/json

{
    "records": [
        { "id": "rec-1", "payload": "<base64>", "ttl": null, "deleted": false },
        { "id": "rec-2", "payload": "<base64>" }
    ]
}
```

**Response (200):**
```json
{
    "results": [
        { "index": 0, "id": "rec-1", "modified_at": 1704067200.123456 },
        { "index": 1, "id": "rec-2", "modified_at": 1704067200.123457 }
    ],
    "count": 2
}
```

Max 100 records per batch.

### Delete Record (Soft Delete)

```
DELETE /collections/{name}/{id}
Authorization: Bearer <sync-token>
```

**Response:** `204 No Content`

### Delete All Records in Collection

```
DELETE /collections/{name}
Authorization: Bearer <sync-token>
```

**Response (200):**
```json
{ "deleted": 42 }
```

---

## Devices

### List Devices

```
GET /devices
Authorization: Bearer <sync-token>
```

### Register/Update Device

```
PUT /devices/{device_id}
Authorization: Bearer <sync-token>
Content-Type: application/json

{
    "name": "My Midori",
    "type": "desktop",
    "os": "Linux",
    "browser_version": "Midori 12.0"
}
```

### Remove Device

```
DELETE /devices/{device_id}
Authorization: Bearer <sync-token>
```

---

## Crypto Key Bundle

### Get Key Bundle

```
GET /crypto/keys
Authorization: Bearer <sync-token>
```

**Response (200):**
```json
{
    "encrypted_bundle": "<base64>",
    "version": 1,
    "updated_at": "2024-01-01T00:00:00Z"
}
```

### Store/Update Key Bundle

```
POST /crypto/keys
Authorization: Bearer <sync-token>
Content-Type: application/json

{
    "encrypted_bundle": "<base64>"
}
```

**Response (201):** Key bundle object.

---

## Error Responses

| Status | Meaning |
|--------|---------|
| 401 | Invalid or expired token |
| 403 | Storage quota exceeded |
| 404 | Collection or record not found |
| 412 | Conflict (stale write) |
| 413 | Payload too large |
| 422 | Validation error |
| 429 | Rate limited |

## Headers

| Header | Direction | Description |
|--------|-----------|-------------|
| `Authorization: Bearer <token>` | Request | Authentication |
| `X-If-Unmodified-Since` | Request | Conditional write (microsecond timestamp) |
| `X-Last-Modified` | Response | Last modification timestamp |
| `X-Device-Id` | Request | Device identifier (optional) |
