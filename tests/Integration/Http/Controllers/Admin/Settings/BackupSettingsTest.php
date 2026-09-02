<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Admin\Settings;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class BackupSettingsTest extends HttpTestCase
{
    // IntegrationTestCase sets $connectionsToTransact but does not use this trait, so
    // nothing a test built on it writes is rolled back. Every settings row and backup
    // created below would otherwise survive into the next test in this class.
    use DatabaseTransactions;

    /**
     * An admin submitting this form is a browser, not an API client. The JSON Accept
     * header IntegrationTestCase sends by default turns a validation failure into a
     * 422 document, while the page itself relies on being redirected back with the
     * errors flashed to the session, which is what these tests assert against.
     */
    protected $defaultHeaders = ['Accept' => 'text/html'];

    public function testEmptyPlainFieldForgetsTheKeyRatherThanStoringAnEmptyString(): void
    {
        Setting::create(['key' => 'settings::backups:disks:borg:repository', 'value' => 'ssh://old-host/pterodactyl']);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:repository' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::query()->where('key', 'settings::backups:disks:borg:repository')->first());
    }

    public function testEmptySecretFieldLeavesTheStoredSecretUnchanged(): void
    {
        $encrypted = encrypt('current-secret');
        Setting::create(['key' => 'settings::backups:disks:borg:passphrase_secret', 'value' => $encrypted]);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:passphrase_secret' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame($encrypted, Setting::query()->where('key', 'settings::backups:disks:borg:passphrase_secret')->first()->value);
    }

    public function testCheckedClearCheckboxForgetsTheSecret(): void
    {
        Setting::create(['key' => 'settings::backups:disks:borg:passphrase_secret', 'value' => encrypt('current-secret')]);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['clear_passphrase_secret' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::query()->where('key', 'settings::backups:disks:borg:passphrase_secret')->first());
    }

    public function testAnInvalidCompressionValueIsRejected(): void
    {
        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:compression' => 'gzip']))
            ->assertSessionHasErrors(['backups:disks:borg:compression']);
    }

    public function testAnInvalidEncryptionModeIsRejected(): void
    {
        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:encryption' => 'aes-256']))
            ->assertSessionHasErrors(['backups:disks:borg:encryption']);
    }

    /**
     * The gate has no backup-count branch: a deleted server hard-deletes its backup
     * rows with it, so a count read from this table can never be trusted to mean "no
     * repository can still exist for this secret". Confirmation is required purely
     * from a secret already being set and the submission changing or clearing it.
     */
    public function testPassphraseSecretChangeRequiresConfirmationWheneverASecretIsAlreadySet(): void
    {
        config(['backups.disks.borg.passphrase_secret' => 'existing-secret']);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:passphrase_secret' => 'new-secret']))
            ->assertSessionHasErrors(['confirm_passphrase_secret_change']);
    }

    public function testPassphraseSecretChangeIsAcceptedWhenConfirmed(): void
    {
        config(['backups.disks.borg.passphrase_secret' => 'existing-secret']);
        Setting::create(['key' => 'settings::backups:disks:borg:passphrase_secret', 'value' => encrypt('existing-secret')]);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload([
                'backups:disks:borg:passphrase_secret' => 'new-secret',
                'confirm_passphrase_secret_change' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $stored = Setting::query()->where('key', 'settings::backups:disks:borg:passphrase_secret')->first();
        $this->assertSame('new-secret', decrypt($stored->value));
    }

    public function testPassphraseSecretChangeIsAcceptedWithoutConfirmationWhenNoSecretIsCurrentlySet(): void
    {
        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:passphrase_secret' => 'new-secret']))
            ->assertSessionHasNoErrors();

        $stored = Setting::query()->where('key', 'settings::backups:disks:borg:passphrase_secret')->first();
        $this->assertSame('new-secret', decrypt($stored->value));
    }

    public function testASecretValueOfZeroIsStoredRatherThanTreatedAsBlank(): void
    {
        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:passphrase_secret' => '0']))
            ->assertSessionHasNoErrors();

        $stored = Setting::query()->where('key', 'settings::backups:disks:borg:passphrase_secret')->first();
        $this->assertSame('0', decrypt($stored->value));
    }

    public function testPrivateKeyLineEndingsAreNormalizedToLf(): void
    {
        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload([
                'backups:disks:borg:ssh:private_key' => "-----BEGIN KEY-----\r\nABCD\r\n-----END KEY-----",
            ]))
            ->assertSessionHasNoErrors();

        $stored = Setting::query()->where('key', 'settings::backups:disks:borg:ssh:private_key')->first();
        $this->assertSame("-----BEGIN KEY-----\nABCD\n-----END KEY-----", decrypt($stored->value));
    }

    public function testKnownHostsLineEndingsAreNormalizedToLf(): void
    {
        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload([
                'backups:disks:borg:ssh:known_hosts' => "host-one ssh-ed25519 AAAA\r\nhost-two ssh-ed25519 BBBB",
            ]))
            ->assertSessionHasNoErrors();

        $stored = Setting::query()->where('key', 'settings::backups:disks:borg:ssh:known_hosts')->first();
        $this->assertSame("host-one ssh-ed25519 AAAA\nhost-two ssh-ed25519 BBBB", $stored->value);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(User::factory()->admin()->create());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'backups:default' => Backup::ADAPTER_BORG,
            'backups:disks:borg:encryption' => 'repokey-blake2',
            'backups:disks:borg:compression' => 'zstd,3',
            'backups:disks:borg:lock_wait' => 600,
            'backups:disks:borg:checkpoint_interval' => 1800,
            'backups:disks:borg:upload_ratelimit' => 0,
        ], $overrides);
    }
}
