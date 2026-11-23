# Changelog

## 1.0.0

First release.

- Writes only the original at upload and makes each image size the first time something requests it.
- WebP, AVIF or the uploaded format, chosen under Settings > Media; older uploads can be converted.
- In-between widths and a `srcset` per placement in the block tree.
- Deferred resizing on PHP-FPM and LiteSpeed, so a visitor never waits for one.
- Media library, Site Health and `wp image-sizes` tools to make, warm, convert and clean up sizes.
- Works on a network sharing one media library.
- Installs as a mu-plugin or a regular plugin, with Composer or from a release zip.
- Dutch translation.
