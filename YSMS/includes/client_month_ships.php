<?php
/**
 * Client-wise Ships per Month (dashboard table and its Excel download): ships attended
 * (Pending Report + Completed) per client and month of the Survey Completed Date — the
 * dashboard's month rule, so a vessel without that date is in no month.
 */

/** [year => [client name => [month 1..12 => ships]]], newest year first, clients A–Z. */
function clientMonthShips(PDO $db): array {
    $cd = surveyCompletedDateSql('s');
    $out = [];
    $q = $db->query("
        SELECT COALESCE(NULLIF(TRIM(c.company_name), ''), 'Client not recorded') AS client,
               YEAR($cd) AS y, MONTH($cd) AS m, COUNT(*) AS n
        FROM surveys s LEFT JOIN clients c ON c.id = s.client_id
        WHERE s.status IN ('Pending Report','Completed') AND $cd IS NOT NULL
        GROUP BY client, y, m");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['y']][(string)$r['client']][(int)$r['m']] = (int)$r['n'];
    krsort($out);
    foreach ($out as &$year) uksort($year, 'strcasecmp');
    unset($year);
    return $out;
}

const CLIENT_MONTH_LABELS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
