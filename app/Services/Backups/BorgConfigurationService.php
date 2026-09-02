<?php

namespace Pterodactyl\Services\Backups;

use Pterodactyl\Models\Server;

class BorgConfigurationService
{
    /**
     * The encryption modes Borg supports for a repository.
     */
    private const VALID_ENCRYPTION_MODES = [
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
    private const COMPRESSION_PATTERN = '/^(?:none|lz4|zstd(?:,(?:[1-9]|1[0-9]|2[0-2]))?|zlib(?:,[0-9])?|lzma(?:,[0-9])?)$/';

    /**
     * Builds the borg configuration sent to Wings for a given server. The same shape is
     * used for a backup, a restore and a delete request; the archive name is the only
     * thing that changes between them, which is why it is taken as an argument rather
     * than read off of a Backup model.
     *
     * @throws \InvalidArgumentException
     */
    public function handle(Server $server, string $archive): array
    {
        $repository = $this->repository($server);
        $remote = $this->isRemote($repository);

        return [
            'repository' => $repository,
            'archive' => $archive,
            'passphrase' => $this->passphrase($server),
            'encryption' => $this->encryption(),
            'compression' => $this->compression(),
            // Only sent for a repository the node reaches over SSH. Handing a private
            // key to a node for an operation that cannot use it puts key material on
            // its disk for nothing.
            'ssh_private_key' => $remote ? config('backups.disks.borg.ssh.private_key') : null,
            'ssh_known_hosts' => $remote ? config('backups.disks.borg.ssh.known_hosts') : null,
            'lock_wait' => config('backups.disks.borg.lock_wait'),
            'checkpoint_interval' => config('backups.disks.borg.checkpoint_interval'),
            'upload_ratelimit' => config('backups.disks.borg.upload_ratelimit'),
        ];
    }

    /**
     * Returns the repository location for a given server: one repository per server,
     * living underneath the configured base location.
     *
     * @throws \InvalidArgumentException
     */
    protected function repository(Server $server): string
    {
        $base = config('backups.disks.borg.repository');
        if (empty($base)) {
            throw new \InvalidArgumentException('No borg repository has been configured for this Panel.');
        }

        return rtrim((string) $base, '/') . '/' . $server->uuid;
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
    protected function passphrase(Server $server): string
    {
        $secret = config('backups.disks.borg.passphrase_secret');
        if (empty($secret)) {
            throw new \InvalidArgumentException('No borg passphrase secret has been configured for this Panel.');
        }

        return hash_hmac('sha256', 'borg:v1:' . $server->uuid, (string) $secret);
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
}
