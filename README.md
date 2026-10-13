# YourMark image sizes

WordPress makes every registered image size at upload, whether anything uses it
or not. This plugin writes only the original at upload and makes each size the
first time something requests its file: a page, the editor, the media library.
After that, the web server serves it as an ordinary static file.

Works with any theme. It hooks into core's attachment metadata, so `srcset`,
`wp_get_attachment_image()`, the block editor and plugins see ordinary sizes.

## How it works

1. **Upload:** the original is stored in the chosen format (Settings > Media).
   Every registered size is added to the metadata when it is read, never
   stored. A size registered later works for every existing upload at once.
2. **First request:** WordPress checks that the file name is a size in that
   attachment's metadata, makes it, sends it and leaves it on disk. Anything
   else falls through to the 404, so nobody can request made-up dimensions.
   At most two images are resized at once per server
   (`yourmark/image-sizes/concurrency`); each holds 150 to 350 MB.
3. **Warming:** saving a published post queues its page; widgets, the
   customiser, template parts and a theme switch queue the front page. A
   minute later a cron job fetches the page and makes each img's `src`, image
   preloads and share images. Other `srcset` candidates depend on the
   visitor's screen and wait for a browser that picks one.

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
location ~* ^/app/uploads/.+\.(?:avif|jpe?g|png|webp)$ {
    try_files $uri /index.php?$args;
    expires max;
}
```

On a standard WordPress install, use `wp-content/uploads` in the path.

## Formats

Settings > Media > Image format: as uploaded, WebP (default) or AVIF. AVIF is
offered only when the server's image editor can write it, at quality 65, which
measured the same as WebP at 82. Switching needs no regeneration: sizes made
from then on take the new format, and a request for a size in the old format
is redirected to the new one, so URLs saved in content keep working.

**Converting existing uploads** stores the original in the chosen format and
keeps the old file, so links to it keep working; it is deleted with the
attachment. The site icon is never converted: iOS ignores a WebP or AVIF
apple-touch-icon.

## Replacing and deleting

When an attachment's file is replaced (Enable Media Replace and the like, or
`wp media regenerate`), sizes made from the old file are deleted, under its old
name and its new one. Deleting an attachment also deletes sizes in a format
switched away from, which core's own deletion does not know about.

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
wp image-sizes prune --dry-run          # list size files nothing refers to
wp image-sizes prune --stored           # also delete sizes made at upload
```

`prune` treats size files that another plugin made without registering the
size as orphans. On sites with page builders, check `--dry-run` first.
`--stored` also drops those sizes from the stored metadata, so switching the
plugin off and running `wp media regenerate --only-missing` brings them back.

## Filters

- `yourmark/image-sizes/base`: sizes still made at upload. None by default.
- `yourmark/image-sizes/concurrency`: images resized at once. Default 2.
- `yourmark/image-sizes/enabled`: switch lazy sizes off.
