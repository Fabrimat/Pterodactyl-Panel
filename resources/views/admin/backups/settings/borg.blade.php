@extends('layouts.admin')
@include('partials/admin.backups.nav', ['activeTab' => 'borg'])

@section('title')
    Backup Settings - Borg
@endsection

@section('content-header')
    <h1>Backup Settings<small>Configure the Borg backup driver for Pterodactyl.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.backups') }}">Backups</a></li>
        <li class="active">Settings</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')
    @php
        $secretPlaceholder = str_repeat('•', 24);
    @endphp
    <div class="row">
        <div class="col-xs-12">
            <p class="text-muted">
                Every setting below overrides its matching environment variable. Leaving a plain field blank removes
                the override and falls back to the environment value for that setting. The passphrase secret and SSH
                private key below work differently: their stored value is never shown on this page, so leaving one
                blank always leaves the stored secret unchanged, and the checkbox next to it removes it instead. A
                field that already has a secret stored shows a row of dots as a placeholder so it is clear something
                is set, without ever revealing what it is.
            </p>
            <form action="" method="POST">
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
                            <div class="form-group col-md-4">
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
                            <div class="form-group col-md-4">
                                <label class="control-label">Backup Mode</label>
                                <div>
                                    @php
                                        $backupMode = old('backups:disks:borg:mode', config('backups.disks.borg.mode'));
                                    @endphp
                                    <select class="form-control" name="backups:disks:borg:mode">
                                        @foreach($modes as $value)
                                            <option value="{{ $value }}" @if($backupMode === $value) selected @endif>{{ $value }}</option>
                                        @endforeach
                                    </select>
                                    <p class="text-muted small">Overrides BORG_BACKUP_MODE. Incremental keeps one repository per server and stores only what changed since the last backup. Snapshot gives every backup its own self-contained repository instead, transferring and storing the full size every time. Changing this only affects backups taken after the change: an existing backup keeps resolving to the repository it was actually written to.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Compression</label>
                                <div>
                                    @php
                                        $compressionAlgorithm = old('backups:disks:borg:compression:algorithm', $compression['algorithm']);
                                        $compressionLevel = old('backups:disks:borg:compression:level', $compression['level']);
                                        $compressionAlgorithms = [
                                            'none' => 'None',
                                            'lz4' => 'LZ4',
                                            'zstd' => 'Zstandard',
                                            'zlib' => 'zlib',
                                            'lzma' => 'LZMA',
                                        ];
                                    @endphp
                                    <div class="row">
                                        <div class="col-xs-7">
                                            <select class="form-control" id="compressionAlgorithm" name="backups:disks:borg:compression:algorithm">
                                                @foreach($compressionAlgorithms as $value => $label)
                                                    <option value="{{ $value }}" @if($compressionAlgorithm === $value) selected @endif>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-xs-5">
                                            <select class="form-control" id="compressionLevel" name="backups:disks:borg:compression:level">
                                                <option value="">Default</option>
                                                @for($level = 0; $level <= 22; $level++)
                                                    <option value="{{ $level }}" @if((string) $compressionLevel === (string) $level) selected @endif>{{ $level }}</option>
                                                @endfor
                                            </select>
                                        </div>
                                    </div>
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" id="compressionAuto" name="backups:disks:borg:compression:auto" value="1" @if(old('backups:disks:borg:compression:auto', $compression['auto'])) checked @endif> Only compress a chunk if doing so actually makes it smaller (auto)
                                        </label>
                                    </div>
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
                                    <textarea class="form-control" rows="2" data-1p-ignore data-lpignore="true" data-bwignore="true" autocomplete="off" spellcheck="false" autocapitalize="off" name="backups:disks:borg:passphrase_secret" placeholder="{{ $passphraseSecretIsSet ? $secretPlaceholder : '' }}"></textarea>
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
                                    <textarea class="form-control" rows="6" data-1p-ignore data-lpignore="true" data-bwignore="true" autocomplete="off" spellcheck="false" autocapitalize="off" name="backups:disks:borg:ssh:private_key" placeholder="{{ $sshPrivateKeyIsSet ? $secretPlaceholder : '' }}"></textarea>
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
                                    <textarea class="form-control" rows="6" data-1p-ignore data-lpignore="true" data-bwignore="true" autocomplete="off" spellcheck="false" autocapitalize="off" name="backups:disks:borg:ssh:known_hosts">{{ old('backups:disks:borg:ssh:known_hosts', config('backups.disks.borg.ssh.known_hosts')) }}</textarea>
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

@section('footer-scripts')
    @parent

    <script>
        // Narrows the compression level options to whatever the selected algorithm
        // actually accepts: none and lz4 take no level at all, zstd runs from 1 to
        // 22, and zlib and lzma both run from 0 to 9. The grammar itself still lives
        // only in BorgConfigurationService, so a client that skips this - a browser
        // with JavaScript disabled, or a hand-crafted request - is caught by the
        // server-side validation instead of by anything in this block.
        $(document).ready(function () {
            var $algorithm = $('#compressionAlgorithm');
            var $level = $('#compressionLevel');

            function refreshCompressionLevel() {
                var algorithm = $algorithm.val();
                var noLevel = algorithm === 'none' || algorithm === 'lz4';
                var min = algorithm === 'zstd' ? 1 : 0;
                var max = algorithm === 'zstd' ? 22 : 9;

                $level.prop('disabled', noLevel);

                $level.find('option[value!=""]').each(function () {
                    var level = parseInt($(this).val(), 10);
                    $(this).prop('hidden', noLevel || level < min || level > max);
                });

                var current = parseInt($level.val(), 10);
                if (noLevel || isNaN(current) || current < min || current > max) {
                    $level.val('');
                }
            }

            $algorithm.on('change', refreshCompressionLevel);
            refreshCompressionLevel();
        });
    </script>
@endsection
