@extends('layouts.app')

@section('content')
<main class="admin-page">
  <section class="admin-shell">
    <header class="admin-header">
      <div>
        <a href="{{ route('chat.index') }}" class="admin-back">← Kembali ke Chat</a>
        <h1>Manajemen Database Regulasi</h1>
        <p>Kelola dokumen hukum lalu lintas, chunk, dan vector RAG untuk LENTRA AI.</p>
      </div>
      <a href="{{ route('admin.regulations.create') }}" class="admin-primary-btn">+ Tambah Regulasi</a>
    </header>

    @if(session('status'))
      <div class="admin-alert">{{ session('status') }}</div>
    @endif

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Judul</th>
            <th>Pasal</th>
            <th>Topik</th>
            <th>Chunk</th>
            <th>Dibuat oleh</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($documents as $document)
            <tr>
              <td>
                <strong>{{ $document->title }}</strong>
                <span>{{ $document->source ?: 'Sumber belum diisi' }}</span>
              </td>
              <td>{{ $document->pasal ?: '-' }}</td>
              <td>{{ $document->topic ?: '-' }}</td>
              <td>{{ $document->chunks_count }}</td>
              <td>{{ $document->creator?->name ?? '-' }}</td>
              <td>
                <div class="admin-actions">
                  <a href="{{ route('admin.regulations.edit', $document) }}">Edit</a>
                  <form action="{{ route('admin.regulations.destroy', $document) }}" method="POST" onsubmit="return confirm('Hapus regulasi ini beserta chunk dan vector terkait?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit">Hapus</button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="admin-empty">Belum ada regulasi. Tambahkan dokumen pertama untuk memperkaya RAG.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="admin-pagination">
      {{ $documents->links() }}
    </div>
  </section>
</main>
@endsection
