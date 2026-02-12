<?php

namespace pragmatic\seo\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\web\Controller;
use pragmatic\seo\fields\SeoField;
use pragmatic\seo\fields\SeoFieldValue;
use yii\web\BadRequestHttpException;
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
        $usedOnly = $this->parseUsedFilter($request->getQueryParam('used'));
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
                if ($handle === '__native_alt__') {
                    $fieldValues[$handle] = $this->getAssetAltValue($asset);
                } else {
                    $fieldValues[$handle] = in_array($handle, $fieldHandles, true)
                        ? (string)$asset->getFieldValue($handle)
                        : null;
                }
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
        $request = Craft::$app->getRequest();
        $search = (string)$request->getParam('q', '');
        $sectionId = (int)$request->getParam('section', 0);
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = (int)$request->getParam('perPage', 50);
        if (!in_array($perPage, [50, 100, 250], true)) {
            $perPage = 50;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $entryQuery = Entry::find()->siteId($siteId)->status(null);
        if ($sectionId) {
            $entryQuery->sectionId($sectionId);
        }
        if ($search !== '') {
            $entryQuery->search($search);
        }

        $entries = $entryQuery->all();
        $rows = [];
        foreach ($entries as $entry) {
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!$field instanceof SeoField) {
                    continue;
                }

                $value = $entry->getFieldValue($field->handle);
                if (!$value instanceof SeoFieldValue) {
                    $value = $field->normalizeValue($value, $entry);
                }

                $rows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $field->handle,
                    'fieldLabel' => $field->name,
                    'value' => $value instanceof SeoFieldValue ? $value : new SeoFieldValue(),
                ];
            }
        }

        $total = count($rows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);

        $imageIds = [];
        foreach ($pageRows as $row) {
            $imageId = $row['value']->imageId ?? null;
            if ($imageId) {
                $imageIds[] = (int)$imageId;
            }
        }
        $imageElementsById = [];
        if (!empty($imageIds)) {
            $images = Asset::find()
                ->id(array_values(array_unique($imageIds)))
                ->status(null)
                ->siteId($siteId)
                ->all();
            foreach ($images as $image) {
                $imageElementsById[(int)$image->id] = $image;
            }
        }

        $entryRowCounts = [];
        foreach ($pageRows as $row) {
            $entryId = $row['entry']->id;
            $entryRowCounts[$entryId] = ($entryRowCounts[$entryId] ?? 0) + 1;
        }

        return $this->renderTemplate('pragmatic-seo/content', [
            'rows' => $pageRows,
            'entryRowCounts' => $entryRowCounts,
            'sections' => Craft::$app->entries->getAllSections(),
            'sectionId' => $sectionId,
            'imageElementsById' => $imageElementsById,
            'search' => $search,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    public function actionSaveContent(): Response
    {
        $this->requirePostRequest();
        $saveRow = Craft::$app->getRequest()->getBodyParam('saveRow');
        $entries = Craft::$app->getRequest()->getBodyParam('entries', []);
        if ($saveRow === null || !isset($entries[$saveRow])) {
            throw new BadRequestHttpException('Invalid entry payload.');
        }

        $row = $entries[$saveRow];
        $entryId = (int)($row['entryId'] ?? 0);
        $fieldHandle = (string)($row['fieldHandle'] ?? '');
        $values = (array)($row['values'] ?? []);
        if (!$entryId || $fieldHandle === '') {
            throw new BadRequestHttpException('Missing entry data.');
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
        if (!$entry) {
            throw new BadRequestHttpException('Entry not found.');
        }

        $isSeoFieldOnEntry = false;
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof SeoField && $field->handle === $fieldHandle) {
                $isSeoFieldOnEntry = true;
                break;
            }
        }
        if (!$isSeoFieldOnEntry) {
            throw new BadRequestHttpException('Invalid SEO field for this entry.');
        }

        $entry->setFieldValue($fieldHandle, [
            'title' => trim((string)($values['title'] ?? '')),
            'description' => trim((string)($values['description'] ?? '')),
            'imageId' => $this->normalizeElementSelectValue($values['imageId'] ?? null),
            'imageDescription' => trim((string)($values['imageDescription'] ?? '')),
        ]);

        Craft::$app->getElements()->saveElement($entry, false, false);
        Craft::$app->getSession()->setNotice('Contenido SEO guardado.');
        return $this->redirectToPostedUrl();
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
                if ((string)$handle === '__native_alt__') {
                    $this->setAssetAltValue($asset, trim((string)$value));
                    continue;
                }
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
        if ($this->assetsSupportNativeAlt($assets)) {
            $columns['__native_alt__'] = [
                'handle' => '__native_alt__',
                'name' => 'Alt',
            ];
        }

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

    private function parseUsedFilter(mixed $rawValue): bool
    {
        if ($rawValue === null || $rawValue === '') {
            return true;
        }
        if (is_array($rawValue)) {
            $rawValue = end($rawValue);
        }
        return in_array((string)$rawValue, ['1', 'true', 'on'], true);
    }

    private function normalizeElementSelectValue(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        return (int)$value;
    }

    private function assetsSupportNativeAlt(array $assets): bool
    {
        foreach ($assets as $asset) {
            if ($this->hasAssetAltAttribute($asset)) {
                return true;
            }
        }
        return false;
    }

    private function hasAssetAltAttribute(Asset $asset): bool
    {
        return in_array('alt', $asset->attributes(), true) || method_exists($asset, 'getAltText');
    }

    private function getAssetAltValue(Asset $asset): ?string
    {
        if (method_exists($asset, 'getAltText')) {
            return (string)$asset->getAltText();
        }
        if ($this->hasAssetAltAttribute($asset)) {
            return (string)$asset->getAttribute('alt');
        }
        return null;
    }

    private function setAssetAltValue(Asset $asset, string $value): void
    {
        if ($this->hasAssetAltAttribute($asset)) {
            $asset->setAttribute('alt', $value);
        }
    }
}
