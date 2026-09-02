@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'orphaned'])

@section('title')
    Orphaned Backups
@endsection

@section('content-header')
    <h1>Orphaned Backups<small>Backups left behind after the server they belonged to was deleted.</small></h1>
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
                A row lands here when the server it belonged to is deleted: the stored data - a Borg repository, an
                S3 object or an archive on the node - is not removed along with it, and this table is the only
                remaining record that it still exists. <strong>Delete</strong> removes that stored data and this row
                together. <strong>Forget</strong> removes only this row and leaves the stored data behind; use it
                when the node a backup was stored on has itself been deleted and there is nowhere left to send a
                delete request.
            </p>
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Orphaned Backup List</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tbody>
                            <tr>
                                <th>Server</th>
                                <th>Backup</th>
                                <th>Disk</th>
                                <th>Size</th>
                                <th>Created</th>
                                <th>Orphaned</th>
                                <th>Node</th>
                                <th></th>
                            </tr>
                            @forelse($backups as $backup)
                                <tr data-row="{{ $backup->id }}">
                                    <td>
                                        {{ $backup->server_name }}<br>
                                        <code title="{{ $backup->server_uuid }}">{{ $backup->server_uuid }}</code>
                                    </td>
                                    <td>
                                        {{ $backup->name }}<br>
                                        <code title="{{ $backup->backup_uuid }}">{{ $backup->backup_uuid }}</code>
                                    </td>
                                    <td>{{ $backup->disk }}</td>
                                    <td>{{ \Illuminate\Support\Number::fileSize($backup->bytes, 2) }}</td>
                                    <td>@datetimeHuman($backup->backup_created_at)</td>
                                    <td>@datetimeHuman($backup->orphaned_at)</td>
                                    <td>
                                        @if($backup->node)
                                            <a href="{{ route('admin.nodes.view', $backup->node_id) }}">{{ $backup->node->name }}</a>
                                        @else
                                            <span class="label label-default">Node deleted</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($backup->disk === \Pterodactyl\Models\Backup::ADAPTER_AWS_S3 || !is_null($backup->node_id))
                                            <button type="button" class="btn btn-xs btn-danger" data-action="delete-orphan" data-id="{{ $backup->id }}">Delete</button>
                                        @else
                                            <p class="text-muted small no-margin-bottom">Node deleted; the stored data cannot be removed from here.</p>
                                        @endif
                                        <button type="button" class="btn btn-xs btn-default" data-action="forget-orphan" data-id="{{ $backup->id }}">Forget (keep stored data)</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center">There are no orphaned backups.</td>
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
                        url: '/admin/settings/backups/orphaned/' + id,
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
                        url: '/admin/settings/backups/orphaned/' + id + '/forget',
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
