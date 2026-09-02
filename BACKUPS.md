# Borg backup adapter

The panel does not run Borg. Wings does, on the node, against the server's data
directory. The panel's part is registering the adapter, owning its
configuration, deriving each repository's passphrase, and shipping a `borg`
configuration object to Wings inside the three daemon calls it already makes
for a backup: create, restore and delete.

**The node needs Borg support too.** Upstream Wings does not implement this
adapter, so a node running it refuses a borg backup; the node half lives in
this fork's Wings, and Borg itself has to be installed there. The wire contract
section below is authoritative for both halves and is what each is built
against.

## How it works

Every server gets its own Borg repository, at `<repository base>/<server
uuid>`, under the default incremental mode - see **Backup mode: incremental
versus snapshot** below for the alternative. The archive inside a repository
is named after the backup's UUID, so one archive is one backup and the
panel's existing restore-the-whole-backup flow needs no change to work with
Borg's archive model.

One repository per server is deliberate, not a first pass that will later be
collapsed into one shared repository. Borg has no per-archive authorization
inside a repository - any key that can open the repository can read every
archive in it - so a repository shared across servers would let one server's
key read another server's data. Splitting per server also keeps the blast
radius of repository corruption to a single server. The cost is real: many
game servers hold identical jars and binaries, and none of that is
deduplicated across the boundary. That trade is intentional.

## Backup mode: incremental versus snapshot

`BORG_BACKUP_MODE` (`backups.disks.borg.mode`) chooses which of two repository
layouts a new backup is written into. It defaults to `incremental`, the
layout described above and the one every panel already running this adapter
keeps using unless it opts in to the other:

* `incremental` - one repository per server, at `<repository base>/<server
  uuid>`, holding every archive that server has ever produced. This is the
  layout the deduplication described below depends on.
* `snapshot` - one repository per backup instead, at `<repository
  base>/<server uuid>_<backup uuid>`. Each one is self-contained and shares
  nothing with any other repository, including the other backups of the same
  server.

A snapshot repository sits as a flat sibling of the per-server repository
rather than nested inside it, and that placement is load-bearing rather than
a style choice. Two things about Borg rule out the nested alternative,
`<repository base>/<server uuid>/<backup uuid>`:

* `borg init` creates the repository directory with a single-level `mkdir`.
  It only creates missing intermediate directories when passed
  `--make-parent-dirs`, which Wings does not. A nested path has two missing
  parents and would fail at init on every server's very first snapshot
  backup over `ssh://`, which is the supported mode.
* Borg refuses outright to create a repository underneath an existing one -
  it walks every parent directory looking for a repository README and aborts
  if it finds one. Any server that has ever run under incremental mode
  already has a repository at `<repository base>/<server uuid>`, so nesting
  a snapshot repository under it would fail for that reason too.

Changing the mode only affects backups created after the change. An existing
backup keeps resolving to the repository it was actually written to, no
matter what the mode is set to afterwards - see **Where a backup's
repository is recorded** below for how that is arranged.

### The cost of snapshot mode

Three things worth knowing before choosing it, not after:

* There is nothing to deduplicate against, since every repository starts
  empty. Every backup transfers and stores its full size. This is the entire
  point of the mode and also its price.
* The node itself accumulates a permanent, unbounded cache - the more
  serious of the two residues below, because it is the node's own disk that
  fills up rather than the backup storage host. Borg keeps a client-side
  cache per repository under Wings' own cache directory, on the node. In
  incremental mode that cache is one per server, so it is bounded by how
  many servers the node runs. In snapshot mode it is one per backup instead:
  deleting an archive does not drop its repository's cache, only deleting
  the whole repository does, which the panel never asks for, so every
  snapshot backup ever taken on that node leaves its cache behind for good.
  This is disk consumption that grows with the number of snapshot backups
  ever taken there, nothing more - not data loss, and not a failure of any
  backup - but it is disk consumption on the node running servers, which is
  a worse place for it to land than the backup storage host.
* Deleting the only archive in a snapshot repository also leaves an empty
  repository skeleton behind, on the storage host. `borg compact` reclaims
  the archive's data but does not remove the repository directory itself.

### Where a backup's repository is recorded

