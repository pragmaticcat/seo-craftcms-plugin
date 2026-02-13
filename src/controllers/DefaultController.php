<?php

namespace pragmatic\seo\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\helpers\Cp;
use craft\web\Controller;
use pragmatic\seo\PragmaticSeo;
use pragmatic\seo\fields\SeoField;
use pragmatic\seo\fields\SeoFieldValue;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    private const LEGACY_ASSET_META_TABLE = '{{%pragmaticseo_asset_meta}}';
    private const SITEMAP_ENTRYTYPE_TABLE = '{{%pragmaticseo_sitemap_entrytypes}}';
    protected int|bool|array $allowAnonymous = ['sitemap-xml'];

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
        $sites = Craft::$app->getSites()->getAllSites();
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;

        $settings = PragmaticSeo::$plugin->getMetaSettings()->getSiteSettings($selectedSiteId);

        return $this->renderTemplate('pragmatic-seo/options', [
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'settings' => $settings,
        ]);
    }

    public function actionAudit(): Response
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $selectedSiteId = (int)$selectedSite->id;
        $sectionId = (int)Craft::$app->getRequest()->getQueryParam('section', 0);
        $sections = $this->getSeoSectionsForSite($selectedSiteId, $sectionId);

        return $this->renderTemplate('pragmatic-seo/audit', [
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $selectedSiteId,
            'sections' => $sections,
            'sectionId' => $sectionId,
        ]);
    }

    public function actionRunAudit(): Response
    {
        $this->requireAcceptsJson();
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sectionId = (int)Craft::$app->getRequest()->getParam('section', 0);

        $settings = PragmaticSeo::$plugin->getMetaSettings()->getSiteSettings($siteId);
        $audit = $this->buildTechnicalAudit($siteId, $settings, $sectionId);

        return $this->asJson($audit);
    }

    public function actionSaveOptions(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $siteId = (int)$request->getBodyParam('site', 0);
        if (!$siteId) {
            throw new BadRequestHttpException('Missing site.');
        }

        $settings = (array)$request->getBodyParam('settings', []);
        PragmaticSeo::$plugin->getMetaSettings()->saveSiteSettings($siteId, $settings);

        Craft::$app->getSession()->setNotice('Opciones SEO guardadas.');
        return $this->redirectToPostedUrl();
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

        $sitesService = Craft::$app->getSites();
        $sites = $sitesService->getAllSites();
        $selectedSite = Cp::requestedSite() ?? $sitesService->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sections = $this->getSeoSectionsForSite($siteId, $sectionId);

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
            'sections' => $sections,
            'sectionId' => $sectionId,
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'selectedSiteId' => $siteId,
            'imageElementsById' => $imageElementsById,
            'search' => $search,
            'perPage' => $perPage,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    private function entryHasSeoField(Entry $entry): bool
    {
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof SeoField) {
                return true;
            }
        }

        return false;
    }

    public function actionSitemap(): Response
    {
        $request = Craft::$app->getRequest();
        $selectedSite = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $siteId = (int)$selectedSite->id;
        $sectionId = (int)$request->getQueryParam('section', 0);
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 50;

        $sections = $this->getSeoSectionsForSite($siteId, $sectionId);

        $entryQuery = Entry::find()->siteId($siteId)->status(null);
        if ($sectionId) {
            $entryQuery->sectionId($sectionId);
        }

        $allRows = [];
        foreach ($entryQuery->all() as $entry) {
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!$field instanceof SeoField) {
                    continue;
                }
                $value = $entry->getFieldValue($field->handle);
                if (!$value instanceof SeoFieldValue) {
                    $value = $field->normalizeValue($value, $entry);
                }
                if (!$value instanceof SeoFieldValue) {
                    $value = new SeoFieldValue();
                }
                $allRows[] = [
                    'entry' => $entry,
                    'fieldHandle' => $field->handle,
                    'sitemapEnabled' => $value->sitemapEnabled ?? true,
                    'sitemapIncludeImages' => $value->sitemapIncludeImages ?? false,
                ];
                break;
            }
        }

        $total = count($allRows);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $rows = array_slice($allRows, ($page - 1) * $perPage, $perPage);

        return $this->renderTemplate('pragmatic-seo/sitemap', [
            'rows' => $rows,
            'sections' => $sections,
            'sectionId' => $sectionId,
            'selectedSite' => $selectedSite,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'perPage' => $perPage,
        ]);
    }

    public function actionSaveSitemap(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $saveRow = $request->getBodyParam('saveRow');
        $entries = (array)$request->getBodyParam('entries', []);
        if ($saveRow === null || !isset($entries[$saveRow])) {
            throw new BadRequestHttpException('Invalid entry payload.');
        }

        $row = $entries[$saveRow];
        $entryId = (int)($row['entryId'] ?? 0);
        $fieldHandle = (string)($row['fieldHandle'] ?? '');
        $requestedSiteId = (int)$request->getBodyParam('site', 0);
        if (!$entryId || $fieldHandle === '') {
            throw new BadRequestHttpException('Missing entry data.');
        }

        $sitesService = Craft::$app->getSites();
        $siteIds = array_map(fn($site) => (int)$site->id, $sitesService->getAllSites());
        $siteId = $requestedSiteId && in_array($requestedSiteId, $siteIds, true)
            ? $requestedSiteId
            : (int)$sitesService->getCurrentSite()->id;

        $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
        if (!$entry) {
            throw new BadRequestHttpException('Entry not found.');
        }

        $field = $entry->getFieldLayout()?->getFieldByHandle($fieldHandle);
        if (!$field instanceof SeoField) {
            throw new BadRequestHttpException('Invalid SEO field for this entry.');
        }

        $current = $entry->getFieldValue($fieldHandle);
        if (!$current instanceof SeoFieldValue) {
            $current = $field->normalizeValue($current, $entry);
        }
        if (!$current instanceof SeoFieldValue) {
            $current = new SeoFieldValue();
        }

        $entry->setFieldValue($fieldHandle, [
            'title' => $current->title,
            'description' => $current->description,
            'imageId' => $current->imageId,
            'imageDescription' => $current->imageDescription,
            'sitemapEnabled' => !empty($row['sitemapEnabled']),
            'sitemapIncludeImages' => !empty($row['sitemapIncludeImages']),
        ]);

        $saved = Craft::$app->getElements()->saveElement($entry, false, false);
        if (!$saved) {
            $message = implode(' ', $entry->getErrorSummary(true)) ?: 'No se pudo guardar el sitemap.';
            if ($request->getAcceptsJson() || $request->getIsAjax()) {
                return $this->asJson(['success' => false, 'message' => $message]);
            }
            Craft::$app->getSession()->setError($message);
            return $this->redirectToPostedUrl();
        }

        if ($request->getAcceptsJson() || $request->getIsAjax()) {
            return $this->asJson(['success' => true, 'message' => 'Sitemap guardado.']);
        }

        Craft::$app->getSession()->setNotice('Sitemap guardado.');
        return $this->redirectToPostedUrl();
    }

    public function actionSitemapXml(): Response
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        $siteId = $site->id;
        $baseUrl = rtrim((string)$site->baseUrl, '/');

        $entryTypeRows = $this->getSitemapEntryTypeRows($siteId);
        $urls = [];

        foreach ($entryTypeRows as $typeRow) {
            if (empty($typeRow['settings']['enabled'])) {
                continue;
            }

            $entries = Entry::find()
                ->typeId((int)$typeRow['entryTypeId'])
                ->siteId($siteId)
                ->status('live')
                ->limit(null)
                ->all();

            foreach ($entries as $entry) {
                $seoHandle = (string)$typeRow['seoHandle'];
                $seoValue = $entry->getFieldValue($seoHandle);
                if (!$seoValue instanceof SeoFieldValue) {
                    $field = $entry->getFieldLayout()?->getFieldByHandle($seoHandle);
                    if ($field instanceof SeoField) {
                        $seoValue = $field->normalizeValue($seoValue, $entry);
                    }
                }

                $entryEnabled = $seoValue instanceof SeoFieldValue && $seoValue->sitemapEnabled !== null
                    ? (bool)$seoValue->sitemapEnabled
                    : (bool)$typeRow['settings']['enabled'];
                if (!$entryEnabled) {
                    continue;
                }

                $entryUrl = $entry->getUrl();
                if (!$entryUrl) {
                    continue;
                }

                $urls[] = [
                    'loc' => str_starts_with($entryUrl, 'http') ? $entryUrl : $baseUrl . '/' . ltrim($entryUrl, '/'),
                    'lastmod' => $entry->dateUpdated?->format(DATE_ATOM),
                ];
            }
        }

        $xml = $this->buildSitemapXml($urls);

        $response = Craft::$app->getResponse();
        $response->getHeaders()->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->format = Response::FORMAT_RAW;
        $response->content = $xml;
        return $response;
    }

    public function actionSaveContent(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $saveRow = $request->getBodyParam('saveRow');
        $entries = $request->getBodyParam('entries', []);
        if ($saveRow === null || !isset($entries[$saveRow])) {
            throw new BadRequestHttpException('Invalid entry payload.');
        }

        $row = $entries[$saveRow];
        $entryId = (int)($row['entryId'] ?? 0);
        $fieldHandle = (string)($row['fieldHandle'] ?? '');
        $values = (array)($row['values'] ?? []);
        $requestedSiteId = (int)$request->getBodyParam('site', 0);
        if (!$entryId || $fieldHandle === '') {
            throw new BadRequestHttpException('Missing entry data.');
        }

        $sitesService = Craft::$app->getSites();
        $siteIds = array_map(fn($site) => (int)$site->id, $sitesService->getAllSites());
        $siteId = $requestedSiteId && in_array($requestedSiteId, $siteIds, true)
            ? $requestedSiteId
            : (int)$sitesService->getCurrentSite()->id;
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

        $saved = Craft::$app->getElements()->saveElement($entry, false, false);
        if (!$saved) {
            $message = implode(' ', $entry->getErrorSummary(true)) ?: 'No se pudo guardar el contenido SEO.';
            if ($request->getAcceptsJson() || $request->getIsAjax()) {
                return $this->asJson([
                    'success' => false,
                    'message' => $message,
                ]);
            }

            Craft::$app->getSession()->setError($message);
            return $this->redirectToPostedUrl();
        }

        Craft::$app->getSession()->setNotice('Contenido SEO guardado.');

        if ($request->getAcceptsJson() || $request->getIsAjax()) {
            return $this->asJson([
                'success' => true,
                'message' => 'Contenido SEO guardado.',
            ]);
        }

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

    private function ensureSitemapEntryTypeTable(): void
    {
        $db = Craft::$app->getDb();
        if ($db->tableExists(self::SITEMAP_ENTRYTYPE_TABLE)) {
            return;
        }

        $db->createCommand()->createTable(self::SITEMAP_ENTRYTYPE_TABLE, [
            'entryTypeId' => 'integer NOT NULL PRIMARY KEY',
            'enabled' => 'boolean NOT NULL DEFAULT true',
            'includeImages' => 'boolean NOT NULL DEFAULT false',
        ])->execute();

        $db->createCommand()->addForeignKey(
            null,
            self::SITEMAP_ENTRYTYPE_TABLE,
            ['entryTypeId'],
            '{{%entrytypes}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        )->execute();
    }

    private function saveEntryTypeSitemapSettings(int $entryTypeId, array $settings): void
    {
        if ($entryTypeId <= 0) {
            return;
        }

        Craft::$app->getDb()->createCommand()->upsert(
            self::SITEMAP_ENTRYTYPE_TABLE,
            [
                'entryTypeId' => $entryTypeId,
                'enabled' => !empty($settings['enabled']) ? 1 : 0,
                'includeImages' => !empty($settings['includeImages']) ? 1 : 0,
            ],
            [
                'enabled' => !empty($settings['enabled']) ? 1 : 0,
                'includeImages' => !empty($settings['includeImages']) ? 1 : 0,
            ]
        )->execute();
    }

    private function getSitemapEntryTypeRows(int $siteId): array
    {
        $this->ensureSitemapEntryTypeTable();

        $savedSettings = (new Query())
            ->select(['entryTypeId', 'enabled', 'includeImages'])
            ->from(self::SITEMAP_ENTRYTYPE_TABLE)
            ->indexBy('entryTypeId')
            ->all();

        $rows = [];
        foreach (Craft::$app->entries->getAllEntryTypes() as $entryType) {
            $seoFields = array_values(array_filter(
                $entryType->getFieldLayout()?->getCustomFields() ?? [],
                fn($field) => $field instanceof SeoField
            ));
            if (empty($seoFields)) {
                continue;
            }

            $section = method_exists($entryType, 'getSection') ? $entryType->getSection() : null;

            $seoField = $seoFields[0];
            $defaults = $savedSettings[$entryType->id] ?? null;
            $settings = [
                'enabled' => $defaults ? (bool)$defaults['enabled'] : true,
                'includeImages' => $defaults ? (bool)$defaults['includeImages'] : false,
            ];

            $entries = Entry::find()
                ->typeId($entryType->id)
                ->siteId($siteId)
                ->status(null)
                ->limit(null)
                ->all();

            $entryRows = [];
            foreach ($entries as $entry) {
                $value = $entry->getFieldValue($seoField->handle);
                if (!$value instanceof SeoFieldValue) {
                    $value = $seoField->normalizeValue($value, $entry);
                }
                if (!$value instanceof SeoFieldValue) {
                    $value = new SeoFieldValue();
                }

                $entryRows[] = [
                    'entry' => $entry,
                    'enabled' => $value->sitemapEnabled ?? $settings['enabled'],
                    'includeImages' => $value->sitemapIncludeImages ?? $settings['includeImages'],
                ];
            }
            if (empty($entryRows)) {
                // Show only entry types that actually have entries in the active site.
                continue;
            }

            $rows[] = [
                'entryTypeId' => $entryType->id,
                'entryTypeName' => $entryType->name,
                'sectionId' => (int)($section?->id ?? 0),
                'sectionName' => $section?->name,
                'sectionType' => (string)($section?->type ?? ''),
                'seoHandle' => $seoField->handle,
                'settings' => $settings,
                'entries' => $entryRows,
            ];
        }

        return $rows;
    }

    private function splitSitemapRowsByPageGroup(array $rows): array
    {
        $regular = [];
        $page = [];

        foreach ($rows as $row) {
            if ($this->isPageSitemapRow($row)) {
                $page[] = $row;
            } else {
                $regular[] = $row;
            }
        }

        return [
            'regular' => $regular,
            'page' => $page,
        ];
    }

    private function isPageSitemapRow(array $row): bool
    {
        $entryTypeName = strtolower(trim((string)($row['entryTypeName'] ?? '')));
        $sectionName = strtolower(trim((string)($row['sectionName'] ?? '')));
        $sectionType = strtolower(trim((string)($row['sectionType'] ?? '')));

        if ($entryTypeName === 'page' || $sectionName === 'page') {
            return true;
        }

        // Craft "Single" sections usually represent page-like content.
        return $sectionType === 'single';
    }

    private function isSectionActiveForSite(mixed $section, int $siteId): bool
    {
        if (!$section || !method_exists($section, 'getSiteSettings')) {
            return false;
        }

        $allSettings = $section->getSiteSettings();
        if (!is_array($allSettings) || empty($allSettings)) {
            return false;
        }

        $siteSetting = null;
        if (isset($allSettings[$siteId])) {
            $siteSetting = $allSettings[$siteId];
        } else {
            foreach ($allSettings as $setting) {
                if ((int)($setting->siteId ?? 0) === $siteId) {
                    $siteSetting = $setting;
                    break;
                }
            }
        }

        if (!$siteSetting) {
            // Craft can expose section site settings with different key structures depending on context.
            // If we can't resolve the specific site setting reliably, avoid a false negative.
            return true;
        }

        // "Active for site" here means the section is assigned/configured for this site.
        // Do not require enabledByDefault, as entries can still exist with manual enablement.
        return true;
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
        return method_exists($asset, 'getAltText') || $asset->canGetProperty('alt') || $asset->canSetProperty('alt');
    }

    private function getAssetAltValue(Asset $asset): ?string
    {
        if (method_exists($asset, 'getAltText')) {
            return (string)$asset->getAltText();
        }
        if ($asset->canGetProperty('alt')) {
            return (string)($asset->alt ?? '');
        }
        return null;
    }

    private function setAssetAltValue(Asset $asset, string $value): void
    {
        if ($asset->canSetProperty('alt')) {
            $asset->alt = $value;
        }
    }

    private function buildTechnicalAudit(int $siteId, array $settings, int $sectionId = 0): array
    {
        $maxEntries = 300;
        $entryQuery = Entry::find()->siteId($siteId)->status(null);
        if ($sectionId) {
            $entryQuery->sectionId($sectionId);
        }
        $totalEntries = (int)(clone $entryQuery)->count();
        $entries = (clone $entryQuery)->limit($maxEntries)->all();
        $sites = Craft::$app->getSites()->getAllSites();
        $isMultiSite = count($sites) > 1;

        $rows = [];
        $summary = [
            'rows' => 0,
            'ok' => 0,
            'warn' => 0,
            'error' => 0,
        ];

        foreach ($entries as $entry) {
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!$field instanceof SeoField) {
                    continue;
                }

                $value = $entry->getFieldValue($field->handle);
                if (!$value instanceof SeoFieldValue) {
                    $value = $field->normalizeValue($value, $entry);
                }
                if (!$value instanceof SeoFieldValue) {
                    $value = new SeoFieldValue();
                }

                $checks = [
                    'title' => trim((string)$value->title) !== '' || trim((string)$entry->title) !== '',
                    'description' => trim((string)$value->description) !== '',
                    'canonical' => !empty($entry->url),
                    'image' => !empty($value->imageId),
                    'robots' => true,
                    'jsonLd' => (string)($settings['schemaMode'] ?? 'auto') !== 'none',
                    'hreflang' => true,
                ];

                if (!empty($settings['enableHreflang']) && $isMultiSite) {
                    $localizedUrls = 0;
                    $canonicalId = (int)($entry->canonicalId ?? $entry->id ?? 0);
                    foreach ($sites as $site) {
                        $localizedEntry = Craft::$app->getElements()->getElementById($canonicalId, Entry::class, (int)$site->id);
                        if ($localizedEntry && !empty($localizedEntry->url)) {
                            $localizedUrls++;
                        }
                    }
                    $checks['hreflang'] = $localizedUrls > 1;
                }

                $errorCount = (!$checks['title'] ? 1 : 0) + (!$checks['canonical'] ? 1 : 0);
                $warnCount = (!$checks['description'] ? 1 : 0)
                    + (!$checks['image'] ? 1 : 0)
                    + (!$checks['hreflang'] ? 1 : 0)
                    + (!$checks['jsonLd'] ? 1 : 0);

                $status = $errorCount > 0 ? 'error' : ($warnCount > 0 ? 'warn' : 'ok');
                $summary['rows']++;
                $summary[$status]++;
                $failedChecks = [];
                foreach ($checks as $check => $passed) {
                    if (!$passed) {
                        $failedChecks[] = $check;
                    }
                }

                $rows[] = [
                    'entryId' => (int)$entry->id,
                    'entrySiteId' => (int)$entry->siteId,
                    'entryTitle' => $entry->title ?: 'Entry #' . $entry->id,
                    'entryUrl' => $entry->url,
                    'entryCpUrl' => $entry->cpEditUrl,
                    'fieldHandle' => $field->handle,
                    'status' => $status,
                    'checks' => $checks,
                    'failedChecks' => $failedChecks,
                ];
            }
        }

        return [
            'summary' => $summary,
            'rows' => $rows,
            'truncated' => $totalEntries > $maxEntries,
            'scannedEntries' => count($entries),
            'totalEntries' => $totalEntries,
        ];
    }

    private function getSeoSectionsForSite(int $siteId, int $selectedSectionId = 0): array
    {
        $availableSectionIds = [];
        $entries = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->all();

        foreach ($entries as $entry) {
            if (!$this->entryHasSeoField($entry)) {
                continue;
            }

            $section = $entry->getSection();
            if (!$section) {
                continue;
            }

            $availableSectionIds[(int)$section->id] = true;
        }

        $sections = [];
        foreach (Craft::$app->entries->getAllSections() as $section) {
            if (isset($availableSectionIds[(int)$section->id])) {
                $sections[] = $section;
            }
        }

        if ($selectedSectionId && !isset($availableSectionIds[$selectedSectionId])) {
            $selectedSection = Craft::$app->entries->getSectionById($selectedSectionId);
            if ($selectedSection) {
                $sections[] = $selectedSection;
            }
        }

        return $sections;
    }

    private function buildSitemapXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $row) {
            $loc = htmlspecialchars((string)($row['loc'] ?? ''), ENT_XML1, 'UTF-8');
            if ($loc === '') {
                continue;
            }
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$loc}</loc>\n";
            if (!empty($row['lastmod'])) {
                $lastmod = htmlspecialchars((string)$row['lastmod'], ENT_XML1, 'UTF-8');
                $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }
}
