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
        return [
            'repository' => $this->repository($server),
            'archive' => $archive,
            'passphrase' => $this->passphrase($server),
            'encryption' => $this->encryption(),
            'compression' => $this->compression(),
            'ssh_private_key' => config('backups.disks.borg.ssh.private_key'),
            'ssh_known_hosts' => config('backups.disks.borg.ssh.known_hosts'),
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
