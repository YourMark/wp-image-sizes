# YourMark image sizes

WordPress makes every registered image size at upload, whether anything uses it
or not. This plugin writes only the original at upload and makes each size the
first time something requests its file: a page, the editor, the media library.
After that, the web server serves it as an ordinary static file.

Works with any theme. It hooks into core's attachment metadata, so `srcset`,
`wp_get_attachment_image()`, the block editor and plugins see ordinary sizes.

## Install

Needs WordPress 7.1 and PHP 8.2. Afterwards, set up the server as under
[Servers](#servers).

### Composer

Add the repository and require the package:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/YourMark/wp-image-sizes" }
  ],
  "require": {
    "yourmark/wp-image-sizes": "^1.0"
  }
}
```

It is a `wordpress-muplugin` and installs into `mu-plugins/yourmark-image-sizes/`.
WordPress loads no mu-plugin from a subdirectory by itself:
[Bedrock](https://roots.io/bedrock/)'s autoloader does, and on any other
Composer install `roots/bedrock-autoloader` does the same.

### Download

Every [release](https://github.com/YourMark/wp-image-sizes/releases) has two zips:

- `yourmark-image-sizes.zip` is a regular plugin: upload it under Plugins > Add New.
- `yourmark-image-sizes-mu.zip` holds the folder and a loader. Unzip it into
  `wp-content/mu-plugins/`, since wp-admin cannot upload mu-plugins.

Installed both ways, it loads once. A zip install does not update itself.

## How it works

1. **Upload:** the original is stored in the chosen format (Settings > Media),
   with two base sizes, core's 768 and 1536 (`yourmark/image-sizes/base`).
   Every other width is at most a step below one of them, so a size that does
   not exist yet is always stood in for by an optimised file rather than the
   original. An animation gets no base: resizing keeps its first frame.
   For an upload from before, a base size is lazy like any other until
   `wp image-sizes base` makes it, so its URL keeps working in between.
   Converting an older upload makes the base in the new format.
   Every other registered size is added to the metadata when it is read,
   never stored. A size registered later works for every existing upload at once.
   A size in another format than its source carries the source extension
   after two hyphens (`photo--jpg-300x200.webp`), so `photo.jpg` and
   `photo.png` in one folder never share size files. No upload can collide
   with that: WordPress collapses any run of hyphens in an upload's name.
   The block editor's in-browser processing (WordPress 7.1) is switched off
   while sizes are lazy: it only scales and rotates a camera photo as part
   of making sizes, and the server does both anyway.
   - **In-between widths:** beside the registered sizes, the metadata lists
     400, 520, 640 and 1280 (`yourmark/image-sizes/steps`), below the
     upload's own width, in its proportions and skipped within 15% of a width
     already offered. With core's sizes that is a ladder of about 1.25x:
     300, 400, 520, 640, 768, 1024, 1280, 1536, 2048. Core puts them in every
     `srcset`, old content included. They are names only: a file exists once
     a browser asked for it. Making every size of an image, from the admin or
     `convert`, leaves them out.
   - **A srcset per placement:** an image block without a `sizes` gets one from
     its place in the block tree: the theme's `contentSize` and `wideSize`,
     alignment, and columns, passed from parent to child while rendering.
     Then every `srcset`, in content and templates, drops the candidates past
     the first one at least twice the widest slot its `sizes` names. Density
     is capped at 2x, as Next.js, Astro and Twitter do. A `sizes` without an
     upper bound (`100vw`, `calc()`) keeps the whole list.
   - **Per site:** a layout the blocks do not describe, such as a grid in the
     theme's CSS, is invisible to this. Set `sizes` on those images in the
     theme, with the `wp_get_attachment_image_attributes` filter; the plugin keeps
     a `sizes` that is already there.
2. **First request:** WordPress checks that the file name belongs to an image
   of this site, makes the size its metadata lists, sends it and leaves it on
   disk. Nobody can request made-up dimensions: only sizes in the metadata are
   ever made.
   - On PHP-FPM and LiteSpeed (`yourmark/image-sizes/defer`) the visitor never
     waits for a resize. The smallest file that exists and is at least as wide,
     else the original, is sent at once with `no-store`, and the size joins a
     queue once the response is out. Only as many requests as there are slots
     work through it; every other one frees its PHP worker straight away, so a
     page of new images is not served one resize at a time. The next visitor
     gets the size itself, as a plain file.
   - A name from before a format switch, a change in dimensions or a
     conversion to a new file name gets a 301 to the size that replaced it, so
     URLs saved in content keep working. Among images that share a name, the
     one whose source had the requested extension wins. The 301
     expires after a day, so switching formats back and forth cannot leave a
     browser in a redirect loop. The target is made by its own request.
   - At most two images are resized at once (`yourmark/image-sizes/concurrency`);
     each holds 150 to 350 MB. A request that waits more than ten seconds in all
     (`yourmark/image-sizes/wait`), or asks for a size that failed in the last
     ten minutes, gets a temporary redirect to the smallest file that exists
     and is at least as wide.
     Problems are logged once an hour. The buttons in the admin always retry.
   - A request through the PHP URL that names no image gets a 404.
3. **Warming:** saving a published post queues its page; widgets, the
   customiser, template parts and a theme switch queue the front page. A
   minute later a cron job fetches the page, past any page cache, and makes each img's `src`, image
   preloads and share images. For every image that is not lazy, which is what
   is above the fold and decides LCP, it also makes the `srcset` candidate a
   browser would pick on five screens: a 390px phone at 3x, Lighthouse's
   412px phone at 1.75x, a 768px tablet at 2x and a 1440px laptop at 1x and
   2x. That is worked out from `sizes` as the browser does, so nothing a
   visitor would not load is made. Lazy images wait for a browser; until
   then the base stands in.

## Servers

The plugin tests daily whether a request for a missing file in uploads reaches
WordPress (`wp image-sizes check`).

- **It does** (Apache, LiteSpeed and nginx with WordPress' usual config): a new
  size gets its final URL straight away.
- **It does not** (nginx in front of Apache, static-file blocks ending in
  `=404`, many managed hosts): the plugin prints `index.php?yourmark-image=…`
  for a size without a file, which reaches PHP on any host. Once the file
  exists, the next render prints its ordinary URL again. Until the first test
  has run, this is the mode.

Both work without any server change. To give new sizes their final URL on an
nginx host that refuses missing images, put this before its static-file block:

```nginx
# Only names like photo-300x200.webp; every other file keeps its own handling.
location ~* ^/app/uploads/.+-\d+x\d+\.(?:avif|jpe?g|png|webp)$ {
    try_files $uri /index.php?$args;
    expires 1y;
    access_log off;
}
```

nginx uses the first regex `location` that matches, so this one has to come
before any rule for image files, such as a caching rule. On SpinupWP that rule
is in `server/static-files.conf`, and the files in that directory load in
alphabetical order: name this one `00-yourmark-image-sizes.conf` in
`/etc/nginx/sites-available/{DOMAIN}/server/`, then run
`sudo nginx -t && sudo service nginx reload` and `wp image-sizes check`.

On a standard WordPress install, use `wp-content/uploads` in the path.

## Formats

Settings > Media > Image format: as uploaded, WebP (default) or AVIF. AVIF is
offered only when the server's image editor can write it, at quality 65, which
measured the same as WebP at 82. Switching needs no regeneration: sizes made
from then on take the new format, and a request for a size in the old format
is redirected to the new one, so URLs saved in content keep working.

Converting a JPEG to WebP or AVIF drops its EXIF and IPTC data (camera,
copyright, caption), as the theme's earlier conversion did. A server whose
image editor cannot write the chosen format keeps uploads as they are.

An image edited in Media > Edit image keeps its format, and core makes all its
sizes for the edit at once, as it always has.

**Converting existing uploads** stores the original in the chosen format and
keeps the old file, so links to it keep working; it is deleted with the
attachment. Never converted: the site icon (iOS ignores a WebP or AVIF
apple-touch-icon) and animated WebP or PNG (conversion keeps only the first
frame).

## Multisite

On a network, the server test and the format are the network's: one server
serves every site. The main site runs the test and is the only one that shows
the format under Settings > Media; every site reads both. Until the main site
has tested, a site keeps the result it had from before the network.

## What gets deleted

Only files that this plugin or core made for an attachment. The plugin records
every file it writes (post meta `_yourmark_image_sizes_made`) and core lists its
own in the metadata; a file merely named like a size, such as an FTP upload or
another plugin's crop, is never touched.

- **Replacing** an attachment's file (Enable Media Replace and the like, or
  `wp media regenerate`) deletes the sizes made from the old file.
- **Deleting** an attachment also deletes the files this plugin made that the
  metadata no longer lists, such as sizes in a format switched away from.
- Files made before the plugin kept records (the first versions on a site) are
  left alone by `prune`. Replacing an image does delete one under its own size
  name, unless another image in the folder shares that name.

A replacement under the same name keeps the same URLs, so a browser or CDN may
show the old image until its cache expires. That is no different in core.

## Admin

Settings > Media has three tools, each run in small batches so a large library
does not time out: convert originals, warm all pages, and clean up size files
(`prune --stored`, after a count and a confirmation in place). The media
library's list view, the media modal and the attachment edit screen show how
many sizes exist, with a button that converts the image if needed and makes the
rest; it disappears once there is nothing left to do.

Text domain `yourmark-image-sizes`, with Dutch in `languages/`. After changing a
string: `wp i18n make-pot`, `wp i18n update-po`, translate, then `wp i18n
make-mo` and `wp i18n make-php`.

## Commands

```bash
wp image-sizes check                    # test the server
wp image-sizes warm                     # warm every page in the sitemap
wp image-sizes warm <url>...            # only these pages
wp image-sizes convert                  # store older uploads in the chosen format
wp image-sizes base                     # make the base sizes for older uploads
wp image-sizes base --limit=500         # at most 500 uploads, for a large library over several nights
wp image-sizes prune --dry-run          # list size files nothing refers to
wp image-sizes prune --stored           # also delete sizes made at upload
```

`prune` deletes files the plugin made that no size lists any more. `--stored`
also deletes the sizes core made at upload, for registered sizes only (a size
registered for a moment, like the site icon's, could not come back), and drops
them from the stored metadata: switching the plugin off and running
`wp media regenerate --only-missing` brings them back. The admin asks before
deleting; the AJAX endpoint itself trusts any administrator with a valid nonce.

## Switching it off

Remove the plugin, then run `wp media regenerate --only-missing`: core makes
every size the plugin left lazy. Files the plugin made stay on disk, as do the
`_yourmark_image_sizes_*` post meta rows; both are harmless, and the plugin
picks them up again when it comes back.

## Releases

A tag `v1.2.3` builds both zips and attaches them to a GitHub release. Bump
the version in the plugin header and `YOURMARK_IMAGE_SIZES_VERSION` first.

## Tests

Integration tests run on the WordPress test suite, against a WordPress core, a
separate database and a throwaway content directory. Inside a Bedrock site they
use its `web/wp`; elsewhere, set `WP_CORE_DIR` to a WordPress 7.1 install.

```bash
composer install
mysql -uroot -e 'CREATE DATABASE IF NOT EXISTS yourmark_image_sizes_tests'
composer test
```

`composer test` runs the suite twice, as a single site and as a network.

The database settings default to Herd's (root, no password, 127.0.0.1) and can
be overridden with `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD`
and `WP_TESTS_DB_HOST`.

## Filters

- `yourmark/image-sizes/base`: sizes made at upload. `medium_large` and
  `1536x1536` by default.
- `yourmark/image-sizes/concurrency`: images resized at once. Default 2.
- `yourmark/image-sizes/wait`: seconds a request waits for its turn. Default 10.
- `yourmark/image-sizes/files_site`: the site that records the files this
  plugin makes, for cleanup. The current site by default; a network sharing one
  media library points it at the library's site.
- `yourmark/image-sizes/enabled`: switch lazy sizes off.
- `yourmark/image-sizes/steps`: in-between widths offered in `srcset`.
- `yourmark/image-sizes/defer`: answer a missing size with a larger file at
  once and make it afterwards. On by default where PHP can end a response early.
- `yourmark/image-sizes/lock-dir`: where lock files go. Defaults to
  `wp-content/cache/yourmark-image-sizes`; point every site on a server at one
  directory to share the resize slots between them.
