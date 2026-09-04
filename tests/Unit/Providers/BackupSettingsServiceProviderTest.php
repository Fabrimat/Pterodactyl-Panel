<?php

namespace Pterodactyl\Tests\Unit\Providers;

use Mockery as m;
use Psr\Log\LoggerInterface;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Providers\BackupSettingsServiceProvider;
use Pterodactyl\Services\Backups\BorgConfigurationService;
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
            'settings::backups:presigned_url_lifespan' => '30',
            'settings::backups:max_part_size' => '1024',
            'settings::backups:prune_age' => '0',
            'settings::backups:throttles:limit' => '5',
            'settings::backups:throttles:period' => '0',
        ]);

        $this->assertSame(120, $this->config->get('backups.disks.borg.lock_wait'));
        $this->assertSame(900, $this->config->get('backups.disks.borg.checkpoint_interval'));
        $this->assertSame(512, $this->config->get('backups.disks.borg.upload_ratelimit'));
        $this->assertSame(30, $this->config->get('backups.presigned_url_lifespan'));
        $this->assertSame(1024, $this->config->get('backups.max_part_size'));
        $this->assertSame(0, $this->config->get('backups.prune_age'));
        $this->assertSame(5, $this->config->get('backups.throttles.limit'));
        $this->assertSame(0, $this->config->get('backups.throttles.period'));
    }

    public function testDatabaseValueOverridesConfigValueForTheBorgMode(): void
    {
        $this->config->set('backups.disks.borg.mode', BorgConfigurationService::MODE_INCREMENTAL);

        $this->boot([
            'settings::backups:disks:borg:mode' => BorgConfigurationService::MODE_SNAPSHOT,
        ]);

        $this->assertSame(BorgConfigurationService::MODE_SNAPSHOT, $this->config->get('backups.disks.borg.mode'));
    }

    public function testAnEncryptedS3SecretIsDecryptedIntoConfig(): void
    {
        $this->boot([
            'settings::backups:disks:s3:secret' => $this->encrypter->encrypt('the-s3-secret'),
        ]);

        $this->assertSame('the-s3-secret', $this->config->get('backups.disks.s3.secret'));
    }

    /**
     * This is the case the boolean coercion exists for: a checkbox that renders
     * unchecked is submitted as the string "0", and a config consumer built
     * around a real boolean - not a truthy check - is only safe from it once
     * this value has actually been turned into one before reaching config().
     */
    public function testAStoredZeroForABooleanKeyReachesConfigAsBooleanFalse(): void
    {
        $this->config->set('backups.disks.s3.use_path_style_endpoint', true);
        $this->config->set('backups.disks.s3.use_accelerate_endpoint', true);

        $this->boot([
            'settings::backups:disks:s3:use_path_style_endpoint' => '0',
            'settings::backups:disks:s3:use_accelerate_endpoint' => '0',
        ]);

        $this->assertFalse($this->config->get('backups.disks.s3.use_path_style_endpoint'));
        $this->assertFalse($this->config->get('backups.disks.s3.use_accelerate_endpoint'));
    }

    public function testAStoredOneForABooleanKeyReachesConfigAsBooleanTrue(): void
    {
        $this->config->set('backups.disks.s3.use_path_style_endpoint', false);
        $this->config->set('backups.disks.s3.use_accelerate_endpoint', false);

        $this->boot([
            'settings::backups:disks:s3:use_path_style_endpoint' => '1',
            'settings::backups:disks:s3:use_accelerate_endpoint' => '1',
        ]);

        $this->assertTrue($this->config->get('backups.disks.s3.use_path_style_endpoint'));
        $this->assertTrue($this->config->get('backups.disks.s3.use_accelerate_endpoint'));
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
