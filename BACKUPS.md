# Borg backup adapter

The panel does not run Borg. Wings does, on the node, against the server's data
directory. The panel's part is registering the adapter, owning its
configuration, deriving each repository's passphrase, and shipping a `borg`
configuration object to Wings inside the three daemon calls it already makes
for a backup: create, restore and delete.

**Wings does not implement any of this yet.** This document doubles as the
specification the node side will be built against; the wire contract section
below is authoritative for that work.

## How it works

Every server gets its own Borg repository, at `<repository base>/<server
uuid>`. The archive inside a repository is named after the backup's UUID, so
one archive is one backup and the panel's existing restore-the-whole-backup
flow needs no change to work with Borg's archive model.

One repository per server is deliberate, not a first pass that will later be
collapsed into one shared repository. Borg has no per-archive authorization
inside a repository - any key that can open the repository can read every
archive in it - so a repository shared across servers would let one server's
key read another server's data. Splitting per server also keeps the blast
radius of repository corruption to a single server. The cost is real: many
game servers hold identical jars and binaries, and none of that is
deduplicated across the boundary. That trade is intentional.

## Deduplication and incrementals

Both are Borg's own doing, not something the adapter turns on. Borg splits
file content into content-defined chunks and only stores chunks it has not
seen before in that repository, so the first backup of a server is full and
every backup after it transfers and stores only what changed. There is no
setting for this and nothing to configure - it is the reason to choose this
adapter over a plain archive-and-upload one.

## Passphrase custody

Every repository is created `repokey-blake2` by default, and nothing about
its passphrase is stored in the panel's database. The passphrase is derived
on demand:

```
HMAC-SHA256('borg:v1:' + server UUID, BORG_PASSPHRASE_SECRET)
```

This has consequences worth stating plainly:

* `BORG_PASSPHRASE_SECRET` must be backed up with exactly the care given to
  `APP_KEY`. Lose it and every repository becomes unreadable ciphertext -
  there is no amount of database access that recovers a derived passphrase.
* Because nothing is stored in the database, a rebuilt panel can still open
  every repository given the secret alone. The server UUIDs it needs are
  recoverable from the repository directory names themselves.
* The `borg:v1:` prefix is domain separation and a version marker, not
  decoration. Rotating `BORG_PASSPHRASE_SECRET` is not supported in this
  version. If that is ever built, the upgrade path is a `v2` prefix plus a
  maintenance command that runs `borg key change-passphrase` over every
  repository.
* `borg key change-passphrase` only re-wraps the repository key; it does not
  rotate the encryption key underneath it. After a node compromise, a new
  repository is the only real remedy - re-keying the existing one does not
  undo exposure of data already read with the old key.

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

| Variable | Default | Meaning |
|---|---|---|
| `APP_BACKUP_DRIVER` | `wings` | set to `borg` to make it the default adapter |
| `BORG_REPOSITORY` | none, required | base location; one repository per server underneath it |
| `BORG_PASSPHRASE_SECRET` | none, required | passphrase derivation secret |
| `BORG_ENCRYPTION` | `repokey-blake2` | `repokey-blake2`, `keyfile-blake2`, `repokey`, `keyfile`, `authenticated-blake2`, `authenticated`, `none` |
| `BORG_COMPRESSION` | `zstd,3` | passed to `borg --compression` verbatim: `none`, `lz4`, `zstd[,1-22]`, `zlib[,0-9]`, `lzma[,0-9]`, optionally `auto,` prefixed |
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

## Remote versus local repositories

A remote `ssh://` repository is the supported mode. A local path on the node
works too, but it is not transfer-safe: the repository lives per server while
a local path lives per node, so a server transferred to a different node
leaves its backups behind, unreachable from the new one.

Changing `BORG_REPOSITORY` after backups already exist strands them in the old
location - exactly like changing the bucket does for the `s3` adapter. Nothing
migrates existing repositories automatically.

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

## Limitations

* Deleting a server does not delete its Borg repository. The panel's server
  deletion path never touches backups for any adapter - upstream leaves S3
  objects orphaned the same way - but a Borg repository holds a server's
  entire backup history and will dominate storage growth on a busy panel
  faster than a pile of orphaned S3 objects does. Cleanup is manual for now;
  a reconciliation command is a follow-up.
* No per-file or per-directory restore, and no browsing an archive's
  contents, even though Borg supports both. That is the "Richer user-facing
  backups" roadmap item.
* No cross-server deduplication, by design - see the repository layout
  tradeoff above.
* Rotating `BORG_PASSPHRASE_SECRET` is not supported.
* The first backup of a large server is a full one and can be slow.
  `BACKUP_PRUNE_AGE` defaults to 360 minutes, after which the panel marks a
  still-running backup as failed; raise it if a large server's first backup
  does not fit inside the default window.
