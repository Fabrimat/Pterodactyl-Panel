<?php

namespace Pterodactyl\Http\Requests\Admin\Backups;

use Pterodactyl\Models\Backup;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;
use Pterodactyl\Services\Backups\BorgConfigurationService;

class BackupSettingsFormRequest extends AdminFormRequest
{
    /**
     * Every key allRules() may validate belongs to exactly one section here. Scoping
     * rules() to the section this request was made against is what makes the write
     * path safe to derive from the same list: normalize() already only ever returns
     * keys present in the request via Request::only(), so a partial submission was
     * never at risk of losing another section's stored override. What scoping
     * actually closes is the opposite hazard: without it, a request submitted to,
     * say, the S3 page could still validate and write a Borg key, or any other
     * section's key, simply by including it in the payload.
     */
    public const SECTIONS = [
        'general' => [
            'backups:default',
            'backups:presigned_url_lifespan',
            'backups:max_part_size',
            'backups:prune_age',
            'backups:throttles:limit',
            'backups:throttles:period',
        ],
        's3' => [
            'backups:disks:s3:region',
            'backups:disks:s3:bucket',
            'backups:disks:s3:key',
            'backups:disks:s3:secret',
            'backups:disks:s3:endpoint',
            'backups:disks:s3:use_path_style_endpoint',
            'backups:disks:s3:use_accelerate_endpoint',
            'backups:disks:s3:storage_class',
            'clear_s3_secret',
        ],
        'borg' => [
            'backups:disks:borg:repository',
            'backups:disks:borg:passphrase_secret',
            'backups:disks:borg:encryption',
            'backups:disks:borg:mode',
            'backups:disks:borg:compression',
            'backups:disks:borg:compression:algorithm',
            'backups:disks:borg:compression:level',
            'backups:disks:borg:compression:auto',
            'backups:disks:borg:ssh:private_key',
            'backups:disks:borg:ssh:known_hosts',
            'backups:disks:borg:lock_wait',
            'backups:disks:borg:checkpoint_interval',
            'backups:disks:borg:upload_ratelimit',
            'clear_passphrase_secret',
            'clear_ssh_private_key',
        ],
    ];

    /**
     * Returns only the rules for the section this request was made against. This is
     * what keeps a rule required on one section - Borg's lock wait, for instance -
     * from rejecting a submission made to a page that has no reason to carry it.
     */
    public function rules(): array
    {
        return array_intersect_key($this->allRules(), array_flip(self::SECTIONS[$this->route('section')]));
    }

    /**
     * The full rule set across every section, keyed the same way SECTIONS lists them.
     * rules() is what actually narrows this down to the current section.
     */
    protected function allRules(): array
    {
        return [
            'backups:default' => ['required', Rule::in([Backup::ADAPTER_WINGS, Backup::ADAPTER_AWS_S3, Backup::ADAPTER_BORG])],
            'backups:presigned_url_lifespan' => 'nullable|integer|min:1',
            'backups:max_part_size' => 'nullable|integer|min:1',
            'backups:prune_age' => 'nullable|integer|min:0',
            'backups:throttles:limit' => 'nullable|integer|min:0',
            'backups:throttles:period' => 'nullable|integer|min:0',
            'backups:disks:s3:region' => 'nullable|string|max:191',
            'backups:disks:s3:bucket' => 'nullable|string|max:191',
            'backups:disks:s3:key' => 'nullable|string|max:191',
            'backups:disks:s3:secret' => 'nullable|string',
            'backups:disks:s3:endpoint' => 'nullable|string|max:191',
            'backups:disks:s3:use_path_style_endpoint' => 'nullable|boolean',
            'backups:disks:s3:use_accelerate_endpoint' => 'nullable|boolean',
            'backups:disks:s3:storage_class' => 'nullable|string|max:191',
            'backups:disks:borg:repository' => 'nullable|string|max:191',
            'backups:disks:borg:passphrase_secret' => 'nullable|string',
            'backups:disks:borg:encryption' => ['required', Rule::in(BorgConfigurationService::VALID_ENCRYPTION_MODES)],
            'backups:disks:borg:mode' => ['required', Rule::in(BorgConfigurationService::VALID_MODES)],
            'backups:disks:borg:compression' => ['required', $this->compressionRule()],
            'backups:disks:borg:compression:algorithm' => 'nullable|string|max:20',
            // Not "integer": the per-algorithm range differs, so a numeric rule here
            // would be a second copy of the grammar that compressionRule() already
            // enforces, kept in sync by hand. This rule only bounds the size of what
            // gets composed; whatever survives it still has to satisfy the grammar,
            // which is where a non-numeric or out-of-range level is caught.
            'backups:disks:borg:compression:level' => 'nullable|string|max:5',
            'backups:disks:borg:compression:auto' => 'nullable|boolean',
            'backups:disks:borg:ssh:private_key' => 'nullable|string',
            'backups:disks:borg:ssh:known_hosts' => 'nullable|string',
            'backups:disks:borg:lock_wait' => 'required|integer|between:1,86400',
            'backups:disks:borg:checkpoint_interval' => 'required|integer|between:0,86400',
            'backups:disks:borg:upload_ratelimit' => 'required|integer|min:0',
            'clear_passphrase_secret' => 'nullable|boolean',
            'clear_ssh_private_key' => 'nullable|boolean',
            'clear_s3_secret' => 'nullable|boolean',
        ];
    }

