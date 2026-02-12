<?php

namespace pragmatic\seo\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use craft\helpers\Json;
use GraphQL\Type\Definition\Type;
use yii\db\Schema;

class SeoField extends Field
{
    public string $translationMethod = self::TRANSLATION_METHOD_SITE;
    public string $defaultTitle = '';
    public string $defaultDescription = '';
    public ?int $defaultImageId = null;
    public string $defaultImageDescription = '';

    public static function displayName(): string
    {
        return 'SEO';
    }

    public static function icon(): string
    {
        return 'globe';
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_TEXT;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['defaultTitle', 'defaultDescription', 'defaultImageDescription'], 'string'];
        $rules[] = [['defaultImageId'], 'integer'];
        return $rules;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof SeoFieldValue) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
        }

        if (!is_array($value)) {
            $value = [];
        }

        return new SeoFieldValue([
            'title' => (string)($value['title'] ?? $this->defaultTitle),
            'description' => (string)($value['description'] ?? $this->defaultDescription),
            'imageId' => $this->normalizeImageId($value['imageId'] ?? null) ?? $this->defaultImageId,
            'imageDescription' => (string)($value['imageDescription'] ?? $this->defaultImageDescription),
            'sitemapEnabled' => array_key_exists('sitemapEnabled', $value) ? (bool)$value['sitemapEnabled'] : null,
            'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', $value) ? (bool)$value['sitemapIncludeImages'] : null,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof SeoFieldValue) {
            return Json::encode($value->toArray());
        }

        if (is_array($value)) {
            return Json::encode([
                'title' => (string)($value['title'] ?? ''),
                'description' => (string)($value['description'] ?? ''),
                'imageId' => $this->normalizeImageId($value['imageId'] ?? null),
                'imageDescription' => (string)($value['imageDescription'] ?? ''),
                'sitemapEnabled' => array_key_exists('sitemapEnabled', $value) ? (bool)$value['sitemapEnabled'] : null,
                'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', $value) ? (bool)$value['sitemapIncludeImages'] : null,
            ]);
        }

        return Json::encode([
            'title' => '',
            'description' => '',
            'imageId' => null,
            'imageDescription' => '',
            'sitemapEnabled' => null,
            'sitemapIncludeImages' => null,
        ]);
    }

    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        $normalized = $this->normalizeValue($value, $element);
        if (!$normalized instanceof SeoFieldValue) {
            return '';
        }

        return implode(' ', [
            $normalized->title,
            $normalized->description,
            $normalized->imageDescription,
        ]);
    }

    public function getInputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        $normalized = $this->normalizeValue($value, $element);
        if (!$normalized instanceof SeoFieldValue) {
            $normalized = new SeoFieldValue();
        }

        $imageElement = null;
        if ($normalized->imageId) {
            $siteId = $element?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
            $imageElement = Craft::$app->getElements()->getElementById($normalized->imageId, Asset::class, $siteId);
            if (!$imageElement) {
                $imageElement = Asset::find()->id($normalized->imageId)->status(null)->one();
            }
        }

        return Craft::$app->getView()->renderTemplate('pragmatic-seo/fields/seo_input', [
            'field' => $this,
            'value' => $normalized,
            'imageElement' => $imageElement,
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('pragmatic-seo/fields/seo_settings', [
            'field' => $this,
        ]);
    }

    public function getContentGqlType(): Type|array
    {
        return Type::string();
    }

    public function getContentGqlMutationArgumentType(): Type|array
    {
        return Type::string();
    }

    private function normalizeImageId(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (int)$value;
    }
}
