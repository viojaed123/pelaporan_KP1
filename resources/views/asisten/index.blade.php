@extends('layouts.app')

@section('judul', 'Asisten AI')

@section('isi')
  <section class='lembar'>
    <h1>Asisten AI</h1>
    <div style='margin-top:10px'>
      <div id='chat-box' style='height:320px;overflow-y:auto;border:1px solid #e2e8f0;padding:12px;border-radius:8px;background:#f8fafc'></div>
      <form id='form-asisten' style='margin-top:10px;display:flex;gap:8px'>
        @csrf
        <input type='text' id='pesan' name='pesan' class='input' style='flex:1;padding:10px;border:1px solid #cbd5e1;border-radius:8px' placeholder='Tulis pertanyaan...'>
        <button type='submit' class='btn btn-primary'>Kirim</button>
      </form>
    </div>
  </section>
@endsection

@push('kaki')
<script>
document.getElementById('form-asisten').addEventListener('submit', async function(e){
  e.preventDefault();
  const pesan = document.getElementById('pesan').value;
  const box = document.getElementById('chat-box');
  box.innerHTML += '<div><strong>Anda:</strong> ' + pesan + '</div>';
  document.getElementById('pesan').value = '';
  const res = await fetch('{{ route(\"asisten.kirim\") }}', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
    body: JSON.stringify({pesan})
  });
  const data = await res.json();
  box.innerHTML += '<div><strong>Asisten:</strong> ' + data.jawaban + '</div>';
  box.scrollTop = box.scrollHeight;
});
</script>
@endpush
