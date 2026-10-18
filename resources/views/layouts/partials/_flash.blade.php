{{-- 
  layouts/partials/_flash.blade.php
  Pesan flash dari session (sukses/gagal/info).
--}}
@if(session('info'))
  <div class="alert alert-info">
    <span class="ikon">{!! ikon_sp('info') !!}</span>
    <span>{{ session('info') }}</span>
  </div>
@endif
@if(session('pesan') || session('success'))
  @php
    $jenis = session('success') ? 'sukses' : 'info';
    $pesan = session('success') ?? session('pesan');
  @endphp
  <div class="alert alert-{{ $jenis === 'sukses' ? 'sukses' : 'info' }}">
    <span class="ikon">{!! ikon_sp($jenis === 'sukses' ? 'centang' : 'info') !!}</span>
    <span>{{ $pesan }}</span>
  </div>
@endif
@if(session('sukses'))
  <div class="alert alert-sukses">
    <span class="ikon">{!! ikon_sp('centang') !!}</span>
    <span>{{ session('sukses') }}</span>
  </div>
@endif
@if(session('gagal'))
  <div class="alert alert-gagal">
    <span class="ikon">{!! ikon_sp('silang') !!}</span>
    <span>{{ session('gagal') }}</span>
  </div>
@endif
@if($errors->any())
  <div class="alert alert-gagal">
    <span class="ikon">{!! ikon_sp('peringatan') !!}</span>
    <span>{{ $errors->first() }}</span>
  </div>
@endif