# Pragmatic SEO

Craft CMS 5 plugin scaffold for a Pragmatic SEO control panel section, with a two-tab CP interface ready to extend.

## Features
- CP section labeled `Pragmatic` with subnavigation item: `SEO`
- SEO section entry point redirects to `Contenido`
- Four CP tabs: `Contenido`, `Imagenes`, `Sitemap`, and `Opciones`
- Custom field type `SEO` with subfields:
- `titulo`
- `descripcion`
- `imagen` (Asset ID)
- `descripcion de imagen`
- `Contenido` view with inline-edit table of entries that use `SEO` fields (filters, pagination, row save, entry slideout)
- `Imagenes` view with inline-edit table for all image assets:
- filename links open the native Craft asset offcanvas editor
- editable `titulo`
- editable custom text fields on assets (`PlainText` / `CKEditor`)
- usage indicator per row (`usado` / `no usado`)
- filter to show only used assets
- row-by-row save (`Guardar fila`)
- `Sitemap` view to configure sitemap defaults by entry type (only entry types with `SEO` field)
- Per-entry sitemap overrides stored inside the `SEO` field value
- Public route: `/sitemap.xml`
- Base Twig layout for SEO pages: `pragmatic-seo/_layout`
- Plugin registered as `pragmatic-seo` for Craft CMS 5 projects

## Requirements
- Craft CMS `^5.0`
- PHP `>=8.2`

## Installation
1. Add the plugin to your Craft project and run `composer install`.
2. Install the plugin from the Craft Control Panel.
3. Run migrations when prompted.

## Usage
### CP
- Go to `Pragmatic > SEO`.
- Use the **Contenido** tab to edit default SEO values for each `SEO` field type instance.
- Use the **Imagenes** tab to edit image metadata and filter by used assets.
- Use the **Sitemap** tab to configure inclusion rules by entry type and entry.
- Use the **Opciones** tab for additional configuration (page scaffold ready).

### Frontend Twig helper
- You can render SEO meta tags for an entry with:
```twig
{{ pragmaticSEO.render(entry, 'seo')|raw }}
```
- Place it inside your frontend layout `<head>`.
- Replace `'seo'` with your SEO field handle if different.

## Project structure
```
src/
  PragmaticSeo.php
  controllers/
    DefaultController.php
  fields/
    SeoField.php
    SeoFieldValue.php
  templates/
    _layout.twig
    content.twig
    images.twig
    sitemap.twig
    sitemap_xml.twig
    fields/
      seo_input.twig
      seo_settings.twig
    general.twig
    options.twig
```

## Notes
- This repository currently provides the control panel structure and routing scaffold.
- Business logic, settings models, and persistence can be added incrementally on top of this base.
