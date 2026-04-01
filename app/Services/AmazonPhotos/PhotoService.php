<?php

namespace App\Services\AmazonPhotos;

use App\DTOs\Photo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PhotoService
{
    public function __construct(
        private readonly AmazonPhotosClient $client,
    ) {}

    /**
     * Fetch all photos from Amazon Photos (paginates automatically).
     *
     * @return Collection<int, Photo>
     */
    public function fetchAll(): Collection
    {
        $pageSize = config('amazon-photos.page_size', 200);
        $photos = collect();
        $offset = 0;

        Log::info('Fetching all photos from Amazon Photos...');

        do {
            $response = $this->client->get('/search', [
                'filters' => 'type:PHOTOS',
                'limit' => $pageSize,
                'offset' => $offset,
                'sort' => ['contentProperties.contentDate DESC'],
                'asset' => 'ALL',
                'tempLink' => 'false',
                'searchContext' => 'customer',
                'resourceVersion' => 'V2',
                'ContentType' => 'JSON',
            ]);

            $nodes = $response['data'] ?? [];
            $count = count($nodes);

            foreach ($nodes as $node) {
                $photos->push(Photo::fromApiResponse($node));
            }

            Log::debug("Fetched {$count} photos at offset {$offset}.");

            $offset += $pageSize;
        } while ($count === $pageSize);

        Log::info("Total photos fetched: {$photos->count()}");

        return $photos;
    }
}
