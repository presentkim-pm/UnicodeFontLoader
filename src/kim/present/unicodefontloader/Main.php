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
 * @noinspection PhpUnused
 */

declare(strict_types=1);

namespace kim\present\unicodefontloader;

use kim\present\register\resourcepack\ResourcePackRegister;
use pocketmine\plugin\PluginBase;
use pocketmine\resourcepacks\ZippedResourcePack;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Path;

use function copy;
use function dechex;
use function hash;
use function hash_file;
use function hexdec;
use function imagealphablending;
use function imagecolorallocatealpha;
use function imagecolorat;
use function imagecolorsforindex;
use function imagecopy;
use function imagecreatefrompng;
use function imagecreatetruecolor;
use function imagefill;
use function imagepng;
use function imagesavealpha;
use function imagesx;
use function imagesy;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function preg_match;
use function scandir;
use function strtoupper;
use function substr;
use function unlink;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class Main extends PluginBase{

    private string $cacheDir;

    protected function onEnable() : void{
        $fontsDir = Path::join($this->getServer()->getDataPath(), "resource_packs/fonts");
        $this->cacheDir = Path::join($this->getDataFolder(), ".cache");

        // Create cache directory
        if(!is_dir($this->cacheDir)){
            mkdir($this->cacheDir, 0777, true);
        }

        // Save default font glyphs
        if(!is_dir($fontsDir)){
            mkdir($fontsDir, 0777, true);

            foreach(["glyph_E0.png", "glyph_E1.png"] as $resourcePath){
                copy($this->getResourcePath($resourcePath), Path::join($fontsDir, $resourcePath));
            }
        }

        // separate the all glyph group images into 256 pieces (16x16)
        foreach(scandir($fontsDir) as $file){
            if(preg_match("/^glyph_[0-9A-F]{2}\.png$/i", $file) === 0){
                continue;
            }

            $glyphPath = Path::join($fontsDir, $file);
            if(!is_file($glyphPath)){
                continue;
            }

            $prefixHex = strtoupper(substr($file, 6, 2));
            try{
                $this->separateGlyph(
                    $glyphPath,
                    Path::join($fontsDir, "glyph_$prefixHex")
                );
            }catch(\Exception $e){
                $this->getLogger()->error("Failed to separate glyph group image : $glyphPath, " . $e->getMessage());
                continue;
            }
            unlink($glyphPath);
            $this->getLogger()->info("separate glyph group image : $glyphPath");
        }

        // Merge the all glyph pieces into a glyph group image
        $glyphPaths = [];
        foreach(scandir($fontsDir) as $file){
            if(preg_match("/^glyph_[0-9A-F]{2}$/i", $file) === 0){
                continue;
            }

            $glyphDir = Path::join($fontsDir, $file);
            if(!is_dir($glyphDir)){
                continue;
            }

            $prefixHex = strtoupper(substr($file, 6, 2));
            $glyphPaths[$prefixHex] = $this->mergeGlyph(
                $glyphDir
            );
        }

        // Build the addon with the glyph images
        $addonPath = $this->buildAddon($glyphPaths);
        $this->getLogger()->info("Load unicode font glyphs from : $fontsDir");

        // Register the addon
        ResourcePackRegister::registerPack(new ZippedResourcePack($addonPath));
        $this->getLogger()->info("Registered unicode font addon : $addonPath");
    }

    /**
     * separate the glyph image into 256 pieces (16x16)
     */
    private function separateGlyph(string $glyphPath, string $glyphDir) : void{
        $image = imagecreatefrompng($glyphPath);

        $width = imagesx($image);
        $height = imagesy($image);
        if($width !== $height){
            throw new \InvalidArgumentException("Glyph image size must be square : $glyphPath");
        }

        // throw error when $weight is have decimal point
        if($width % 16 !== 0){
            throw new \InvalidArgumentException("Glyph image size must be a multiple of 16 : $glyphPath");
        }

        if(!is_dir($glyphDir)){
            mkdir($glyphDir, 0777, true);
        }

        $partSize = $width / 16;
        for($i = 0; $i < 16; $i++){
            for($j = 0; $j < 16; $j++){
                $hasPixel = false;

                $part = imagecreatetruecolor($partSize, $partSize);
                imagealphablending($part, false);
                imagesavealpha($part, true);
                $transparent = imagecolorallocatealpha($part, 0, 0, 0, 127);
                imagefill($part, 0, 0, $transparent);

                $startX = $j * $partSize;
                $startY = $i * $partSize;
                for($x = 0; $x < $partSize; $x++){
                    for($y = 0; $y < $partSize; $y++){
                        $color = imagecolorat($image, $startX + $x, $startY + $y);
                        $rgba = imagecolorsforindex($image, $color);
                        $alpha = $rgba["alpha"];
                        if($alpha < 0x7f){
                            $hasPixel = true;
                            break 2;
                        }
                    }
                }
                if(!$hasPixel){
                    continue;
                }
                $suffixHex = self::padHexCode(dechex($i * 16 + $j));
                imagecopy($part, $image, 0, 0, $startX, $startY, $partSize, $partSize);
                try{
                    imagepng($part, Path::join($glyphDir, "$suffixHex.png"));
                }catch(\Exception){
                }
            }
        }
    }

    /**
     * Merge the 256 pieces (16x16) into a glyph image
     */
    private function mergeGlyph(string $glyphDir) : string{
        $hash = "";
        $piecesFiles = [];
        foreach(scandir($glyphDir) as $file){
            if(preg_match("/^([0-9A-F]{1,2})\.png$/i", $file, $matches) === 0){
                continue;
            }

            $piecePath = Path::join($glyphDir, $file);
            if(!is_file($piecePath)){
                continue;
            }

            $hash .= hash_file("md5", $piecePath);
            $piecesFiles[$matches[1]] = $piecePath;
        }
        $hash = hash("md5", $hash);
        $cachePath = Path::join($this->cacheDir, "$hash.png");
        if(is_file($cachePath)){
            return $cachePath;
        }

        $pieces = [];
        $maxLength = 2;
        foreach($piecesFiles as $hexCode => $piecePath){
            $pieceImg = imagecreatefrompng($piecePath);
            $width = imagesx($pieceImg);
            $height = imagesy($pieceImg);

            $maxLength = max($maxLength, $width, $height);
            $hex = self::padHexCode((string) $hexCode);
            $pieces[] = [$pieceImg, hexdec($hex[1]), hexdec($hex[0]), $width, $height];
        }

        $merged = imagecreatetruecolor($maxLength * 16, $maxLength * 16);
        imagealphablending($merged, false);
        imagesavealpha($merged, true);
        $transparent = imagecolorallocatealpha($merged, 0, 0, 0, 127);
        imagefill($merged, 0, 0, $transparent);

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

        imagepng($merged, $cachePath);
        return $cachePath;
    }

    private function buildAddon(array $glyphPaths) : string{
        $hash = "";
        $glyphFiles = [];
        foreach($glyphPaths as $prefixHex => $glyphPath){
            $hash .= hash_file("md5", $glyphPath);
            $glyphFiles["font/glyph_$prefixHex.png"] = $glyphPath;
        }

        $uuid = hash("md5", $hash);
        $cachePath = Path::join($this->cacheDir, "$uuid.zip");
        if(is_file($cachePath)){
            return $cachePath;
        }

        $archive = new \ZipArchive();
        $archive->open($cachePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach($glyphFiles as $entryName => $glyphPath){
            $archive->addFile($glyphPath, $entryName);
            $archive->setCompressionName($entryName, \ZipArchive::CM_DEFLATE64);
        }

        $archive->addFromString("manifest.json", json_encode([
            "format_version" => 2,
            "header" => [
                "name" => "UnicodeFont",
                "description" => "Unicode font addon built automatically by Unicode FontLoader plug-in",
                "uuid" => Uuid::fromString($uuid)->toString(),
                "version" => [1, 0, 0],
                "min_engine_version" => [1, 20, 0]
            ],
            "modules" => [
                [
                    "description" => "Unicode font addon built automatically by Unicode FontLoader plug-in",
                    "type" => "resources",
                    "uuid" => Uuid::fromString(hash("md5", $hash . "resources"))->toString(),
                    "version" => [1, 0, 0]
                ]
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $archive->close();

        return $cachePath;
    }

    private static function padHexCode(string $hexCode) : string{
        return str_pad(strtoupper($hexCode), 2, "0", STR_PAD_LEFT);
    }

}
