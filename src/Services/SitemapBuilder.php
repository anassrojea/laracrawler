<?php

namespace Anassrojea\Laracrawler\Services;

class SitemapBuilder
{
    protected array $urls = [];

    /**
     * Add a URL to the sitemap, optionally with images and videos.
     *
     * @param string $url The URL to add
     * @param array $images An array of image URLs to associate with the URL
     * @param array $videos An array of video URLs to associate with the URL
     * @return void
     */
    public function addUrl(string $url, array $images = [], array $videos = []): void
    {
        $this->urls[] = compact('url', 'images', 'videos');
    }

    /**
     * Returns an array of URLs and their associated images and videos.
     *
     * @return array An array of URLs with their associated images and videos
     */
    public function getUrls(): array
    {
        return $this->urls;
    }
}
