# Roadmap

Direction for this fork. Everything here is additive to upstream Pterodactyl: the
fork must stay cleanly mergeable from `upstream/1.0-develop`, so features land as
new files and new adapters wherever possible, and touch upstream-owned files only
where there is no alternative.

Several items below need matching work in Wings. Those are marked, since the panel
side cannot ship alone.

## Delivered

- **Per-user two-factor enforcement.** A nullable `require_2fa` column on users:
  `null` inherits the global `pterodactyl.auth.2fa_required` level, `true` forces
  2FA regardless of it, `false` exempts. Exposed in the admin user pages and the
  application API; deliberately not settable from the client API.
- **MCP server.** The panel serves the Model Context Protocol natively at
  `POST /mcp`, over a declarative endpoint table: 42 application (admin) tools
  and 68 client (user) tools. Stateless Streamable HTTP, authenticated with
  either an API key or an OAuth bearer token, dispatching every tool call
  through the panel's own HTTP kernel so it gets the same middleware and
  permission checks a REST call would. No separate process, no reverse proxy.
- **OAuth 2.1 via Laravel Passport.** The panel is its own authorization server
  so an MCP client authenticates as a real user rather than with a shared key,
  and administrators get the application tools automatically. Four scopes:
  `client:read`, `client:write`, `admin:read`, `admin:write`. The panel is also
  an OAuth resource server for `/mcp`, publishing RFC 9728 protected-resource
  metadata so a client can discover the authorization server on its own.
- **Borg-compatible backup system.** A third backup adapter beside
  `Backup::ADAPTER_WINGS` and `Backup::ADAPTER_AWS_S3`, registered in
  `config/backups.php`. One Borg repository per server rather than per node or
  panel-wide: Borg has no per-archive authorization inside a repository, so
  sharing one across servers would let any server's key read every other
  server's data, and per-server also caps the blast radius of corruption. The
  repository passphrase is never stored - it is derived from the server UUID
  and `BORG_PASSPHRASE_SECRET` on demand, the same secret discipline as
  `APP_KEY`. Retention stays a panel concern rather than Borg's own `prune`,
  since `prune` has no way to see a locked backup and would eventually delete
  one out from under the panel. A configurable mode picks between one
  repository per server (the default, deduplicating) and one repository per
  backup (self-contained, no deduplication). Deleting a server no longer
  loses track of its backups either: they are recorded in an
  `orphaned_backups` table before the cascade removes them and appear in the
  unified backups list at `/admin/backups`, where they can be deleted or
  forgotten. See [`BACKUPS.md`](BACKUPS.md). **Needs Wings work:** the node
  side does not exist yet, so this delivered piece is
  the panel half plus the specification the node side will be built against.

## Planned

### Attribution for OAuth-driven activity

Activity logs currently record `api_key_id = null` for actions driven through
OAuth bearer tokens, making them indistinguishable from browser actions by the
same user. While this is at parity with a stolen session cookie (not a new
vulnerability), it prevents understanding which MCP client or external
application performed an action. Attribute OAuth-driven activity to the OAuth
client so the activity log can distinguish an MCP client from a browser session.

### Richer user-facing backups

The client API currently exposes little more than a backup's name, size and
completion state. With a deduplicating backend there is much more worth showing:

- Per-archive contents, so a user can see what is actually in a backup.
- Single-file and single-directory restore, rather than all-or-nothing.
- Real disk usage: deduplicated and compressed size against logical size.
- Progress and failure detail during creation and restore.

Three follow-ups from the Borg work belong here too: a reconciliation command
that sweeps up repositories orphaned by deleted servers along with the
per-backup client caches and empty repository skeletons that snapshot mode
leaves behind on the node after a delete, and time-based retention as a
panel scheduled command rather than anything driven by Borg's own `prune`.

### Minecraft world manager

Per-server management of Minecraft worlds: list, switch the active world, import
and export, delete, and back up or restore an individual world rather than the
whole server.

Fits the existing egg-features mechanism (`config/egg_features/`, alongside the
current `eula` feature) so it only appears on servers whose egg declares it.
**Needs Wings work** for the filesystem operations.

Open question: how much of this is world-format-aware - reading `level.dat` for
seed, game mode and version enables a far better interface, but couples the panel
to Minecraft world formats and their version drift.

### Versioning for YAML configuration files

A way to keep a history of server configuration files and roll back a bad edit.
**Approach still to be decided.** The two shapes worth comparing:

- Panel-side snapshots taken on write, stored and diffed by the panel. Self
  contained, no external dependency, but a second storage concern to operate.
- A git repository backing the server's config directory, which makes history,
  diffing and rollback free but puts a git dependency on the node.

Either way it hooks the file manager's write path. The second option overlaps
heavily with the deployment item below, so the two should be decided together
rather than separately.

### Git and deployment integration

Deploy server content from a git repository or another deployment source, with
first-class plugin deployment.

- Pull a repository into a server's files, on demand or on a schedule.
- Deploy hooks around the pull: stop, sync, run a command, start.
- Plugin deployment: install and update plugins from their upstream sources,
  with pinned versions so a deployment is reproducible.
- Credentials for private repositories, kept out of server files.

**Needs Wings work.** Overlaps with the YAML versioning item; settle the git
question once, for both.

## Cross-cutting

- Every feature above should gain MCP tools as it lands, so the panel stays fully
  drivable from an MCP client. The endpoint table makes this cheap: new panel
  routes become new rows.
- Items needing Wings work should be specced on both sides before either starts,
  so the panel does not ship an API the node cannot serve.
