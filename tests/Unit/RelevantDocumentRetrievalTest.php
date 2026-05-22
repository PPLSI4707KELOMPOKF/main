<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\VectorDatabaseService;
use Illuminate\Support\Facades\Storage;

class RelevantDocumentRetrievalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Fake storage to prevent modifying the actual vector_store.json
        Storage::fake();
    }

    /**
     * Test that search retrieves exactly Top-K (limit) documents.
     */
    public function test_search_retrieves_exact_top_k_documents(): void
    {
        $vectorDb = new VectorDatabaseService();

        // Add mock documents
        // Using simple orthogonal and diagonal vectors for predictable cosine similarities
        $vectorDb->addDocument(
            'doc-1',
            'Tentang penggunaan helm SNI',
            ['pasal' => 'Pasal 57', 'topik' => 'Helm'],
            [1.0, 0.0, 0.0]
        );

        $vectorDb->addDocument(
            'doc-2',
            'Tentang kepemilikan SIM',
            ['pasal' => 'Pasal 281', 'topik' => 'SIM'],
            [0.0, 1.0, 0.0]
        );

        $vectorDb->addDocument(
            'doc-3',
            'Tentang membawa STNK kendaraan',
            ['pasal' => 'Pasal 288', 'topik' => 'STNK'],
            [0.0, 0.0, 1.0]
        );

        // Query vector similar to doc-1 [1.0, 0.0, 0.0]
        $queryEmbedding = [0.9, 0.1, 0.0];

        // Test with Top-1
        $resultsTop1 = $vectorDb->search($queryEmbedding, 1);
        $this->assertCount(1, $resultsTop1);
        $this->assertEquals('doc-1', $resultsTop1[0]['id']);

        // Test with Top-2
        $resultsTop2 = $vectorDb->search($queryEmbedding, 2);
        $this->assertCount(2, $resultsTop2);
        $this->assertEquals('doc-1', $resultsTop2[0]['id']);
        $this->assertEquals('doc-2', $resultsTop2[1]['id']); // score should be positive and higher than doc-3 (which is 0)

        // Test with Top-3
        $resultsTop3 = $vectorDb->search($queryEmbedding, 3);
        // doc-3 has similarity 0.0 with queryEmbedding [0.9, 0.1, 0.0] because dot product is 0.
        // The service filters out non-positive similarities (similarity > 0.0).
        // Therefore, only 2 documents (doc-1 and doc-2) should be returned even if limit is 3.
        $this->assertCount(2, $resultsTop3);
    }

    /**
     * Test that search results are sorted by cosine similarity in descending order.
     */
    public function test_search_results_are_sorted_by_similarity_descending(): void
    {
        $vectorDb = new VectorDatabaseService();

        $vectorDb->addDocument('doc-a', 'Doc A', [], [0.5, 0.5]);
        $vectorDb->addDocument('doc-b', 'Doc B', [], [1.0, 0.0]); // Identical to query
        $vectorDb->addDocument('doc-c', 'Doc C', [], [0.1, 0.9]);

        $queryEmbedding = [1.0, 0.0];

        $results = $vectorDb->search($queryEmbedding, 3);

        $this->assertCount(3, $results);
        
        // Assert descending order of scores
        $this->assertEquals('doc-b', $results[0]['id']); // similarity = 1.0
        $this->assertEquals('doc-a', $results[1]['id']); // similarity = 0.707
        $this->assertEquals('doc-c', $results[2]['id']); // similarity = 0.1

        $this->assertGreaterThan($results[1]['score'], $results[0]['score']);
        $this->assertGreaterThan($results[2]['score'], $results[1]['score']);
    }

    /**
     * Test search when vector database is empty.
     */
    public function test_search_returns_empty_array_when_database_is_empty(): void
    {
        $vectorDb = new VectorDatabaseService();
        $queryEmbedding = [0.5, 0.5];

        $results = $vectorDb->search($queryEmbedding, 3);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }
}
