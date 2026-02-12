<?php

namespace pragmatic\seo\fields;

use craft\base\Model;

class SeoFieldValue extends Model
{
    public string $title = '';
    public string $description = '';
    public ?int $imageId = null;
    public string $imageDescription = '';
    public ?bool $sitemapEnabled = null;
    public ?bool $sitemapIncludeImages = null;

    public function rules(): array
    {
        return [
            [['title', 'description', 'imageDescription'], 'string'],
            [['imageId'], 'integer'],
            [['sitemapEnabled', 'sitemapIncludeImages'], 'boolean'],
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'imageId' => $this->imageId,
            'imageDescription' => $this->imageDescription,
            'sitemapEnabled' => $this->sitemapEnabled,
            'sitemapIncludeImages' => $this->sitemapIncludeImages,
        ];
    }
}
