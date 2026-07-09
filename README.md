# Page Galleries

Adds a page-level image gallery to GetSimpleCMS-CE, auto-detected per theme
template. No manual per-template configuration is required: the plugin scans
the template file currently assigned to a page for a literal
`get_page_gallery(` call, and only then shows the gallery panel when that
page is edited.

## Installation

1. Copy `PageGallery.php` into `plugins/`
2. Copy the `PageGallery/` folder into `plugins/` as a sibling of the file
   above (not nested inside it)
3. Requires the SQLite3 PHP extension (same as your other data-backed plugins)

```
plugins/
  PageGallery.php
  PageGallery/
    lang/
      en_US.php
      es_ES.php
    assets/
      glightbox/
        glightbox.min.js
        glightbox.min.css
    README.md
```

## Using it in a theme

Add the template tag anywhere in a theme template file:

```php
<?php get_page_gallery(); ?>
```

Optional per-call overrides:

```php
<?php get_page_gallery(['columns' => 4, 'lightbox' => false, 'captions' => true]); ?>
```

As soon as this tag exists in a template file, any page using that template
will show a "Page Gallery" panel below the content editor when edited, with:

- Add Images (pick from the shared uploads library, or upload new files)
- Drag-to-reorder thumbnails
- Per-image captions
- Per-gallery settings: enabled, column count, lightbox on/off, captions on/off

If the editor switches a page's template dropdown to one that doesn't call
`get_page_gallery()`, the panel hides live (no page reload needed). If it
switches back, it reappears.

## Image browser

"Add Images" opens a Browse / Upload picker modeled directly on Items
Manager's file browser:

- **Browse** — navigates the real `data/uploads/` folder (with subfolders,
  breadcrumb navigation, and a filename filter). Clicking an image adds it to
  the gallery immediately.
- **Upload** — uploads straight into `data/uploads/` (root), with the same
  filename sanitisation and collision handling (`name.jpg` → `name-1.jpg`,
  etc.) as Items Manager, and calls GS's own `genStdThumb()` (from
  `admin/inc/imagemanipulation.php`) so the file gets a standard GS
  thumbnail just like anything uploaded through GS's own file browser.

The front-end gallery uses that thumbnail for the visible grid image and
links to the full-size original for the lightbox, same pattern as Items
Manager's own frontend carousel.

## Data storage

- Gallery metadata and image order/captions: SQLite3 at
  `data/other/PageGallery.db` (tables `pg_galleries`, `pg_images`)
- Images themselves live directly in `data/uploads/` — no private plugin
  subfolder. Removing an image from a gallery only removes the database
  reference, not the underlying upload (it's part of the shared media
  library and may be used elsewhere or in another gallery).

## Notes

- GLightbox is vendored locally in `assets/glightbox/` (same approach as
  gs_Cuisine) — no CDN dependency.
- The admin drag-reorder uses native HTML5 drag-and-drop rather than
  SortableJS, so the admin panel itself has zero external JS dependencies.
- Slug renames are handled: if you change a page's URL/slug and save, the
  gallery's data follows it to the new slug via the `changedata-save` hook.
- CSS classes and element IDs all use a `pg-` prefix; internal AJAX/JS
  namespacing uses `pg_` / `PG` respectively.
