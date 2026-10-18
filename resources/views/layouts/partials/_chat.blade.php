{{-- layouts/partials/_chat.blade.php --}}
@if((defined('AI_AKTIF') && AI_AKTIF) || !empty($tampilkanChat))
<button class="tombol-chat" type="button" id="tombolChat" aria-label="Buka asisten AI" aria-expanded="false">{!! ikon_sp('robot') !!}</button>
<section class="panel-chat" id="panelChat" aria-label="Asisten AI" hidden>
  <div class="kepala">
    <span class="avatar avatar-kecil" style="background:rgba(255,255,255,.2)">AI</span>
    <span><span class="judul">Asisten Pelaporan</span><br><span class="status">Terhubung</span></span>
    <button class="tutup" type="button" id="tutupChat" aria-label="Tutup chat">&times;</button>
  </div>
  <div class="badan" id="badanChat">
    <div class="pesan-chat bot">Halo <strong>{{ e(Auth::user()->nama ?? '') }}</strong>! Apa yang bisa dibantu?</div>
  </div>
  <div class="kaki">
    <textarea id="inputChat" rows="1" placeholder="Tulis pesan..."></textarea>
    <button class="kirim" type="button" id="kirimChat">{!! ikon_sp('kirim') !!}</button>
  </div>
</section>
@endif