A nullable `borg_repository` column on `backups` holds the backup's
repository, as a suffix relative to the configured base - never an absolute
URL. It is recorded once, when the backup is created, and used verbatim for
every restore, delete and download of that backup afterwards.

`NULL` means the legacy per-server layout, `<repository base>/<server
uuid>`, unconditionally, regardless of what the mode is set to now. An
incremental backup records `NULL` rather than the server UUID for the same
reason: a pre-column backup and one taken under incremental mode resolve to
the identical repository, so there is nothing to distinguish between them and
nothing worth recording.

This is also why changing the mode does not strand existing backups the way
changing `BORG_REPOSITORY` still does, and for the same reason it always did.
The column stores a suffix, relative to the base, precisely so that the one
kind of base change that works today keeps working: rsync the whole
repository tree to a new host and update `BORG_REPOSITORY`. An absolute URL
recorded per row instead would have defeated that - every existing backup
would have kept pointing at the dead host regardless of where
`BORG_REPOSITORY` was updated to.

## Deduplication and incrementals

Both are Borg's own doing, not something the adapter turns on. Borg splits
file content into content-defined chunks and only stores chunks it has not
seen before in that repository, so the first backup of a server is full and
every backup after it transfers and stores only what changed. There is no
setting for this and nothing to configure - it is the reason to choose this
adapter over a plain archive-and-upload one.

Measured rather than assumed, on a real node over an `ssh://` repository: a
second backup of an unchanged 58 MB server added **652 bytes** to its
repository, against a logical archive size of 60,899,838 bytes. Two archives of
identical content occupy the space of one.

That figure is also the thing to check first if deduplication ever looks wrong,
because there is one way to lose it completely while everything still appears
to work. Borg chunks whatever stream it is given, so a node that handed it an
already-compressed archive would be chunking compressed bytes, where a single
changed byte near the start alters everything after it. Deduplication would
collapse to nearly nothing and every backup would quietly transfer in full. A
second backup of an unchanged server costing roughly as much as the first is
that symptom.

Everything above is true of incremental mode, which is where the two
measured archives actually live, and false of snapshot mode by design: a
snapshot repository starts empty every time, so there is nothing prior in it
to deduplicate against. That is not this section's chunking failing to do
its job - it is the direct, intended consequence of choosing that layout. See
**Backup mode: incremental versus snapshot** above.

## Passphrase custody

Every repository is created `repokey-blake2` by default, and the derived
passphrase itself is never stored anywhere. It is recomputed on demand:

```
HMAC-SHA256('borg:v1:' + server UUID, the passphrase secret in force)
```

The secret that derivation consumes can live in either of two places, and
which one is in force decides what a recovery needs. Set only in the
environment, it is `BORG_PASSPHRASE_SECRET`. Saved at
`/admin/backups/settings/borg`, it becomes a row in the `settings` table
encrypted with `APP_KEY`, and takes precedence over the environment variable,
which stops having
any effect until the stored value is cleared. See **Configuration** below.

This has consequences worth stating plainly:

* The secret in force must be backed up with exactly the care given to
  `APP_KEY`. Lose it and every repository becomes unreadable ciphertext: the
  derived passphrase exists nowhere to be read back, and no amount of database
  access reconstructs it without the secret.
* Whether a rebuilt panel can open the existing repositories depends on which
  of the two places the secret was in, so a backup plan has to cover the right
  one. If it was only ever an environment variable, the environment alone is
  enough. If it was saved at `/admin/backups/settings/borg`, what is needed
  is the `settings` row **together with** the `APP_KEY` that encrypted it:
  either one without the other recovers nothing, and an environment copy left
  over from
  before the save is stale and derives the wrong passphrases.
* The server UUIDs a recovery needs are recoverable from the repository
  directory names themselves, in both cases, and regardless of which
  repository mode was in force when a given repository was written - see
  **Opening a repository without the panel** below for how the directory
  name differs between the two.
* The `borg:v1:` prefix is domain separation and a version marker, not
  decoration. Rotation is not supported in this version, and
  `/admin/backups/settings/borg` does not provide it: replacing the secret
  there abandons access to every
  repository the old one unlocked rather than re-keying them. If real rotation
  is ever built, the upgrade path is a `v2` prefix plus a maintenance command
  that runs `borg key change-passphrase` over every repository.
