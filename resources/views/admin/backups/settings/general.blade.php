@extends('layouts.admin')
@include('partials/admin.backups.nav', ['activeTab' => 'general'])

@section('title')
    Backup Settings - General
@endsection

@section('content-header')
    <h1>Backup Settings<small>Configure the backup driver and general backup behaviour for Pterodactyl.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.backups') }}">Backups</a></li>
        <li class="active">Settings</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')
    <div class="row">
        <div class="col-xs-12">
            <p class="text-muted">
                Every setting below overrides its matching environment variable. Leaving a field blank removes the
                override and falls back to the environment value for that setting.
            </p>
            @if(config('backups.default') === \Pterodactyl\Models\Backup::ADAPTER_BORG && (empty(config('backups.disks.borg.repository')) || empty(config('backups.disks.borg.passphrase_secret'))))
                <div class="alert alert-warning">
                    The backup driver is set to Borg, but the repository or the passphrase secret on the
                    <a href="{{ route('admin.backups.settings', 'borg') }}">Borg</a> page is not configured. No
                    backup can be taken until both are set.
                </div>
            @endif
            @if(config('backups.default') === \Pterodactyl\Models\Backup::ADAPTER_AWS_S3 && (empty(config('backups.disks.s3.bucket')) || empty(config('backups.disks.s3.secret'))))
                <div class="alert alert-warning">
                    The backup driver is set to Amazon S3, but the bucket or the secret access key on the
                    <a href="{{ route('admin.backups.settings', 's3') }}">S3</a> page is not configured. No backup
                    can be taken until both are set.
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
                                    <p class="text-muted small">Overrides APP_BACKUP_DRIVER. Wings stores archives on the node itself and needs nothing further configured here; S3 and Borg both need the matching settings on their own page to actually take a backup.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">General Backup Settings</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-4">
                                <label class="control-label">Presigned URL Lifespan <span class="field-optional"></span></label>
                                <div>
                                    <input type="number" class="form-control" name="backups:presigned_url_lifespan" value="{{ old('backups:presigned_url_lifespan', config('backups.presigned_url_lifespan')) }}">
                                    <p class="text-muted small">Overrides BACKUP_PRESIGNED_URL_LIFESPAN. Minutes a presigned S3 upload URL handed to Wings stays valid. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Max Part Size <span class="field-optional"></span></label>
                                <div>
                                    <input type="number" class="form-control" name="backups:max_part_size" value="{{ old('backups:max_part_size', config('backups.max_part_size')) }}">
                                    <p class="text-muted small">Overrides BACKUP_MAX_PART_SIZE. Bytes allowed for a single part of an S3 multipart upload; AWS itself caps this at 5GB. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Prune Age <span class="field-optional"></span></label>
                                <div>
                                    <input type="number" class="form-control" name="backups:prune_age" value="{{ old('backups:prune_age', config('backups.prune_age')) }}">
                                    <p class="text-muted small">Overrides BACKUP_PRUNE_AGE. Minutes before a stuck backup is automatically failed; 0 disables this. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Throttle Limit <span class="field-optional"></span></label>
                                <div>
                                    <input type="number" class="form-control" name="backups:throttles:limit" value="{{ old('backups:throttles:limit', config('backups.throttles.limit')) }}">
                                    <p class="text-muted small">Overrides BACKUP_THROTTLE_LIMIT. Backups a user may create within the throttle period below, whether or not they are later deleted. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Throttle Period <span class="field-optional"></span></label>
                                <div>
                                    <input type="number" class="form-control" name="backups:throttles:period" value="{{ old('backups:throttles:period', config('backups.throttles.period')) }}">
                                    <p class="text-muted small">Overrides BACKUP_THROTTLE_PERIOD. Seconds the limit above applies over; 0 disables the throttle. Leave this field blank to fall back to the environment value.</p>
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
