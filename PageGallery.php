<?php if (!defined('IN_GS')) { die('You cannot load this page directly.'); }

/*
Plugin Name: Page Gallery
Description: Adds a page-level image gallery, auto-detected per theme template. (SQLite3 required)
Version: 1.0
Author: risingisland
Author URI: https://ko-fi.com/ericmontgomery
*/

i18n_merge('PageGallery') || i18n_merge('PageGallery', 'en_US');

// ─── Constants ───────────────────────────────────────────────────────────────
define('PageGallery_DB_PATH',      GSDATAOTHERPATH . 'PageGallery.db');
define('PageGallery_SLUG',         basename(__FILE__, '.php'));
define('PageGallery_PLUGIN_DIR',   GSPLUGINPATH . PageGallery_SLUG . '/');
define('PageGallery_PLUGIN_URL',   $SITEURL . 'plugins/' . PageGallery_SLUG . '/');
// Images live in the standard GS uploads folder, same convention as Items Manager
define('PageGallery_UPLOAD_DIR',   GSDATAUPLOADPATH);
define('PageGallery_UPLOAD_URL',   $SITEURL . 'data/uploads/');
define('PageGallery_THUMB_DIR',    GSTHUMBNAILPATH);
define('PageGallery_THUMB_URL',    $SITEURL . 'data/thumbs/');

// ─── Bootstrap ───────────────────────────────────────────────────────────────

register_plugin(
	PageGallery_SLUG,
	'Page Gallery',
	'1.0',
	'risingisland',
	'https://ko-fi.com/ericmontgomery',
	i18n_r('PageGallery/lang_Description'),
	'theme',
	'PageGallery_admin_page'
);

add_action('theme-sidebar', 'createSideMenu', array(
	PageGallery_SLUG,
	i18n_r('PageGallery/lang_Menu_Title') . ' <svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="22" height="22" viewBox="0 0 48 48"><path d="M0 0h48v48H0z" fill="none"/><path fill="#E65100" d="M41 42H13c-2.2 0-4-1.8-4-4V18c0-2.2 1.8-4 4-4h28c2.2 0 4 1.8 4 4v20c0 2.2-1.8 4-4 4"/><path fill="#F57C00" d="M35 36H7c-2.2 0-4-1.8-4-4V12c0-2.2 1.8-4 4-4h28c2.2 0 4 1.8 4 4v20c0 2.2-1.8 4-4 4"/><circle cx="30" cy="16" r="3" fill="#FFF9C4"/><path fill="#942A09" d="M17 17.9L8 31h18z"/><path fill="#BF360C" d="M28 23.5L22 31h12z"/></svg>',
	'?id=' . PageGallery_SLUG
));

PageGallery_init_db();

// ── Early AJAX intercept ──────────────────────────────────────────────────────
// This plugin's admin UI is injected into GS's own edit.php (via the 'edit-content'
// hook), not just our own admin page - so we can't gate this the way FormBuilderPro
// does with a ?id= check. Any backend request carrying pg_action is handled here,
// before any HTML has been emitted.
if (!empty($_REQUEST['pg_action']) && !is_frontend()) {
	PageGallery_handle_ajax($_REQUEST['pg_action']);
	exit; // handle_ajax() always exit()s - safety net
}

add_action('edit-content', 'PageGallery_edit_panel');
add_action('changedata-save', 'PageGallery_handle_page_save');

// ─────────────────────────────────────────────────────────────────────────────
// DATABASE
// ─────────────────────────────────────────────────────────────────────────────

function PageGallery_db() {
	static $db = null;
	if ($db === null) {
		$db = new SQLite3(PageGallery_DB_PATH);
		$db->busyTimeout(5000);
		$db->exec('PRAGMA journal_mode=WAL;');
	}
	return $db;
}

function PageGallery_init_db() {
	$db = PageGallery_db();
	$db->exec("
		CREATE TABLE IF NOT EXISTS pg_galleries (
			id         INTEGER PRIMARY KEY AUTOINCREMENT,
			page_slug  TEXT    NOT NULL UNIQUE,
			enabled    INTEGER NOT NULL DEFAULT 1,
			settings   TEXT    NOT NULL DEFAULT '{}',
			created    INTEGER NOT NULL DEFAULT (strftime('%s','now')),
			updated    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
		);
		CREATE TABLE IF NOT EXISTS pg_images (
			id          INTEGER PRIMARY KEY AUTOINCREMENT,
			gallery_id  INTEGER NOT NULL,
			filename    TEXT    NOT NULL,
			caption     TEXT    NOT NULL DEFAULT '',
			sort_order  INTEGER NOT NULL DEFAULT 0,
			created     INTEGER NOT NULL DEFAULT (strftime('%s','now'))
		);
	");
}

function PageGallery_get_gallery($slug) {
	$db   = PageGallery_db();
	$stmt = $db->prepare("SELECT * FROM pg_galleries WHERE page_slug = :s LIMIT 1");
	$stmt->bindValue(':s', $slug, SQLITE3_TEXT);
	$res  = $stmt->execute();
	return $res ? ($res->fetchArray(SQLITE3_ASSOC) ?: null) : null;
}

function PageGallery_get_or_create_gallery($slug) {
	$g = PageGallery_get_gallery($slug);
	if ($g) return $g;
	$db   = PageGallery_db();
	$stmt = $db->prepare("INSERT INTO pg_galleries (page_slug) VALUES (:s)");
	$stmt->bindValue(':s', $slug, SQLITE3_TEXT);
	$stmt->execute();
	return PageGallery_get_gallery($slug);
}

function PageGallery_get_images($gallery_id) {
	$db   = PageGallery_db();
	$stmt = $db->prepare("SELECT * FROM pg_images WHERE gallery_id = :g ORDER BY sort_order ASC, id ASC");
	$stmt->bindValue(':g', (int)$gallery_id, SQLITE3_INTEGER);
	$res  = $stmt->execute();
	$out  = [];
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $out[] = $row; }
	return $out;
}

function PageGallery_all_galleries() {
	$db  = PageGallery_db();
	$res = $db->query("SELECT * FROM pg_galleries ORDER BY updated DESC");
	$out = [];
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
		$row['image_count'] = (int)$db->querySingle(
			"SELECT COUNT(*) FROM pg_images WHERE gallery_id = " . (int)$row['id']
		);
		$out[] = $row;
	}
	return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// UPLOADS / THUMBNAILS
// Same conventions as Items Manager: browse and upload straight into the
// standard GS uploads folder (with subfolder support), and generate the
// standard GS thumbnail via the core imagemanipulation.php helper so these
// uploads stay consistent with ones made through GS's own file browser.
// ─────────────────────────────────────────────────────────────────────────────