* `borg key change-passphrase` only re-wraps the repository key; it does not
  rotate the encryption key underneath it. After a node compromise, a new
  repository is the only real remedy - re-keying the existing one does not
  undo exposure of data already read with the old key.

### What a replaced secret looks like afterwards

Worth knowing before it happens, because almost none of it is visible from the
panel. Once the secret no longer matches a repository, the node finds the
repository present but unopenable and fails the backup. The failure report it
sends back carries a success boolean and nothing else: there is no reason field
on the completion report or on the websocket event, so the panel has no cause
to store and none to show. The message explaining what went wrong exists in
exactly one place, the wings log on the node.

The operator's experience is therefore every backup for every affected server
failing indefinitely, with no cause reachable from the panel at all, unless
they have shell on the node and know to go read that log. This is why the
confirmation at `/admin/backups/settings/borg` warns unconditionally and
offers no reassuring branch: it is not a courtesy before a scary action, it
is the only diagnostic for this failure that ever reaches a human, and it
has to arrive before the fact because nothing arrives after it.

Carrying a reason through would mean a new field on both the completion report
and the event payload, in files upstream owns on both sides. That is a design
conversation rather than a fix, and it is not built.

### Opening a repository without the panel

Because the passphrase is derived rather than stored, recovering a repository
needs no Pterodactyl code at all - only the secret in force, the server's
UUID, and Borg. Deriving it by hand is the same HMAC the panel computes:

```bash
# The secret in force, which is not necessarily what the environment says. If
# it was saved at `/admin/backups/settings/borg`, take the settings row for
# backups:disks:borg:passphrase_secret and decrypt it with the panel's
# APP_KEY. Otherwise it is BORG_PASSPHRASE_SECRET from the environment.
SECRET_IN_FORCE=...

export BORG_PASSPHRASE=$(printf 'borg:v1:%s' "$SERVER_UUID"     | openssl dgst -sha256 -hmac "$SECRET_IN_FORCE" -hex     | awk '{print $NF}')

# Incremental mode: one repository per server.
borg list "$BORG_REPOSITORY/$SERVER_UUID"

# Snapshot mode: one repository per backup, named after both UUIDs.
borg list "$BORG_REPOSITORY/${SERVER_UUID}_${BACKUP_UUID}"
```

The passphrase derivation above is identical either way - it only ever
depends on the server UUID, never on which repository the backup landed in -
so the same `BORG_PASSPHRASE` line opens a repository written under either
mode. What differs is the directory name. Under incremental mode the server
UUID is the repository directory name outright, so a repository base is
self-describing: an operator holding the secret can enumerate and open every
repository in it with nothing but Borg. Under snapshot mode a directory name
is `<server uuid>_<backup uuid>` instead - the server UUID is still there, as
the segment before the first underscore, since a UUID never itself contains
one - so the same enumeration still works, just one directory per backup
rather than one per server. Either way this is the disaster recovery path,
and it is worth testing before it is needed rather than after.

## Retention

The panel is the retention authority. Borg's own `prune` is deliberately not
exposed anywhere in this adapter. `Backup::is_locked` has no representation
Borg can see, so a scheduled `borg prune` would eventually delete a locked
backup's archive out from under the panel, leaving a backup listed that no
longer exists on disk.

Retention works the same way it does for every other adapter: the per-server
backup limit an administrator sets, plus the locked flag. Deleting a backup
in the panel maps to `borg delete` of that one archive on the node. Time-based
retention, if it is ever wanted, belongs in a panel scheduled command that
deletes `Backup` rows through `DeleteBackupService` - that keeps one retention
authority that works for every adapter, Borg included, instead of a second one
living inside Borg itself.

## Configuration

The BACKUPS sidebar category has two entries. Backups at `/admin/backups`
lists all backups (live and orphaned), with each row showing its server, and
can be filtered by server or orphan status. Backup Settings at
`/admin/backups/settings/{section}` holds driver-specific configuration, where
section is `general`, `s3`, or `borg`.

