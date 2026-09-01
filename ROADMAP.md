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
- **MCP server** (`mcp/`). A standalone server exposing the panel's existing REST
  APIs as tools: 42 application (admin) tools and 68 client (user) tools, over a
  declarative endpoint table. Stdio transport with env-configured API keys.

## In progress

- **OAuth 2.1 via Laravel Passport.** The panel becomes its own authorization
  server so an MCP client authenticates as a real user rather than with a shared
  key, and administrators get the application tools automatically. Four scopes:
  `client:read`, `client:write`, `admin:read`, `admin:write`. Adds a Streamable
  HTTP transport to the MCP server with one session per user.

## Planned

### Borg-compatible backup system

Full Borg support, not a partial or best-effort integration — deduplicating,
incremental, encrypted repositories as a first-class backup target.

Lands as a third backup adapter beside the existing `Backup::ADAPTER_WINGS` and
`Backup::ADAPTER_AWS_S3`, registered in `config/backups.php`. **Needs Wings work:**
Borg runs on the node, against the server's data directory.

Open questions worth settling before building:

- Repository layout: one Borg repository per server, per node, or per panel, and
  what that implies for deduplication ratio versus blast radius on corruption.
- Passphrase and key custody — where the repokey lives, who can read it, and what
  happens on node compromise.
- Retention and pruning policy, and whether it is per-server or panel-wide.
- Restore semantics: Borg's archive model does not map onto the current
  restore-the-whole-backup flow, which the next item depends on.

### Richer user-facing backups

The client API currently exposes little more than a backup's name, size and
completion state. With a deduplicating backend there is much more worth showing:

- Per-archive contents, so a user can see what is actually in a backup.
- Single-file and single-directory restore, rather than all-or-nothing.
- Real disk usage — deduplicated and compressed size against logical size.
- Progress and failure detail during creation and restore.

### Minecraft world manager

Per-server management of Minecraft worlds: list, switch the active world, import
and export, delete, and back up or restore an individual world rather than the
whole server.

Fits the existing egg-features mechanism (`config/egg_features/`, alongside the
current `eula` feature) so it only appears on servers whose egg declares it.
**Needs Wings work** for the filesystem operations.

Open question: how much of this is world-format-aware — reading `level.dat` for
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
- Deploy hooks around the pull — stop, sync, run a command, start.
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
