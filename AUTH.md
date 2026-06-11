# Authentication Implementation Plan

## Goal
Simple stateless token-based authentication using self-contained HMAC-signed tokens (no PHP sessions, no DB storage for tokens). Token is a JWT-like `base64(payload).base64(signature)` string signed with the app's existing encryption key.

---

## Token Format

```
base64url(json_payload) . '.' . base64url(hmac_signature)
```

**Payload:**
```json
{
  "user": "admin",
  "exp": 1735689600,
  "iat": 1735603200
}
```

**Signature:** `HMAC-SHA256(base64url(payload), app_key)`

Token lifetime: 24 hours from issuance.

---

## Files to Create

### `src/Core/Auth.php`
- `generateToken(string $username): string` — creates HMAC-signed token with 24h expiry
- `validateToken(string $token): ?array` — verifies signature and expiry, returns payload on success, null on failure
- `authenticate(string $password): ?string` — checks password against config, returns token or null
- `getCurrentUser(Request $request): ?string` — extracts username from `Authorization` header or `auth_token` cookie

### `src/Api/AuthController.php`
| Method | Route | Description |
|--------|-------|-------------|
| login | `POST /api/auth/login` | Accepts `{username, password}`, returns `{token, user}` |
| me | `GET /api/auth/me` | Returns current user info from token |
| logout | `POST /api/auth/logout` | No-op (stateless), returns success |

### `views/auth/login.php`
- Standalone login page (full HTML, not wrapped in the app layout)
- Username/password form
- On success: stores token in `localStorage` + `auth_token` cookie, redirects to `/`
- Shows error on bad credentials

---

## Files to Edit

### `config/app.php`
Add `auth` section:
```php
'auth' => [
    'enabled' => false,
    'username' => 'admin',
    'password' => 'admin',
],
```
When `enabled` is `false`, all routes are public (backward compatible).  
When `true`, login is required for all routes except the bypass list.

### `index.php`
Register global auth middleware via `$router->addMiddleware()`.

### `src/Core/Router.php`
The existing `addMiddleware()` and `$middleware` infrastructure is already in place. The auth check closure is registered in `index.php`.

### `src/web_routes.php`
- Add `GET /login` → renders `views/auth/login.php` (standalone, no layout navbar)
- All other web routes remain unchanged (protected by middleware)

### `src/routes.php`
- Add `POST /api/auth/login`
- Add `GET /api/auth/me`
- Add `POST /api/auth/logout`

### `views/layout.php`
- Add support for a `showNavbar` variable set to `false` (login page renders without navbar)
- Currently always includes `partials/navbar.php`

### `views/partials/navbar.php`
- Show logged-in username on the right side
- Add logout button that clears token + cookie and redirects to `/login`

### `js/app.js`
In the `api()` method:
- Read token from `localStorage` (`auth_token` key)
- Include as `Authorization: Bearer <token>` header
- On 401 response, redirect to `/login`

---

## Auth Bypass Routes (always public)

| Route | Reason |
|-------|--------|
| `GET /login` | Login page itself |
| `POST /api/auth/login` | Login API |
| `GET /api/render/*` | Embeddable report rendering (iframes, external access) |
| `GET /css/*`, `/js/*`, `/img/*` | Static assets |

---

## Middleware Flow

```
Request → Router::dispatch()
  → Run global auth middleware:
    → Is route in bypass list? → skip auth
    → Is auth enabled in config?
      → API request (Accept: application/json or path starts with /api/):
        → Check Authorization: Bearer <token> header
        → Invalid/missing → return 401 JSON response
      → Web request:
        → Check auth_token cookie
        → Invalid/missing → redirect 302 to /login
    → Auth disabled → skip auth
  → Route matched → controller called
```

---

## Frontend Flow

```
1. User visits any page
   → Middleware checks auth_token cookie
   → Not authenticated → redirect to /login

2. User submits login form
   → POST /api/auth/login {username, password}
   → Server validates against config
   → Returns {token, user}
   → JS stores token in localStorage + sets auth_token cookie
   → Redirects to /

3. API calls from JS (app.js api() method)
   → Read token from localStorage
   → Include in all requests: Authorization: Bearer <token>
   → On 401 response → redirect to /login

4. Logout
   → Clear localStorage auth_token
   → Clear auth_token cookie
   → Redirect to /login
```

---

## Cookie Settings

- Name: `auth_token`
- Path: `/`
- HTTP-only: `false` (JS needs to read it for the api() method, but we use localStorage primarily)
- Secure: `true` if HTTPS
- SameSite: `Lax`

Since the JS `api()` method reads from localStorage (not the cookie), the cookie is only used as a signal for the server-side middleware on page loads. The JS uses localStorage as the source of truth.

---

## Transition Path

1. Set `'enabled' => false` in config → existing deployments continue working with no auth
2. Set `'enabled' => true`, configure `username` and `password` → auth is enforced
3. All API consumers need to add `Authorization: Bearer <token>` header after obtaining a token via `POST /api/auth/login`
