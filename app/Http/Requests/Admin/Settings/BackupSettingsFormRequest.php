<?php

namespace Pterodactyl\Http\Requests\Admin\Settings;

use Pterodactyl\Models\Backup;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;
use Pterodactyl\Services\Backups\BorgConfigurationService;

class BackupSettingsFormRequest extends AdminFormRequest
{
    /**
     * Return all the rules to apply to this request's data.
     */
    public function rules(): array
    {
        return [
            'backups:default' => ['required', Rule::in([Backup::ADAPTER_WINGS, Backup::ADAPTER_AWS_S3, Backup::ADAPTER_BORG])],
            'backups:disks:borg:repository' => 'nullable|string|max:191',
            'backups:disks:borg:passphrase_secret' => 'nullable|string',
            'backups:disks:borg:encryption' => ['required', Rule::in(BorgConfigurationService::VALID_ENCRYPTION_MODES)],
            'backups:disks:borg:compression' => ['required', $this->compressionRule()],
            'backups:disks:borg:ssh:private_key' => 'nullable|string',
            'backups:disks:borg:ssh:known_hosts' => 'nullable|string',
            'backups:disks:borg:lock_wait' => 'required|integer|between:1,86400',
            'backups:disks:borg:checkpoint_interval' => 'required|integer|between:0,86400',
            'backups:disks:borg:upload_ratelimit' => 'required|integer|min:0',
            'clear_passphrase_secret' => 'nullable|boolean',
            'clear_ssh_private_key' => 'nullable|boolean',
        ];
    }

    /**
     * A checkbox that is left unticked is not submitted at all, so a per-field rule on
     * "confirm_passphrase_secret_change" - implicit or not - is never evaluated on the
     * one path this gate exists to catch: the field is simply absent from the request.
     * An after() callback runs regardless of what was submitted, which is why the check
     * lives here instead of in rules().
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->passphraseSecretChangeIsDangerous() && !$this->boolean('confirm_passphrase_secret_change')) {
                $validator->errors()->add(
                    'confirm_passphrase_secret_change',
                    'You must confirm that replacing or clearing the passphrase secret will make every existing backup permanently unreadable.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'backups:default' => 'Backup Driver',
            'backups:disks:borg:repository' => 'Borg Repository',
            'backups:disks:borg:passphrase_secret' => 'Borg Passphrase Secret',
            'backups:disks:borg:encryption' => 'Borg Encryption Mode',
            'backups:disks:borg:compression' => 'Borg Compression',
            'backups:disks:borg:ssh:private_key' => 'Borg SSH Private Key',
            'backups:disks:borg:ssh:known_hosts' => 'Borg SSH Known Hosts',
            'backups:disks:borg:lock_wait' => 'Borg Lock Wait',
            'backups:disks:borg:checkpoint_interval' => 'Borg Checkpoint Interval',
            'backups:disks:borg:upload_ratelimit' => 'Borg Upload Ratelimit',
            'clear_passphrase_secret' => 'Clear Passphrase Secret',
            'clear_ssh_private_key' => 'Clear SSH Private Key',
        ];
    }

    /**
     * Only the fields that map directly onto a settings key are normalized this
     * way. The two secrets are excluded because they are never set with a raw
     * value: an empty submission leaves the stored secret untouched, and a
     * non-empty one has to be encrypted first, both of which the controller
     * handles separately. The two clear checkboxes are request-only fields,
     * not settings keys.
     */
    public function normalize(?array $only = null): array
    {
        $keys = array_diff(array_keys($this->rules()), [
            'backups:disks:borg:passphrase_secret',
            'backups:disks:borg:ssh:private_key',
            'clear_passphrase_secret',
            'clear_ssh_private_key',
        ]);

        return $this->only($only ?? $keys);
    }

    /**
     * The compression grammar itself lives only on BorgConfigurationService::
     * COMPRESSION_PATTERN. This just reapplies the same optional "auto,"
     * prefix stripping the service does before checking a value against it,
     * so the two never drift apart.
     */
    protected function compressionRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $value = (string) $value;
            $spec = str_starts_with($value, 'auto,') ? substr($value, 5) : $value;

            if (!preg_match(BorgConfigurationService::COMPRESSION_PATTERN, $spec)) {
                $fail('The :attribute must be a value accepted by Borg\'s --compression flag, such as none, lz4, zstd,3 or zlib,9, optionally prefixed with auto,.');
            }
        };
    }

    /**
     * A passphrase secret change is dangerous once a value is already set and
     * this Panel has ever taken a backup. Soft-deleted backups still count:
     * deleting a backup only removes its archive, not the repository, which
     * keeps its key wrapped with the old passphrase until something rewraps
     * it. A repository can therefore still exist, and stay locked to the old
     * secret, even once every visible backup is gone.
     */
    protected function passphraseSecretChangeIsDangerous(): bool
    {
        $currentlySet = filled(config('backups.disks.borg.passphrase_secret'));
        $changing = $this->boolean('clear_passphrase_secret') || filled($this->input('backups:disks:borg:passphrase_secret'));

        return $currentlySet && $changing && Backup::withTrashed()->count() > 0;
    }
}
