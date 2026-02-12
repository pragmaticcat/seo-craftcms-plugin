<?php

namespace pragmatic\seo\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\fields\PlainText;
use craft\web\Controller;
use pragmatic\seo\fields\SeoField;
use yii\web\Response;

class DefaultController extends Controller
{
    private const LEGACY_ASSET_META_TABLE = '{{%pragmaticseo_asset_meta}}';
    protected int|bool|array $allowAnonymous = false;

    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-seo/content');
    }

    public function actionGeneral(): Response
    {
        return $this->redirect('pragmatic-seo/content');
    }

    public function actionOptions(): Response
    {
        return $this->renderTemplate('pragmatic-seo/options');
    }

    public function actionImages(): Response
    {
        $this->cleanupLegacyAssetMetaTable();
        $request = Craft::$app->getRequest();
        $usedOnly = $request->getQueryParam('used', '1') === '1';
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $allowedPerPage = [50, 100, 250];
        $perPage = (int)$request->getQueryParam('perPage', 50);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 50;
        }

        $assetQuery = Asset::find()
            ->kind('image')
            ->status(null)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id);

        if ($usedOnly) {
            $usedIds = $this->getUsedAssetIds();
            $assetQuery->id(!empty($usedIds) ? $usedIds : [0]);
        }

        $total = (int)(clone $assetQuery)->count();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $assets = (clone $assetQuery)
            ->offset($offset)
            ->limit($perPage)
            ->all();

        $assetIds = array_map(fn(Asset $asset) => (int)$asset->id, $assets);
        $usedIds = $this->getUsedAssetIds($assetIds);
        $textColumns = $this->collectAssetTextColumns($assets);

        $rows = [];
        foreach ($assets as $asset) {
            $isUsed = in_array((int)$asset->id, $usedIds, true);
            if ($usedOnly && !$isUsed) {
                continue;
            }

            $fieldHandles = $this->assetTextFieldHandles($asset);
            $fieldValues = [];
            foreach ($textColumns as $handle => $meta) {
                $fieldValues[$handle] = in_array($handle, $fieldHandles, true)
                    ? (string)$asset->getFieldValue($handle)
                    : null;
            }

            $rows[] = [
                'asset' => $asset,
                'isUsed' => $isUsed,
                'fieldValues' => $fieldValues,
            ];
        }

        return $this->renderTemplate('pragmatic-seo/images', [
            'rows' => $rows,
            'usedOnly' => $usedOnly,
            'textColumns' => $textColumns,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
        ]);
    }

    public function actionContent(): Response
    {
        $seoFields = array_values(array_filter(
            Craft::$app->getFields()->getAllFields(),
            fn($field) => $field instanceof SeoField
        ));

        return $this->renderTemplate('pragmatic-seo/content', [
            'seoFields' => $seoFields,
        ]);
    }

    public function actionSaveContent(): Response
    {
        $this->requirePostRequest();
        $fieldsData = Craft::$app->getRequest()->getBodyParam('fields', []);
        $fieldsService = Craft::$app->getFields();

        foreach ($fieldsData as $fieldId => $data) {
            $field = $fieldsService->getFieldById((int)$fieldId);
            if (!$field instanceof SeoField) {
                continue;
            }

            $field->defaultTitle = trim((string)($data['title'] ?? ''));
            $field->defaultDescription = trim((string)($data['description'] ?? ''));
            $field->defaultImageId = !empty($data['imageId']) ? (int)$data['imageId'] : null;
            $field->defaultImageDescription = trim((string)($data['imageDescription'] ?? ''));

            $fieldsService->saveField($field);
        }

        Craft::$app->getSession()->setNotice('Contenido SEO guardado.');
        return $this->redirect('pragmatic-seo/content');
    }

    public function actionSaveImages(): Response
    {
        $this->requirePostRequest();
        $this->cleanupLegacyAssetMetaTable();

        $assetsData = Craft::$app->getRequest()->getBodyParam('assets', []);
        $saveRowId = (int)Craft::$app->getRequest()->getBodyParam('saveRowId', 0);
        if ($saveRowId > 0) {
            $assetsData = isset($assetsData[$saveRowId]) ? [$saveRowId => $assetsData[$saveRowId]] : [];
        }
        $elements = Craft::$app->getElements();

        foreach ($assetsData as $assetId => $data) {
            $asset = Asset::find()
                ->id((int)$assetId)
                ->status(null)
                ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
                ->one();
            if (!$asset) {
                continue;
            }

            $title = trim((string)($data['title'] ?? ''));
            if ($title !== '' && $title !== $asset->title) {
                $asset->title = $title;
            }

            $fieldsData = $data['fields'] ?? [];
            $assetTextHandles = $this->assetTextFieldHandles($asset);
            foreach ($fieldsData as $handle => $value) {
                if (!in_array((string)$handle, $assetTextHandles, true)) {
                    continue;
                }
                $asset->setFieldValue((string)$handle, trim((string)$value));
            }

            $elements->saveElement($asset, false, false, false);
        }

        Craft::$app->getSession()->setNotice('Imagenes guardadas.');
        return $this->redirectToPostedUrl();
    }

    private function getUsedAssetIds(array $assetIds = []): array
    {
        $query = (new Query())
            ->select(['targetId'])
            ->distinct()
            ->from('{{%relations}}');

        if (!empty($assetIds)) {
            $query->where(['targetId' => $assetIds]);
        }

        return array_map('intval', $query->column());
    }

    private function collectAssetTextColumns(array $assets): array
    {
        $columns = [];
        foreach ($assets as $asset) {
            foreach ($asset->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!$this->isSupportedAssetTextField($field)) {
                    continue;
                }
                $columns[$field->handle] = [
                    'handle' => $field->handle,
                    'name' => $field->name,
                ];
            }
        }

        uasort($columns, function(array $a, array $b): int {
            $aIsAlt = $this->isAltColumn($a);
            $bIsAlt = $this->isAltColumn($b);
            if ($aIsAlt !== $bIsAlt) {
                return $aIsAlt ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $columns;
    }

    private function assetTextFieldHandles(Asset $asset): array
    {
        $handles = [];
        foreach ($asset->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($this->isSupportedAssetTextField($field)) {
                $handles[] = $field->handle;
            }
        }
        return $handles;
    }

    private function isSupportedAssetTextField(FieldInterface $field): bool
    {
        if ($field instanceof PlainText) {
            return true;
        }

        return strtolower(get_class($field)) === 'craft\\ckeditor\\field';
    }

    private function isAltColumn(array $column): bool
    {
        $handle = strtolower((string)($column['handle'] ?? ''));
        $name = strtolower((string)($column['name'] ?? ''));

        return str_contains($handle, 'alt') || str_contains($name, 'alt');
    }

    private function cleanupLegacyAssetMetaTable(): void
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::LEGACY_ASSET_META_TABLE)) {
            return;
        }

        $db->createCommand()->dropTable(self::LEGACY_ASSET_META_TABLE)->execute();
    }
}
