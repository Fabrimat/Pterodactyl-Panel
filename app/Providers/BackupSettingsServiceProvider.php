<?php

namespace Pterodactyl\Providers;

use Psr\Log\LoggerInterface as Log;
use Illuminate\Database\QueryException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class BackupSettingsServiceProvider extends ServiceProvider
{
    /**
     * An array of configuration keys to override with database values
     * if they exist. Kept separate from SettingsServiceProvider since these
     * are fork-owned settings rather than upstream ones.
     */
    protected array $keys = [
        'backups:default',
        'backups:disks:borg:repository',
        'backups:disks:borg:passphrase_secret',
        'backups:disks:borg:encryption',
        'backups:disks:borg:compression',
        'backups:disks:borg:ssh:private_key',
        'backups:disks:borg:ssh:known_hosts',
        'backups:disks:borg:lock_wait',
        'backups:disks:borg:checkpoint_interval',
        'backups:disks:borg:upload_ratelimit',
    ];

    /**
     * Keys that are encrypted and should be decrypted when set in the
     * configuration array.
     */
    protected static array $encrypted = [
        'backups:disks:borg:passphrase_secret',
        'backups:disks:borg:ssh:private_key',
    ];

    /**
     * Keys that config/backups.php casts to an integer from their environment
     * value. A database value arrives as a string, and BorgConfigurationService
     * sends these fields straight through into the payload it builds, so a
     * string here would change their type in that payload from a number to
     * a string without anything in this file's own logic looking wrong.
     */
    protected static array $integers = [
        'backups:disks:borg:lock_wait',
        'backups:disks:borg:checkpoint_interval',
        'backups:disks:borg:upload_ratelimit',
    ];

    /**
     * Boot the service provider.
     */
    public function boot(ConfigRepository $config, Encrypter $encrypter, Log $log, SettingsRepositoryInterface $settings): void
    {
        try {
            $values = $settings->all()->mapWithKeys(function ($setting) {
                return [$setting->key => $setting->value];
            })->toArray();
        } catch (QueryException $exception) {
            $log->notice('A query exception was encountered while trying to load backup settings from the database: ' . $exception->getMessage());

            return;
        }

        foreach ($this->keys as $key) {
            $value = array_get($values, 'settings::' . $key, $config->get(str_replace(':', '.', $key)));
            if (in_array($key, self::$encrypted)) {
                try {
                    $value = $encrypter->decrypt($value);
                } catch (DecryptException $exception) {
                }
            }

            switch (strtolower($value)) {
                case 'true':
                case '(true)':
                    $value = true;
                    break;
                case 'false':
                case '(false)':
                    $value = false;
                    break;
                case 'empty':
                case '(empty)':
                    $value = '';
                    break;
                case 'null':
                case '(null)':
                    $value = null;
            }

            if (in_array($key, self::$integers)) {
                $value = (int) $value;
            }

            $config->set(str_replace(':', '.', $key), $value);
        }
    }
}
