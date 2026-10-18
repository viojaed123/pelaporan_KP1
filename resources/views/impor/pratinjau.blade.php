@extends('layouts.app')
@section('judul', 'Pratinjau Impor')
@section('isi')
<section class="lembar">
  <h1>Pratinjau Impor ({{ strtoupper($jenis) }})</h1>
  <p>{{ $valid['ringkas']['sah'] }} baris valid, {{ $valid['ringkas']['gagal'] }} gagal, total {{ $valid['ringkas']['total'] }}</p>
</section>
@endsection
