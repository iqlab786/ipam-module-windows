<?php
declare(strict_types = 1);

namespace Modules\IPAMPro\Includes\IpamPro;

final class CsvExporter {
	public function stream(string $filename, array $rows): void {
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="'.$filename.'"');

		$out = fopen('php://output', 'wb');
		if (!$out) {
			return;
		}

		if ($rows) {
			fputcsv($out, array_keys($rows[0]));
			foreach ($rows as $row) {
				fputcsv($out, $row);
			}
		}

		fclose($out);
	}
}
