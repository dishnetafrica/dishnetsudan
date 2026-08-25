<?php
/**
 * Lightweight XLSX reader — ZipArchive + SimpleXML (standard PHP).
 * Handles default namespace via xpath with registered prefix.
 */
class XlsxReader
{
    const NS     = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const NS_R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const NS_PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Read xlsx, return ['SheetName' => [[row0], [row1], ...]]
     * Row 0 is the header. Values are strings or null.
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open xlsx: ' . $path);
        }

        // 1. Shared strings
        $ss = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $sx = simplexml_load_string($ssXml);
            $sx->registerXPathNamespace('ns', self::NS);
            foreach ($sx->xpath('//ns:si') as $si) {
                $si->registerXPathNamespace('ns', self::NS);
                $text = '';
                foreach ($si->xpath('.//ns:t') as $t) {
                    $text .= (string)$t;
                }
                $ss[] = $text;
            }
        }

        // 2. Sheet rId -> filename
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relsX   = simplexml_load_string($relsXml);
        $relsX->registerXPathNamespace('pkg', self::NS_PKG);
        $ridMap  = [];
        foreach ($relsX->xpath('//pkg:Relationship') as $rel) {
            $ridMap[(string)$rel['Id']] = 'xl/' . (string)$rel['Target'];
        }

        // 3. Sheet list from workbook
        $wbXml = $zip->getFromName('xl/workbook.xml');
        $wbX   = simplexml_load_string($wbXml);
        $wbX->registerXPathNamespace('ns', self::NS);
        $wbX->registerXPathNamespace('r', self::NS_R);

        $result = [];
        foreach ($wbX->xpath('//ns:sheet') as $sheet) {
            $name = (string)$sheet['name'];
            $rid  = (string)$sheet->attributes(self::NS_R)['id'];
            $file = $ridMap[$rid] ?? null;
            if (!$file) continue;

            $sheetXml = $zip->getFromName($file);
            if (!$sheetXml) continue;

            $sx = simplexml_load_string($sheetXml);
            $sx->registerXPathNamespace('ns', self::NS);

            $rows = [];
            foreach ($sx->xpath('//ns:sheetData/ns:row') as $row) {
                $row->registerXPathNamespace('ns', self::NS);
                $rowData = [];
                $prevCol = -1;
                foreach ($row->xpath('ns:c') as $cell) {
                    $ref    = (string)$cell['r'];
                    $colIdx = self::colIndex($ref);
                    // Fill gaps
                    while (++$prevCol < $colIdx) {
                        $rowData[] = null;
                    }
                    $type = (string)$cell['t'];
                    $vNode = $cell->xpath('ns:v');
                    $v = !empty($vNode) ? (string)$vNode[0] : null;

                    if ($v === null || $v === '') {
                        $rowData[] = null;
                    } elseif ($type === 's') {
                        $rowData[] = $ss[(int)$v] ?? '';
                    } else {
                        $rowData[] = $v; // numeric or formula cached value
                    }
                }
                $rows[] = $rowData;
            }
            $result[$name] = $rows;
        }

        $zip->close();
        return $result;
    }

    /** Excel serial date -> Y-m-d */
    public static function excelDate(string $serial): ?string
    {
        if (!is_numeric($serial)) return null;
        $n = (int)$serial;
        if ($n < 1) return null;
        if ($n >= 60) $n--; // Lotus 1-2-3 leap year bug
        $ts = mktime(0, 0, 0, 1, 1, 1900) + ($n - 1) * 86400;
        return date('Y-m-d', $ts);
    }

    /** "A1" -> 0, "B3" -> 1, "AA5" -> 26 */
    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Za-z]+)/', $ref, $m);
        $col = strtoupper($m[1] ?? 'A');
        $idx = 0;
        foreach (str_split($col) as $ch) {
            $idx = $idx * 26 + (ord($ch) - 64);
        }
        return $idx - 1;
    }
}
