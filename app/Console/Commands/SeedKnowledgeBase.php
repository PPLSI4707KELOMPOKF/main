<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmbeddingService;
use App\Services\VectorDatabaseService;

class SeedKnowledgeBase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rag:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed the local vector database with UU LLAJ documents for PBI-5';

    /**
     * Execute the console command.
     */
    public function handle(EmbeddingService $embeddingService, VectorDatabaseService $vectorDb)
    {
        $this->info('Starting to seed knowledge base...');

        $documents = [
            [
                'id' => 'pasal-57',
                'metadata' => ['pasal' => 'Pasal 57', 'topik' => 'Helm'],
                'content' => 'Setiap pengendara sepeda motor dan penumpang wajib menggunakan helm Standar Nasional Indonesia (SNI). Pelanggar dapat dipidana kurungan paling lama 1 bulan atau denda paling banyak Rp 250.000.'
            ],
            [
                'id' => 'pasal-281',
                'metadata' => ['pasal' => 'Pasal 281', 'topik' => 'SIM'],
                'content' => 'Setiap orang yang mengemudikan kendaraan bermotor di jalan yang tidak memiliki Surat Izin Mengemudi (SIM) dipidana dengan pidana kurungan paling lama 4 bulan atau denda paling banyak Rp 1.000.000.'
            ],
            [
                'id' => 'pasal-288',
                'metadata' => ['pasal' => 'Pasal 288', 'topik' => 'STNK'],
                'content' => 'Setiap orang yang mengemudikan kendaraan bermotor di jalan yang tidak dilengkapi dengan Surat Tanda Nomor Kendaraan (STNK) yang ditetapkan oleh Polri dipidana dengan pidana kurungan paling lama 2 bulan atau denda paling banyak Rp 500.000.'
            ],
            [
                'id' => 'pasal-287',
                'metadata' => ['pasal' => 'Pasal 287', 'topik' => 'Rambu dan Lampu Lalu Lintas'],
                'content' => 'Setiap orang yang mengemudikan Kendaraan Bermotor di Jalan yang melanggar aturan perintah atau larangan yang dinyatakan dengan Rambu Lalu Lintas atau Marka Jalan dipidana dengan pidana kurungan paling lama 2 bulan atau denda paling banyak Rp 500.000.'
            ],
        ];

        foreach ($documents as $doc) {
            $this->info("Generating embedding for {$doc['id']}...");
            
            // Generate embedding for the document content
            $embedding = $embeddingService->generateEmbedding($doc['content']);
            
            if ($embedding) {
                $vectorDb->addDocument($doc['id'], $doc['content'], $doc['metadata'], $embedding);
                $this->info("Successfully added {$doc['id']} to vector store.");
            } else {
                $this->error("Failed to generate embedding for {$doc['id']}. Ensure Ollama is running.");
            }
        }

        $this->info('Seeding completed!');
    }
}