Every value below can be set either in the environment or through the panel.
`APP_BACKUP_DRIVER` lives at `/admin/backups/settings/general` and every
`BORG_` value below it at `/admin/backups/settings/borg`. A value saved
through the page becomes a row in the `settings` table and takes precedence
over the environment variable of the same name, which stops having any effect
until that row is cleared; clearing it returns the environment variable to
force. The passphrase secret and the SSH private key are stored encrypted
with `APP_KEY`. A panel that sets `APP_ENVIRONMENT_ONLY=true` ignores stored
settings entirely and reads the environment only, so on such a panel the page
shows these values without being able to change them.

| Variable | Default | Meaning |
|---|---|---|
| `APP_BACKUP_DRIVER` | `wings` | set to `borg` to make it the default adapter |
| `BORG_REPOSITORY` | none, required | base location; every repository this adapter creates sits underneath it, in the layout `BORG_BACKUP_MODE` selects |
| `BORG_PASSPHRASE_SECRET` | none, required | passphrase derivation secret |
| `BORG_ENCRYPTION` | `repokey-blake2` | `repokey-blake2`, `keyfile-blake2`, `repokey`, `keyfile`, `authenticated-blake2`, `authenticated`, `none` |
| `BORG_COMPRESSION` | `zstd,3` | passed to `borg --compression` verbatim: `none`, `lz4`, `zstd[,1-22]`, `zlib[,0-9]`, `lzma[,0-9]`, optionally `auto,` prefixed |
| `BORG_BACKUP_MODE` | `incremental` | `incremental` or `snapshot` - see **Backup mode: incremental versus snapshot** above |
| `BORG_SSH_PRIVATE_KEY` | none | key for a remote `ssh://` repository |
| `BORG_SSH_KNOWN_HOSTS` | none | host verification for the same |
| `BORG_LOCK_WAIT` | `600` | seconds to wait on the repository lock before failing the backup |
| `BORG_CHECKPOINT_INTERVAL` | `1800` | seconds between checkpoints during a long backup |
| `BORG_UPLOAD_RATELIMIT` | `0` | KiB/s, 0 disables |

`zstd,3` is the default because it sits close to `lz4`'s speed while
compressing meaningfully better. Drop to `lz4` on a node that is CPU-bound
rather than storage- or bandwidth-bound; raise the `zstd` level when the link
to a remote repository, not the CPU doing the compressing, is the bottleneck.

The panel validates `BORG_COMPRESSION` and `BORG_ENCRYPTION` when it builds a
backup request, so a typo in either one fails on the panel rather than
failing silently on the node partway through a backup.

`BORG_LOCK_WAIT` carries more weight than its position in that table suggests.
Borg's own default is one second, so the field is what makes two operations on
the same repository queue behind each other instead of the second one failing
almost immediately. It is not a tuning knob to be removed for looking
arbitrary.

## Remote versus local repositories

A remote `ssh://` repository is the supported mode. A local path on the node
works too, but it is not transfer-safe: the repository lives per server while
a local path lives per node, so a server transferred to a different node
leaves its backups behind, unreachable from the new one.

Changing `BORG_REPOSITORY` after backups already exist strands them in the old
location - exactly like changing the bucket does for the `s3` adapter. Nothing
migrates existing repositories automatically.

### Preparing an ssh:// repository host

The panel never touches a repository itself, so all of this is set up once on
the machine that stores them. That machine can be the node: Borg does not care
that the far end is the same host, and pointing at `127.0.0.1` keeps the
traffic off the network entirely while still using the remote code path.

Borg has to be installed at both ends, and the account the node connects as
needs a login shell, since `borg serve` runs as its command.

```bash
useradd --system --create-home --home-dir /var/lib/borg --shell /bin/bash borg
mkdir -p /var/lib/borg/pterodactyl
chown -R borg:borg /var/lib/borg
```

Generate the key the panel will hold. It must have **no passphrase**: the node
connects with `BatchMode=yes`, so there is no prompt at which one could be
entered.

```bash
ssh-keygen -t ed25519 -N '' -f borg_ed25519
```

Authorize it with a forced command rather than as an ordinary login key:

```
command="borg serve --restrict-to-path /var/lib/borg/pterodactyl",restrict ssh-ed25519 AAAA...
```

