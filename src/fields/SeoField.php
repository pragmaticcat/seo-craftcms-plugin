<?php

namespace pragmatic\seo\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use GraphQL\Type\Definition\Type;
use yii\db\Query;
use yii\db\Schema;

class SeoField extends Field
{
    private const STORAGE_TABLE = '{{%pragmaticseo_seo_blocks}}';
    private const STORAGE_UNIQUE_INDEX = 'pragmaticseo_seo_blocks_unique';
    private static bool $storageReady = false;

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
        return null;
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

        $this->ensureStorageTable();

        if ($element && $element->id && $this->id) {
            $stored = $this->loadStoredValue((int)$element->id, (int)$element->siteId);
            if ($stored !== null) {
                return $stored;
            }
        }

        return new SeoFieldValue([
            'title' => $this->defaultTitle,
            'description' => $this->defaultDescription,
            'imageId' => $this->defaultImageId,
            'imageDescription' => $this->defaultImageDescription,
            'sitemapEnabled' => null,
            'sitemapIncludeImages' => null,
        ]);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        $this->ensureStorageTable();

        if ($value instanceof SeoFieldValue) {
            $data = $value->toArray();
        } elseif (is_array($value)) {
            $data = [
                'title' => (string)($value['title'] ?? ''),
                'description' => (string)($value['description'] ?? ''),
                'imageId' => $this->normalizeImageId($value['imageId'] ?? null),
                'imageDescription' => (string)($value['imageDescription'] ?? ''),
                'sitemapEnabled' => array_key_exists('sitemapEnabled', $value) ? (bool)$value['sitemapEnabled'] : null,
                'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', $value) ? (bool)$value['sitemapIncludeImages'] : null,
            ];
        } else {
            $data = [
                'title' => '',
                'description' => '',
                'imageId' => null,
                'imageDescription' => '',
                'sitemapEnabled' => null,
                'sitemapIncludeImages' => null,
            ];
        }

        if ($element && $element->id && $this->id) {
            $this->persistStoredValue(
                (int)$element->id,
                (int)$element->siteId,
                $data
            );
        }

        return null;
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

    private function ensureStorageTable(): void
    {
        if (self::$storageReady) {
            return;
        }
        self::$storageReady = true;

        $db = Craft::$app->getDb();
        if ($db->tableExists(self::STORAGE_TABLE)) {
            $this->ensureStorageIndexes();
            return;
        }

        $db->createCommand()->createTable(self::STORAGE_TABLE, [
            'id' => Schema::TYPE_PK,
            'canonicalId' => Schema::TYPE_INTEGER . ' NOT NULL',
            'siteId' => Schema::TYPE_INTEGER . ' NOT NULL',
            'fieldId' => Schema::TYPE_INTEGER . ' NOT NULL',
            'title' => Schema::TYPE_TEXT,
            'description' => Schema::TYPE_TEXT,
            'imageId' => Schema::TYPE_INTEGER,
            'imageDescription' => Schema::TYPE_TEXT,
            'sitemapEnabled' => Schema::TYPE_BOOLEAN,
            'sitemapIncludeImages' => Schema::TYPE_BOOLEAN,
            'dateCreated' => Schema::TYPE_DATETIME . ' NOT NULL',
            'dateUpdated' => Schema::TYPE_DATETIME . ' NOT NULL',
            'uid' => 'char(36) NOT NULL',
        ])->execute();

        $this->ensureStorageIndexes();
    }

    private function ensureStorageIndexes(): void
    {
        $db = Craft::$app->getDb();
        try {
            $db->createCommand()->createIndex(
                self::STORAGE_UNIQUE_INDEX,
                self::STORAGE_TABLE,
                ['canonicalId', 'siteId', 'fieldId'],
                true
            )->execute();
        } catch (\Throwable) {
            // Index may already exist (reinstall/partial setup); safe to ignore.
        }
    }

    private function loadStoredValue(int $elementId, int $siteId): ?SeoFieldValue
    {
        $canonicalId = $this->resolveCanonicalId($elementId);
        $row = (new Query())
            ->from(self::STORAGE_TABLE)
            ->where([
                'canonicalId' => $canonicalId,
                'siteId' => $siteId,
                'fieldId' => (int)$this->id,
            ])
            ->one();

        $fallbackSiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        if (!$row && $siteId !== $fallbackSiteId) {
            $row = (new Query())
                ->from(self::STORAGE_TABLE)
                ->where([
                    'canonicalId' => $canonicalId,
                    'siteId' => $fallbackSiteId,
                    'fieldId' => (int)$this->id,
                ])
                ->one();
        }

        $optionsRow = (new Query())
            ->from(self::STORAGE_TABLE)
            ->where([
                'canonicalId' => $canonicalId,
                'siteId' => 0,
                'fieldId' => (int)$this->id,
            ])
            ->one();

        if (!$row && !$optionsRow) {
            return null;
        }

        return new SeoFieldValue([
            'title' => (string)($row['title'] ?? $this->defaultTitle),
            'description' => (string)($row['description'] ?? $this->defaultDescription),
            'imageId' => !empty($row['imageId']) ? (int)$row['imageId'] : $this->defaultImageId,
            'imageDescription' => (string)($row['imageDescription'] ?? $this->defaultImageDescription),
            'sitemapEnabled' => array_key_exists('sitemapEnabled', (array)$optionsRow) ? ($optionsRow['sitemapEnabled'] === null ? null : (bool)$optionsRow['sitemapEnabled']) : null,
            'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', (array)$optionsRow) ? ($optionsRow['sitemapIncludeImages'] === null ? null : (bool)$optionsRow['sitemapIncludeImages']) : null,
        ]);
    }

    private function persistStoredValue(int $elementId, int $siteId, array $data): void
    {
        $canonicalId = $this->resolveCanonicalId($elementId);
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $db->createCommand()->upsert(self::STORAGE_TABLE, [
            'canonicalId' => $canonicalId,
            'siteId' => $siteId,
            'fieldId' => (int)$this->id,
            'title' => (string)($data['title'] ?? ''),
            'description' => (string)($data['description'] ?? ''),
            'imageId' => $this->normalizeImageId($data['imageId'] ?? null),
            'imageDescription' => (string)($data['imageDescription'] ?? ''),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            'title' => (string)($data['title'] ?? ''),
            'description' => (string)($data['description'] ?? ''),
            'imageId' => $this->normalizeImageId($data['imageId'] ?? null),
            'imageDescription' => (string)($data['imageDescription'] ?? ''),
            'dateUpdated' => $now,
        ])->execute();

        if (array_key_exists('sitemapEnabled', $data) || array_key_exists('sitemapIncludeImages', $data)) {
            $db->createCommand()->upsert(self::STORAGE_TABLE, [
                'canonicalId' => $canonicalId,
                'siteId' => 0,
                'fieldId' => (int)$this->id,
                'title' => null,
                'description' => null,
                'imageId' => null,
                'imageDescription' => null,
                'sitemapEnabled' => array_key_exists('sitemapEnabled', $data) ? $data['sitemapEnabled'] : null,
                'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', $data) ? $data['sitemapIncludeImages'] : null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], [
                'sitemapEnabled' => array_key_exists('sitemapEnabled', $data) ? $data['sitemapEnabled'] : null,
                'sitemapIncludeImages' => array_key_exists('sitemapIncludeImages', $data) ? $data['sitemapIncludeImages'] : null,
                'dateUpdated' => $now,
            ])->execute();
        }
    }

    private function resolveCanonicalId(int $elementId): int
    {
        $canonicalId = (new Query())
            ->select(['canonicalId'])
            ->from('{{%elements}}')
            ->where(['id' => $elementId])
            ->scalar();

        return $canonicalId ? (int)$canonicalId : $elementId;
    }
}
