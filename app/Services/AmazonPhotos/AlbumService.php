<?php

namespace App\Services\AmazonPhotos;

use App\DTOs\Album;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AlbumService
{
    public function __construct(
        private readonly AmazonPhotosClient $client,
    ) {}

    /**
     * Fetch all albums from Amazon Photos.
     *
     * @return Collection<int, Album>
     */
    public function fetchAll(): Collection
    {
        $pageSize = config('amazon-photos.page_size', 200);
        $albums = collect();
        $offset = 0;

        Log::info('Fetching all albums from Amazon Photos...');

        do {
            $response = $this->client->get('/search', [
                'filters' => 'type:(ALBUMS)',
                'limit' => $pageSize,
                'offset' => $offset,
                'asset' => 'ALL',
                'searchContext' => 'customer',
                'resourceVersion' => 'V2',
                'ContentType' => 'JSON',
            ]);

            $nodes = $response['data'] ?? [];
            $count = count($nodes);

            foreach ($nodes as $node) {
                $albums->push(Album::fromApiResponse($node));
            }

            $offset += $pageSize;
        } while ($count === $pageSize);

        Log::info("Total albums fetched: {$albums->count()}");

        return $albums;
    }
}