That is worth the extra line. The panel hands this key to every node that runs
a backup, which makes it the most widely copied secret in the system. The
forced command means it can only ever start `borg serve`, and
`--restrict-to-path` means it cannot open a repository outside that directory,
so a leaked key does not become access to the whole host. Confirm the
restriction rather than trusting the line: `borg info` against a path outside
it should fail.

`BORG_SSH_PRIVATE_KEY` holds the key's contents rather than a path to the file,
in the same shape as `PASSPORT_PRIVATE_KEY`. `BORG_SSH_KNOWN_HOSTS` holds the
repository host's public key, as `ssh-keyscan` prints it:

```bash
ssh-keyscan -t ed25519 127.0.0.1
```

Populating it is not optional in practice. The node keeps
`StrictHostKeyChecking=yes` and never falls back to accepting an unknown host,
so a missing or mismatched entry refuses the connection and arrives at the
panel as a failed backup rather than as something that reads like a
configuration mistake.

A `./` after the host is relative to that account's home directory, so
`ssh://borg@127.0.0.1/./pterodactyl` with the layout above resolves to
`/var/lib/borg/pterodactyl`, and each server's repository is created
underneath it.

## The Wings wire contract

The `borg` object reaches Wings by two complementary channels, pushed for the
operations the panel itself initiates and pulled for the one it does not:

* **Pushed.** The panel adds the object to the JSON body of the three
  requests it already makes to the node:
  * `POST /api/servers/{server}/backup`
  * `POST /api/servers/{server}/backup/{backup}/restore`
  * `DELETE /api/servers/{server}/backup/{backup}` - this request carries no
    body upstream and gains one here.
* **Pulled.** A backup download is served by the node in response to the
  user's browser hitting the existing JWT-signed download endpoint, not by a
  request the panel makes - the download JWT carries only `backup_uuid` and
  `server_uuid` and travels as a query parameter, so it can never carry a
  passphrase, and Wings has nothing to call back to on that path. For this
  case only, Wings fetches the object itself, on demand, from:

  ```
  GET /api/remote/backups/{backup}/borg
  ```

  behind the same node authentication as the rest of `/api/remote`, the
  direct analogue of the existing endpoint that hands S3 credentials to the
  node. It answers `403` when the requesting node does not own the server,
  `400` when the backup is not a borg backup, and `404` for an unknown
  backup. Persisting the object node-side instead was considered and
  rejected: that would put the passphrase at rest on the node, which is
  exactly what deriving it on demand is meant to avoid.

Both channels carry the identical object - one contract, not two:

```json
{
  "repository": "ssh://borg@backup.example.com:22/./pterodactyl/<server-uuid>",
  "archive": "<backup-uuid>",
  "passphrase": "<64 hex characters>",
  "encryption": "repokey-blake2",
  "compression": "zstd,3",
  "ssh_private_key": null,
  "ssh_known_hosts": null,
  "lock_wait": 600,
  "checkpoint_interval": 1800,
  "upload_ratelimit": 0
}
```

The object has no field for which repository layout mode produced it. The
mode is already fully encoded in `repository` itself - a snapshot
repository's path simply ends in `<server uuid>_<backup uuid>` rather than
`<server uuid>` - so Wings never needs to know which mode built a given
repository, only where it is. That absence is deliberate rather than
something to fix: a `mode` field would duplicate information the path
already carries and give Wings a second thing to trust instead of the one it
already has.

`ssh_private_key` and `ssh_known_hosts` are populated only when the repository
is one the node reaches over SSH; for a local path both are null. Borg counts
both an `ssh://` URL and the scp-style `user@host:path` as remote, so the panel
classifies anything that is not unambiguously a local path as remote. That
direction is deliberate: sending a key that turns out not to be needed wastes
it, while withholding one that is needed fails every backup against that
repository.

A node should not rely on the panel withholding it, though, and should decline
to set up SSH material for a local repository on its own account. There is no
`BORG_RSH` to consult in that case, so writing a private key to disk there is
key material at rest for an operation that could never use it.

Those two checks are a pair, and the pair has a failure mode worth naming
because it is not obvious from either side alone. Read on its own, each looks
redundant: the panel's check appears unnecessary to someone who has confirmed
the node declines the key anyway, and the node's check appears unreachable to
someone who has confirmed the panel never sends it. Both readings are correct,
and both are only correct while the other check is still in place. So the
protection does not weaken gradually. It survives the first removal, which
looks justified, and is then silently annulled by a second removal in a
different repository that also looks justified, with neither review able to see
the other.

