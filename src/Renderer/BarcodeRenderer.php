<?php

namespace ReportingEngine\Renderer;

use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;

class BarcodeRenderer
{
    private static array $symbologyMap = [
        'code128' => BarcodeGeneratorPNG::TYPE_CODE_128,
        'code39' => BarcodeGeneratorPNG::TYPE_CODE_39,
        'ean13' => BarcodeGeneratorPNG::TYPE_EAN_13,
        'ean8' => BarcodeGeneratorPNG::TYPE_EAN_8,
        'upca' => BarcodeGeneratorPNG::TYPE_UPC_A,
        'upce' => BarcodeGeneratorPNG::TYPE_UPC_E,
        'qr' => BarcodeGeneratorPNG::TYPE_QR_CODE,
        'pdf417' => BarcodeGeneratorPNG::TYPE_PDF417,
        'datamatrix' => BarcodeGeneratorPNG::TYPE_DATA_MATRIX,
        'codabar' => BarcodeGeneratorPNG::TYPE_CODABAR,
        'msi' => BarcodeGeneratorPNG::TYPE_MSI,
        'pharmacode' => BarcodeGeneratorPNG::TYPE_PHARMA_CODE,
    ];

    public static function renderPng(string $value, string $symbology = 'code128', bool $showText = true): string
    {
        $type = self::$symbologyMap[$symbology] ?? BarcodeGeneratorPNG::TYPE_CODE_128;
        $generator = new BarcodeGeneratorPNG();
        $barcode = $generator->getBarcode($value, $type, 2, 40);
        return 'data:image/png;base64,' . base64_encode($barcode);
    }

    public static function renderSvg(string $value, string $symbology = 'code128'): string
    {
        $type = self::$symbologyMap[$symbology] ?? BarcodeGeneratorSVG::TYPE_CODE_128;
        $generator = new BarcodeGeneratorSVG();
        return $generator->getBarcode($value, $type, 2, 40);
    }

    public static function getSymbologyLabel(string $symbology): string
    {
        return strtoupper($symbology);
    }
}
