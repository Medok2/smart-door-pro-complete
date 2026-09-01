# Smart Door Pro - API Documentation

## Base URL

```
https://yourdomain.com/api/v1
```

## Authentication

### Admin Authentication

**Login**

```http
POST /admin/login HTTP/1.1
Content-Type: application/json

{
  "email": "admin@smartdoor.com",
  "password": "your_password"
}
```

**Response (200)**

```json
{
  "ok": true,
  "data": {
    "user": {
      "id": 1,
      "email": "admin@smartdoor.com",
      "role": "admin"
    },
    "tokens": {
      "access_token": "abcd1234...",
      "refresh_token": "efgh5678...",
      "expires_in": 3600
    }
  },
  "requestId": "abc123",
  "serverTime": "2024-01-01T10:00:00Z"
}
```

### Device Authentication

All device requests must include:

```http
Device-ID: DEVICE_1A2B3C4D
Device-Signature: hmac_sha256_hex
```

## Endpoints

### Door Control

**Open Door**

```http
POST /door/open HTTP/1.1
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "duration": 3000
}
```

**Response (202)**

```json
{
  "ok": true,
  "data": {
    "command_id": "CMD_A1B2C3D4",
    "status": "PENDING",
    "message": "Door open command queued"
  }
}
```

### Device Endpoints

**Poll for Commands**

```http
POST /device/poll HTTP/1.1
Device-ID: DEVICE_1A2B3C4D
Device-Signature: {signature}
```

**Response (200)**

```json
{
  "ok": true,
  "data": {
    "command": {
      "id": 123,
      "command_id": "CMD_A1B2C3D4",
      "action": "unlock",
      "duration_ms": 3000,
      "expires_at": "2024-01-01T10:00:10Z",
      "signature": "..."
    }
  }
}
```

**Send Acknowledgment**

```http
POST /device/ack HTTP/1.1
Device-ID: DEVICE_1A2B3C4D
Device-Signature: {signature}
Content-Type: application/json

{
  "command_id": "CMD_A1B2C3D4",
  "status": "EXECUTED",
  "actual_duration_ms": 3001,
  "free_heap": 45000
}
```

**Send Heartbeat**

```http
POST /device/heartbeat HTTP/1.1
Device-ID: DEVICE_1A2B3C4D
Content-Type: application/json

{
  "status": "online",
  "rssi": -65,
  "free_heap": 45000,
  "uptime_seconds": 86400
}
```

### User Management

**List Users**

```http
GET /users?page=1&limit=50 HTTP/1.1
Authorization: Bearer {access_token}
```

**Create User**

```http
POST /users HTTP/1.1
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "name": "John Doe",
  "permissions": 1,
  "remaining_uses": 5
}
```

**Response (201)**

```json
{
  "ok": true,
  "data": {
    "user": {
      "user_id": 1234,
      "activation_code": "A1B2C3D4",
      "name": "John Doe"
    }
  }
}
```

### Guest Passes (Public)

**Get Pass Info**

```http
GET /guest/pass/{token} HTTP/1.1
```

**Open Door via QR**

```http
POST /guest/pass/{token} HTTP/1.1
Content-Type: application/json

{
  "action": "open"
}
```

### Health Check

```http
GET /health HTTP/1.1
```

**Response (200)**

```json
{
  "ok": true,
  "data": {
    "status": "ok",
    "version": "1.0.0",
    "database": {"status": "connected"},
    "device": {"online": true}
  }
}
```

## Error Responses

All errors return JSON with appropriate HTTP status code:

```json
{
  "ok": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message"
  },
  "requestId": "abc123"
}
```

### Status Codes

- `200` - Success
- `201` - Created
- `202` - Accepted (async operation)
- `204` - No content
- `400` - Bad request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found
- `409` - Conflict
- `410` - Gone (resource expired)
- `429` - Too many requests
- `503` - Service unavailable

## Rate Limiting

Door open requests are limited to 10 per minute per admin.

```http
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 5
X-RateLimit-Reset: 1640000000
```

## Security Headers

All responses include:

```http
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
```
