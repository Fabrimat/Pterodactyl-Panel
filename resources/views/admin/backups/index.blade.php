@extends('layouts.admin')

@section('title')
    Backups
@endsection

@section('content-header')
    <h1>Backups<small>Every backup on the panel, live and orphaned.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Backups</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-xs-12">
            <p class="text-muted">
                Every backup the panel has a record of is listed below, whether the server it belongs to still exists
                or not. A row becomes <strong>orphaned</strong> when the server it belonged to is deleted: the stored
                data - a Borg repository, an S3 object or an archive on the node - is not removed along with it, and
                the orphaned row is the only remaining record that it still exists. <strong>Delete</strong> removes
                that stored data and the row together. <strong>Forget</strong> removes only the row and leaves the
                stored data behind; use it when the node a backup was stored on has itself been deleted and there is
                nowhere left to send a delete request. Neither action is offered for a live backup here - manage
                those from the server's own backups page instead.
            </p>
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Backup List</h3>
                    <div class="box-tools">
                        <form action="{{ route('admin.backups') }}" method="GET" class="form-inline">
                            <select name="server" class="form-control input-sm" onchange="this.form.submit()">
                                <option value="">All Servers</option>
                                @foreach($servers as $server)
                                    <option value="{{ $server->id }}" {{ $selectedServer === $server->id ? 'selected' : '' }}>{{ $server->name }}</option>
                                @endforeach
                            </select>
                            <label class="checkbox-inline" style="margin-left: 10px;">
                                <input type="checkbox" name="orphaned" value="1" onchange="this.form.submit()" {{ $orphanedOnly ? 'checked' : '' }}>
                                Orphaned only
                            </label>
                            <noscript><button type="submit" class="btn btn-sm btn-default">Filter</button></noscript>
                        </form>
                    </div>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tbody>
                            <tr>
                                <th>Server</th>
                                <th>Backup</th>
                                <th>Disk</th>
                                <th>Size</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Node</th>
                                <th></th>
                            </tr>
                            @forelse($backups as $backup)
                                <tr data-row="{{ $backup->id }}" class="{{ $backup->source === 'orphaned' ? 'text-muted' : '' }}">
                                    <td>
                                        @if($backup->source === 'orphaned')
                                            <span class="label label-warning">Orphaned</span><br>
                                            {{ $backup->server_name }}<br>
                                            <code title="{{ $backup->server_uuid }}">{{ $backup->server_uuid }}</code>
                                        @else
                                            <a href="{{ route('admin.servers.view', $backup->server_id) }}">{{ $backup->server_name }}</a><br>
                                            <code title="{{ $backup->server_uuid }}">{{ $backup->server_uuid }}</code>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $backup->name }}<br>
                                        <code title="{{ $backup->backup_uuid }}">{{ $backup->backup_uuid }}</code>
                                    </td>
                                    <td>{{ $backup->disk }}</td>
                                    <td>{{ \Illuminate\Support\Number::fileSize($backup->bytes, 2) }}</td>
                                    <td>
                                        @if($backup->source === 'orphaned')
                                            <span class="label label-success">Successful</span>
                                        @elseif(is_null($backup->completed_at))
                                            <span class="label label-warning">In Progress</span>
                                        @elseif($backup->is_successful)
                                            <span class="label label-success">Successful</span>
                                        @else
                                            <span class="label label-danger">Failed</span>
                                        @endif
                                        @if($backup->is_locked)
                                            <span class="label label-default"><i class="fa fa-lock"></i> Locked</span>
                                        @endif
                                    </td>
                                    <td>
                                        @datetimeHuman($backup->created_at)
                                        @if($backup->source === 'orphaned')
                                            <br><span class="text-muted small">orphaned @datetimeHuman($backup->orphaned_at)</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($backup->node_name)
                                            <a href="{{ route('admin.nodes.view', $backup->node_id) }}">{{ $backup->node_name }}</a>
                                        @else
                                            <span class="label label-default">Node deleted</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($backup->source === 'orphaned')
                                            @if($backup->disk === \Pterodactyl\Models\Backup::ADAPTER_AWS_S3 || !is_null($backup->node_id))
                                                <button type="button" class="btn btn-xs btn-danger" data-action="delete-orphan" data-id="{{ $backup->id }}">Delete</button>
                                            @else
                                                <p class="text-muted small no-margin-bottom">Node deleted; the stored data cannot be removed from here.</p>
                                            @endif
                                            <button type="button" class="btn btn-xs btn-default" data-action="forget-orphan" data-id="{{ $backup->id }}">Forget (keep stored data)</button>
                                        @else
                                            <a class="btn btn-xs btn-default" href="/server/{{ $backup->server_short_uuid }}/backups">Manage</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center">There are no backups.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($backups->hasPages())
                    <div class="box-footer with-border">
                        <div class="col-md-12 text-center">{!! $backups->render() !!}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $(document).ready(function () {
            $('[data-action="delete-orphan"]').click(function (event) {
                event.preventDefault();
                var row = $(this).closest('tr');
                var id = $(this).data('id');
                swal({
                    type: 'warning',
                    title: 'Delete Orphaned Backup',
                    text: 'This permanently removes the stored backup data and this record. There is no way to undo this.',
                    showCancelButton: true,
                    closeOnConfirm: false,
                    confirmButtonText: 'Delete',
                    confirmButtonColor: '#d9534f',
                    showLoaderOnConfirm: true
                }, function () {
                    $.ajax({
                        method: 'DELETE',
                        url: '/admin/backups/orphaned/' + id,
                        headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}'}
                    }).done(function () {
                        swal({type: 'success', title: '', text: 'The orphaned backup has been deleted.'});
                        row.slideUp();
                    }).fail(function (jqXHR) {
                        console.error(jqXHR);
                        swal({
                            type: 'error',
                            title: 'Whoops!',
                            text: 'An error occurred while attempting to delete this backup. Check the log for details, or use Forget if the node it was stored on is gone for good.'
                        });
                    });
                });
            });

            $('[data-action="forget-orphan"]').click(function (event) {
                event.preventDefault();
                var row = $(this).closest('tr');
                var id = $(this).data('id');
                swal({
                    type: 'warning',
                    title: 'Forget Orphaned Backup',
                    text: 'This removes only the panel record. The stored backup data, if any still exists, is left behind and can no longer be tracked or deleted from the panel afterward.',
                    showCancelButton: true,
                    closeOnConfirm: false,
                    confirmButtonText: 'Forget',
                    confirmButtonColor: '#d9534f',
                    showLoaderOnConfirm: true
                }, function () {
                    $.ajax({
                        method: 'POST',
                        url: '/admin/backups/orphaned/' + id + '/forget',
                        headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}'}
                    }).done(function () {
                        swal({type: 'success', title: '', text: 'The orphaned backup record has been forgotten.'});
                        row.slideUp();
                    }).fail(function (jqXHR) {
                        console.error(jqXHR);
                        swal({
                            type: 'error',
                            title: 'Whoops!',
                            text: 'An error occurred while attempting to forget this backup.'
                        });
                    });
                });
            });
        });
    </script>
@endsection
