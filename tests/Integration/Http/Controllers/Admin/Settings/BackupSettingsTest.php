<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Admin\Settings;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Setting;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

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

    /**
     * A number input left blank submits as an empty string, not as an absent field.
     * The "integer" rule on its own rejects that outright rather than treating it as
     * absent, which would turn clearing one of these fields into a validation error
     * instead of the same fall-back-to-environment behaviour every other blank plain
     * field on this page has.
     */
    public function testEmptyGeneralIntegerFieldForgetsTheKeyRatherThanFailingValidation(): void
    {
        Setting::create(['key' => 'settings::backups:prune_age', 'value' => '120']);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:prune_age' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::query()->where('key', 'settings::backups:prune_age')->first());
    }

    /**
     * These two fields are select controls rather than a plain checkbox precisely so
     * this state is reachable: a control that can only ever submit "0" or "1" would
     * pin an override in the database the first time the page is saved for any
     * reason at all, with nothing on the page able to remove it again.
     */
    public function testEmptyS3BooleanFieldForgetsTheKeyRatherThanFailingValidation(): void
    {
        Setting::create(['key' => 'settings::backups:disks:s3:use_path_style_endpoint', 'value' => '1']);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:s3:use_path_style_endpoint' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::query()->where('key', 'settings::backups:disks:s3:use_path_style_endpoint')->first());
    }

    /**
     * config() can only ever resolve to a real true or false, whether that came from
     * a stored override or from the environment default falling through untouched -
     * BackupSettingsServiceProvider collapses the two the same way the database
     * string itself would if nothing coerced it. The page has to read the settings
     * table directly to tell "no override" apart from "override happens to agree
     * with the environment", or the third option this control exists for would be
     * unreachable from the display side even though it works when submitted.
     */
    public function testS3BooleanSelectRendersEnvironmentDefaultOnlyWhenNoOverrideIsStored(): void
    {
        // BackupSettingsServiceProvider only runs once, when the application under
        // test boots, so a settings row written mid-test never gets picked up by
        // config() on its own - the same reason other tests in this class assign to
        // config() directly instead of relying on a fresh Setting row to be noticed.
        $settings = $this->app->make(SettingsRepositoryInterface::class);
        $settings->forget('settings::backups:disks:s3:use_path_style_endpoint');
        config(['backups.disks.s3.use_path_style_endpoint' => false]);

        $html = $this->actingAsAdmin()->get('/admin/settings/backups')->getContent();
        $this->assertSame('', $this->selectedOptionValue($html, 'backups:disks:s3:use_path_style_endpoint'));

        $settings->set('settings::backups:disks:s3:use_path_style_endpoint', '1');
        config(['backups.disks.s3.use_path_style_endpoint' => true]);

        $html = $this->actingAsAdmin()->get('/admin/settings/backups')->getContent();
        $this->assertSame('1', $this->selectedOptionValue($html, 'backups:disks:s3:use_path_style_endpoint'));
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

    /**
     * The page composes these three request-only controls into the single string
     * BorgConfigurationService::COMPRESSION_PATTERN expects, before that value is
     * validated and stored under its usual settings key. Every one of these cases
     * has to come back out exactly as it went in, or the page would silently
     * change a repository's compression setting the next time it is saved.
     */
    public function testCompressionControlsComposeIntoTheExactConfiguredString(): void
    {
        $cases = [
            'none' => ['algorithm' => 'none', 'level' => '', 'auto' => false],
            'lz4' => ['algorithm' => 'lz4', 'level' => '', 'auto' => false],
            'zstd,3' => ['algorithm' => 'zstd', 'level' => '3', 'auto' => false],
            'zlib,9' => ['algorithm' => 'zlib', 'level' => '9', 'auto' => false],
            'lzma,6' => ['algorithm' => 'lzma', 'level' => '6', 'auto' => false],
            'auto,zstd,10' => ['algorithm' => 'zstd', 'level' => '10', 'auto' => true],
        ];

        foreach ($cases as $expected => $controls) {
            $overrides = [
                'backups:disks:borg:compression:algorithm' => $controls['algorithm'],
                'backups:disks:borg:compression:level' => $controls['level'],
            ];
            if ($controls['auto']) {
                $overrides['backups:disks:borg:compression:auto'] = '1';
            }

            $this->actingAsAdmin()
                ->patch('/admin/settings/backups', $this->payload($overrides))
                ->assertSessionHasNoErrors();

            $stored = Setting::query()->where('key', 'settings::backups:disks:borg:compression')->first();
            $this->assertSame($expected, $stored->value);
        }
    }

    /**
     * The algorithm and level controls are request-only fields with no rule of
     * their own tying them to Borg's grammar - the composed string still goes
     * through the same closure rule a directly submitted value would, which is
     * what actually catches a combination the grammar has no room for.
     */
    public function testAnAlgorithmLevelComboOutsideTheGrammarIsRejected(): void
    {
        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload([
                'backups:disks:borg:compression:algorithm' => 'none',
                'backups:disks:borg:compression:level' => '5',
            ]))
            ->assertSessionHasErrors(['backups:disks:borg:compression']);
    }

    public function testS3SecretIsNeverRenderedOnThePage(): void
    {
        Setting::create(['key' => 'settings::backups:disks:s3:secret', 'value' => encrypt('super-secret-access-key')]);

        $this->actingAsAdmin()
            ->get('/admin/settings/backups')
            ->assertDontSee('super-secret-access-key', false);
    }

    public function testS3SecretIsNotFlashedToTheSessionOnValidationFailure(): void
    {
        $this->actingAsAdmin()
            ->from('/admin/settings/backups')
            ->patch('/admin/settings/backups', $this->payload([
                'backups:disks:borg:encryption' => 'not-a-real-mode',
                'backups:disks:s3:secret' => 'super-secret-access-key',
            ]))
            ->assertSessionHasErrors(['backups:disks:borg:encryption']);

        $this->assertArrayNotHasKey('backups:disks:s3:secret', session('_old_input', []));
    }

    public function testEmptyS3SecretFieldLeavesTheStoredSecretUnchanged(): void
    {
        $encrypted = encrypt('current-s3-secret');
        Setting::create(['key' => 'settings::backups:disks:s3:secret', 'value' => $encrypted]);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['backups:disks:s3:secret' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame($encrypted, Setting::query()->where('key', 'settings::backups:disks:s3:secret')->first()->value);
    }

    public function testCheckedClearCheckboxForgetsTheS3Secret(): void
    {
        Setting::create(['key' => 'settings::backups:disks:s3:secret', 'value' => encrypt('current-s3-secret')]);

        $this->actingAsAdmin()
            ->patch('/admin/settings/backups', $this->payload(['clear_s3_secret' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::query()->where('key', 'settings::backups:disks:s3:secret')->first());
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * Pulls the value of whichever <option> is marked "selected" inside the named
     * <select>, so a test can assert on what the page actually renders as chosen
     * rather than reading the same config() value the bug this guards against
     * would also read.
     */
    private function selectedOptionValue(string $html, string $name): ?string
    {
        preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/select>/s', $html, $select);
        preg_match('/<option value="([^"]*)"[^>]*\sselected/', $select[1] ?? '', $option);

        return $option[1] ?? null;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'backups:default' => Backup::ADAPTER_BORG,
            'backups:disks:borg:encryption' => 'repokey-blake2',
            'backups:disks:borg:mode' => 'incremental',
            'backups:disks:borg:compression' => 'zstd,3',
            'backups:disks:borg:lock_wait' => 600,
            'backups:disks:borg:checkpoint_interval' => 1800,
            'backups:disks:borg:upload_ratelimit' => 0,
            'backups:disks:s3:use_path_style_endpoint' => '0',
            'backups:disks:s3:use_accelerate_endpoint' => '0',
        ], $overrides);
    }
}
