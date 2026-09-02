<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Admin\Settings;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

class BackupSettingsTest extends HttpTestCase
{
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

    public function testPassphraseSecretChangeIsRejectedWhenBackupsExistAndNotConfirmed(): void
    {
        config(['backups.disks.borg.passphrase_secret' => 'existing-secret']);
        $server = $this->createServerModel();
        Backup::factory()->create(['server_id' => $server->id]);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:passphrase_secret' => 'new-secret']))
            ->assertSessionHasErrors(['confirm_passphrase_secret_change']);
    }

    public function testPassphraseSecretChangeIsAcceptedWhenConfirmed(): void
    {
        config(['backups.disks.borg.passphrase_secret' => 'existing-secret']);
        Setting::create(['key' => 'settings::backups:disks:borg:passphrase_secret', 'value' => encrypt('existing-secret')]);
        $server = $this->createServerModel();
        Backup::factory()->create(['server_id' => $server->id]);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload([
                'backups:disks:borg:passphrase_secret' => 'new-secret',
                'confirm_passphrase_secret_change' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $stored = Setting::query()->where('key', 'settings::backups:disks:borg:passphrase_secret')->first();
        $this->assertSame('new-secret', decrypt($stored->value));
    }

    public function testPassphraseSecretChangeIsAcceptedWithoutConfirmationWhenThisPanelHasNeverTakenABackup(): void
    {
        config(['backups.disks.borg.passphrase_secret' => 'existing-secret']);
        Setting::create(['key' => 'settings::backups:disks:borg:passphrase_secret', 'value' => encrypt('existing-secret')]);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:passphrase_secret' => 'new-secret']))
            ->assertSessionHasNoErrors();

        $stored = Setting::query()->where('key', 'settings::backups:disks:borg:passphrase_secret')->first();
        $this->assertSame('new-secret', decrypt($stored->value));
    }

    /**
     * A repository outlives its archives: deleting every backup does not delete the
     * repository a node already created for it, so the confirmation still has to be
     * required once a backup has ever existed, soft-deleted or not.
     */
    public function testPassphraseSecretChangeIsRejectedWhenOnlySoftDeletedBackupsExist(): void
    {
        config(['backups.disks.borg.passphrase_secret' => 'existing-secret']);
        $server = $this->createServerModel();
        $backup = Backup::factory()->create(['server_id' => $server->id]);
        $backup->delete();

        $this->assertSame(0, Backup::query()->count());

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:borg:passphrase_secret' => 'new-secret']))
            ->assertSessionHasErrors(['confirm_passphrase_secret_change']);
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
