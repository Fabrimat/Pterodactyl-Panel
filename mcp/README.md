# Pterodactyl panel MCP server

An MCP (Model Context Protocol) server that exposes the panel's existing REST
APIs as tools. It is a thin, typed client over HTTP - it does not reimplement
any panel logic, and it adds no new panel endpoints. Everything it can do is
exactly what `/api/application` and `/api/client` already do.

Every tool maps 1:1 to a route in `routes/api-application.php` (Application
API, `panel_admin_*` tools) or `routes/api-client.php` (Client API,
`panel_client_*` tools), with one deliberate exception: the `/websocket`
route is not exposed, since a token plus a raw socket URL is not usable over
this server's stdio transport.

## Setup

```
cd mcp
npm install
npm run build
```

Configure the server with environment variables (see `.env.example`):

| Variable | Required | Description |
| --- | --- | --- |
| `PANEL_URL` | yes | Base URL of the panel, no trailing slash. |
| `PANEL_APPLICATION_KEY` | one of these two | Admin API key (`ptla_...`). Enables the `panel_admin_*` tools. |
| `PANEL_CLIENT_KEY` | one of these two | User API key (`ptlc_...`). Enables the `panel_client_*` tools. |
| `PANEL_MCP_READ_ONLY` | no | Set to `1` to only register GET (read-only) tools. |

At least one of `PANEL_APPLICATION_KEY` / `PANEL_CLIENT_KEY` must be set; the
server refuses to start with neither. The two keys are independent - set only
`PANEL_CLIENT_KEY` to get a user-scoped server with no admin capabilities at
all, or only `PANEL_APPLICATION_KEY` for an admin-only server, or both for
the full tool set. Keys are read from the environment only; there is no
config file and no tool parameter that accepts a key.

Run it with `npm start`, or point an MCP client at
`node <path-to-mcp>/dist/src/index.js` with the environment variables above
set in the client's server configuration. The server speaks stdio.

## Design

- `src/endpoints.ts` is a single declarative table: one row per route, each
  carrying its HTTP method, path template, and zod schemas for path
  parameters, query parameters and body fields.
- `src/index.ts` iterates that table once and registers one MCP tool per row
  against a shared handler. There is no per-tool code and no generic
  `panel_request(method, path)` escape hatch.
- `src/client.ts` is the only place that talks HTTP. It never exposes a raw
  request/response/error object - only a status code plus the panel's own
  JSON error body ever reach a tool result.

### Special cases handled in the row schema

- **Raw text bodies/responses.** `files/contents` returns plain text
  (`responseType: 'text'`), and `files/write` sends the new content as a raw
  `text/plain` body with the path in a `file` query parameter
  (`bodyType: 'text'`), not JSON.
- **Signed URLs.** `files/download`, `files/upload` and backup download
  return a one-time signed Wings URL in a normal JSON response. The tool
  hands that URL back as its result; it does not fetch or proxy the file
  itself, and each tool's description says so.
- **Pagination/filtering.** List tools accept `page` and `per_page`, and
  where the underlying controller supports it, `filter`, `sort` and
  `include` too.
- **Destructive actions.** GET tools are annotated `readOnlyHint: true`.
  Every other tool is treated as destructive by default (the MCP spec
  defaults `destructiveHint` to true when the annotation is absent, so this
  server never asserts `destructiveHint: false` on anything - that would
  tell a hint-gating client a merely-unflagged action needs no
  confirmation). The `destructive: true` flags in `endpoints.ts` don't
  change that; they document *why* a specific non-GET row is dangerous
  (server reinstall, `files/write`, `files/decompress`, backup restore,
  two-factor disable, database password/credential rotation,
  `panel_admin_users_update`).
- **Read-only mode.** `PANEL_MCP_READ_ONLY=1` skips registering every
  non-GET row.

## Scope: what this does NOT cover

This server can only do what the panel's REST API already exposes. As of
this API version, the Application API has **no endpoints at all** for:

- Panel-wide settings (mail, general config, etc.)
- Database hosts (the servers MySQL/PostgreSQL databases are created on)
- Mounts
- Creating, updating, importing or deleting nests or eggs (nest and egg
  routes are read-only: list and view only)

Adding tools for any of the above would require adding new PHP routes and
controllers to the panel, which is out of scope here - this server only
wraps what already exists.

## Two-factor authentication interaction

`RequireTwoFactorAuthentication` lives in the base `'api'` middleware group
(`app/Http/Kernel.php`), which wraps both `/api/application` and
`/api/client` equally in `RouteServiceProvider`. It is not part of the
`client-api` group, and it does not branch on which API is being called - it
only looks at the resolved user (`$request->user()`), which both `ptla_` and
`ptlc_` keys resolve identically since both are Sanctum personal access
tokens.

- At global 2FA level "all users", both key types are rejected identically
  if the key's owning user has not enrolled TOTP.
- At global level "admins only", an **Application API key is the exposed
  case, not the exempt one**: `ptla_` keys can only be created by root
  admins (`AuthenticateApplicationUser` rejects non-admins), so their owner
  is always in the targeted population. An ordinary non-admin `ptlc_` key
  owner is not required to have 2FA at this level.
- Either way, the fix for a service account is the per-user override: set
  that user's `require_2fa` to `false` (exempt), rather than changing the
  panel-wide setting.

`panel_admin_users_update` is not a partial update - the panel requires
`email`, `username`, `first_name` and `last_name` on every call, not just
the field being changed. To exempt a service account: first call
`panel_admin_users_view` for that user, then call `panel_admin_users_update`
with `require_2fa: false` plus that user's current `email`, `username`,
`first_name` and `last_name` echoed back unchanged. Omitting any of those
four fields gets the request rejected.

## Testing

```
npm test
```

Runs the build then `node --test` against the compiled test in
`test/redaction-check.ts`, which asserts that the error path in `client.ts`
never leaks the `Authorization` header or a raw request/error object into a
tool result, even when the underlying HTTP client throws an axios-shaped
error object that carries the header on `.config.headers`.
