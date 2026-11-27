# Changelog

## 2.0.0 (2026-11-27)

The Poggit plugin version for the original [pmmp/PocketMine-MP](https://github.com/pmmp/PocketMine-MP) 5.x is kept on the
[`legacy/pmmp-5.44`](https://github.com/presentkim-pm/unicodefontloader/tree/legacy/pmmp-5.44) branch.

### ⚠️ Breaking changes
- Targets [axolotl-pm/PocketMine-MP](https://github.com/axolotl-pm/PocketMine-MP) `5.51` (BedrockProtocol 64,
  Bedrock `1.26.60`) and **PHP 8.4+**.
- Distributed as a phar from GitHub Releases instead of Poggit.
- `plugin.yml` also requires the `zip` PHP extension (it was already used to build the pack).

### 🔄 Changes
- Resource pack registration uses [presentkim/resourcepack-register](https://packagist.org/packages/presentkim/resourcepack-register) `^2.0`, bundled through Composer instead of the Poggit virion.
- The image and archive work moved from `Main` to the new `FontPackBuilder` class, so it can be unit tested.
- The font pack is unregistered when the plugin is disabled (registering a pack twice is an error in resourcepack-register 2.0).
- `composer.json` added (Composer is only used for development, testing and bundling libraries); `plugin.yml` has
  `src-namespace-prefix`, and the source files moved from `src/kim/present/unicodefontloader/` to `src/`.

### 🐛 Fixes
- `ZipArchive::CM_DEFLATE64` was requested for the glyph images, which libzip cannot write (the call silently failed); the default deflate is used now.
- A glyph image or cell that could not be read relied on a PHP warning being turned into an exception (and `imagecreatefrompng()` returning `false` would have been a `TypeError`); it is an explicit `RuntimeException` now, logged for that file.
- The merged glyph image and the pack zip are written to a temporary file and renamed, so an interrupted server start no longer leaves a broken file that the cache then keeps using.
- The cache key of a merged glyph image ignored where each cell is, so moving a cell to another code point reused the old image. The cell positions (and glyph group names, for the pack) are part of the key now.
- Results of `ZipArchive` (`open`, `addFile`, `close`) and `imagepng()` were not checked, a failure produced a broken pack instead of an error.
- Cell images are only allocated for cells that contain a pixel.
