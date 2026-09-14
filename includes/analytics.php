<?php
/**
 * Lightweight, dependency-free visit tracking for the public site, feeding
 * the admin-only Analytics dashboard (admin/analytics.php).
 *
 * Country relies on a CDN/proxy passing a country header (Cloudflare's
 * CF-IPCountry, or Google App Engine's equivalent) — without one of those in
 * front of the domain, country stays "Unknown" for every visit. There is no
 * outbound GeoIP API call here on purpose: this runs on every single
 * pageview, and a per-request external HTTP call would add latency and a
 * new failure mode to every page load.
 */

function ensureSiteVisitsTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_visits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            visited_at DATETIME NOT NULL,
            visit_date DATE NOT NULL,
            page_path VARCHAR(255) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            user_agent VARCHAR(512) NULL,
            device_type VARCHAR(20) NOT NULL DEFAULT 'Desktop',
            country VARCHAR(100) NOT NULL DEFAULT 'Unknown',
            visitor_id VARCHAR(64) NOT NULL,
            referrer VARCHAR(255) NULL,
            KEY idx_visited_at (visited_at),
            KEY idx_visit_date (visit_date),
            KEY idx_visitor (visitor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureSiteVisitsTable: ' . $e->getMessage());
    }
}

function ymrDetectDeviceType(string $ua): string
{
    $ua = strtolower($ua);
    if ($ua === '') {
        return 'Desktop';
    }
    if (preg_match('/ipad|tablet|kindle|playbook|silk|nexus 7|nexus 9|nexus 10/', $ua)) {
        return 'Tablet';
    }
    if (preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|windows phone|webos/', $ua)) {
        return 'Mobile';
    }
    return 'Desktop';
}

function ymrIsLikelyBot(string $ua): bool
{
    if ($ua === '') {
        return true;
    }
    return (bool)preg_match(
        '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegrambot|bingpreview|pingdom|uptimerobot|curl\/|wget\/|python-requests|headlesschrome|monitor/i',
        $ua
    );
}

function ymrDetectCountry(): string
{
    foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_COUNTRY_CODE'] as $header) {
        $v = strtoupper(trim((string)($_SERVER[$header] ?? '')));
        if ($v !== '' && $v !== 'XX' && $v !== 'T1') {
            return $v;
        }
    }
    return 'Unknown';
}

/** ISO 3166-1 alpha-2 -> display name, for the analytics dashboard. Falls
 * back to the raw code for anything not in this (deliberately non-exhaustive,
 * common-countries) list, so an unmapped code still displays instead of
 * breaking. */
function ymrCountryName(string $code): string
{
    if ($code === '' || $code === 'Unknown') {
        return 'Unknown';
    }
    static $names = [
        'IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'AE' => 'UAE',
        'SG' => 'Singapore', 'CN' => 'China', 'JP' => 'Japan', 'KR' => 'South Korea',
        'DE' => 'Germany', 'FR' => 'France', 'NL' => 'Netherlands', 'IT' => 'Italy',
        'ES' => 'Spain', 'PT' => 'Portugal', 'GR' => 'Greece', 'RU' => 'Russia',
        'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'BR' => 'Brazil',
        'MX' => 'Mexico', 'ZA' => 'South Africa', 'EG' => 'Egypt', 'NG' => 'Nigeria',
        'KE' => 'Kenya', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'KW' => 'Kuwait',
        'OM' => 'Oman', 'BH' => 'Bahrain', 'IL' => 'Israel', 'TR' => 'Turkey',
        'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka', 'NP' => 'Nepal',
        'MM' => 'Myanmar', 'TH' => 'Thailand', 'VN' => 'Vietnam', 'MY' => 'Malaysia',
        'ID' => 'Indonesia', 'PH' => 'Philippines', 'HK' => 'Hong Kong', 'TW' => 'Taiwan',
        'PL' => 'Poland', 'UA' => 'Ukraine', 'SE' => 'Sweden', 'NO' => 'Norway',
        'DK' => 'Denmark', 'FI' => 'Finland', 'BE' => 'Belgium', 'CH' => 'Switzerland',
        'AT' => 'Austria', 'IE' => 'Ireland', 'CY' => 'Cyprus', 'MT' => 'Malta',
        'PA' => 'Panama', 'LR' => 'Liberia', 'MH' => 'Marshall Islands', 'BS' => 'Bahamas',
        'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'PE' => 'Peru',
    ];
    return $names[$code] ?? $code;
}

/**
 * Call once, early, on every public-facing page. GET-only (so form POSTs
 * don't double-count), skips known bots/crawlers, and never lets a tracking
 * failure break the page — any error is swallowed and logged.
 */
function trackVisit(PDO $pdo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (ymrIsLikelyBot($ua)) {
        return;
    }

    try {
        ensureSiteVisitsTable($pdo);

        // Long-lived anonymous cookie so repeat visits from the same browser
        // count as one "unique visitor" rather than one per pageview.
        if (empty($_COOKIE['ymr_vid'])) {
            $vid = bin2hex(random_bytes(16));
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie('ymr_vid', $vid, [
                'expires' => time() + 60 * 60 * 24 * 365 * 2,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $isHttps,
            ]);
        } else {
            $vid = (string)$_COOKIE['ymr_vid'];
        }

        $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        date_default_timezone_set('Asia/Kolkata');
        $stmt = $pdo->prepare(
            "INSERT INTO site_visits
                (visited_at, visit_date, page_path, ip_address, user_agent, device_type, country, visitor_id, referrer)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            date('Y-m-d H:i:s'),
            date('Y-m-d'),
            substr($path, 0, 255),
            substr($ip, 0, 64),
            substr($ua, 0, 512),
            ymrDetectDeviceType($ua),
            ymrDetectCountry(),
            $vid,
            substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('trackVisit: ' . $e->getMessage());
    }
}
