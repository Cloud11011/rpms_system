<?php
/** Spreadsheet-formula protection shared with the established IERB CSV format. */
function csv_safe($value): string
{
    $s = (string)($value ?? '');
    return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $s : $s;
}

/** Stream an authorized dataset; never create a persistent export file. */
function prism_stream_csv(string $filename, array $columns, iterable $rows, callable $cells): int
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $put = static fn(array $values) => fputcsv($out, array_map(static fn($v) => csv_safe($v), $values), ',', '"', '');
    $put($columns);
    $count = 0;
    foreach ($rows as $row) { $put($cells($row)); $count++; }
    fclose($out);
    return $count;
}
