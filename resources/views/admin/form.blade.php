@extends('layouts.app')

@section('content')
<main class="admin-page">
  <section class="admin-shell narrow">
    <header class="admin-header">
      <div>
        <a href="{{ route('admin.regulations.index') }}" class="admin-back">← Kembali ke Regulasi</a>
        <h1>{{ $mode === 'create' ? 'Tambah Regulasi' : 'Edit Regulasi' }}</h1>
        <p>Isi teks regulasi akan otomatis dipecah menjadi chunk dan disimpan ke vector database.</p>
      </div>
    </header>

    <form
      class="admin-form"
      method="POST"
      action="{{ $mode === 'create' ? route('admin.regulations.store') : route('admin.regulations.update', $document) }}"
    >
      @csrf
      @if($mode === 'edit')
        @method('PUT')
      @endif

      <label class="auth-field">
        <span>Judul Regulasi</span>
        <input type="text" name="title" value="{{ old('title', $document->title) }}" required autofocus>
        @error('title') <small>{{ $message }}</small> @enderror
      </label>

      <div class="admin-form-grid">
        <label class="auth-field">
          <span>Pasal</span>
          <input type="text" name="pasal" value="{{ old('pasal', $document->pasal) }}" placeholder="Contoh: Pasal 281">
          @error('pasal') <small>{{ $message }}</small> @enderror
        </label>

        <label class="auth-field">
          <span>Topik</span>
          <input type="text" name="topic" value="{{ old('topic', $document->topic) }}" placeholder="Contoh: SIM">
          @error('topic') <small>{{ $message }}</small> @enderror
        </label>
      </div>

      <label class="auth-field">
        <span>Sumber</span>
        <input type="text" name="source" value="{{ old('source', $document->source) }}" placeholder="Contoh: UU No. 22 Tahun 2009">
        @error('source') <small>{{ $message }}</small> @enderror
      </label>

      <label class="auth-field">
        <span>Isi Regulasi</span>
        <textarea name="content" rows="16" required placeholder="Tempel teks regulasi di sini...">{{ old('content', $document->content) }}</textarea>
        @error('content') <small>{{ $message }}</small> @enderror
      </label>

      <div class="admin-form-actions">
        <a href="{{ route('admin.regulations.index') }}" class="admin-secondary-btn">Batal</a>
        <button type="submit" class="admin-primary-btn">
          {{ $mode === 'create' ? 'Simpan & Proses Chunk' : 'Update & Proses Ulang' }}
        </button>
      </div>
    </form>
  </section>
</main>
@endsection
