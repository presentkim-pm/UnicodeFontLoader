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
use Symfony\Component\Filesystem\Path;

use function copy;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match;
use function scandir;
use function strtoupper;
use function substr;
use function unlink;

final class Main extends PluginBase{

    private string $cacheDir;

    /** UUID of the registered font pack, null while it is not registered */
    private ?string $packId = null;

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
        foreach(FontPackBuilder::listDirectory($fontsDir) as $file){
            if(preg_match("/^glyph_[0-9A-F]{2}\.png$/i", $file) === 0){
                continue;
            }

            $glyphPath = Path::join($fontsDir, $file);
            if(!is_file($glyphPath)){
                continue;
            }

            $prefixHex = strtoupper(substr($file, 6, 2));
            try{
                FontPackBuilder::separateGlyph(
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
        foreach(FontPackBuilder::listDirectory($fontsDir) as $file){
            if(preg_match("/^glyph_[0-9A-F]{2}$/i", $file) === 0){
                continue;
            }

            $glyphDir = Path::join($fontsDir, $file);
            if(!is_dir($glyphDir)){
                continue;
            }

            $prefixHex = strtoupper(substr($file, 6, 2));
            $glyphPaths[$prefixHex] = FontPackBuilder::mergeGlyph($glyphDir, $this->cacheDir);
        }

        // Build the addon with the glyph images
        $addonPath = FontPackBuilder::buildAddon($glyphPaths, $this->cacheDir);
        $this->getLogger()->info("Load unicode font glyphs from : $fontsDir");

        // Register the addon
        $pack = new ZippedResourcePack($addonPath);
        ResourcePackRegister::registerPack($pack);
        $this->packId = $pack->getPackId();
        $this->getLogger()->info("Registered unicode font addon : $addonPath");
    }

    protected function onDisable() : void{
        // Unregister it so that enabling the plugin again does not register the same pack twice
        if($this->packId !== null){
            ResourcePackRegister::unregisterPack($this->packId);
            $this->packId = null;
        }
    }
}
