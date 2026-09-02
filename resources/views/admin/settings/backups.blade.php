@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'backups'])

@section('title')
    Backup Settings
@endsection

@section('content-header')
    <h1>Backup Settings<small>Configure the backup driver and Borg settings for Pterodactyl.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Settings</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')
    <div class="row">
        <div class="col-xs-12">
            <p class="text-muted">
                Every setting below overrides its matching environment variable. Leaving a plain field blank removes
                the override and falls back to the environment value for that setting. The two secrets below work
                differently: their stored value is never shown on this page, so an empty secret field always leaves
                the stored secret unchanged, and the checkbox next to it removes it instead.
            </p>
            @if(config('backups.default') === \Pterodactyl\Models\Backup::ADAPTER_BORG && (empty(config('backups.disks.borg.repository')) || empty(config('backups.disks.borg.passphrase_secret'))))
                <div class="alert alert-warning">
                    The backup driver is set to Borg, but the repository or the passphrase secret below is not
                    configured. No backup can be taken until both are set.
                </div>
            @endif
            <form action="" method="POST">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Backup Driver</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-12">
                                <label class="control-label">Driver</label>
                                <div>
                                    @php
                                        $driver = old('backups:default', config('backups.default'));
                                    @endphp
                                    <select class="form-control" name="backups:default">
                                        @foreach($drivers as $value => $label)
                                            <option value="{{ $value }}" @if($driver === $value) selected @endif>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-muted small">Overrides APP_BACKUP_DRIVER. Wings stores archives on the node itself and needs nothing further configured here; S3 and Borg both need the matching settings below to actually take a backup.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Borg Repository</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-12">
                                <label class="control-label">Repository <span class="field-optional"></span></label>
                                <div>
                                    <input type="text" class="form-control" name="backups:disks:borg:repository" value="{{ old('backups:disks:borg:repository', config('backups.disks.borg.repository')) }}">
                                    <p class="text-muted small">Overrides BORG_REPOSITORY. The base location under which every server gets its own repository, named after the server's UUID. Leave this field blank to fall back to the environment value. Changing this value after backups already exist strands them at the old location; unlike the passphrase secret below, that is reversible by putting the old value back.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Encryption</label>
                                <div>
                                    @php
                                        $encryption = old('backups:disks:borg:encryption', config('backups.disks.borg.encryption'));
                                    @endphp
                                    <select class="form-control" name="backups:disks:borg:encryption">
                                        @foreach($encryptionModes as $mode)
                                            <option value="{{ $mode }}" @if($encryption === $mode) selected @endif>{{ $mode }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-muted small">Overrides BORG_ENCRYPTION. The encryption mode used for newly created repositories.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Compression</label>
                                <div>
                                    <input type="text" required class="form-control" name="backups:disks:borg:compression" value="{{ old('backups:disks:borg:compression', config('backups.disks.borg.compression')) }}">
                                    <p class="text-muted small">Overrides BORG_COMPRESSION. Passed to <code>borg --compression</code> verbatim: <code>none</code>, <code>lz4</code>, <code>zstd[,1-22]</code>, <code>zlib[,0-9]</code> or <code>lzma[,0-9]</code>, optionally prefixed with <code>auto,</code>.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Borg Passphrase</h3>
                    </div>
                    <div class="box-body">
                        @if($passphraseSecretIsSet)
                            <div class="row">
                                <div class="col-xs-12">
                                    <div class="alert alert-danger no-margin-bottom">
                                        Replacing or clearing the passphrase secret will make every repository it unlocks permanently unreadable. A repository already created on a node still exists and simply cannot be opened once the secret it was created with is gone, whether or not this Panel still lists a backup for it. This Panel keeps no copy of the derived passphrase - it is recomputed from this secret every time it is needed - so there is no way to recover access once the secret changes. Archive the current secret somewhere safe, with the same care given to <code>APP_KEY</code>, before replacing it. While no value has been stored here, <code>BORG_PASSPHRASE_SECRET</code> is the one in force, and editing it directly bypasses this confirmation entirely. Once a value has been stored here, it takes precedence over <code>BORG_PASSPHRASE_SECRET</code>, so changing the variable afterward has no effect at all.
                                    </div>
                                </div>
                            </div>
                        @endif
                        <div class="row">
                            <div class="form-group col-md-12">
                                <label class="control-label">Passphrase Secret <span class="label label-{{ $passphraseSecretIsSet ? 'success' : 'default' }}">{{ $passphraseSecretIsSet ? 'Set' : 'Not Set' }}</span></label>
                                <div>
                                    <textarea class="form-control" rows="2" name="backups:disks:borg:passphrase_secret"></textarea>
                                    <p class="text-muted small">
                                        Every repository's passphrase is derived from this secret; losing it makes every existing backup permanently unreadable, so it deserves exactly the same care as <code>APP_KEY</code>. See <code>BACKUPS.md</code> for details. The stored value is never displayed here: leave this field blank to leave it unchanged.
                                    </p>
                                </div>
                            </div>
                            <div class="form-group col-md-12">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="clear_passphrase_secret" value="1"> Remove the stored passphrase secret and fall back to the environment value.
                                    </label>
                                </div>
                            </div>
                            @if($passphraseSecretIsSet)
                                <div class="form-group col-md-12">
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="confirm_passphrase_secret_change" value="1"> I understand that replacing or clearing this secret is not recoverable and will make every existing backup unreadable.
                                        </label>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Borg SSH</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="control-label">Private Key <span class="label label-{{ $sshPrivateKeyIsSet ? 'success' : 'default' }}">{{ $sshPrivateKeyIsSet ? 'Set' : 'Not Set' }}</span></label>
                                <div>
                                    <textarea class="form-control" rows="6" name="backups:disks:borg:ssh:private_key"></textarea>
                                    <p class="text-muted small">Only used when the repository is reached over SSH; ignored for a local path. The stored value is never displayed here: leave this field blank to leave it unchanged.</p>
                                </div>
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="clear_ssh_private_key" value="1"> Remove the stored private key and fall back to the environment value.
                                    </label>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Known Hosts <span class="field-optional"></span></label>
                                <div>
                                    <textarea class="form-control" rows="6" name="backups:disks:borg:ssh:known_hosts">{{ old('backups:disks:borg:ssh:known_hosts', config('backups.disks.borg.ssh.known_hosts')) }}</textarea>
                                    <p class="text-muted small">Overrides BORG_SSH_KNOWN_HOSTS. Only used when the repository is reached over SSH; ignored for a local path. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Borg Tuning</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-4">
                                <label class="control-label">Lock Wait</label>
                                <div>
                                    <input type="number" required class="form-control" name="backups:disks:borg:lock_wait" value="{{ old('backups:disks:borg:lock_wait', config('backups.disks.borg.lock_wait')) }}">
                                    <p class="text-muted small">Overrides BORG_LOCK_WAIT. Seconds to wait on the repository lock before failing the backup.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Checkpoint Interval</label>
                                <div>
                                    <input type="number" required class="form-control" name="backups:disks:borg:checkpoint_interval" value="{{ old('backups:disks:borg:checkpoint_interval', config('backups.disks.borg.checkpoint_interval')) }}">
                                    <p class="text-muted small">Overrides BORG_CHECKPOINT_INTERVAL. Seconds between checkpoints while an archive is being written.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Upload Ratelimit</label>
                                <div>
                                    <input type="number" required class="form-control" name="backups:disks:borg:upload_ratelimit" value="{{ old('backups:disks:borg:upload_ratelimit', config('backups.disks.borg.upload_ratelimit')) }}">
                                    <p class="text-muted small">Overrides BORG_UPLOAD_RATELIMIT. KiB/s, 0 disables the limit.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box box-primary">
                    <div class="box-footer">
                        {{ csrf_field() }}
                        <button type="submit" name="_method" value="PATCH" class="btn btn-sm btn-primary pull-right">Save</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
