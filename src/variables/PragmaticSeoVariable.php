<?php

namespace pragmatic\seo\variables;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use pragmatic\seo\PragmaticSeo;
use pragmatic\seo\fields\SeoFieldValue;

class PragmaticSeoVariable
{
    public function render(?ElementInterface $element = null, string $fieldHandle = 'seo'): string
    {
        if (!$element) {
            return '';
        }

        $seoValue = $this->normalizeSeoValue($element->getFieldValue($fieldHandle));
        $siteId = (int)($element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id);
        $settings = $this->siteSettings($siteId);
        $title = $this->firstNonEmptyString(
            $seoValue['title'] ?? null,
            $element->title ?? null
        );
        $description = $this->firstNonEmptyString($seoValue['description'] ?? null);
        [$imageUrl, $imageAsset] = $this->resolveImage($element, $seoValue['imageId'] ?? null);
        $imageDescription = $this->firstNonEmptyString($seoValue['imageDescription'] ?? null);
        $canonicalUrl = $this->firstNonEmptyString($element->url ?? null);
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $ogType = $this->resolveOgType($settings['ogType'] ?? 'auto', $element);
        $ogLocale = $this->toOgLocale($site?->language);
        $alternateLocales = !empty($settings['enableHreflang']) ? $this->alternateOgLocales($element, $site?->id, $settings) : [];
        $robots = $this->firstNonEmptyString($settings['robots'] ?? null, $this->robotsContent($element));
        $siteName = $this->firstNonEmptyString($settings['siteNameOverride'] ?? null, $site?->name, Craft::$app->getSystemName());

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

        $tags[] = $this->metaTag('property', 'og:type', $ogType);
        if ($canonicalUrl !== null) {
            $tags[] = $this->metaTag('property', 'og:url', $canonicalUrl);
        }
        if ($siteName !== null) {
            $tags[] = $this->metaTag('property', 'og:site_name', $siteName);
        }
        if ($ogLocale !== null) {
            $tags[] = $this->metaTag('property', 'og:locale', $ogLocale);
            foreach ($alternateLocales as $locale) {
                $tags[] = $this->metaTag('property', 'og:locale:alternate', $locale);
            }
        }

        if ($imageUrl !== null) {
            $tags[] = $this->metaTag('property', 'og:image', $imageUrl);
            $tags[] = $this->metaTag('name', 'twitter:image', $imageUrl);
            if (!empty($settings['includeImageMeta']) && $imageDescription !== null) {
                $tags[] = $this->metaTag('property', 'og:image:alt', $imageDescription);
            }
            if (!empty($settings['includeImageMeta']) && $imageAsset) {
                $width = $imageAsset->getWidth();
                $height = $imageAsset->getHeight();
                if ($width) {
                    $tags[] = $this->metaTag('property', 'og:image:width', (string)$width);
                }
                if ($height) {
                    $tags[] = $this->metaTag('property', 'og:image:height', (string)$height);
                }
            }
        }

        $tags[] = $this->metaTag('name', 'twitter:card', $imageUrl ? 'summary_large_image' : 'summary');
        if (!empty($settings['twitterSite'])) {
            $tags[] = $this->metaTag('name', 'twitter:site', (string)$settings['twitterSite']);
        }
        if (!empty($settings['twitterCreator'])) {
            $tags[] = $this->metaTag('name', 'twitter:creator', (string)$settings['twitterCreator']);
        }
        if ($robots !== null) {
            $tags[] = $this->metaTag('name', 'robots', $robots);
        }

        if ($canonicalUrl !== null) {
            $tags[] = '<link rel="canonical" href="' . $this->e($canonicalUrl) . '">';
        }
        if (!empty($settings['enableHreflang'])) {
            foreach ($this->buildHrefLangLinks($element, $settings) as $alt) {
                $tags[] = '<link rel="alternate" hreflang="' . $this->e($alt['hreflang']) . '" href="' . $this->e($alt['url']) . '">';
            }
        }
        if (!empty($settings['enableArticleMeta'])) {
            foreach ($this->articleMetaTags($element) as $tag) {
                $tags[] = $tag;
            }
        }
        foreach ($this->jsonLdTags($element, $title, $description, $canonicalUrl, $imageUrl, $siteName, (string)($settings['schemaMode'] ?? 'auto')) as $jsonLd) {
            $tags[] = '<script type="application/ld+json">' . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
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
                'imageDescription' => $value->imageDescription,
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
                'imageDescription' => (string)($value['imageDescription'] ?? ''),
            ];
        }

