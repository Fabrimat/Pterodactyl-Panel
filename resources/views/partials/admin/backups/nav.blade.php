@include('partials/admin.settings.notice')

@section('settings::nav')
    @yield('settings::notice')
    <div class="row">
        <div class="col-xs-12">
            <div class="nav-tabs-custom nav-tabs-floating">
                <ul class="nav nav-tabs">
                    <li @if($activeTab === 'general')class="active"@endif><a href="{{ route('admin.backups.settings', 'general') }}">General</a></li>
                    <li @if($activeTab === 's3')class="active"@endif><a href="{{ route('admin.backups.settings', 's3') }}">S3</a></li>
                    <li @if($activeTab === 'borg')class="active"@endif><a href="{{ route('admin.backups.settings', 'borg') }}">Borg</a></li>
                </ul>
            </div>
        </div>
    </div>
@endsection
