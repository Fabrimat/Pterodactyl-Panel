@extends('layouts.admin')
@include('partials/admin.backups.nav', ['activeTab' => 's3'])

@section('title')
    Backup Settings - S3
@endsection

@section('content-header')
    <h1>Backup Settings<small>Configure the Amazon S3 backup driver for Pterodactyl.</small></h1>
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
                the override and falls back to the environment value for that setting. The secret access key works
                differently: its stored value is never shown on this page, so leaving it blank always leaves the
                stored secret unchanged, and the checkbox next to it removes it instead. A field that already has a
                secret stored shows a row of dots as a placeholder so it is clear something is set, without ever
                revealing what it is.
            </p>
            <form action="" method="POST">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Amazon S3</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="control-label">Region <span class="field-optional"></span></label>
                                <div>
                                    <input type="text" class="form-control" name="backups:disks:s3:region" value="{{ old('backups:disks:s3:region', config('backups.disks.s3.region')) }}">
                                    <p class="text-muted small">Overrides AWS_DEFAULT_REGION. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Bucket <span class="field-optional"></span></label>
                                <div>
                                    <input type="text" class="form-control" name="backups:disks:s3:bucket" value="{{ old('backups:disks:s3:bucket', config('backups.disks.s3.bucket')) }}">
                                    <p class="text-muted small">Overrides AWS_BACKUPS_BUCKET. The bucket every server's backups are stored under, each inside a folder named after the server's UUID. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Access Key ID <span class="field-optional"></span></label>
                                <div>
                                    <input type="text" data-1p-ignore data-lpignore="true" data-bwignore="true" autocomplete="off" spellcheck="false" autocapitalize="off" class="form-control" name="backups:disks:s3:key" value="{{ old('backups:disks:s3:key', config('backups.disks.s3.key')) }}">
                                    <p class="text-muted small">Overrides AWS_ACCESS_KEY_ID. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Secret Access Key <span class="label label-{{ $s3SecretIsSet ? 'success' : 'default' }}">{{ $s3SecretIsSet ? 'Set' : 'Not Set' }}</span></label>
                                <div>
                                    <textarea class="form-control" rows="2" data-1p-ignore data-lpignore="true" data-bwignore="true" autocomplete="off" spellcheck="false" autocapitalize="off" name="backups:disks:s3:secret" placeholder="{{ $s3SecretIsSet ? $secretPlaceholder : '' }}"></textarea>
                                    <p class="text-muted small">
                                        Overrides AWS_SECRET_ACCESS_KEY. The stored value is never displayed here: leave this field blank to leave it unchanged.
                                    </p>
                                </div>
                                <div class="checkbox checkbox-primary">
                                    <input type="checkbox" id="clearS3Secret" name="clear_s3_secret" value="1" />
                                    <label for="clearS3Secret">Remove the stored secret access key and fall back to the environment value.</label>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Endpoint <span class="field-optional"></span></label>
                                <div>
                                    <input type="text" class="form-control" name="backups:disks:s3:endpoint" value="{{ old('backups:disks:s3:endpoint', config('backups.disks.s3.endpoint')) }}">
                                    <p class="text-muted small">Overrides AWS_ENDPOINT. Only needed for an S3-compatible service other than AWS itself. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Storage Class <span class="field-optional"></span></label>
                                <div>
                                    <input type="text" class="form-control" name="backups:disks:s3:storage_class" value="{{ old('backups:disks:s3:storage_class', config('backups.disks.s3.storage_class')) }}">
                                    <p class="text-muted small">Overrides AWS_BACKUPS_STORAGE_CLASS. Leave this field blank to fall back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Path-Style Endpoint <span class="field-optional"></span></label>
                                <div>
                                    @php
                                        $usePathStyleEndpoint = old('backups:disks:s3:use_path_style_endpoint', $usePathStyleEndpointIsSet ? (config('backups.disks.s3.use_path_style_endpoint') ? '1' : '0') : '');
                                    @endphp
                                    <select class="form-control" name="backups:disks:s3:use_path_style_endpoint">
                                        <option value="" @if((string) $usePathStyleEndpoint === '') selected @endif>Environment Default</option>
                                        <option value="1" @if((string) $usePathStyleEndpoint === '1') selected @endif>Yes</option>
                                        <option value="0" @if((string) $usePathStyleEndpoint === '0') selected @endif>No</option>
                                    </select>
                                    <p class="text-muted small">Overrides AWS_USE_PATH_STYLE_ENDPOINT. Needed for some S3-compatible services that do not support virtual-hosted-style requests. Environment Default removes the override and falls back to the environment value.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Accelerate Endpoint <span class="field-optional"></span></label>
                                <div>
                                    @php
                                        $useAccelerateEndpoint = old('backups:disks:s3:use_accelerate_endpoint', $useAccelerateEndpointIsSet ? (config('backups.disks.s3.use_accelerate_endpoint') ? '1' : '0') : '');
                                    @endphp
                                    <select class="form-control" name="backups:disks:s3:use_accelerate_endpoint">
                                        <option value="" @if((string) $useAccelerateEndpoint === '') selected @endif>Environment Default</option>
                                        <option value="1" @if((string) $useAccelerateEndpoint === '1') selected @endif>Yes</option>
                                        <option value="0" @if((string) $useAccelerateEndpoint === '0') selected @endif>No</option>
                                    </select>
                                    <p class="text-muted small">Overrides AWS_BACKUPS_USE_ACCELERATE. Only meaningful for AWS itself; leave this set to Environment Default or No for an S3-compatible service that does not support transfer acceleration.</p>
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
