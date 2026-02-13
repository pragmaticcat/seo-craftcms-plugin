<?php

namespace pragmatic\seo\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use yii\db\Query;
use yii\db\Schema;

class MetaSettingsService extends Component
{
    private const TABLE = '{{%pragmaticseo_meta_site_settings}}';
    private static bool $tableReady = false;

    public function getSiteSettings(int $siteId): array
    {
        $this->ensureTable();
        $defaults = $this->defaults();

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->one();

        if (!$row) {
            return $defaults;
        }

        return [
            'ogType' => $this->sanitizeOgType($row['ogType'] ?? null),
            'robots' => $this->sanitizeRobots($row['robots'] ?? null),
            'twitterSite' => trim((string)($row['twitterSite'] ?? '')),
            'twitterCreator' => trim((string)($row['twitterCreator'] ?? '')),
            'siteNameOverride' => trim((string)($row['siteNameOverride'] ?? '')),
            'enableHreflang' => (bool)($row['enableHreflang'] ?? true),
            'xDefaultSiteId' => !empty($row['xDefaultSiteId']) ? (int)$row['xDefaultSiteId'] : null,
            'schemaMode' => $this->sanitizeSchemaMode($row['schemaMode'] ?? null),
            'enableArticleMeta' => (bool)($row['enableArticleMeta'] ?? true),
            'includeImageMeta' => (bool)($row['includeImageMeta'] ?? true),
        ];
    }

    public function saveSiteSettings(int $siteId, array $input): void
    {
        $this->ensureTable();

        $data = [
            'siteId' => $siteId,
            'ogType' => $this->sanitizeOgType($input['ogType'] ?? null),
            'robots' => $this->sanitizeRobots($input['robots'] ?? null),
            'twitterSite' => trim((string)($input['twitterSite'] ?? '')),
            'twitterCreator' => trim((string)($input['twitterCreator'] ?? '')),
            'siteNameOverride' => trim((string)($input['siteNameOverride'] ?? '')),
            'enableHreflang' => !empty($input['enableHreflang']) ? 1 : 0,
            'xDefaultSiteId' => !empty($input['xDefaultSiteId']) ? (int)$input['xDefaultSiteId'] : null,
            'schemaMode' => $this->sanitizeSchemaMode($input['schemaMode'] ?? null),
            'enableArticleMeta' => !empty($input['enableArticleMeta']) ? 1 : 0,
            'includeImageMeta' => !empty($input['includeImageMeta']) ? 1 : 0,
        ];

        $now = Db::prepareDateForDb(new \DateTime());
        $db = Craft::$app->getDb();
        $db->createCommand()->upsert(self::TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();
    }

    private function defaults(): array
    {
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

    private function sanitizeOgType(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, ['auto', 'article', 'website'], true) ? $value : 'auto';
    }

    private function sanitizeSchemaMode(mixed $value): string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, ['auto', 'none', 'webpage', 'article'], true) ? $value : 'auto';
    }

    private function sanitizeRobots(mixed $value): string
    {
        return trim((string)($value ?? ''));
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        self::$tableReady = true;

        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::TABLE)) {
            $db->createCommand()->createTable(self::TABLE, [
                'id' => Schema::TYPE_PK,
                'siteId' => Schema::TYPE_INTEGER . ' NOT NULL',
                'ogType' => Schema::TYPE_STRING . "(16) NOT NULL DEFAULT 'auto'",
                'robots' => Schema::TYPE_STRING . '(128)',
                'twitterSite' => Schema::TYPE_STRING . '(64)',
                'twitterCreator' => Schema::TYPE_STRING . '(64)',
                'siteNameOverride' => Schema::TYPE_STRING . '(255)',
                'enableHreflang' => Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1',
                'xDefaultSiteId' => Schema::TYPE_INTEGER,
                'schemaMode' => Schema::TYPE_STRING . "(16) NOT NULL DEFAULT 'auto'",
                'enableArticleMeta' => Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1',
                'includeImageMeta' => Schema::TYPE_BOOLEAN . ' NOT NULL DEFAULT 1',
                'dateCreated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'dateUpdated' => Schema::TYPE_DATETIME . ' NOT NULL',
                'uid' => 'char(36) NOT NULL',
            ])->execute();
        }

        try {
            $db->createCommand()->createIndex(
                'pragmaticseo_meta_site_settings_siteId_unique',
                self::TABLE,
                ['siteId'],
                true
            )->execute();
        } catch (\Throwable) {
            // Safe: may already exist.
        }
    }
}