    /**
     * The three compression controls are request-only fields: they never map onto a
     * settings key, and the composed string this builds out of them is validated by
     * compressionRule() the same way a directly submitted value would be. Reassembling
     * them has to run before rules() sees the request at all, which is why this cannot
     * wait until normalize().
     */
    public function prepareForValidation(): void
    {
        if (!$this->has('backups:disks:borg:compression:algorithm')) {
            return;
        }

        $algorithm = (string) $this->input('backups:disks:borg:compression:algorithm');
        $level = $this->input('backups:disks:borg:compression:level');

        $compression = $algorithm;
        if (filled($level)) {
            $compression .= ',' . $level;
        }

        if ($this->boolean('backups:disks:borg:compression:auto')) {
            $compression = 'auto,' . $compression;
        }

        $this->merge(['backups:disks:borg:compression' => $compression]);
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
            'backups:presigned_url_lifespan' => 'Presigned URL Lifespan',
            'backups:max_part_size' => 'Max Part Size',
            'backups:prune_age' => 'Prune Age',
            'backups:throttles:limit' => 'Backup Throttle Limit',
            'backups:throttles:period' => 'Backup Throttle Period',
            'backups:disks:s3:region' => 'S3 Region',
            'backups:disks:s3:bucket' => 'S3 Bucket',
            'backups:disks:s3:key' => 'S3 Access Key ID',
            'backups:disks:s3:secret' => 'S3 Secret Access Key',
            'backups:disks:s3:endpoint' => 'S3 Endpoint',
            'backups:disks:s3:use_path_style_endpoint' => 'S3 Path-Style Endpoint',
            'backups:disks:s3:use_accelerate_endpoint' => 'S3 Accelerate Endpoint',
            'backups:disks:s3:storage_class' => 'S3 Storage Class',
            'backups:disks:borg:repository' => 'Borg Repository',
            'backups:disks:borg:passphrase_secret' => 'Borg Passphrase Secret',
            'backups:disks:borg:encryption' => 'Borg Encryption Mode',
            'backups:disks:borg:mode' => 'Borg Backup Mode',
            'backups:disks:borg:compression' => 'Borg Compression',
            'backups:disks:borg:compression:algorithm' => 'Borg Compression Algorithm',
            'backups:disks:borg:compression:level' => 'Borg Compression Level',
            'backups:disks:borg:compression:auto' => 'Borg Compression Auto Prefix',
            'backups:disks:borg:ssh:private_key' => 'Borg SSH Private Key',
            'backups:disks:borg:ssh:known_hosts' => 'Borg SSH Known Hosts',
            'backups:disks:borg:lock_wait' => 'Borg Lock Wait',
            'backups:disks:borg:checkpoint_interval' => 'Borg Checkpoint Interval',
            'backups:disks:borg:upload_ratelimit' => 'Borg Upload Ratelimit',
            'clear_passphrase_secret' => 'Clear Passphrase Secret',
            'clear_ssh_private_key' => 'Clear SSH Private Key',
            'clear_s3_secret' => 'Clear S3 Secret',
        ];
    }

    /**
     * Only the fields that map directly onto a settings key are normalized this
     * way. The three secrets are excluded because they are never set with a raw
     * value: an empty submission leaves the stored secret untouched, and a
     * non-empty one has to be encrypted first, both of which the controller
     * handles separately. The three clear checkboxes and the three compression
     * controls are request-only fields, not settings keys.
     */
    public function normalize(?array $only = null): array
    {
        $keys = array_diff(array_keys($this->rules()), [
            'backups:disks:borg:passphrase_secret',
            'backups:disks:borg:ssh:private_key',
            'backups:disks:borg:compression:algorithm',
            'backups:disks:borg:compression:level',
            'backups:disks:borg:compression:auto',
            'backups:disks:s3:secret',
            'clear_passphrase_secret',
            'clear_ssh_private_key',
            'clear_s3_secret',
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
     * A passphrase secret change is dangerous whenever a value is already set and
     * the submission changes or clears it. There is no reliable count of backups
     * this Panel can read to decide whether it is safe to skip confirming: a
     * server's backup rows are removed along with it, which is not the same as a
     * repository no longer existing for it, so the gate does not try to use one.
     */
    protected function passphraseSecretChangeIsDangerous(): bool
    {
        $currentlySet = filled(config('backups.disks.borg.passphrase_secret'));
        $changing = $this->boolean('clear_passphrase_secret') || filled($this->input('backups:disks:borg:passphrase_secret'));

        return $currentlySet && $changing;
    }
}