// Build a thumbnail URL for a filename (which may include a subfolder path).
// Falls back to the full-size image if no thumbnail exists yet.
function PageGallery_thumb_url($filename) {
	$parts    = explode('/', $filename);
	$basename = array_pop($parts);
	$subdir   = count($parts) ? implode('/', $parts) . '/' : '';
	$thumb_file = PageGallery_THUMB_DIR . $subdir . 'thumbnail.' . $basename;
	if (file_exists($thumb_file)) {
		return PageGallery_THUMB_URL . implode('/', array_map('rawurlencode', explode('/', $subdir . 'thumbnail.' . $basename)));
	}
	return PageGallery_UPLOAD_URL . implode('/', array_map('rawurlencode', explode('/', $filename)));
}

// Recursively list every image in the GS uploads folder, including subfolders.
function PageGallery_get_upload_images() {
	$images  = [];
	$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
	$base    = PageGallery_UPLOAD_DIR;

	$scan = function ($dir, $rel) use (&$scan, &$images, $allowed) {
		if (!is_dir($dir)) return;
		foreach (scandir($dir) as $file) {
			if ($file[0] === '.') continue;
			$full = $dir . $file;
			$path = $rel ? $rel . '/' . $file : $file;
			if (is_dir($full)) {
				$scan($full . '/', $path);
			} else {
				$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
				if (!in_array($ext, $allowed)) continue;
				$images[] = [
					'filename' => $path,
					'url'      => PageGallery_UPLOAD_URL . implode('/', array_map('rawurlencode', explode('/', $path))),
					'folder'   => $rel ?: '',
					'basename' => $file,
				];
			}
		}
	};

	$scan($base, '');
	usort($images, fn($a, $b) => strcmp($a['filename'], $b['filename']));
	return $images;
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMPLATE DETECTION
// Scans a theme template file for a literal get_page_gallery( call, so the
// admin gallery panel only appears where a theme developer has actually placed
// the tag - no manual per-template configuration required.
// ─────────────────────────────────────────────────────────────────────────────

function PageGallery_template_supports($template_file, $theme = null) {
	global $TEMPLATE;
	$theme = (string)($theme ?: $TEMPLATE);
	$template_file = (string)$template_file;
	if (!$theme || !$template_file) return false;

	static $cache = [];
	$key = $theme . '/' . $template_file;
	if (array_key_exists($key, $cache)) return $cache[$key];

	$path = GSTHEMESPATH . $theme . '/' . $template_file;
	if (!file_exists($path)) return $cache[$key] = false;

	$src = @file_get_contents($path);
	return $cache[$key] = ($src !== false && strpos($src, 'get_page_gallery') !== false);
}

// Returns ['template.php' => true, 'blog.php' => false, ...] for every template
// file in the given (or currently active) theme.
function PageGallery_theme_templates($theme = null) {
	global $TEMPLATE;
	$theme = (string)($theme ?: $TEMPLATE);
	$dir   = GSTHEMESPATH . $theme . '/';
	$out   = [];
	if (is_dir($dir)) {
		foreach (glob($dir . '*.php') as $f) {
			$base = basename($f);
			if ($base === 'functions.php' || substr($base, -8) === '.inc.php' || $base[0] === '.') continue;
			$out[$base] = PageGallery_template_supports($base, $theme);
		}
	}
	return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGE-SAVE HOOK  (slug rename migration only - gallery content saves via AJAX)
// ─────────────────────────────────────────────────────────────────────────────

function PageGallery_handle_page_save() {
	$old_slug = $_POST['existing-url'] ?? '';
	$new_slug = $_POST['post-id']      ?? '';
	if (!$old_slug || !$new_slug || $old_slug === $new_slug) return;

	$existing = PageGallery_get_gallery($old_slug);
	if (!$existing) return;

	// Don't clobber a gallery that may already exist under the new slug
	if (PageGallery_get_gallery($new_slug)) return;

	$db   = PageGallery_db();
	$stmt = $db->prepare("UPDATE pg_galleries SET page_slug = :n WHERE page_slug = :o");
	$stmt->bindValue(':n', $new_slug, SQLITE3_TEXT);
	$stmt->bindValue(':o', $old_slug, SQLITE3_TEXT);
	$stmt->execute();
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN PANEL  (hooked to 'edit-content', renders directly below the page
// content textarea in edit.php)
// ─────────────────────────────────────────────────────────────────────────────

function PageGallery_edit_panel() {
	global $url, $template, $TEMPLATE, $SITEURL;

	// $url / $template / $TEMPLATE come from GS's page and website XML as
	// SimpleXMLElement objects, not plain strings. String concatenation and
	// echo coerce them fine, but array-key access and SQLite3::bindValue() do
	// not - both throw a TypeError on a raw object. Cast up front and use the
	// local string copies for everything from here on.
	$page_url      = (string)$url;
	$tpl           = $template ? (string)$template : 'template.php';
	$theme_name    = (string)$TEMPLATE;
	$supports_map  = PageGallery_theme_templates($theme_name);
	$supports_now  = $supports_map[$tpl] ?? PageGallery_template_supports($tpl, $theme_name);

	// New, unsaved page - gallery data is keyed by page slug, which doesn't exist yet
	if (empty($page_url)) {
		if (!$supports_now) return; // nothing to show at all
		echo '<div class="pg-panel pg-panel-unsaved"><h4>' . i18n_r('PageGallery/lang_Panel_Title') . '</h4><p>'
			. i18n_r('PageGallery/lang_Save_First') . '</p></div>';
		return;
	}

	$gallery  = PageGallery_get_gallery($page_url);
	$has_data = $gallery ? true : false;

	// Nothing to show: template doesn't call the tag, and no gallery data exists yet
	if (!$supports_now && !$has_data) {
		// Still emit a hidden container + supports map so the panel can appear live
		// if the editor switches the page's template to one that supports it.
		PageGallery_print_supports_script($supports_map);
		echo '<div class="pg-panel" id="pg-panel" style="display:none;" data-slug="' . htmlspecialchars($page_url) . '"></div>';
		return;
	}

	$gallery  = $gallery ?: PageGallery_get_or_create_gallery($page_url);
	$images   = PageGallery_get_images($gallery['id']);
	$settings = json_decode($gallery['settings'], true) ?: [];
	$columns  = (int)($settings['columns'] ?? 3);
	$lightbox = $settings['lightbox'] ?? true;
	$captions = $settings['captions'] ?? true;

	PageGallery_print_supports_script($supports_map);
	?>
	<div class="pg-panel" id="pg-panel" data-slug="<?= htmlspecialchars($page_url) ?>" data-gallery-id="<?= (int)$gallery['id'] ?>">
		<h4 class="pg-title"><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="22" height="22" viewBox="0 0 48 48"><path d="M0 0h48v48H0z" fill="none"/><path fill="#E65100" d="M41 42H13c-2.2 0-4-1.8-4-4V18c0-2.2 1.8-4 4-4h28c2.2 0 4 1.8 4 4v20c0 2.2-1.8 4-4 4"/><path fill="#F57C00" d="M35 36H7c-2.2 0-4-1.8-4-4V12c0-2.2 1.8-4 4-4h28c2.2 0 4 1.8 4 4v20c0 2.2-1.8 4-4 4"/><circle cx="30" cy="16" r="3" fill="#FFF9C4"/><path fill="#942A09" d="M17 17.9L8 31h18z"/><path fill="#BF360C" d="M28 23.5L22 31h12z"/></svg> <?php i18n('PageGallery/lang_Panel_Title'); ?></h4>

		<?php if (!$supports_now): ?>
		<p class="pg-warning"><?php i18n('PageGallery/lang_Template_Mismatch'); ?></p>
		<?php endif ?>

		<div class="pg-toolbar">
			<label class="pg-inline">
				<input type="checkbox" id="pg-enabled" <?= $gallery['enabled'] ? 'checked' : '' ?>>
				<?php i18n('PageGallery/lang_Enabled'); ?>
			</label>
			<label class="pg-inline">
				<?php i18n('PageGallery/lang_Columns'); ?>
				<select id="pg-columns">
					<?php for ($i = 1; $i <= 6; $i++): ?>
					<option value="<?= $i ?>" <?= $i === $columns ? 'selected' : '' ?>><?= $i ?></option>
					<?php endfor ?>
				</select>
			</label>
			<label class="pg-inline">
				<input type="checkbox" id="pg-lightbox" <?= $lightbox ? 'checked' : '' ?>>
				<?php i18n('PageGallery/lang_Lightbox'); ?>
			</label>
			<label class="pg-inline">
				<input type="checkbox" id="pg-captions" <?= $captions ? 'checked' : '' ?>>
				<?php i18n('PageGallery/lang_ShowCaptions'); ?>
			</label>
			<button type="button" class="pg-btn" id="pg-add-images"><?php i18n('PageGallery/lang_AddImages'); ?></button>
		</div>

		<ul class="pg-grid" id="pg-grid">
			<?php foreach ($images as $img): ?>
			<li class="pg-thumb" draggable="true" data-id="<?= (int)$img['id'] ?>">
				<img src="<?= htmlspecialchars(PageGallery_UPLOAD_URL . implode('/', array_map('rawurlencode', explode('/', $img['filename'])))) ?>" alt="">
				<input type="text" class="pg-caption-input" placeholder="<?php echo strip_tags(i18n_r('PageGallery/lang_CaptionPlaceholder')); ?>" value="<?= htmlspecialchars($img['caption']) ?>">
				<button type="button" class="pg-remove" title="<?php echo strip_tags(i18n_r('PageGallery/lang_Remove')); ?>">&times;</button>
			</li>
			<?php endforeach ?>
		</ul>
		<p class="pg-empty" id="pg-empty" style="<?= $images ? 'display:none;' : '' ?>"><?php i18n('PageGallery/lang_NoImages'); ?></p>
	</div>

	<!-- Picker modal -->
	<div class="pg-modal" id="pg-modal" style="display:none;">
		<div class="pg-modal-inner">
			<div class="pg-modal-head">
				<h4><?php i18n('PageGallery/lang_PickImages'); ?></h4>
				<button type="button" class="pg-modal-close" id="pg-modal-close">&times;</button>
			</div>

			<div class="pg-fb-tabs">
				<button type="button" class="pg-fb-tab active" data-tab="browse"><?php i18n('PageGallery/lang_BrowseUploads'); ?></button>
				<button type="button" class="pg-fb-tab" data-tab="upload"><?php i18n('PageGallery/lang_UploadNew'); ?></button>
			</div>

			<div class="pg-fb-pane" id="pg-fb-browse">
				<div class="pg-fb-crumb" id="pg-fb-crumb"></div>
				<div class="pg-fb-search-row">
					<input type="text" id="pg-fb-search" placeholder="<?php echo strip_tags(i18n_r('PageGallery/lang_FilterImages')); ?>">
				</div>
				<div class="pg-fb-grid" id="pg-fb-grid"><div class="pg-loading"><?php i18n('PageGallery/lang_Loading'); ?></div></div>
			</div>

			<div class="pg-fb-pane" id="pg-fb-upload" style="display:none;">
				<label class="pg-fb-upload-area" for="pg-upload-input">
					<span><?php i18n('PageGallery/lang_ChooseFiles'); ?></span>
				</label>
				<input type="file" id="pg-upload-input" accept="image/png,image/jpeg,image/gif,image/webp" multiple style="display:none;">
				<div class="pg-upload-status" id="pg-upload-status"></div>
			</div>
		</div>
	</div>

	<style>
	.pg-panel{margin:20px 0;padding:15px;border:1px solid #dcdfe3;border-radius:6px;background:#fbfbfc}
	.pg-panel .pg-title{margin:0 0 10px;font-size:1rem}
	.pg-panel .pg-warning{background:#fff8e1;border:1px solid #ffe58a;color:#8a6100;padding:.5rem .75rem;border-radius:4px;font-size:.85rem;margin-bottom:10px}
	.pg-panel .pg-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-bottom:12px}
	.pg-panel .pg-inline{display:inline-flex;align-items:center;gap:5px;font-size:.875rem;font-weight:400!important}
	.pg-panel .pg-btn{background:#2764e7;color:#fff;border:none;padding:.4rem .9rem;border-radius:4px;cursor:pointer;font-size:.85rem}
	.pg-panel .pg-btn:hover{background:#0094f0}
	.pg-panel .pg-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}
	.pg-panel .pg-thumb{position:relative;border:1px solid #d0d5dd;border-radius:5px;background:#fff;padding:6px;cursor:grab}
	.pg-panel .pg-thumb.pg-dragging{opacity:.4}
	.pg-panel .pg-thumb img{width:100%;height:90px;object-fit:cover;border-radius:3px;display:block;margin-bottom:5px}
	.pg-panel .pg-caption-input{width:100%;box-sizing:border-box;font-size:.75rem;padding:.25rem .35rem;border:1px solid #d0d5dd;border-radius:3px}
	.pg-panel .pg-remove{position:absolute;top:2px;right:2px;width:20px;height:20px;line-height:18px;text-align:center;border-radius:50%;border:none;background:rgba(0,0,0,.55);color:#fff;cursor:pointer;font-size:14px;padding:0}
	.pg-panel .pg-empty{color:#6b7280;font-size:.875rem;margin:0}

	.pg-modal{position:fixed;inset:0;background:rgba(20,20,30,.55);z-index:99999;display:flex;align-items:flex-start;justify-content:center;padding:3rem 1rem 1rem;}
	.pg-modal-inner{background:#fff;width:min(660px,92vw);max-height:calc(100vh - 4rem);border-radius:8px;overflow-y:auto;display:flex;flex-direction:column}
	.pg-modal-head{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid #f0f0f0;position:sticky;top:0;background:#fff;z-index:1}
	.pg-modal-head h4{margin:0;font-size:.9375rem}
	.pg-modal-close{border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#6b7280}

	.pg-fb-tabs{display:flex;gap:0;border-bottom:1px solid #e5e7eb;padding:0 1.25rem;margin-top:.25rem}
	.pg-fb-tab{display:inline-flex;align-items:center;gap:.4rem;background:none;border:none;border-bottom:2px solid transparent;padding:.6rem 1rem;font-size:.875rem;font-weight:600;color:#6b7280;cursor:pointer;margin-bottom:-1px}
	.pg-fb-tab.active{color:#2764e7;border-bottom-color:#2764e7}
	.pg-fb-tab:hover:not(.active){color:#374151}
	.pg-fb-pane{padding:1.25rem}

	.pg-fb-crumb{font-size:.8125rem;color:#6b7280;margin-bottom:.6rem;display:flex;align-items:center;flex-wrap:wrap;gap:.2rem;min-height:1.4rem}
	.pg-fb-crumb-item{cursor:pointer;color:#2764e7;font-weight:600;padding:.1rem .2rem;border-radius:3px}
	.pg-fb-crumb-item:hover{background:#f0f4ff}
	.pg-fb-crumb-item.active{color:#374151;cursor:default;font-weight:400}
	.pg-fb-crumb-item.active:hover{background:none}

	.pg-fb-search-row{margin-bottom:.75rem}
	.pg-fb-search-row input{width:100%;box-sizing:border-box;padding:.45rem .75rem;border:1px solid #d0d5dd;border-radius:6px;font-size:.875rem;font-family:inherit}
	.pg-fb-search-row input:focus{outline:none;border-color:#2764e7;box-shadow:0 0 0 3px rgba(39,100,231,.1)}

	.pg-fb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:.6rem;max-height:360px;overflow-y:auto;padding:.25rem}
	.pg-fb-img{cursor:pointer;border:2px solid transparent;border-radius:7px;overflow:hidden;background:#f4f6f9;display:flex;flex-direction:column}
	.pg-fb-img:hover{border-color:#2764e7;box-shadow:0 2px 8px rgba(39,100,231,.2)}
	.pg-fb-img img{width:100%;aspect-ratio:1;object-fit:cover;display:block}
	.pg-fb-name{font-size:.67rem;color:#6b7280;padding:.2rem .3rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:center;background:#f9fafb}
	.pg-fb-empty{text-align:center;color:#9ca3af;padding:2rem;font-size:.875rem;grid-column:1/-1}

	.pg-fb-folder{cursor:pointer;border:2px solid #e5e7eb;border-radius:7px;background:#fffbeb;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:.6rem .4rem;gap:.3rem;position:relative}
	.pg-fb-folder:hover{border-color:#f59e0b;background:#fef3c7}
	.pg-fb-folder .pg-fb-name{font-size:.72rem;color:#92400e;font-weight:600;text-align:center;background:none}
	.pg-fb-folder-count{position:absolute;top:4px;right:5px;font-size:.65rem;color:#92400e;font-weight:700;background:#fde68a;border-radius:3px;padding:0 .25rem}

	.pg-fb-upload-area{display:flex;align-items:center;justify-content:center;border:2px dashed #d0d5dd;border-radius:9px;padding:2.5rem 1.5rem;text-align:center;background:#fafafa;cursor:pointer;font-size:.875rem;color:#6b7280;font-weight:600}
	.pg-fb-upload-area:hover{border-color:#2764e7;background:#f8faff;color:#2764e7}
	.pg-upload-status{font-size:.8rem;color:#6b7280;margin-top:.75rem}
	.pg-loading{grid-column:1/-1;text-align:center;padding:2.5rem;color:#9ca3af;font-size:.875rem}
	</style>

	<script>
	(function(){
		var PG = {
			slug: <?= json_encode($page_url) ?>,
			ajaxUrl: window.location.pathname,
			panel: document.getElementById('pg-panel'),
		};
		if (!PG.panel) return;

		function tgPost(action, data) {
			var body = new URLSearchParams(Object.assign({pg_action: action, slug: PG.slug}, data || {}));
			return fetch(PG.ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString()})
				.then(function(r){ return r.json(); });
		}

		var grid  = document.getElementById('pg-grid');
		var empty = document.getElementById('pg-empty');

		function toggleEmpty() {
			if (!grid || !empty) return;
			empty.style.display = grid.children.length ? 'none' : '';
		}
		toggleEmpty();

		// ── settings toggles ──────────────────────────────────────────────
		['pg-enabled','pg-columns','pg-lightbox','pg-captions'].forEach(function(id){
			var el = document.getElementById(id);
			if (!el) return;
			el.addEventListener('change', function(){
				tgPost('save_settings', {
					enabled:  document.getElementById('pg-enabled').checked ? 1 : 0,
					columns:  document.getElementById('pg-columns').value,
					lightbox: document.getElementById('pg-lightbox').checked ? 1 : 0,
					captions: document.getElementById('pg-captions').checked ? 1 : 0
				});
			});
		});

		// ── caption editing (debounced-ish via blur) ─────────────────────
		if (grid) {
			grid.addEventListener('blur', function(e){
				if (!e.target.classList || !e.target.classList.contains('pg-caption-input')) return;
				var li = e.target.closest('.pg-thumb');
				if (!li) return;
				tgPost('update_caption', {image_id: li.dataset.id, caption: e.target.value});
			}, true);

			grid.addEventListener('click', function(e){
				if (!e.target.classList || !e.target.classList.contains('pg-remove')) return;
				var li = e.target.closest('.pg-thumb');
				if (!li) return;
				if (!confirm(<?= json_encode(i18n_r('PageGallery/lang_ConfirmRemove')) ?>)) return;
				tgPost('remove_image', {image_id: li.dataset.id}).then(function(){
					li.remove();
					toggleEmpty();
				});
			});

			// ── drag reorder ──────────────────────────────────────────────
			var dragEl = null;
			grid.addEventListener('dragstart', function(e){
				var li = e.target.closest('.pg-thumb');
				if (!li) return;
				dragEl = li;
				li.classList.add('pg-dragging');
			});
			grid.addEventListener('dragend', function(e){
				var li = e.target.closest('.pg-thumb');
				if (li) li.classList.remove('pg-dragging');
				if (!dragEl) return;
				var order = Array.prototype.map.call(grid.children, function(el){ return el.dataset.id; });
				tgPost('reorder', {order: JSON.stringify(order)});
				dragEl = null;
			});
			grid.addEventListener('dragover', function(e){
				e.preventDefault();
				var after = Array.prototype.find.call(grid.children, function(el){
					var box = el.getBoundingClientRect();
					return e.clientY < box.top + box.height / 2 && e.clientX < box.left + box.width;
				});
				if (!dragEl) return;
				if (after) grid.insertBefore(dragEl, after);
				else grid.appendChild(dragEl);
			});
		}

		// ── picker modal ──────────────────────────────────────────────────
		var modal    = document.getElementById('pg-modal');
		var closeBtn = document.getElementById('pg-modal-close');
		var addBtn   = document.getElementById('pg-add-images');
		var fbGrid   = document.getElementById('pg-fb-grid');
		var fbCrumb  = document.getElementById('pg-fb-crumb');
		var fbSearch = document.getElementById('pg-fb-search');
		var uploadIn = document.getElementById('pg-upload-input');
		var uploadSt = document.getElementById('pg-upload-status');

		var fbImages = [];
		var fbFolder = '';

		function appendThumb(img) {
			if (!grid) return;
			var li = document.createElement('li');
			li.className = 'pg-thumb';
			li.draggable = true;
			li.dataset.id = img.id;
			li.innerHTML = '<img src="' + img.url + '" alt="">' +
				'<input type="text" class="pg-caption-input" placeholder="' + <?= json_encode(strip_tags(i18n_r('PageGallery/lang_CaptionPlaceholder'))) ?> + '" value="">' +
				'<button type="button" class="pg-remove" title="' + <?= json_encode(strip_tags(i18n_r('PageGallery/lang_Remove'))) ?> + '">&times;</button>';
			grid.appendChild(li);
			toggleEmpty();
		}

		function fbEsc(s) {
			var d = document.createElement('div');
			d.textContent = s == null ? '' : s;
			return d.innerHTML;
		}

		function fbLoad(force) {
			if (fbImages.length && !force) { fbRender(); return; }
			fbGrid.innerHTML = '<div class="pg-loading">' + <?= json_encode(i18n_r('PageGallery/lang_Loading')) ?> + '</div>';
			fetch(PG.ajaxUrl + '?pg_action=get_upload_images')
				.then(function(r){ return r.json(); })
				.then(function(res){
					fbImages = res.images || [];
					fbRender();
				});
		}

		function fbRender() {
			var filter = (fbSearch ? fbSearch.value : '').toLowerCase().trim();
			var images, folders;

			if (filter) {
				images = fbImages.filter(function(img){ return img.filename.toLowerCase().indexOf(filter) !== -1; });
				folders = [];
			} else {
				var seen = {};
				folders = [];
				images = [];
				fbImages.forEach(function(img){
					if (fbFolder && img.filename.indexOf(fbFolder + '/') !== 0) return;
					var rel = fbFolder ? img.filename.slice(fbFolder.length + 1) : img.filename;
					var parts = rel.split('/');
					if (parts.length > 1) {
						var sub = (fbFolder ? fbFolder + '/' : '') + parts[0];
						if (!seen[sub]) { seen[sub] = true; folders.push(parts[0]); }
					} else {
						images.push(img);
					}
				});
			}

			if (fbCrumb) {
				var html = '<svg style="vertical-align:middle" width="16" height="16" viewBox="0 0 24 24" fill="#f59e0b"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg> <span class="pg-fb-crumb-item' + (fbFolder === '' ? ' active' : '') + '" data-nav="">uploads</span>';
				if (fbFolder) {
					var parts = fbFolder.split('/'), built = '';
					parts.forEach(function(p, i){
						built = built ? built + '/' + p : p;
						html += ' / <span class="pg-fb-crumb-item' + (i === parts.length - 1 ? ' active' : '') + '" data-nav="' + fbEsc(built) + '">' + fbEsc(p) + '</span>';
					});
				}
				fbCrumb.innerHTML = html;
			}

			if (!folders.length && !images.length) {
				fbGrid.innerHTML = '<p class="pg-fb-empty">' + <?= json_encode(i18n_r('PageGallery/lang_NoImages')) ?> + '</p>';
				return;
			}

			var out = '';
			folders.sort().forEach(function(f){
				var fullPath = (fbFolder ? fbFolder + '/' : '') + f;
				var count = fbImages.filter(function(img){ return img.filename.indexOf(fullPath + '/') === 0; }).length;
				out += '<div class="pg-fb-folder" data-nav="' + fbEsc(fullPath) + '">'
					+ '<svg width="28" height="28" viewBox="0 0 24 24" fill="#f59e0b"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>'
					+ '<span class="pg-fb-name">' + fbEsc(f) + '</span>'
					+ '<span class="pg-fb-folder-count">' + count + '</span>'
					+ '</div>';
			});
			images.forEach(function(img){
				out += '<div class="pg-fb-img" data-pick="' + fbEsc(img.filename) + '" title="' + fbEsc(img.filename) + '">'
					+ '<img src="' + fbEsc(img.url) + '" alt="' + fbEsc(img.basename) + '" loading="lazy">'
					+ '<span class="pg-fb-name">' + fbEsc(img.basename) + '</span>'
					+ '</div>';
			});
			fbGrid.innerHTML = out;
		}

		function fbSelect(filename) {
			tgPost('add_image', {filename: filename}).then(function(res){
				if (res.ok) {
					appendThumb(res.image);
					if (modal) modal.style.display = 'none';
				}
			});
		}

		if (addBtn) addBtn.addEventListener('click', function(){
			fbFolder = '';
			if (fbSearch) fbSearch.value = '';
			document.querySelectorAll('.pg-fb-tab').forEach(function(b, i){ b.classList.toggle('active', i === 0); });
			document.getElementById('pg-fb-browse').style.display = '';
			document.getElementById('pg-fb-upload').style.display = 'none';
			if (modal) modal.style.display = 'flex';
			fbLoad();
		});
		if (closeBtn) closeBtn.addEventListener('click', function(){
			if (modal) modal.style.display = 'none';
		});
		if (modal) modal.addEventListener('click', function(e){
			if (e.target === modal) modal.style.display = 'none';
		});

		document.querySelectorAll('.pg-fb-tab').forEach(function(btn){
			btn.addEventListener('click', function(){
				document.querySelectorAll('.pg-fb-tab').forEach(function(b){ b.classList.remove('active'); });
				btn.classList.add('active');
				var tab = btn.dataset.tab;
				document.getElementById('pg-fb-browse').style.display = tab === 'browse' ? '' : 'none';
				document.getElementById('pg-fb-upload').style.display = tab === 'upload' ? '' : 'none';
			});
		});

		if (fbGrid) fbGrid.addEventListener('click', function(e){
			var folderEl = e.target.closest('[data-nav]');
			if (folderEl) { fbFolder = folderEl.dataset.nav; if (fbSearch) fbSearch.value = ''; fbRender(); return; }
			var imgEl = e.target.closest('[data-pick]');
			if (imgEl) fbSelect(imgEl.dataset.pick);
		});
		if (fbCrumb) fbCrumb.addEventListener('click', function(e){
			var el = e.target.closest('[data-nav]');
			if (!el) return;
			fbFolder = el.dataset.nav;
			if (fbSearch) fbSearch.value = '';
			fbRender();
		});
		if (fbSearch) fbSearch.addEventListener('input', fbRender);

		if (uploadIn) uploadIn.addEventListener('change', function(){
			var files = Array.prototype.slice.call(uploadIn.files || []);
			if (!files.length) return;
			var done = 0;
			uploadSt.textContent = <?= json_encode(i18n_r('PageGallery/lang_Uploading')) ?> + ' 0 / ' + files.length + '\u2026';
			files.forEach(function(file){
				var fd = new FormData();
				fd.append('pg_action', 'upload_image');
				fd.append('slug', PG.slug);
				fd.append('image', file);
				fetch(PG.ajaxUrl, {method:'POST', body: fd})
					.then(function(r){ return r.json(); })
					.then(function(res){
						done++;
						uploadSt.textContent = <?= json_encode(i18n_r('PageGallery/lang_Uploading')) ?> + ' ' + done + ' / ' + files.length + '\u2026';
						if (res.ok) appendThumb(res.image);
						if (done === files.length) {
							uploadSt.textContent = '';
							uploadIn.value = '';
							fbImages = []; // invalidate cache so Browse tab reflects the new upload
						}
					});
			});
		});
	})();
	</script>
	<?php
}

// Emits a small JS map of {template_file: supports_gallery} plus a listener on
// the core #post-template dropdown so the panel appears/disappears live when
// the editor switches templates, without a page reload.
function PageGallery_print_supports_script($supports_map) {
	static $printed = false;
	if ($printed) return;
	$printed = true;
	?>
	<script>
	(function(){
		var supports = <?= json_encode($supports_map) ?>;
		var select   = document.getElementById('post-template');
		var panel    = document.getElementById('pg-panel');
		if (!select || !panel) return;
		select.addEventListener('change', function(){
			var ok = !!supports[select.value];
			var hasContent = panel.querySelector('.pg-grid') || panel.querySelector('.pg-toolbar');
			panel.style.display = (ok || hasContent) ? '' : 'none';
			var warn = panel.querySelector('.pg-warning');
			if (warn) warn.style.display = ok ? 'none' : '';
		});
	})();
	</script>
	<?php
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX ENDPOINTS  (only reached via the early pg_action intercept above)
// ─────────────────────────────────────────────────────────────────────────────

function PageGallery_handle_ajax($action) {
	header('Content-Type: application/json');

	switch ($action) {

		case 'save_settings': {
			$slug = $_POST['slug'] ?? '';
			if (!$slug) { echo json_encode(['ok' => false]); exit; }
			$gallery  = PageGallery_get_or_create_gallery($slug);
			$settings = [
				'columns'  => max(1, min(6, (int)($_POST['columns'] ?? 3))),
				'lightbox' => !empty($_POST['lightbox']),
				'captions' => !empty($_POST['captions']),
			];
			$db   = PageGallery_db();
			$stmt = $db->prepare("UPDATE pg_galleries SET enabled=:e, settings=:s, updated=strftime('%s','now') WHERE id=:id");
			$stmt->bindValue(':e',  !empty($_POST['enabled']) ? 1 : 0, SQLITE3_INTEGER);
			$stmt->bindValue(':s',  json_encode($settings), SQLITE3_TEXT);
			$stmt->bindValue(':id', $gallery['id'], SQLITE3_INTEGER);
			$stmt->execute();
			echo json_encode(['ok' => true]);
			exit;
		}

		case 'add_image': {
			// Add an existing GS upload image to the gallery (supports subfolders)
			$slug     = $_POST['slug'] ?? '';
			$filename = str_replace('\\', '/', $_POST['filename'] ?? '');
			$filename = preg_replace('/\.\.+/', '', $filename); // prevent directory traversal
			$filename = trim($filename, '/');
			if (!$slug || !$filename) { echo json_encode(['ok' => false]); exit; }

			$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
			if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
				echo json_encode(['ok' => false, 'msg' => 'Unsupported file type']); exit;
			}
			if (!file_exists(PageGallery_UPLOAD_DIR . $filename)) {
				echo json_encode(['ok' => false, 'msg' => 'File not found']); exit;
			}

			$gallery = PageGallery_get_or_create_gallery($slug);
			$db      = PageGallery_db();
			$max     = (int)$db->querySingle("SELECT COALESCE(MAX(sort_order),-1) FROM pg_images WHERE gallery_id=" . (int)$gallery['id']);

			$stmt = $db->prepare("INSERT INTO pg_images (gallery_id, filename, sort_order) VALUES (:g, :f, :o)");
			$stmt->bindValue(':g', $gallery['id'], SQLITE3_INTEGER);
			$stmt->bindValue(':f', $filename, SQLITE3_TEXT);
			$stmt->bindValue(':o', $max + 1, SQLITE3_INTEGER);
			$stmt->execute();

			echo json_encode(['ok' => true, 'image' => [
				'id' => $db->lastInsertRowID(), 'filename' => $filename,
				'url' => PageGallery_UPLOAD_URL . implode('/', array_map('rawurlencode', explode('/', $filename))),
			]]);
			exit;
		}

		case 'upload_image': {
			// Upload a new image directly into the GS uploads folder (root)
			$slug = $_POST['slug'] ?? '';
			if (!$slug) { echo json_encode(['ok' => false]); exit; }
			if (empty($_FILES['image']['name'])) { echo json_encode(['ok' => false, 'msg' => 'No file']); exit; }

			$file         = $_FILES['image'];
			$allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
			$mime         = mime_content_type($file['tmp_name']);
			if (!in_array($mime, $allowed_mime)) { echo json_encode(['ok' => false, 'msg' => 'Unsupported file type']); exit; }
			if ($file['size'] > 8 * 1024 * 1024) { echo json_encode(['ok' => false, 'msg' => 'File exceeds 8 MB']); exit; }

			$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
			$safe_ext = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? $ext : 'jpg';
			// Sanitise original filename: lowercase, replace spaces/special chars with hyphens
			$base = strtolower(pathinfo($file['name'], PATHINFO_FILENAME));
			$base = preg_replace('/[^a-z0-9._-]/', '-', $base);
			$base = preg_replace('/-+/', '-', trim($base, '-'));
			$base = $base ?: 'image';
			$filename = $base . '.' . $safe_ext;
			// Handle filename collisions - append -1, -2, etc. if the file already exists
			if (file_exists(PageGallery_UPLOAD_DIR . $filename)) {
				$i = 1;
				while (file_exists(PageGallery_UPLOAD_DIR . $base . '-' . $i . '.' . $safe_ext)) { $i++; }
				$filename = $base . '-' . $i . '.' . $safe_ext;
			}

			$dest = PageGallery_UPLOAD_DIR . $filename;
			if (!move_uploaded_file($file['tmp_name'], $dest)) {
				echo json_encode(['ok' => false, 'msg' => 'Upload failed']); exit;
			}

			// Generate the standard GS thumbnail the same way GS's own upload.php
			// does, so this file behaves identically to one uploaded through the
			// core file browser (e.g. shows a thumbnail there too).
			$imgmanip = GSADMININCPATH . 'imagemanipulation.php';
			if (file_exists($imgmanip)) {
				require_once($imgmanip);
				if (function_exists('genStdThumb')) genStdThumb('', $filename);
			}

			$gallery = PageGallery_get_or_create_gallery($slug);
			$db      = PageGallery_db();
			$max     = (int)$db->querySingle("SELECT COALESCE(MAX(sort_order),-1) FROM pg_images WHERE gallery_id=" . (int)$gallery['id']);

			$stmt = $db->prepare("INSERT INTO pg_images (gallery_id, filename, sort_order) VALUES (:g, :f, :o)");
			$stmt->bindValue(':g', $gallery['id'], SQLITE3_INTEGER);
			$stmt->bindValue(':f', $filename, SQLITE3_TEXT);
			$stmt->bindValue(':o', $max + 1, SQLITE3_INTEGER);
			$stmt->execute();

			echo json_encode(['ok' => true, 'image' => [
				'id' => $db->lastInsertRowID(), 'filename' => $filename,
				'url' => PageGallery_UPLOAD_URL . rawurlencode($filename),
			]]);
			exit;
		}

		case 'remove_image': {
			$id = (int)($_POST['image_id'] ?? 0);
			if (!$id) { echo json_encode(['ok' => false]); exit; }
			$db   = PageGallery_db();
			$stmt = $db->prepare("DELETE FROM pg_images WHERE id=:id");
			$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
			$stmt->execute();
			// Note: the underlying upload is left in place (it's part of the shared
			// media library and may be reused elsewhere or re-added later).
			echo json_encode(['ok' => true]);
			exit;
		}

		case 'update_caption': {
			$id      = (int)($_POST['image_id'] ?? 0);
			$caption = trim(strip_tags($_POST['caption'] ?? ''));
			if (!$id) { echo json_encode(['ok' => false]); exit; }
			$db   = PageGallery_db();
			$stmt = $db->prepare("UPDATE pg_images SET caption=:c WHERE id=:id");
			$stmt->bindValue(':c', $caption, SQLITE3_TEXT);
			$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
			$stmt->execute();
			echo json_encode(['ok' => true]);
			exit;
		}

		case 'reorder': {
			$slug  = $_POST['slug']  ?? '';
			$order = json_decode($_POST['order'] ?? '[]', true);
			if (!$slug || !is_array($order)) { echo json_encode(['ok' => false]); exit; }
			$gallery = PageGallery_get_gallery($slug);
			if (!$gallery) { echo json_encode(['ok' => false]); exit; }
			$db = PageGallery_db();
			foreach ($order as $i => $image_id) {
				$stmt = $db->prepare("UPDATE pg_images SET sort_order=:o WHERE id=:id AND gallery_id=:g");
				$stmt->bindValue(':o', (int)$i, SQLITE3_INTEGER);
				$stmt->bindValue(':id', (int)$image_id, SQLITE3_INTEGER);
				$stmt->bindValue(':g', $gallery['id'], SQLITE3_INTEGER);
				$stmt->execute();
			}
			echo json_encode(['ok' => true]);
			exit;
		}

		case 'get_upload_images': {
			echo json_encode(['ok' => true, 'images' => PageGallery_get_upload_images()]);
			exit;
		}

		case 'delete_page_gallery': {
			// Used by the admin overview list to fully remove a gallery + its image rows
			$slug = $_POST['slug'] ?? '';
			if (!$slug) { echo json_encode(['ok' => false]); exit; }
			$gallery = PageGallery_get_gallery($slug);
			if (!$gallery) { echo json_encode(['ok' => false]); exit; }
			$db = PageGallery_db();
			$stmt = $db->prepare("DELETE FROM pg_images WHERE gallery_id=:g");
			$stmt->bindValue(':g', $gallery['id'], SQLITE3_INTEGER);
			$stmt->execute();
			$stmt2 = $db->prepare("DELETE FROM pg_galleries WHERE id=:id");
			$stmt2->bindValue(':id', $gallery['id'], SQLITE3_INTEGER);
			$stmt2->execute();
			echo json_encode(['ok' => true]);
			exit;
		}

		default:
			echo json_encode(['ok' => false, 'msg' => 'Unknown action']);
			exit;
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// FRONT-END TEMPLATE TAG
// Usage in a theme file: call get_page_gallery() from any template.php
// Optional per-call overrides: get_page_gallery(['columns' => 4, 'lightbox' => false])
// ─────────────────────────────────────────────────────────────────────────────

function get_page_gallery($args = []) {
	global $url, $SITEURL;
	$page_url = (string)$url; // $url is a SimpleXMLElement in GS front-end context
	if (empty($page_url)) return;

	$gallery = PageGallery_get_gallery($page_url);
	if (!$gallery || !$gallery['enabled']) return;

	$images = PageGallery_get_images($gallery['id']);
	if (empty($images)) return;

	$settings = json_decode($gallery['settings'], true) ?: [];
	$defaults = ['columns' => 3, 'lightbox' => true, 'captions' => true, 'gap' => '10px'];
	$opts     = array_merge($defaults, $settings, $args);

	$gid = 'pg-gallery-' . (int)$gallery['id'];

	PageGallery_print_frontend_css();
	if (!empty($opts['lightbox'])) PageGallery_enqueue_lightbox();

	echo '<div class="pg-fgallery" id="' . $gid . '" style="--pg-cols:' . (int)$opts['columns'] . ';--pg-gap:' . htmlspecialchars($opts['gap']) . ';">';
	foreach ($images as $img) {
		$thumb = PageGallery_thumb_url($img['filename']);
		$cap = htmlspecialchars($img['caption']);
		if (!empty($opts['lightbox'])) {
			$full = PageGallery_UPLOAD_URL . implode('/', array_map('rawurlencode', explode('/', $img['filename'])));
			$glb  = ' data-glightbox="' . ($cap !== '' ? 'title: ' . $cap : '') . '" class="pg-item glightbox-' . $gid . '"';
			echo '<a href="' . $full . '"' . $glb . '>';
			echo '<img src="' . $thumb . '" alt="' . $cap . '" loading="lazy">';
			if (!empty($opts['captions']) && $cap !== '') echo '<span class="pg-fcaption">' . $cap . '</span>';
			echo '</a>';
		} else {
			// No lightbox: plain, non-clickable image - no link to the raw file
			echo '<div class="pg-item">';
			echo '<img src="' . $thumb . '" alt="' . $cap . '" loading="lazy">';
			if (!empty($opts['captions']) && $cap !== '') echo '<span class="pg-fcaption">' . $cap . '</span>';
			echo '</div>';
		}
	}
	echo '</div>';

	if (!empty($opts['lightbox'])) {
		add_action('theme-footer', function() use ($gid) {
			echo '<script>window.addEventListener("load",function(){if(window.GLightbox){GLightbox({selector:"#' . $gid . ' [data-glightbox]"});}});</script>';
		});
	}
}

function PageGallery_print_frontend_css() {
	static $printed = false;
	if ($printed) return;
	$printed = true;
	echo '<style>
.pg-fgallery{display:grid;grid-template-columns:repeat(var(--pg-cols,3),1fr);gap:var(--pg-gap,10px)}
.pg-fgallery .pg-item{display:block;position:relative;border-radius:6px;overflow:hidden;border: 1px solid #ccc !important;}
.pg-fgallery .pg-item img{width:100%;height:100%;aspect-ratio:1.5/1;object-fit:cover;display:block}
.pg-fgallery .pg-fcaption{position:absolute;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);color:#fff;font-size:.8rem;padding:.35rem .5rem}
@media (max-width:640px){.pg-fgallery{grid-template-columns:repeat(min(2,var(--pg-cols,3)),1fr)}}
</style>';
}

function PageGallery_enqueue_lightbox() {
	static $printed = false;
	if ($printed) return;
	$printed = true;
	echo '<link rel="stylesheet" href="' . PageGallery_PLUGIN_URL . 'assets/glightbox/glightbox.min.css">';
	add_action('theme-footer', function() {
		echo '<script src="' . PageGallery_PLUGIN_URL . 'assets/glightbox/glightbox.min.js"></script>';
	});
}

// ─────────────────────────────────────────────────────────────────────────────
// ADMIN OVERVIEW PAGE  (register_plugin admin callback)
// ─────────────────────────────────────────────────────────────────────────────

function PageGallery_admin_page() {
	$galleries = PageGallery_all_galleries();
	?>
	<style>a{text-decoration:none!important}</style>
	<h3><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="22" height="22" viewBox="0 0 48 48"><path d="M0 0h48v48H0z" fill="none"/><path fill="#E65100" d="M41 42H13c-2.2 0-4-1.8-4-4V18c0-2.2 1.8-4 4-4h28c2.2 0 4 1.8 4 4v20c0 2.2-1.8 4-4 4"/><path fill="#F57C00" d="M35 36H7c-2.2 0-4-1.8-4-4V12c0-2.2 1.8-4 4-4h28c2.2 0 4 1.8 4 4v20c0 2.2-1.8 4-4 4"/><circle cx="30" cy="16" r="3" fill="#FFF9C4"/><path fill="#942A09" d="M17 17.9L8 31h18z"/><path fill="#BF360C" d="M28 23.5L22 31h12z"/></svg> <?php i18n('PageGallery/lang_Overview_Title'); ?></h3>
	<p><?php i18n('PageGallery/lang_Overview_Help'); ?></p>

	<?php if (empty($galleries)): ?>
	<p><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="1.3em" height="1.3em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M9 16c.85-.63 1.885-1 3-1s2.15.37 3 1m-5.5-5.5V10m5 .5V10"/><path d="M21 12a9 9 0 1 1-18 0a9 9 0 0 1 18 0"/></g></svg> <em><?php i18n('PageGallery/lang_NoGalleries'); ?></em></p>
	<?php else: ?>
	<table class="widetable">
		<thead>
			<tr>
				<th><?php i18n('PageGallery/lang_Th_Page'); ?></th>
				<th><?php i18n('PageGallery/lang_Th_Images'); ?></th>
				<th><?php i18n('PageGallery/lang_Th_Enabled'); ?></th>
				<th><?php i18n('PageGallery/lang_Th_Updated'); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($galleries as $g): ?>
			<tr>
				<td><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="1em" height="1em" viewBox="0 0 16 16"><path d="M0 0h16v16H0z" fill="none"/><path fill="currentColor" fill-rule="evenodd" d="M14 4.57a.5.5 0 0 0-.024-.235l-.013-.063a1.5 1.5 0 0 0-.18-.434c-.092-.15-.222-.28-.482-.54l-2.59-2.59c-.259-.26-.389-.39-.54-.483a1.5 1.5 0 0 0-.496-.193a.5.5 0 0 0-.235-.024C9.329.004 9.194.004 9.015.004h-2.21c-1.68 0-2.52 0-3.16.327a3.02 3.02 0 0 0-1.31 1.31c-.327.642-.327 1.48-.327 3.16v6.4c0 1.68 0 2.52.327 3.16a3.02 3.02 0 0 0 1.31 1.31c.642.327 1.48.327 3.16.327h2.4c1.68 0 2.52 0 3.16-.327a3 3 0 0 0 1.31-1.31c.327-.642.327-1.48.327-3.16V4.99c0-.178 0-.313-.005-.425zm-4.8 10.4H6.8c-.857 0-1.44 0-1.89-.038c-.438-.035-.663-.1-.819-.18a2 2 0 0 1-.874-.874c-.08-.156-.145-.38-.18-.819c-.037-.45-.038-1.03-.038-1.89v-6.4c0-.857.001-1.44.038-1.89c.036-.438.101-.663.18-.819c.192-.376.498-.682.874-.874c.156-.08.381-.145.819-.18C5.36.97 5.94.97 6.8.97H9v3.5a.5.5 0 0 0 .5.5H13v6.2c0 .857 0 1.44-.038 1.89c-.035.438-.1.663-.18.819a2 2 0 0 1-.874.874c-.156.08-.38.145-.819.18c-.45.037-1.03.037-1.89.037zm.8-13.6l2.59 2.59H10z" clip-rule="evenodd"/></svg> <a href="edit.php?id=<?= urlencode($g['page_slug']) ?>"><?= htmlspecialchars($g['page_slug']) ?></a></td>
				<td><?= (int)$g['image_count'] ?></td>
				<td><?= $g['enabled'] ? '&#10003;' : '&#8212;' ?></td>
				<td><?= date('Y-m-d H:i', (int)$g['updated']) ?></td>
				<td><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;" width="1em" height="1em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><g fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" d="M22 12c0 4.714 0 7.071-1.465 8.535C19.072 22 16.714 22 12 22s-7.071 0-8.536-1.465C2 19.072 2 16.714 2 12s0-7.071 1.464-8.536C4.93 2 7.286 2 12 2"/><path stroke-linecap="round" d="m2 12.5l1.752-1.533a2.3 2.3 0 0 1 3.14.105l4.29 4.29a2 2 0 0 0 2.564.222l.299-.21a3 3 0 0 1 3.731.225L21 18.5" opacity=".5"/><path d="m18.562 2.935l.417-.417a1.77 1.77 0 0 1 2.503 2.503l-.417.417m-2.503-2.503s.052.887.834 1.669s1.669.834 1.669.834m-2.503-2.503L14.727 6.77c-.26.26-.39.39-.5.533a3 3 0 0 0-.338.545c-.078.164-.136.338-.252.686l-.372 1.116m7.8-4.212L17.23 9.273c-.26.26-.39.39-.533.5a3 3 0 0 1-.545.338c-.164.078-.338.136-.686.252l-1.116.372m0 0l-.722.24a.477.477 0 0 1-.604-.603l.241-.722m1.085 1.085L13.265 9.65"/></g></svg> <a href="edit.php?id=<?= urlencode($g['page_slug']) ?>"><?php i18n('PageGallery/lang_Manage'); ?></a></td>
			</tr>
			<?php endforeach ?>
		</tbody>
	</table>
	<?php endif ?>

	<h3 style="margin-top:2rem;"><?php i18n('PageGallery/lang_HowTo_Title'); ?></h3>
	<p><?php i18n('PageGallery/lang_HowTo_Body'); ?></p>
	<pre style="background:#f4f6f9;padding:.75rem 1rem;border-radius:5px;border:1px solid #e5e7eb;color:navy!important">&lt;?php get_page_gallery(); ?&gt;</pre>
	<?php
}