<?php

/**
 *  ____                           _   _  ___
 * |  _ \ _ __ ___  ___  ___ _ __ | |_| |/ (_)_ __ ___
 * | |_) | '__/ _ \/ __|/ _ \ '_ \| __| ' /| | '_ ` _ \
 * |  __/| | |  __/\__ \  __/ | | | |_| . \| | | | | | |
 * |_|   |_|  \___||___/\___|_| |_|\__|_|\_\_|_| |_| |_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author       PresentKim (debe3721@gmail.com)
 * @link         https://github.com/PresentKim
 * @license      https://www.gnu.org/licenses/lgpl-3.0 LGPL-3.0 License
 *
 *   (\ /)
 *  ( . .) ♥
 *  c(")(")
 *
 */

declare(strict_types=1);

namespace kim\present\unicodefontloader;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Path;

use function dechex;
use function hash;
use function hash_file;
use function hexdec;
use function imagealphablending;
use function imagecolorallocatealpha;
use function imagecolorat;
use function imagecopy;
use function imagecreatefrompng;
use function imagecreatetruecolor;
use function imagefill;
use function imagepng;
use function imagesavealpha;
use function imagesx;
use function imagesy;
use function intdiv;
use function is_dir;
use function is_file;
use function json_encode;
use function max;
use function mkdir;
use function preg_match;
use function rename;
use function scandir;
use function str_pad;
use function strtoupper;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const STR_PAD_LEFT;

/**
 * Image and archive work of the plugin : separates a glyph group image into cells, merges cells back into
 * a glyph group image and packs the glyph group images into a resource pack archive.
 */
final class FontPackBuilder{

    private function __construct(){
        // NOOP
    }

