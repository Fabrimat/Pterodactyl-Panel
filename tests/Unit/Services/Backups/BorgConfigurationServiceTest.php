<?php

namespace Pterodactyl\Tests\Unit\Services\Backups;

use Pterodactyl\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Services\Backups\BorgConfigurationService;

class BorgConfigurationServiceTest extends TestCase
{
    private const SERVER_UUID = '9c858901-8a57-4791-81fe-4c455b099bc9';

    private const ARCHIVE = 'archive-uuid';

    private BorgConfigurationService $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = new BorgConfigurationService();

        config([
            'backups.disks.borg.repository' => 'ssh://borg@backup.example.com:22/./pterodactyl',
            'backups.disks.borg.passphrase_secret' => 'test-secret',
            'backups.disks.borg.encryption' => 'repokey-blake2',
            'backups.disks.borg.compression' => 'zstd,3',
            'backups.disks.borg.mode' => BorgConfigurationService::MODE_INCREMENTAL,
        ]);
    }

    public function testPassphraseIsSixtyFourLowercaseHexCharacters(): void
    {
        $passphrase = $this->handle(self::SERVER_UUID)['passphrase'];

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $passphrase);
    }

    public function testPassphraseIsStableForTheSameServerAndSecret(): void
    {
        $this->assertSame(
            $this->handle(self::SERVER_UUID)['passphrase'],
            $this->handle(self::SERVER_UUID)['passphrase']
        );
    }

    public function testPassphraseDiffersForADifferentServer(): void
    {
        $first = $this->handle('11111111-1111-1111-1111-111111111111');
        $second = $this->handle('22222222-2222-2222-2222-222222222222');

        $this->assertNotSame($first['passphrase'], $second['passphrase']);
    }

    public function testPassphraseDiffersForADifferentSecret(): void
    {
        $first = $this->handle(self::SERVER_UUID);

        config(['backups.disks.borg.passphrase_secret' => 'a-different-secret']);

        $second = $this->handle(self::SERVER_UUID);

        $this->assertNotSame($first['passphrase'], $second['passphrase']);
    }

    public function testPassphraseIsDomainSeparatedFromTheBareHmac(): void
    {
        $bare = hash_hmac('sha256', self::SERVER_UUID, 'test-secret');

        $this->assertNotSame($bare, $this->handle(self::SERVER_UUID)['passphrase']);
    }

    public function testEmptyPassphraseSecretThrows(): void
    {
        config(['backups.disks.borg.passphrase_secret' => '']);

        $this->expectException(\InvalidArgumentException::class);

        $this->handle(self::SERVER_UUID);
    }

    public function testMissingPassphraseSecretThrows(): void
    {
        config(['backups.disks.borg.passphrase_secret' => null]);

        $this->expectException(\InvalidArgumentException::class);

        $this->handle(self::SERVER_UUID);
    }

    public function testMissingRepositoryThrows(): void
    {
        config(['backups.disks.borg.repository' => null]);

        $this->expectException(\InvalidArgumentException::class);

        $this->handle(self::SERVER_UUID);
    }

    public function testRepositoryUrlIsTheBaseJoinedWithTheServerUuidUnderTheIncrementalMode(): void
    {
        $this->assertSame(
            'ssh://borg@backup.example.com:22/./pterodactyl/' . self::SERVER_UUID,
            $this->handle(self::SERVER_UUID)['repository']
        );
    }

    public function testRepositoryUrlHasNoDoubledSlashWhenTheBaseHasATrailingOne(): void
    {
        config(['backups.disks.borg.repository' => 'ssh://borg@backup.example.com:22/./pterodactyl/']);

        $this->assertSame(
            'ssh://borg@backup.example.com:22/./pterodactyl/' . self::SERVER_UUID,
            $this->handle(self::SERVER_UUID)['repository']
        );
    }

    /**
     * A null suffix always means the legacy per-server layout, unconditionally - the
     * mode never enters into resolving an existing backup. This is the guarantee the
     * borg_repository column exists for: without it, flipping the mode to snapshot
     * would make every backup taken under the old mode unrestorable and undeletable,
     * because the panel would recompute a path the archive is not at.
     */
    public function testANullSuffixStaysAtThePerServerRepositoryEvenUnderTheSnapshotMode(): void
    {
        config(['backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT]);

        $this->assertSame(
            'ssh://borg@backup.example.com:22/./pterodactyl/' . self::SERVER_UUID,
            $this->handle(self::SERVER_UUID)['repository']
        );
    }

    /**
     * A recorded suffix is what a backup actually was written to, so it is always used
     * verbatim rather than recomputed - otherwise flipping the mode afterwards would
     * make every backup taken under the old mode unrestorable and undeletable.
     */
    public function testARecordedSuffixIsUsedVerbatimRegardlessOfTheCurrentMode(): void
    {
        $recorded = self::SERVER_UUID . '_some-older-archive';
        $expected = 'ssh://borg@backup.example.com:22/./pterodactyl/' . $recorded;

        config(['backups.disks.borg.mode' => BorgConfigurationService::MODE_INCREMENTAL]);
        $this->assertSame($expected, $this->handle(self::SERVER_UUID, $recorded)['repository']);

        config(['backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT]);
        $this->assertSame($expected, $this->handle(self::SERVER_UUID, $recorded)['repository']);
    }

    /**
     * This is the two-step sequence BorgDaemonBackupRepository::backup() actually runs
     * for a new backup: ask newRepositorySuffix() what the current mode calls for, then
     * pass that straight into handle(). Under the snapshot mode that suffix is a flat
     * sibling of the server's own path rather than nested underneath it. Both matter: a
     * nested path would need borg init to create two missing parent directories over
     * ssh://, which it cannot do, and a repository nested under the server's own would
     * have Borg refuse to create it at all, since Borg will not create one underneath an
     * existing one.
     */
    public function testANewBackupUnderTheSnapshotModeIsAFlatSiblingOfTheServerRepository(): void
    {
        config(['backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT]);

        $suffix = $this->service->newRepositorySuffix(self::SERVER_UUID, self::ARCHIVE);

        $this->assertSame(
            'ssh://borg@backup.example.com:22/./pterodactyl/' . self::SERVER_UUID . '_' . self::ARCHIVE,
            $this->handle(self::SERVER_UUID, $suffix)['repository']
        );
    }

    public function testNewRepositorySuffixIsNullUnderTheIncrementalMode(): void
    {
        $this->assertNull($this->service->newRepositorySuffix(self::SERVER_UUID, self::ARCHIVE));
    }

    public function testNewRepositorySuffixIsTheServerAndArchiveUnderTheSnapshotMode(): void
    {
        config(['backups.disks.borg.mode' => BorgConfigurationService::MODE_SNAPSHOT]);

        $this->assertSame(
            self::SERVER_UUID . '_' . self::ARCHIVE,
            $this->service->newRepositorySuffix(self::SERVER_UUID, self::ARCHIVE)
        );
    }

    /**
     * The mode is only ever read by newRepositorySuffix(), at the point a new backup is
     * created - resolving an existing backup never touches it, so an invalid value only
     * ever surfaces there rather than on every restore or delete of a backup already on
     * disk.
     */
    public function testInvalidModeThrows(): void
    {
        config(['backups.disks.borg.mode' => 'full']);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->newRepositorySuffix(self::SERVER_UUID, self::ARCHIVE);
    }

    #[DataProvider('validCompressionDataProvider')]
    public function testValidCompressionValuesAreAccepted(string $value): void
    {
        config(['backups.disks.borg.compression' => $value]);

        $this->assertSame($value, $this->handle(self::SERVER_UUID)['compression']);
    }

    public static function validCompressionDataProvider(): array
    {
        return [
            'none' => ['none'],
            'lz4' => ['lz4'],
            'zstd bare' => ['zstd'],
            'zstd with level' => ['zstd,3'],
            'zstd max level' => ['zstd,22'],
            'zlib with level' => ['zlib,9'],
            'lzma with level' => ['lzma,6'],
            'auto prefixed' => ['auto,zstd,3'],
        ];
    }

    #[DataProvider('invalidCompressionDataProvider')]
    public function testInvalidCompressionValuesThrow(string $value): void
    {
        config(['backups.disks.borg.compression' => $value]);

        $this->expectException(\InvalidArgumentException::class);

        $this->handle(self::SERVER_UUID);
    }

    public static function invalidCompressionDataProvider(): array
    {
        return [
            'unsupported algorithm' => ['gzip'],
            'level out of range' => ['zstd,23'],
            'dangling comma' => ['zstd,'],
            'empty' => [''],
        ];
    }

    public function testInvalidEncryptionModeThrows(): void
    {
        config(['backups.disks.borg.encryption' => 'aes-256']);

        $this->expectException(\InvalidArgumentException::class);

        $this->handle(self::SERVER_UUID);
    }

    #[DataProvider('remoteRepositories')]
    public function testSshMaterialIsSentForARemoteRepository(string $base): void
    {
        config([
            'backups.disks.borg.repository' => $base,
            'backups.disks.borg.ssh.private_key' => 'PRIVATE KEY BODY',
            'backups.disks.borg.ssh.known_hosts' => 'HOST KEY LINE',
        ]);

        $config = $this->handle(self::SERVER_UUID);

        $this->assertSame('PRIVATE KEY BODY', $config['ssh_private_key']);
        $this->assertSame('HOST KEY LINE', $config['ssh_known_hosts']);
    }

    #[DataProvider('localRepositories')]
    public function testSshMaterialIsWithheldForALocalRepository(string $base): void
    {
        config([
            'backups.disks.borg.repository' => $base,
            'backups.disks.borg.ssh.private_key' => 'PRIVATE KEY BODY',
            'backups.disks.borg.ssh.known_hosts' => 'HOST KEY LINE',
        ]);

        $config = $this->handle(self::SERVER_UUID);

        $this->assertNull($config['ssh_private_key']);
        $this->assertNull($config['ssh_known_hosts']);
    }

    /**
     * Borg treats the scp-style form as remote just as it does an ssh:// URL, so it has
     * to be classified as remote here too. Getting that one wrong would withhold the key
     * from a repository that needs it and fail every backup against it.
     */
    public static function remoteRepositories(): array
    {
        return [
            ['ssh://borg@backup.example.com:22/./pterodactyl'],
            ['ssh://borg@backup.example.com/./pterodactyl'],
            ['borg@backup.example.com:pterodactyl'],
        ];
    }

    public static function localRepositories(): array
    {
        return [
            ['/var/lib/pterodactyl/borg'],
            ['/srv/borg'],
            ['file:///var/lib/pterodactyl/borg'],
        ];
    }

    private function handle(string $serverUuid, ?string $repositorySuffix = null): array
    {
        return $this->service->handle($serverUuid, self::ARCHIVE, $repositorySuffix);
    }
}
