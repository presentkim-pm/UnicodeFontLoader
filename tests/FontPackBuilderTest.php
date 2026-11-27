<?php

declare(strict_types=1);

namespace kim\present\unicodefontloader\tests;

use kim\present\unicodefontloader\FontPackBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\resourcepacks\ZippedResourcePack;
use Ramsey\Uuid\Uuid;

final class FontPackBuilderTest extends TestCase{

    private string $dir;

    protected function setUp() : void{
        $this->dir = sys_get_temp_dir() . '/unicodefontloader_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/cache', 0777, true);
    }

    protected function tearDown() : void{
        self::removeDirectory($this->dir);
    }

    private static function removeDirectory(string $dir) : void{
        if(!is_dir($dir)){
            return;
        }
        foreach(scandir($dir) as $entry){
            if($entry === '.' || $entry === '..'){
                continue;
            }
            $path = "$dir/$entry";
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** Writes a transparent PNG of $size x $size with the given pixels (x, y) painted opaque red */
    private function writePng(string $path, int $size, array $pixels = []) : void{
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        foreach($pixels as [$x, $y]){
            imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, 255, 0, 0, 0));
        }
        self::assertTrue(imagepng($image, $path));
    }

    /** @return iterable<string, array{string, string}> */
    public static function hexProvider() : iterable{
        yield 'one digit' => ['a', '0A'];
        yield 'one digit upper' => ['F', '0F'];
        yield 'zero' => ['0', '00'];
        yield 'two digits' => ['e0', 'E0'];
        yield 'two digits upper' => ['FF', 'FF'];
    }

    #[DataProvider('hexProvider')]
    public function testPadHexCode(string $input, string $expected) : void{
        self::assertSame($expected, FontPackBuilder::padHexCode($input));
    }

    public function testListDirectory() : void{
        touch($this->dir . '/b.txt');
        touch($this->dir . '/a.txt');
        $list = FontPackBuilder::listDirectory($this->dir);
        sort($list);
        self::assertSame(['a.txt', 'b.txt', 'cache'], $list);
        self::assertSame([], FontPackBuilder::listDirectory($this->dir . '/missing'));
    }

    public function testSeparateGlyphWritesOnlyNonEmptyCells() : void{
        // 32x32 image, so the cells are 2x2 : cell 0x00 (row 0, col 0) and cell 0x1F (row 1, col 15)
        $this->writePng($this->dir . '/glyph_E0.png', 32, [[1, 1], [30, 2]]);
        FontPackBuilder::separateGlyph($this->dir . '/glyph_E0.png', $this->dir . '/glyph_E0');

        $files = FontPackBuilder::listDirectory($this->dir . '/glyph_E0');
        sort($files);
        self::assertSame(['00.png', '1F.png'], $files);

        $cell = imagecreatefrompng($this->dir . '/glyph_E0/00.png');
        self::assertSame(2, imagesx($cell));
        self::assertSame(2, imagesy($cell));
        self::assertSame(0, (imagecolorat($cell, 1, 1) >> 24) & 0x7f, 'the painted pixel stays opaque');
        self::assertSame(127, (imagecolorat($cell, 0, 0) >> 24) & 0x7f, 'the others stay transparent');
    }

    public function testSeparateGlyphRejectsNonSquareImage() : void{
        $image = imagecreatetruecolor(32, 16);
        imagepng($image, $this->dir . '/glyph_E0.png');
        $this->expectException(\InvalidArgumentException::class);
        FontPackBuilder::separateGlyph($this->dir . '/glyph_E0.png', $this->dir . '/glyph_E0');
    }

    public function testSeparateGlyphRejectsSizeNotMultipleOf16() : void{
        $this->writePng($this->dir . '/glyph_E0.png', 20);
        $this->expectException(\InvalidArgumentException::class);
        FontPackBuilder::separateGlyph($this->dir . '/glyph_E0.png', $this->dir . '/glyph_E0');
    }

    public function testSeparateGlyphRejectsBrokenImage() : void{
        file_put_contents($this->dir . '/glyph_E0.png', 'not a png');
        $this->expectException(\RuntimeException::class);
        FontPackBuilder::separateGlyph($this->dir . '/glyph_E0.png', $this->dir . '/glyph_E0');
    }

    public function testMergeGlyphPlacesPiecesByTheirHexCode() : void{
        mkdir($this->dir . '/glyph_E0');
        // 0x21 = row 2, column 1
        $this->writePng($this->dir . '/glyph_E0/21.png', 4, [[0, 0]]);

        $path = FontPackBuilder::mergeGlyph($this->dir . '/glyph_E0', $this->dir . '/cache');
        self::assertFileExists($path);
        $merged = imagecreatefrompng($path);
        self::assertSame(64, imagesx($merged));
        self::assertSame(0, (imagecolorat($merged, 1 * 4, 2 * 4) >> 24) & 0x7f);
        self::assertSame(127, (imagecolorat($merged, 0, 0) >> 24) & 0x7f);
        self::assertSame([], array_filter(FontPackBuilder::listDirectory($this->dir . '/cache'), static fn(string $f) => str_ends_with($f, '.tmp')));
    }

    public function testMergeGlyphUsesTheLargestPieceAndCentersSmallerOnes() : void{
        mkdir($this->dir . '/glyph_E0');
        $this->writePng($this->dir . '/glyph_E0/00.png', 8, []);
        $this->writePng($this->dir . '/glyph_E0/01.png', 4, [[0, 0]]);

        $merged = imagecreatefrompng(FontPackBuilder::mergeGlyph($this->dir . '/glyph_E0', $this->dir . '/cache'));
        self::assertSame(128, imagesx($merged));
        // 4x4 piece in the 8x8 cell of column 1 : x offset (8 - 4) / 2 = 2, y offset (8 - 4 + 0.5) / 2 = 2
        self::assertSame(0, (imagecolorat($merged, 8 + 2, 2) >> 24) & 0x7f);
    }

    public function testMergeGlyphIsCachedByContentAndPosition() : void{
        mkdir($this->dir . '/glyph_E0');
        $this->writePng($this->dir . '/glyph_E0/00.png', 4, [[0, 0]]);
        $first = FontPackBuilder::mergeGlyph($this->dir . '/glyph_E0', $this->dir . '/cache');
        self::assertSame($first, FontPackBuilder::mergeGlyph($this->dir . '/glyph_E0', $this->dir . '/cache'));

        // the same image at another position is another result
        rename($this->dir . '/glyph_E0/00.png', $this->dir . '/glyph_E0/01.png');
        self::assertNotSame($first, FontPackBuilder::mergeGlyph($this->dir . '/glyph_E0', $this->dir . '/cache'));
    }

    public function testMergeGlyphWithoutPiecesCreatesAnEmptyImage() : void{
        mkdir($this->dir . '/glyph_E0');
        $merged = imagecreatefrompng(FontPackBuilder::mergeGlyph($this->dir . '/glyph_E0', $this->dir . '/cache'));
        self::assertSame(32, imagesx($merged));
    }

    public function testBuildAddonCreatesALoadablePack() : void{
        $this->writePng($this->dir . '/E0.png', 16, [[0, 0]]);
        $this->writePng($this->dir . '/E1.png', 16, [[1, 1]]);

        $path = FontPackBuilder::buildAddon(['E0' => $this->dir . '/E0.png', 'E1' => $this->dir . '/E1.png'], $this->dir . '/cache');
        self::assertFileExists($path);
        self::assertSame($path, FontPackBuilder::buildAddon(['E0' => $this->dir . '/E0.png', 'E1' => $this->dir . '/E1.png'], $this->dir . '/cache'));

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertNotFalse($zip->locateName('font/glyph_E0.png'));
        self::assertNotFalse($zip->locateName('font/glyph_E1.png'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $zip->close();
        self::assertSame(2, $manifest['format_version']);
        self::assertTrue(Uuid::isValid($manifest['header']['uuid']));
        self::assertNotSame($manifest['header']['uuid'], $manifest['modules'][0]['uuid']);

        // the server accepts it as a resource pack
        $pack = new ZippedResourcePack($path);
        self::assertSame($manifest['header']['uuid'], $pack->getPackId());
    }

    public function testBuildAddonChangesWithTheGlyphs() : void{
        $this->writePng($this->dir . '/E0.png', 16, [[0, 0]]);
        $this->writePng($this->dir . '/E1.png', 16, [[1, 1]]);
        $a = FontPackBuilder::buildAddon(['E0' => $this->dir . '/E0.png'], $this->dir . '/cache');
        $b = FontPackBuilder::buildAddon(['E0' => $this->dir . '/E1.png'], $this->dir . '/cache');
        $c = FontPackBuilder::buildAddon(['E1' => $this->dir . '/E0.png'], $this->dir . '/cache');
        self::assertCount(3, array_unique([$a, $b, $c]));
    }

    public function testBuildAddonWithoutGlyphs() : void{
        $path = FontPackBuilder::buildAddon([], $this->dir . '/cache');
        self::assertSame('zip', pathinfo($path, PATHINFO_EXTENSION));
        new ZippedResourcePack($path);
    }

    public function testBuildAddonFailsWithoutCacheDirectory() : void{
        $this->writePng($this->dir . '/E0.png', 16, [[0, 0]]);
        $this->expectException(\RuntimeException::class);
        FontPackBuilder::buildAddon(['E0' => $this->dir . '/E0.png'], $this->dir . '/missing');
    }

    public function testSeparateThenMergeRoundTrip() : void{
        $this->writePng($this->dir . '/glyph_E0.png', 32, [[2, 3], [17, 20]]);
        FontPackBuilder::separateGlyph($this->dir . '/glyph_E0.png', $this->dir . '/glyph_E0');
        $merged = imagecreatefrompng(FontPackBuilder::mergeGlyph($this->dir . '/glyph_E0', $this->dir . '/cache'));

        // Pieces are 2x2, so the merged image is 32x32 again and the painted pixels are where they were
        self::assertSame(32, imagesx($merged));
        self::assertSame(0, (imagecolorat($merged, 2, 3) >> 24) & 0x7f);
        self::assertSame(0, (imagecolorat($merged, 17, 20) >> 24) & 0x7f);
        self::assertSame(127, (imagecolorat($merged, 0, 0) >> 24) & 0x7f);
    }
}
