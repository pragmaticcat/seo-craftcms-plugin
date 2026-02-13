<?php

namespace pragmatic\seo\variables;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use pragmatic\seo\fields\SeoFieldValue;

class PragmaticSeoVariable
{
    public function render(?ElementInterface $element = null, string $fieldHandle = 'seo'): string
    {
        if (!$element) {
            return '';
        }

        $seoValue = $this->normalizeSeoValue($element->getFieldValue($fieldHandle));
        $title = $this->firstNonEmptyString(
            $seoValue['title'] ?? null,
            $element->title ?? null
        );
        $description = $this->firstNonEmptyString($seoValue['description'] ?? null);
        $imageUrl = $this->resolveImageUrl($element, $seoValue['imageId'] ?? null);
        $canonicalUrl = $this->firstNonEmptyString($element->url ?? null);

        $tags = [];
        if ($title !== null) {
            $tags[] = '<title>' . $this->e($title) . '</title>';
            $tags[] = $this->metaTag('property', 'og:title', $title);
            $tags[] = $this->metaTag('name', 'twitter:title', $title);
        }

        if ($description !== null) {
            $tags[] = $this->metaTag('name', 'description', $description);
            $tags[] = $this->metaTag('property', 'og:description', $description);
            $tags[] = $this->metaTag('name', 'twitter:description', $description);
        }

        if ($imageUrl !== null) {
            $tags[] = $this->metaTag('property', 'og:image', $imageUrl);
            $tags[] = $this->metaTag('name', 'twitter:image', $imageUrl);
        }

        $tags[] = $this->metaTag('name', 'twitter:card', $imageUrl ? 'summary_large_image' : 'summary');

        if ($canonicalUrl !== null) {
            $tags[] = '<link rel="canonical" href="' . $this->e($canonicalUrl) . '">';
        }

        return implode("\n", $tags);
    }

    private function normalizeSeoValue(mixed $value): array
    {
        if ($value instanceof SeoFieldValue) {
            return [
                'title' => $value->title,
                'description' => $value->description,
                'imageId' => $value->imageId,
            ];
        }

        if (is_array($value)) {
            $imageId = $value['imageId'] ?? null;
            if (is_array($imageId)) {
                $imageId = reset($imageId);
            }
            return [
                'title' => (string)($value['title'] ?? ''),
                'description' => (string)($value['description'] ?? ''),
                'imageId' => $imageId !== null && $imageId !== '' ? (int)$imageId : null,
            ];
        }

        return [];
    }

    private function resolveImageUrl(ElementInterface $element, mixed $imageId): ?string
    {
        if ($imageId === null || $imageId === '' || !$imageId) {
            return null;
        }

        $siteId = (int)($element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id);
        $asset = Craft::$app->getElements()->getElementById((int)$imageId, Asset::class, $siteId);
        if (!$asset) {
            $asset = Asset::find()->id((int)$imageId)->status(null)->one();
        }

        if (!$asset) {
            return null;
        }

        $url = $asset->getUrl();
        return $url ? (string)$url : null;
    }

    private function metaTag(string $kind, string $name, string $content): string
    {
        return '<meta ' . $kind . '="' . $this->e($name) . '" content="' . $this->e($content) . '">';
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string)($value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

