<?php

namespace pragmatic\seo;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use craft\events\RegisterCpNavItemsEvent;
use craft\web\twig\variables\Cp;
use pragmatic\seo\fields\SeoField;
use pragmatic\seo\services\MetaSettingsService;
use pragmatic\seo\variables\PragmaticSeoVariable;
use yii\base\Event;

class PragmaticSeo extends Plugin
{
    public static PragmaticSeo $plugin;
    public bool $hasCpSection = true;
    public string $templateRoot = 'src/templates';
    private bool $seoFieldsTranslationEnsured = false;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        $this->setComponents([
            'metaSettings' => MetaSettingsService::class,
        ]);

        Craft::$app->i18n->translations['pragmatic-seo'] = [
            'class' => \yii\i18n\PhpMessageSource::class,
            'basePath' => __DIR__ . '/translations',
            'forceTranslation' => true,
        ];

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['pragmatic-seo'] = 'pragmatic-seo/default/index';
                $event->rules['pragmatic-seo/images'] = 'pragmatic-seo/default/images';
                $event->rules['pragmatic-seo/options'] = 'pragmatic-seo/default/options';
                $event->rules['pragmatic-seo/options/save'] = 'pragmatic-seo/default/save-options';
                $event->rules['pragmatic-seo/audit'] = 'pragmatic-seo/default/audit';
                $event->rules['pragmatic-seo/audit/run'] = 'pragmatic-seo/default/run-audit';
                $event->rules['pragmatic-seo/content'] = 'pragmatic-seo/default/content';
                $event->rules['pragmatic-seo/sitemap'] = 'pragmatic-seo/default/sitemap';
                $event->rules['pragmatic-seo/sitemap/save'] = 'pragmatic-seo/default/save-sitemap';
                $event->rules['pragmatic-seo/general'] = 'pragmatic-seo/default/general';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['sitemap.xml'] = 'pragmatic-seo/default/sitemap-xml';
            }
        );

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = SeoField::class;
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('pragmaticSEO', PragmaticSeoVariable::class);
                $variable->set('pragmaticSeo', PragmaticSeoVariable::class);
            }
        );

        Craft::$app->onInit(function () {
            $this->ensureSeoFieldsAreTranslatable();
            $twig = Craft::$app->getView()->getTwig();
            $seoVariable = new PragmaticSeoVariable();
            $twig->addGlobal('pragmaticSEO', $seoVariable);
            $twig->addGlobal('pragmaticSeo', $seoVariable);
        });

        // Register nav item under shared "Tools" group
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function(RegisterCpNavItemsEvent $event) {
                $toolsLabel = Craft::t('pragmatic-seo', 'Tools');
                $groupKey = null;
                foreach ($event->navItems as $key => $item) {
                    if (($item['label'] ?? '') === $toolsLabel && isset($item['subnav'])) {
                        $groupKey = $key;
                        break;
                    }
                }

                if ($groupKey === null) {
                    $newItem = [
                        'label' => $toolsLabel,
                        'url' => 'pragmatic-seo',
                        'icon' => __DIR__ . '/icons/icon.svg',
                        'subnav' => [],
                    ];

                    // Insert after the first matching nav item
                    $afterKey = null;
                    $insertAfter = ['users', 'assets', 'categories', 'entries'];
                    foreach ($insertAfter as $target) {
                        foreach ($event->navItems as $key => $item) {
                            if (($item['url'] ?? '') === $target) {
                                $afterKey = $key;
                                break 2;
                            }
                        }
                    }

                    if ($afterKey !== null) {
                        $pos = array_search($afterKey, array_keys($event->navItems)) + 1;
                        $event->navItems = array_merge(
                            array_slice($event->navItems, 0, $pos, true),
                            ['pragmatic' => $newItem],
                            array_slice($event->navItems, $pos, null, true),
                        );
                        $groupKey = 'pragmatic';
                    } else {
                        $event->navItems['pragmatic'] = $newItem;
                        $groupKey = 'pragmatic';
                    }
                }

                $event->navItems[$groupKey]['subnav']['seo'] = [
                    'label' => 'SEO',
                    'url' => 'pragmatic-seo',
                ];

                $path = Craft::$app->getRequest()->getPathInfo();
                if ($path === 'pragmatic-seo' || str_starts_with($path, 'pragmatic-seo/')) {
                    $event->navItems[$groupKey]['url'] = 'pragmatic-seo';
                }
            }
        );
        
    }

    public function getCpNavItem(): ?array
    {
        return null;
    }

    public function getMetaSettings(): MetaSettingsService
    {
        /** @var MetaSettingsService $service */
        $service = $this->get('metaSettings');
        return $service;
    }

    private function ensureSeoFieldsAreTranslatable(): void
    {
        if ($this->seoFieldsTranslationEnsured) {
            return;
        }
        $this->seoFieldsTranslationEnsured = true;

        $fieldsService = Craft::$app->getFields();
        foreach ($fieldsService->getAllFields() as $field) {
            if (!$field instanceof SeoField) {
                continue;
            }

            if ($field->translationMethod === SeoField::TRANSLATION_METHOD_SITE) {
                continue;
            }

            $field->translationMethod = SeoField::TRANSLATION_METHOD_SITE;
            $fieldsService->saveField($field, false);
        }
    }
}