        return [];
    }

    private function resolveImage(ElementInterface $element, mixed $imageId): array
    {
        if ($imageId === null || $imageId === '' || !$imageId) {
            return [null, null];
        }

        $siteId = (int)($element->siteId ?? Craft::$app->getSites()->getCurrentSite()->id);
        $asset = Craft::$app->getElements()->getElementById((int)$imageId, Asset::class, $siteId);
        if (!$asset) {
            $asset = Asset::find()->id((int)$imageId)->status(null)->one();
        }

        if (!$asset) {
            return [null, null];
        }

        $url = $asset->getUrl();
        return [$url ? (string)$url : null, $asset];
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

    private function toOgLocale(?string $language): ?string
    {
        $lang = trim((string)($language ?? ''));
        if ($lang === '') {
            return null;
        }

        if (str_contains($lang, '-')) {
            [$a, $b] = explode('-', $lang, 2);
            return strtolower($a) . '_' . strtoupper($b);
        }

        return strtolower($lang);
    }

    private function alternateOgLocales(ElementInterface $element, ?int $currentSiteId, array $settings): array
    {
        $locales = [];
        foreach ($this->buildHrefLangLinks($element, $settings) as $alt) {
            if (($alt['siteId'] ?? null) === $currentSiteId) {
                continue;
            }
            $locale = $this->toOgLocale($alt['language'] ?? null);
            if ($locale) {
                $locales[$locale] = true;
            }
        }

        return array_keys($locales);
    }

    private function buildHrefLangLinks(ElementInterface $element, array $settings): array
    {
        $canonicalId = (int)($element->canonicalId ?? $element->id ?? 0);
        if (!$canonicalId) {
            return [];
        }

        $links = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $localized = Craft::$app->getElements()->getElementById($canonicalId, $element::class, (int)$site->id);
            if (!$localized || empty($localized->url)) {
                continue;
            }

            $links[] = [
                'siteId' => (int)$site->id,
                'language' => $site->language,
                'hreflang' => strtolower(str_replace('_', '-', $site->language)),
                'url' => (string)$localized->url,
            ];
        }

        $xDefaultSiteId = !empty($settings['xDefaultSiteId']) ? (int)$settings['xDefaultSiteId'] : null;
        $xDefaultSite = $xDefaultSiteId ? Craft::$app->getSites()->getSiteById($xDefaultSiteId) : Craft::$app->getSites()->getPrimarySite();
        if ($xDefaultSite) {
            $primary = Craft::$app->getElements()->getElementById($canonicalId, $element::class, (int)$xDefaultSite->id);
            if ($primary && !empty($primary->url)) {
                $links[] = [
                    'siteId' => (int)$xDefaultSite->id,
                    'language' => $xDefaultSite->language,
                    'hreflang' => 'x-default',
                    'url' => (string)$primary->url,
                ];
            }
        }

        $dedup = [];
        foreach ($links as $link) {
            $dedup[$link['hreflang'] . '|' . $link['url']] = $link;
        }
        return array_values($dedup);
    }

    private function articleMetaTags(ElementInterface $element): array
    {
        if (!$element instanceof Entry) {
            return [];
        }

        $tags = [];
        if ($element->postDate) {
            $tags[] = $this->metaTag('property', 'article:published_time', $element->postDate->format(DATE_ATOM));
        }
        if ($element->dateUpdated) {
            $tags[] = $this->metaTag('property', 'article:modified_time', $element->dateUpdated->format(DATE_ATOM));
        }
        if ($element->section) {
            $tags[] = $this->metaTag('property', 'article:section', (string)$element->section->name);
        }
        if ($element->author) {
            $authorName = $this->firstNonEmptyString($element->author->fullName, $element->author->username);
            if ($authorName) {
                $tags[] = $this->metaTag('property', 'article:author', $authorName);
            }
        }

        return $tags;
    }

    private function robotsContent(ElementInterface $element): ?string
    {
        if ($element instanceof Entry && $element->enabled === false) {
            return 'noindex,nofollow';
        }

        return 'index,follow';
    }

    private function jsonLdTags(
        ElementInterface $element,
        ?string $title,
        ?string $description,
        ?string $canonicalUrl,
        ?string $imageUrl,
        ?string $siteName,
        string $schemaMode
    ): array {
        if ($schemaMode === 'none') {
            return [];
        }

        $items = [];
        $includeWebPage = $schemaMode === 'auto' || $schemaMode === 'webpage';
        $includeArticle = $schemaMode === 'article' || ($schemaMode === 'auto' && $element instanceof Entry);

        if ($canonicalUrl !== null && $includeWebPage) {
            $items[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'url' => $canonicalUrl,
                'name' => $title,
                'description' => $description,
                'inLanguage' => $this->firstNonEmptyString(Craft::$app->getSites()->getSiteById((int)($element->siteId ?? 0))?->language),
                'image' => $imageUrl,
            ]);
        }

        if ($element instanceof Entry && $includeArticle) {
            $article = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $title,
                'description' => $description,
                'mainEntityOfPage' => $canonicalUrl,
                'datePublished' => $element->postDate?->format(DATE_ATOM),
                'dateModified' => $element->dateUpdated?->format(DATE_ATOM),
                'image' => $imageUrl ? [$imageUrl] : null,
                'publisher' => $siteName ? [
                    '@type' => 'Organization',
                    'name' => $siteName,
                ] : null,
                'author' => $element->author ? [
                    '@type' => 'Person',
                    'name' => $this->firstNonEmptyString($element->author->fullName, $element->author->username),
                ] : null,
            ];
            $items[] = array_filter($article, fn($v) => $v !== null && $v !== '');
        }

        return $items;
    }

    private function siteSettings(int $siteId): array
    {
        if (!isset(PragmaticSeo::$plugin)) {
            return [
                'ogType' => 'auto',
                'robots' => '',
                'twitterSite' => '',
                'twitterCreator' => '',
                'siteNameOverride' => '',
                'enableHreflang' => true,
                'xDefaultSiteId' => null,
                'schemaMode' => 'auto',
                'enableArticleMeta' => true,
                'includeImageMeta' => true,
            ];
        }

        return PragmaticSeo::$plugin->getMetaSettings()->getSiteSettings($siteId);
    }

    private function resolveOgType(string $configuredType, ElementInterface $element): string
    {
        if (in_array($configuredType, ['article', 'website'], true)) {
            return $configuredType;
        }

        return $element instanceof Entry ? 'article' : 'website';
    }
}