The practical rule that follows: neither check may be removed on evidence
gathered from the opposite side, and neither one's comment should rest its
justification on the other's behaviour. Each has a reason that stands on its
own - do not send a secret that cannot be used, and do not write a secret to
disk for an operation that cannot use it - and those are the reasons to keep.

What this puts on Wings, stated as requirements rather than suggestions:

* **Wings must never log the `borg` object, on either channel.** It carries
  the repository passphrase and, when set, an SSH private key, whether it
  arrived pushed in a request body or pulled from `/api/remote`.
* Wings owns `borg init`. It must check whether the repository exists,
  create it if not, and treat "repository already exists" as success rather
  than an error. The panel's own creation throttle does not stop two
  concurrent backups for the same server from both attempting init, so this
  has to be safe to race. Borg's repository lock provides the mutual
  exclusion; a lock timeout is a failed backup, not a hang.
* On completion Wings reports to `POST /api/remote/backups/{backup}` as it
  already does for every adapter. `checksum_type` is `borg-archive-id` and
  `checksum` is the archive ID, which is itself a content hash. The panel's
  validation on that endpoint requires both fields for a successful backup,
  so omitting either one fails the completion with a 422.
* `size` is the archive's original, uncompressed logical size. Borg also
  reports compressed and deduplicated sizes; the panel only stores one number
  today, and reporting the others is a separate roadmap item, not something
  Wings needs to solve now.
* Download streams `borg export-tar` against the object it pulled, so what
  the user downloads is an ordinary tar file they can open with anything.
  Restore, driven by the pushed object, materializes the archive into the
  server's data directory. How it gets there is Wings' choice: writing the
  files straight out of Borg would skip the disk-quota check, the ownership
  fixing and the symlink sandboxing that every other restore on the node goes
  through, so routing the archive back through the node's own restore path is
  the expected shape rather than a workaround.
* Delete should acknowledge the request and do the work after: `borg delete`,
  and especially the `borg compact` that should follow it, can take minutes
  on a large repository, which is longer than the panel's Guzzle timeout on
  that call allows for. Compaction should happen opportunistically after a
  delete, coalesced per repository. It deliberately does not say "on a
  schedule": a scheduled compaction would need the passphrase, and for an
  `ssh://` repository the SSH key, sitting on the node between operations,
  which is the exact thing deriving them per request exists to avoid.
* The panel sends ignore patterns in the existing `ignore` field, as
  newline-separated gitignore-shaped strings, which is not Borg's `--pattern`
  syntax. The requirement is on the outcome, not the means: a file excluded by
  that list must be absent from the archive, not merely filtered out of what
  gets listed afterward. Translating the patterns and never letting Borg walk
  the tree at all are both acceptable ways to get there, and they are not
  equally easy to get right - Borg patterns are first-match-wins where
  gitignore is last-match-wins, and a trailing slash means different things in
  each.
* Wings targets Borg 1.2 or newer, below 2.0. The `encryption` vocabulary
  above and the repository creation this document describes are 1.x; Borg 2.0
  renames repository creation and changes the encryption mode names, so
  supporting it is a revision of this contract rather than a change Wings can
  absorb on its own.
* Borg exits 1 for warnings, not failures, and the most common one - a file
  changed while it was being read - is routine on a running game server. Only
  an exit code of 2 or higher is a failed backup. A backup can therefore be
  reported successful with warnings in the node's logs, and that is correct
  rather than something to investigate.

## Orphaned backups

`Server` does not use `SoftDeletes`, so deleting one hard-deletes the row,
and the `backups` table's `server_id` foreign key - `onDelete('cascade')` -
hard-deletes every backup row belonging to it in the same stroke. Nothing
about that cascade touches the stored data itself: the Borg repository, the
S3 object or the node's own archive is left exactly where it was, and once
the cascade runs the panel no longer has a row anywhere saying it exists.

