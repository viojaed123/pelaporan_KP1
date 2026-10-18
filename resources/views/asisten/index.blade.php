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
  const pesan = document.getElementById('pesan').value.trim();
  if (!pesan) return;
  const box = document.getElementById('chat-box');
  const tombol = this.querySelector('button');
  box.innerHTML += '<div><strong>Anda:</strong> ' + pesan.replace(/</g,'&lt;') + '</div>';
  document.getElementById('pesan').value = '';
  const tanda = document.createElement('div');
  tanda.innerHTML = '<strong>Asisten:</strong> <em>mengetik&hellip;</em>';
  box.appendChild(tanda);
  box.scrollTop = box.scrollHeight;
  tombol.disabled = true;
  try {
    const res = await fetch('{{ route("asisten.kirim") }}', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
      body: JSON.stringify({pesan}),
      signal: AbortSignal.timeout(20000)
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    tanda.innerHTML = '<strong>Asisten:</strong> ' + (data.jawaban || '(kosong)').replace(/</g,'&lt;');
  } catch (err) {
    tanda.innerHTML = '<strong>Asisten:</strong> Maaf, saya sedang sibuk -- silakan coba lagi beberapa saat lagi.';
  } finally {
    tombol.disabled = false;
    box.scrollTop = box.scrollHeight;
  }
});
</script>
@endpush
