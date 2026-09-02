<?php

namespace Pterodactyl\Services\Backups;

class BorgConfigurationService
{
    /**
     * The encryption modes Borg supports for a repository.
     */
    public const VALID_ENCRYPTION_MODES = [
        'repokey-blake2',
        'keyfile-blake2',
        'repokey',
        'keyfile',
        'authenticated-blake2',
        'authenticated',
        'none',
    ];

    /**
     * Matches the compression grammar Borg's --compression flag accepts, without the
     * optional "auto," prefix. That prefix is checked for separately since it wraps
     * the same grammar rather than extending it.
     */
    public const COMPRESSION_PATTERN = '/^(?:none|lz4|zstd(?:,(?:[1-9]|1[0-9]|2[0-2]))?|zlib(?:,[0-9])?|lzma(?:,[0-9])?)$/';

    /**
     * One repository per server, holding every backup that server has ever taken.
     * The current, and default, behaviour.
     */
    public const MODE_INCREMENTAL = 'incremental';

    /**
     * One repository per backup, so each one is self-contained and shares nothing
     * with any other, at the cost of transferring and storing the full size every
     * time.
     */
    public const MODE_SNAPSHOT = 'snapshot';

    /**
     * The repository layout modes this adapter supports.
     */
    public const VALID_MODES = [
        self::MODE_INCREMENTAL,
        self::MODE_SNAPSHOT,
    ];

    /**
     * Builds the borg configuration sent to Wings for a given server. The same shape is
     * used for a backup, a restore and a delete request; the archive name is the only
     * thing that changes between them, which is why it is taken as an argument rather
     * than read off of a Backup model. Only the server's UUID is needed, never the
     * model itself, since nothing here reads anything else off of it.
     *
     * $repositorySuffix is the value recorded on the backup row, if any. A non-null
     * value is used verbatim and never recomputed, so a backup keeps resolving to the
     * repository it actually was written to no matter what the mode is changed to
     * afterwards; see repository() for what a null value falls back to.
     *
     * @throws \InvalidArgumentException
     */
    public function handle(string $serverUuid, string $archive, ?string $repositorySuffix = null): array
    {
        $repository = $this->repository($serverUuid, $repositorySuffix);
        $remote = $this->isRemote($repository);

        return [
            'repository' => $repository,
            'archive' => $archive,
            'passphrase' => $this->passphrase($serverUuid),
            'encryption' => $this->encryption(),
            'compression' => $this->compression(),
            // Only sent for a repository the node reaches over SSH. A secret should
            // never be sent for an operation that cannot use it, regardless of what
            // the receiver then does with it - for example, a node that wrote it to
            // disk anyway would be holding key material it has no use for.
            'ssh_private_key' => $remote ? config('backups.disks.borg.ssh.private_key') : null,
            'ssh_known_hosts' => $remote ? config('backups.disks.borg.ssh.known_hosts') : null,
            'lock_wait' => config('backups.disks.borg.lock_wait'),
            'checkpoint_interval' => config('backups.disks.borg.checkpoint_interval'),
            'upload_ratelimit' => config('backups.disks.borg.upload_ratelimit'),
        ];
    }

    /**
     * The repository suffix a new backup should be recorded and built against, given
     * the mode currently configured. This is the only place the mode is ever read: it
     * is consulted once, at the moment a backup is created, and never again. NULL
     * under the incremental mode, since that layout is nothing more than the server
     * UUID, which repository() already falls back to for a null suffix - there is
     * nothing that needs to be recorded for it. Callers creating a new backup must
     * record whatever this returns onto the backup row, and pass that same value back
     * in as handle()'s $repositorySuffix - see BorgDaemonBackupRepository::backup().
     *
     * @throws \InvalidArgumentException
     */
    public function newRepositorySuffix(string $serverUuid, string $archive): ?string
    {
        return $this->mode() === self::MODE_SNAPSHOT ? $serverUuid . '_' . $archive : null;
    }

    /**
     * Returns the repository location a request should be built against.
     *
     * A non-null $repositorySuffix - whatever was recorded on the backup row - is
     * joined to the base verbatim, and is never recomputed from the mode: a backup
     * keeps resolving to the repository it actually was written to no matter what the
     * mode is changed to afterwards. A null suffix always means the legacy per-server
     * layout, unconditionally, regardless of the currently configured mode - either the
     * row predates this column, or it was written while the mode was incremental, and
     * both cases resolve the same way. The current mode never enters into resolving an
     * existing backup; it is only ever consulted once, by newRepositorySuffix(), at the
     * moment a new backup is created.
     *
     * @throws \InvalidArgumentException
     */
    protected function repository(string $serverUuid, ?string $repositorySuffix): string
    {
        $base = config('backups.disks.borg.repository');
        if (empty($base)) {
            throw new \InvalidArgumentException('No borg repository has been configured for this Panel.');
        }

        $suffix = $repositorySuffix ?? $serverUuid;

        return rtrim((string) $base, '/') . '/' . $suffix;
    }

    /**
     * Whether a repository is one Borg reaches over SSH rather than a path on the node
     * itself. Borg accepts both an ssh:// URL and the scp-style user@host:path form, so
     * anything that is not unambiguously a local path counts as remote here. That
     * direction is deliberate: withholding the key from a repository that needs it
     * fails every backup, while sending it to a local repository only wastes it.
     */
    protected function isRemote(string $repository): bool
    {
        return !str_starts_with($repository, '/') && !str_starts_with($repository, 'file://');
    }

    /**
     * Derives the passphrase that unlocks a server's repository. The passphrase is
     * never stored anywhere: it is recomputed from the server's UUID and a secret only
     * the Panel holds, so an empty secret would silently encrypt every repository
     * under a guessable value rather than failing loudly.
     *
     * @throws \InvalidArgumentException
     */
    protected function passphrase(string $serverUuid): string
    {
        $secret = config('backups.disks.borg.passphrase_secret');
        if (empty($secret)) {
            throw new \InvalidArgumentException('No borg passphrase secret has been configured for this Panel.');
        }

        return hash_hmac('sha256', 'borg:v1:' . $serverUuid, (string) $secret);
    }

    /**
     * Validates the configured encryption mode against the set Borg supports.
     *
     * @throws \InvalidArgumentException
     */
    protected function encryption(): string
    {
        $encryption = (string) config('backups.disks.borg.encryption');
        if (!in_array($encryption, self::VALID_ENCRYPTION_MODES, true)) {
            throw new \InvalidArgumentException("The configured borg encryption mode [$encryption] is not supported.");
        }

        return $encryption;
    }

    /**
     * Validates the configured compression value against Borg's --compression grammar.
     * This value is passed straight through to Borg, so a typo here should fail on the
     * Panel rather than only surfacing once Wings tries to run the backup.
     *
     * @throws \InvalidArgumentException
     */
    protected function compression(): string
    {
        $compression = (string) config('backups.disks.borg.compression');

        $spec = str_starts_with($compression, 'auto,') ? substr($compression, 5) : $compression;
        if (!preg_match(self::COMPRESSION_PATTERN, $spec)) {
            throw new \InvalidArgumentException("The configured borg compression value [$compression] is not valid.");
        }

        return $compression;
    }

    /**
     * Validates the configured repository mode against the set this adapter supports.
     *
     * @throws \InvalidArgumentException
     */
    protected function mode(): string
    {
        $mode = (string) config('backups.disks.borg.mode');
        if (!in_array($mode, self::VALID_MODES, true)) {
            throw new \InvalidArgumentException("The configured borg backup mode [$mode] is not supported.");
        }

        return $mode;
    }
}
