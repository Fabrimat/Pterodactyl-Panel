<?php

namespace Pterodactyl\Tests\Unit\Providers;

use Mockery as m;
use Psr\Log\LoggerInterface;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Providers\BackupSettingsServiceProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class BackupSettingsServiceProviderTest extends TestCase
{
    private ConfigRepository $config;

    private Encrypter $encrypter;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = $this->app->make(ConfigRepository::class);
        $this->encrypter = $this->app->make(Encrypter::class);
    }

    public function testDatabaseValueOverridesConfigValueForAPlainKey(): void
    {
        $this->config->set('backups.disks.borg.repository', 'ssh://env-value/pterodactyl');

        $this->boot([
            'settings::backups:disks:borg:repository' => 'ssh://database-value/pterodactyl',
        ]);

        $this->assertSame('ssh://database-value/pterodactyl', $this->config->get('backups.disks.borg.repository'));
    }

    public function testAKeyWithNoDatabaseRowKeepsTheConfigValue(): void
    {
        $this->config->set('backups.disks.borg.compression', 'zstd,3');

        $this->boot([]);

        $this->assertSame('zstd,3', $this->config->get('backups.disks.borg.compression'));
    }

    public function testAnEncryptedSecretIsDecryptedIntoConfig(): void
    {
        $this->boot([
            'settings::backups:disks:borg:passphrase_secret' => $this->encrypter->encrypt('the-real-secret'),
        ]);

        $this->assertSame('the-real-secret', $this->config->get('backups.disks.borg.passphrase_secret'));
    }

    public function testTheIntegerKeysArriveAsIntNotString(): void
    {
        $this->boot([
            'settings::backups:disks:borg:lock_wait' => '120',
            'settings::backups:disks:borg:checkpoint_interval' => '900',
            'settings::backups:disks:borg:upload_ratelimit' => '512',
        ]);

        $this->assertSame(120, $this->config->get('backups.disks.borg.lock_wait'));
        $this->assertSame(900, $this->config->get('backups.disks.borg.checkpoint_interval'));
        $this->assertSame(512, $this->config->get('backups.disks.borg.upload_ratelimit'));
    }

    /**
     * Runs the provider's boot logic directly against a stubbed settings table, rather
     * than relying on the copy already booted for the application under test: that copy
     * ran before this test ever had a chance to control what the settings repository
     * returns.
     */
    private function boot(array $rows): void
    {
        $settings = m::mock(SettingsRepositoryInterface::class);
        $settings->shouldReceive('all')->once()->andReturn(
            (new Collection($rows))->map(fn ($value, $key) => new Setting(['key' => $key, 'value' => $value]))->values()
        );

        $log = m::mock(LoggerInterface::class);
        $log->shouldNotReceive('notice');

        (new BackupSettingsServiceProvider($this->app))->boot($this->config, $this->encrypter, $log, $settings);
    }
}
