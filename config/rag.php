<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RAG Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the Top-K limit of relevant documents to retrieve
    | from the local vector store database.
    |
    */

    'top_k' => (int) env('RAG_TOP_K', 3),
];