A listener on the existing `Server\Deleting` event closes that gap. It runs
inside `ServerDeletionService`'s own transaction, before the server row (and
the backup rows cascaded from it) are actually removed, and copies every
successful, completed, non-deleted backup into a new `orphaned_backups`
table - a failed or still-running backup never finished writing anything
worth tracking, and a soft-deleted one already had its data removed by
`DeleteBackupService`. Running inside that same transaction means a rolled
back server deletion rolls the orphan rows back with it: an orphan is never
recorded for a server that is still there. A backup's `borg_repository` is
carried onto its orphan row untouched, so an orphaned snapshot backup still
resolves to the repository it actually was written to rather than falling
back to the legacy per-server path.

Orphaned backups appear in the unified list at `/admin/backups`, reachable
by filtering to show orphans only, newest first, with two actions per row:

* **Delete** removes the stored data and then the row. For `s3` the panel
  deletes the object directly with credentials it already holds, so this
  works today. For `borg` and `wings` it sends `DELETE
  /api/backups/{backup-uuid}` to the node the backup belonged to - a
  node-scoped route, since there is no longer a `Server` model to route a
  request through.
* **Forget** removes only the panel row and leaves the stored data behind.
  It is the escape hatch for a node that no longer exists to be asked
  anything, not the normal path, and it is the only action offered once a
  row's node has itself been deleted.

Three things worth knowing before relying on Delete:

* **Needs Wings work.** The node-scoped delete route above does not exist in
  Wings yet, so today Delete for a borg or wings orphan fails outright:
  there is no tolerance for a 404 response here, unlike the regular
  per-server delete path. That is deliberate rather than an oversight. A row
  that outlives its data is only a tidiness problem, one an operator can
  resolve by hand with Forget once they know the data is really gone; a row
  that is deleted while its data survives is unrecoverable, because that row
  was the only remaining record that the data existed at all. Given that
  choice, a failure that keeps the row and surfaces the error is the safe
  direction, so the failure is left to propagate: the transaction rolls
  back and the row stays exactly where it was, ready to retry once Wings
  registers the route.
* Once that route exists, the fix belongs on the panel side, as a branch on
  the response it gets back, not as any change to what the node answers. A
  404 from that route will then mean precisely "this node has no such
  file" - a genuine, honest answer for the plain `wings` local adapter,
  whose delete path really does look for a file on disk - so a 404 is the
  one response safe to treat as already gone and drop the row for.
  Anything else still keeps the row and surfaces the error. Having the node
  answer 204 for a file it never found was considered instead and rejected:
  that would have the node claim it removed something it never saw, and it
  would blunt a signal the panel needs to read honestly.
* Once that route exists and behaves like the rest of the wire contract, the
  same acknowledge-then-work pattern applies to it as to the existing
  per-server delete: the borg deletion is acknowledged before it actually
  runs, in a background goroutine, so the panel drops its row on a
  successful acknowledgement while the archive deletion itself may still
  fail afterwards, with the failure reaching only the node's own log. This
  is not a new risk - the per-server delete has behaved this way since the
  adapter shipped - but it is worth restating here, where the row being
  dropped was the last record that the data ever existed at all.

## Limitations

* Deleting a server does not delete its Borg repository outright, but the
  data no longer disappears from the panel's view either - see **Orphaned
  backups** above for what is now recorded and what an administrator can do
  about it. Actually removing a borg or wings backup's stored data from the
  unified backups list still needs the node-scoped delete route Wings does
  not have yet, so cleanup for those two adapters stays effectively manual
  until it ships; a Borg repository holds a server's entire backup history
  and will still dominate storage growth on a busy panel faster than a pile
  of orphaned S3 objects does.
* No per-file or per-directory restore, and no browsing an archive's
  contents, even though Borg supports both. That is the "Richer user-facing
  backups" roadmap item.
* No cross-server deduplication, by design - see the repository layout
  tradeoff above.
* Rotating the passphrase secret is not supported.
  `/admin/backups/settings/borg` can be used to replace it, but replacing it
  abandons access to every repository the old secret unlocked rather than
  re-keying them, which is destruction and not rotation. See **Passphrase
  custody**.
* The first backup of a large server is a full one and can be slow.
  `BACKUP_PRUNE_AGE` defaults to 360 minutes, after which the panel marks a
  still-running backup as failed; raise it if a large server's first backup
  does not fit inside the default window.
