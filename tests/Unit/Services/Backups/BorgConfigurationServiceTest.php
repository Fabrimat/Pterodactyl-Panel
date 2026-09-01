<?php

namespace Pterodactyl\Tests\Unit\Services\Backups;

use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Pterodactyl\Services\Backups\BorgConfigurationService;

class BorgConfigurationServiceTest extends TestCase
{
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
        ]);
    }

    public function testPassphraseIsSixtyFourLowercaseHexCharacters(): void
    {
        $passphrase = $this->handle($this->server())['passphrase'];

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $passphrase);
    }

    public function testPassphraseIsStableForTheSameServerAndSecret(): void
    {
        $server = $this->server();

        $this->assertSame(
            $this->handle($server)['passphrase'],
            $this->handle($server)['passphrase']
        );
    }

    public function testPassphraseDiffersForADifferentServer(): void
    {
        $first = $this->handle($this->server('11111111-1111-1111-1111-111111111111'));
        $second = $this->handle($this->server('22222222-2222-2222-2222-222222222222'));

        $this->assertNotSame($first['passphrase'], $second['passphrase']);
    }

    public function testPassphraseDiffersForADifferentSecret(): void
    {
        $server = $this->server();

        $first = $this->handle($server);

        config(['backups.disks.borg.passphrase_secret' => 'a-different-secret']);

        $second = $this->handle($server);

        $this->assertNotSame($first['passphrase'], $second['passphrase']);
    }

    public function testPassphraseIsDomainSeparatedFromTheBareHmac(): void
    {
        $server = $this->server();

        $bare = hash_hmac('sha256', $server->uuid, 'test-secret');

        $this->assertNotSame($bare, $this->handle($server)['passphrase']);
    }

    public function testEmptyPassphraseSecretThrows(): void
    {
        config(['backups.disks.borg.passphrase_secret' => '']);

        $this->expectException(\InvalidArgumentException::class);

        $this->handle($this->server());
    }

    public function testMissingPassphraseSecretThrows(): void
    {
        config(['backups.disks.borg.passphrase_secret' => null]);

        $this->expectException(\InvalidArgumentException::class);

        $this->handle($this->server());
    }

    public function testMissingRepositoryThrows(): void
    {
        config(['backups.disks.borg.repository' => null]);

        $this->expectException(\InvalidArgumentException::class);

        $this->handle($this->server());
    }

    public function testRepositoryUrlIsTheBaseJoinedWithTheServerUuid(): void
    {
        $server = $this->server();

        $this->assertSame(
            'ssh://borg@backup.example.com:22/./pterodactyl/' . $server->uuid,
            $this->handle($server)['repository']
        );
    }

    public function testRepositoryUrlHasNoDoubledSlashWhenTheBaseHasATrailingOne(): void
    {
        config(['backups.disks.borg.repository' => 'ssh://borg@backup.example.com:22/./pterodactyl/']);

        $server = $this->server();

        $this->assertSame(
            'ssh://borg@backup.example.com:22/./pterodactyl/' . $server->uuid,
            $this->handle($server)['repository']
        );
    }

    #[DataProvider('validCompressionDataProvider')]
    public function testValidCompressionValuesAreAccepted(string $value): void
    {
        config(['backups.disks.borg.compression' => $value]);

        $this->assertSame($value, $this->handle($this->server())['compression']);
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

        $this->handle($this->server());
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

        $this->handle($this->server());
    }

    private function server(string $uuid = '9c858901-8a57-4791-81fe-4c455b099bc9'): Server
    {
        return Server::factory()->make(['uuid' => $uuid]);
    }

    private function handle(Server $server): array
    {
        return $this->service->handle($server, 'archive-uuid');
    }
}
