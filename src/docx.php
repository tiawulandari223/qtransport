<?php

/**
 * Minimal but genuinely valid Office Open XML (.docx) writer.
 * Produces a real ZIP package Word/LibreOffice opens natively — no HTML-in-.doc trick.
 */
class Docx {
    private array $body = [];
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const CONTENT_WIDTH = 9000; // twips, A4 minus margins

    private static function x(string $s): string {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function run(string $text, array $fmt = []): string {
        $rpr = '';
        if (!empty($fmt['bold'])) $rpr .= '<w:b/>';
        if (!empty($fmt['italic'])) $rpr .= '<w:i/>';
        if (!empty($fmt['color'])) $rpr .= '<w:color w:val="' . self::x($fmt['color']) . '"/>';
        if (!empty($fmt['size'])) $rpr .= '<w:sz w:val="' . (int)$fmt['size'] . '"/><w:szCs w:val="' . (int)$fmt['size'] . '"/>';
        $rprXml = $rpr ? "<w:rPr>$rpr</w:rPr>" : '';
        $parts = preg_split('/\r?\n/', $text);
        $inner = '';
        foreach ($parts as $i => $line) {
            if ($i > 0) $inner .= '<w:br/>';
            $inner .= '<w:t xml:space="preserve">' . self::x($line) . '</w:t>';
        }
        return "<w:r>$rprXml$inner</w:r>";
    }

    public function para(string $text, array $fmt = []): self {
        $pPr = '';
        if (!empty($fmt['size']) || !empty($fmt['bold']) || !empty($fmt['color']) || !empty($fmt['italic'])) {
            $f = ['bold' => $fmt['bold'] ?? false, 'italic' => $fmt['italic'] ?? false,
                'color' => $fmt['color'] ?? '', 'size' => $fmt['size'] ?? ''];
            $rpr = '';
            if (!empty($f['bold'])) $rpr .= '<w:b/>';
            if (!empty($f['italic'])) $rpr .= '<w:i/>';
            if ($f['color']) $rpr .= '<w:color w:val="' . self::x($f['color']) . '"/>';
            if ($f['size']) $rpr .= '<w:sz w:val="' . (int)$f['size'] . '"/><w:szCs w:val="' . (int)$f['size'] . '"/>';
            $pPr .= '<w:rPr>' . $rpr . '</w:rPr>';
        }
        $before = (int)($fmt['before'] ?? 0);
        $after = (int)($fmt['after'] ?? 120);
        $spacing = '<w:spacing w:before="' . $before . '" w:after="' . $after . '"/>';
        if (!empty($fmt['align'])) $spacing .= '<w:jc w:val="' . self::x($fmt['align']) . '"/>';
        $this->body[] = '<w:p><w:pPr>' . $spacing . $pPr . '</w:pPr>' . $this->run($text, $fmt) . '</w:p>';
        return $this;
    }

    public function h1(string $t): self { return $this->para($t, ['bold' => 1, 'size' => 36, 'color' => '1F3864', 'before' => 240, 'after' => 160]); }
    public function h2(string $t): self { return $this->para($t, ['bold' => 1, 'size' => 28, 'color' => '1F3864', 'before' => 260, 'after' => 120]); }
    public function h3(string $t): self { return $this->para($t, ['bold' => 1, 'size' => 24, 'color' => '2E5A80', 'before' => 180, 'after' => 80]); }
    public function bullet(string $t): self {
        $this->body[] = '<w:p><w:pPr><w:spacing w:after="60"/><w:ind w:left="360"/></w:pPr>'
            . $this->run('•  ' . $t) . '</w:p>';
        return $this;
    }
    public function pageBreak(): self {
        $this->body[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        return $this;
    }

    /**
     * @param string[] $headers
     * @param array<int,array<int,string>> $rows
     * @param int[] $widths relative weights
     * @param array<int,string> $fills background fill per row (hex, no #), row index = data row index
     */
    public function table(array $headers, array $rows, array $widths = [], array $fills = []): self {
        $n = max(1, count($headers));
        if (!$widths) $widths = array_fill(0, $n, 1);
        $total = array_sum($widths);
        $cols = array_map(fn($w) => (int)round(self::CONTENT_WIDTH * $w / $total), $widths);

        $tbl = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblLayout w:type="fixed"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $b) {
            $tbl .= '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="B7C1C8"/>';
        }
        $tbl .= '</w:tblBorders><w:tblCellMar>'
            . '<w:top w:w="60" w:type="dxa"/><w:left w:w="90" w:type="dxa"/>'
            . '<w:bottom w:w="60" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar></w:tblPr>';

        $tbl .= '<w:tblGrid>';
        foreach ($cols as $c) $tbl .= '<w:gridCol w:w="' . $c . '"/>';
        $tbl .= '</w:tblGrid>';

        $tbl .= $this->row($headers, $cols, ['fill' => '1F3864', 'bold' => 1, 'color' => 'FFFFFF']);
        foreach (array_values($rows) as $i => $row) {
            $opt = [];
            if (isset($fills[$i])) $opt['fill'] = $fills[$i];
            $tbl .= $this->row(array_values($row), $cols, $opt);
        }
        $tbl .= '</w:tbl>';
        $this->body[] = $tbl;
        // Word requires a paragraph between consecutive tables / before sectPr
        $this->body[] = '<w:p><w:pPr><w:spacing w:after="60"/></w:pPr></w:p>';
        return $this;
    }

    private function row(array $cells, array $cols, array $opt = []): string {
        $tr = '<w:tr>';
        foreach (array_values($cells) as $i => $cell) {
            $w = $cols[$i] ?? 1000;
            $shd = !empty($opt['fill']) ? '<w:shd w:val="clear" w:color="auto" w:fill="' . self::x($opt['fill']) . '"/>' : '';
            $fmt = [
                'bold' => $opt['bold'] ?? 0,
                'color' => $opt['color'] ?? '1B2630',
                'size' => $opt['size'] ?? 18,
            ];
            $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . $shd . '</w:tcPr>'
                . '<w:p><w:pPr><w:spacing w:before="20" w:after="20"/></w:pPr>'
                . $this->run((string)$cell, $fmt) . '</w:p></w:tc>';
        }
        return $tr . '</w:tr>';
    }

    public function save(string $path): void {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $body = implode('', $this->body);
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . self::W . '"><w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1080" w:right="1440" w:bottom="1080" w:left="1440" w:header="720" w:footer="720"/>'
            . '</w:sectPr></w:body></w:document>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="' . self::W . '"><w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="21"/><w:szCs w:val="21"/>'
            . '</w:rPr></w:rPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '</w:styles>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        if (is_file($path)) unlink($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa membuat file DOCX di ' . $path);
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->addFromString('word/_rels/document.xml.rels', $docRels);
        $zip->close();
    }
}
