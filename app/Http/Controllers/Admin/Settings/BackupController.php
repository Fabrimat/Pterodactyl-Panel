<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Illuminate\View\View;
use Pterodactyl\Models\Backup;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Illuminate\Contracts\Console\Kernel;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Services\Backups\BorgConfigurationService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Requests\Admin\Settings\BackupSettingsFormRequest;

class BackupController extends Controller
{
    /**
     * BackupController constructor.
     */
    public function __construct(
        private AlertsMessageBag $alert,
        private ConfigRepository $config,
        private Encrypter $encrypter,
        private Kernel $kernel,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Render backup Panel settings UI.
     */
    public function index(): View
    {
        return view('admin.settings.backups', [
            'drivers' => [
                Backup::ADAPTER_WINGS => 'Wings (local node storage)',
                Backup::ADAPTER_AWS_S3 => 'Amazon S3',
                Backup::ADAPTER_BORG => 'Borg',
            ],
            'encryptionModes' => BorgConfigurationService::VALID_ENCRYPTION_MODES,
            'modes' => BorgConfigurationService::VALID_MODES,
            'compression' => $this->decomposeCompression((string) $this->config->get('backups.disks.borg.compression')),
            'passphraseSecretIsSet' => filled($this->config->get('backups.disks.borg.passphrase_secret')),
            'sshPrivateKeyIsSet' => filled($this->config->get('backups.disks.borg.ssh.private_key')),
            's3SecretIsSet' => filled($this->config->get('backups.disks.s3.secret')),
            // Unlike the plain fields above, a boolean here has no textual "unset" value
            // config() could ever expose: BackupSettingsServiceProvider always coerces it
            // to a real true/false, whether that came from a stored override or from the
            // environment default. Only the settings table itself still knows which one
            // it was, which is why this reads from it directly instead of from config().
            'usePathStyleEndpointIsSet' => $this->settings->get('settings::backups:disks:s3:use_path_style_endpoint', null) !== null,
            'useAccelerateEndpointIsSet' => $this->settings->get('settings::backups:disks:s3:use_accelerate_endpoint', null) !== null,
        ]);
    }

    /**
     * Splits the single string Borg's --compression flag expects back into the three
     * controls the page exposes for it: an algorithm, an optional level and whether
     * the "auto," prefix is present. Every value the grammar accepts is made up of
     * exactly those three parts, so this is lossless - BackupSettingsFormRequest
     * rebuilds the identical string out of whatever this returns.
     */
    protected function decomposeCompression(string $compression): array
    {
        $auto = str_starts_with($compression, 'auto,');
        if ($auto) {
            $compression = substr($compression, 5);
        }

        [$algorithm, $level] = array_pad(explode(',', $compression, 2), 2, '');

        return ['algorithm' => $algorithm, 'level' => $level, 'auto' => $auto];
    }

    /**
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function update(BackupSettingsFormRequest $request): RedirectResponse
    {
        foreach ($request->normalize() as $key => $value) {
            // A browser submits textarea content with CRLF line endings, while the
            // same value supplied through the environment arrives as LF. Normalizing
            // here keeps a value stored through this page byte-identical to the same
            // value set through the environment.
            if ($key === 'backups:disks:borg:ssh:known_hosts' && is_string($value)) {
                $value = str_replace("\r\n", "\n", $value);
            }

            // A plain field submitted empty falls back to the environment or the
            // config default rather than being stored as an override of nothing.
            if ($value === null || $value === '') {
                $this->settings->forget('settings::' . $key);
                continue;
            }

            $this->settings->set('settings::' . $key, $value);
        }

        $this->updateSecret($request, 'backups:disks:borg:passphrase_secret', 'clear_passphrase_secret');
        $this->updateSecret($request, 'backups:disks:borg:ssh:private_key', 'clear_ssh_private_key');
        $this->updateSecret($request, 'backups:disks:s3:secret', 'clear_s3_secret');

        $this->kernel->call('queue:restart');
        $this->alert->success('Backup settings have been updated successfully and the queue worker was restarted to apply these changes.')->flash();

        return redirect()->route('admin.settings.backups');
    }

    /**
     * Persists a secret field. The form never renders a secret's current value,
     * so a checked clear box forgets it outright, an empty submitted value
     * leaves the stored secret untouched, and any other value is encrypted
     * before being stored. The clear box wins over a submitted value.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    protected function updateSecret(BackupSettingsFormRequest $request, string $key, string $clearField): void
    {
        if ($request->boolean($clearField)) {
            $this->settings->forget('settings::' . $key);

            return;
        }

        $value = $request->input($key);
        if ($value === null || $value === '') {
            return;
        }

        if ($key === 'backups:disks:borg:ssh:private_key') {
            $value = str_replace("\r\n", "\n", $value);
        }

        $this->settings->set('settings::' . $key, $this->encrypter->encrypt($value));
    }
}
