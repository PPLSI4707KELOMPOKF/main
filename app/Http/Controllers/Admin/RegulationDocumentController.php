<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegulationDocument;
use App\Services\RegulationIngestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegulationDocumentController extends Controller
{
    public function index()
    {
        $documents = RegulationDocument::withCount('chunks')
            ->with('creator:id,name')
            ->latest()
            ->paginate(10);

        return view('admin.regulations.index', compact('documents'));
    }

    public function create()
    {
        return view('admin.regulations.form', [
            'document' => new RegulationDocument(),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request, RegulationIngestionService $ingestion)
    {
        $data = $this->validatedData($request);
        $data['created_by'] = Auth::id();

        $chunkCount = DB::transaction(function () use ($data, $ingestion) {
            $document = RegulationDocument::create($data);

            return $ingestion->ingest($document);
        });

        return redirect()
            ->route('admin.regulations.index')
            ->with('status', "Regulasi berhasil ditambahkan dan diproses menjadi {$chunkCount} chunk.");
    }

    public function edit(RegulationDocument $regulation)
    {
        return view('admin.regulations.form', [
            'document' => $regulation,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, RegulationDocument $regulation, RegulationIngestionService $ingestion)
    {
        $data = $this->validatedData($request);

        $chunkCount = DB::transaction(function () use ($regulation, $data, $ingestion) {
            $regulation->update($data);

            return $ingestion->ingest($regulation->fresh());
        });

        return redirect()
            ->route('admin.regulations.index')
            ->with('status', "Regulasi berhasil diperbarui dan diproses ulang menjadi {$chunkCount} chunk.");
    }

    public function destroy(RegulationDocument $regulation, RegulationIngestionService $ingestion)
    {
        DB::transaction(function () use ($regulation, $ingestion) {
            $ingestion->removeDocumentVectors($regulation);
            $regulation->delete();
        });

        return redirect()
            ->route('admin.regulations.index')
            ->with('status', 'Regulasi dan vector terkait berhasil dihapus.');
    }

    protected function validatedData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'pasal' => ['nullable', 'string', 'max:100'],
            'topic' => ['nullable', 'string', 'max:120'],
            'content' => ['required', 'string', 'min:20'],
        ], [
            'title.required' => 'Judul regulasi wajib diisi.',
            'content.required' => 'Isi regulasi wajib diisi.',
            'content.min' => 'Isi regulasi minimal 20 karakter.',
        ]);
    }
}