    /**
     * Lists the entries of the directory, without "." and "..".
     *
     * @return string[]
     */
    public static function listDirectory(string $directory) : array{
        $entries = @scandir($directory);
        if($entries === false){
            return [];
        }
        $result = [];
        foreach($entries as $entry){
            if($entry !== "." && $entry !== ".."){
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * Splits a single glyph group image (e.g. glyph_E0.png) into 256 cell images (16x16 grid)
     * and writes them under $glyphDir. Only cells that contain non-transparent pixels are saved.
     *
     * @param string $glyphPath Path to the source PNG (must be square, size multiple of 16).
     * @param string $glyphDir  Directory to write cell PNGs (XX.png) into.
     *
     * @throws \InvalidArgumentException When image is not square or size is not a multiple of 16.
     * @throws \RuntimeException When reading the image or writing a cell PNG fails.
     */
    public static function separateGlyph(string $glyphPath, string $glyphDir) : void{
        $image = @imagecreatefrompng($glyphPath);
        if($image === false){
            throw new \RuntimeException("Failed to read glyph image : $glyphPath");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if($width !== $height){
            throw new \InvalidArgumentException("Glyph image size must be square : $glyphPath");
        }

        // throw when $width is not a multiple of 16
        if($width % 16 !== 0){
            throw new \InvalidArgumentException("Glyph image size must be a multiple of 16 : $glyphPath");
        }

        if(!is_dir($glyphDir)){
            mkdir($glyphDir, 0777, true);
        }

        $partSize = intdiv($width, 16);
        for($i = 0; $i < 16; $i++){
            for($j = 0; $j < 16; $j++){
                $startX = $j * $partSize;
                $startY = $i * $partSize;

                $hasPixel = false;
                for($x = 0; $x < $partSize && !$hasPixel; $x++){
                    for($y = 0; $y < $partSize; $y++){
                        // the alpha of a GD color is 0 (opaque) to 127 (fully transparent)
                        if(((imagecolorat($image, $startX + $x, $startY + $y) >> 24) & 0x7f) < 0x7f){
                            $hasPixel = true;
                            break;
                        }
                    }
                }
                if(!$hasPixel){
                    continue;
                }

                $part = imagecreatetruecolor($partSize, $partSize);
                imagealphablending($part, false);
                imagesavealpha($part, true);
                imagefill($part, 0, 0, imagecolorallocatealpha($part, 0, 0, 0, 127));
                imagecopy($part, $image, 0, 0, $startX, $startY, $partSize, $partSize);

                $suffixHex = self::padHexCode(dechex($i * 16 + $j));
                $outPath = Path::join($glyphDir, "$suffixHex.png");
                if(!imagepng($part, $outPath)){
                    throw new \RuntimeException("Failed to write glyph piece: $outPath");
                }
            }
        }
    }

    /**
     * Merges 256 piece images (16x16 grid, filenames 00.png–FF.png) in $glyphDir into one PNG.
     * Result is cached by content hash; returns cached path if unchanged.
     *
     * @param string $glyphDir Directory containing piece PNGs (e.g. glyph_E0/00.png, 01.png, ...).
     * @param string $cacheDir Directory to write the merged PNG into.
     *
     * @return string Path to the cached merged PNG file.
     *
     * @throws \RuntimeException When reading a piece or writing the merged PNG fails.
     */
    public static function mergeGlyph(string $glyphDir, string $cacheDir) : string{
        $hash = "";
        $piecesFiles = [];
        foreach(self::listDirectory($glyphDir) as $file){
            if(preg_match("/^([0-9A-F]{1,2})\.png$/i", $file, $matches) === 0){
                continue;
            }

            $piecePath = Path::join($glyphDir, $file);
            if(!is_file($piecePath)){
                continue;
            }

            // The position of the piece is a part of the result, so it is a part of the hash
            $hex = self::padHexCode($matches[1]);
            $hash .= $hex . hash_file("md5", $piecePath);
            $piecesFiles[$hex] = $piecePath;
        }
        $hash = hash("md5", $hash);
        $cachePath = Path::join($cacheDir, "$hash.png");
        if(is_file($cachePath)){
            return $cachePath;
        }

        // When no piece files are found, we still create a minimal cached PNG (empty 32x32) and return its path.
        $pieces = [];
        $maxLength = 2;
        foreach($piecesFiles as $hexKey => $piecePath){
            $hex = (string) $hexKey; // PHP turns a numeric string key such as "21" into an int
            $pieceImg = @imagecreatefrompng($piecePath);
            if($pieceImg === false){
                throw new \RuntimeException("Failed to read glyph piece : $piecePath");
            }
            $width = imagesx($pieceImg);
            $height = imagesy($pieceImg);

            $maxLength = max($maxLength, $width, $height);
            $pieces[] = [$pieceImg, (int) hexdec($hex[1]), (int) hexdec($hex[0]), $width, $height];
        }

        $merged = imagecreatetruecolor($maxLength * 16, $maxLength * 16);
        imagealphablending($merged, false);
        imagesavealpha($merged, true);
        imagefill($merged, 0, 0, imagecolorallocatealpha($merged, 0, 0, 0, 127));

        foreach($pieces as [$pieceImg, $x, $y, $width, $height]){
            imagecopy(
                dst_image: $merged,
                src_image: $pieceImg,
                dst_x: $x * $maxLength + (int) max(0, ($maxLength - $width) / 2),
                dst_y: $y * $maxLength + (int) max(0, ($maxLength - $height + 0.5) / 2),
                src_x: 0,
                src_y: 0,
                src_width: $width,
                src_height: $height
            );
        }

        // Write to a temporary file first, so that an interrupted write never leaves a broken cache file
        $tmpPath = "$cachePath.tmp";
        if(!imagepng($merged, $tmpPath) || !rename($tmpPath, $cachePath)){
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to write merged glyph image : $cachePath");
        }
        return $cachePath;
    }

    /**
     * Builds a ZIP resource pack addon from merged glyph images and writes manifest.json.
     * Output is cached by combined file hash; returns existing zip path when inputs are unchanged.
     *
     * @param array<string|int, string> $glyphPaths Map of prefix hex (e.g. "E0") => path to merged PNG.
     * @param string                    $cacheDir   Directory to write the zip into.
     *
     * @return string Path to the cached .zip addon file.
     *
     * @throws \RuntimeException When the archive could not be written.
     */
    public static function buildAddon(array $glyphPaths, string $cacheDir) : string{
        $hash = "";
        $glyphFiles = [];
        foreach($glyphPaths as $prefixHex => $glyphPath){
            $hash .= $prefixHex . hash_file("md5", $glyphPath);
            $glyphFiles["font/glyph_$prefixHex.png"] = $glyphPath;
        }

        $uuid = hash("md5", $hash);
        $cachePath = Path::join($cacheDir, "$uuid.zip");
        if(is_file($cachePath)){
            return $cachePath;
        }

        if(!is_dir($cacheDir)){
            throw new \RuntimeException("Cache directory does not exist : $cacheDir");
        }

        // Write to a temporary file first, so that an interrupted write never leaves a broken cache file
        $tmpPath = "$cachePath.tmp";
        $archive = new \ZipArchive();
        if($archive->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true){
            throw new \RuntimeException("Failed to create addon archive : $tmpPath");
        }
        $description = "Unicode font addon built automatically by Unicode FontLoader plug-in";
        $ok = true;
        foreach($glyphFiles as $entryName => $glyphPath){
            $ok = $archive->addFile($glyphPath, $entryName) && $ok;
        }

        $ok = $archive->addFromString("manifest.json", json_encode([
            "format_version" => 2,
            "header" => [
                "name" => "UnicodeFont",
                "description" => $description,
                "uuid" => Uuid::fromString($uuid)->toString(),
                "version" => [1, 0, 0],
                "min_engine_version" => [1, 20, 0]
            ],
            "modules" => [
                [
                    "description" => $description,
                    "type" => "resources",
                    "uuid" => Uuid::fromString(hash("md5", $hash . "resources"))->toString(),
                    "version" => [1, 0, 0]
                ]
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) && $ok;

        if(!$archive->close() || !$ok || !rename($tmpPath, $cachePath)){
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to write addon archive : $cachePath");
        }

        return $cachePath;
    }

    /**
     * Pads a hex string to two uppercase characters (e.g. "a" -> "0A").
     *
     * @param string $hexCode One- or two-character hex code.
     *
     * @return string Two-character uppercase hex, zero-padded on the left.
     */
    public static function padHexCode(string $hexCode) : string{
        return str_pad(strtoupper($hexCode), 2, "0", STR_PAD_LEFT);
    }
}
