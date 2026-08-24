<?php
/**
 * Agents master + "latest update" email to agent
 */

function ensureAgentsTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `agents` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `agent_name` VARCHAR(255) NOT NULL,
            `emails` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_agents_name` (`agent_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureAgentsTable: ' . $e->getMessage());
    }
    try {
        $cols = [];
        foreach ($db->query("SHOW COLUMNS FROM surveys")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cols[strtolower((string)$c)] = true;
        }
        if (empty($cols['agent_email_sent_at'])) {
            $db->exec("ALTER TABLE surveys ADD COLUMN agent_email_sent_at DATETIME NULL DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('ensureAgentsTable surveys col: ' . $e->getMessage());
    }
    $done = true;
}

/** Normalize name for lookup */
function normalizeAgentName(string $name): string {
    return trim(preg_replace('/\s+/', ' ', $name));
}

/** Parse emails from string (comma / semicolon / newline) */
function parseAgentEmails(?string $raw): array {
    if ($raw === null || trim($raw) === '') return [];
    $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = strtolower(trim($p));
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL) && !in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return $out;
}

function formatAgentEmails(array $emails): string {
    return implode(', ', $emails);
}

function findAgentByName(PDO $db, string $agentName): ?array {
    ensureAgentsTable($db);
    $name = normalizeAgentName($agentName);
    if ($name === '') return null;
    try {
        $stmt = $db->prepare("SELECT * FROM agents WHERE LOWER(agent_name) = LOWER(?) LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function upsertAgentEmails(PDO $db, string $agentName, array $emails): ?array {
    ensureAgentsTable($db);
    $name = normalizeAgentName($agentName);
    $emails = array_values(array_unique(array_map('strtolower', $emails)));
    $emails = array_filter($emails, fn($e) => (bool)filter_var($e, FILTER_VALIDATE_EMAIL));
    if ($name === '' || empty($emails)) return null;
    $emailStr = formatAgentEmails($emails);
    try {
        $existing = findAgentByName($db, $name);
        if ($existing) {
            // merge with existing
            $merged = array_values(array_unique(array_merge(parseAgentEmails($existing['emails'] ?? ''), $emails)));
            $emailStr = formatAgentEmails($merged);
            $db->prepare("UPDATE agents SET emails = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$emailStr, (int)$existing['id']]);
            $existing['emails'] = $emailStr;
            return $existing;
        }
        $db->prepare("INSERT INTO agents (agent_name, emails) VALUES (?, ?)")->execute([$name, $emailStr]);
        $id = (int)$db->lastInsertId();
        return ['id' => $id, 'agent_name' => $name, 'emails' => $emailStr];
    } catch (Throwable $e) {
        error_log('upsertAgentEmails: ' . $e->getMessage());
        return null;
    }
}

function getAgentEmailsForSurvey(PDO $db, string $agentName): array {
    $row = findAgentByName($db, $agentName);
    if (!$row) return [];
    return parseAgentEmails($row['emails'] ?? '');
}

/**
 * Send latest-update email to agent(s). Uses system SMTP; Reply-To = admin profile email.
 */
function sendAgentLatestUpdateEmail(PDO $db, array $survey, array $toEmails, array $adminUser = []): array {
    if (!function_exists('sendHtmlEmail')) {
        require_once __DIR__ . '/mailer.php';
    }
    $toEmails = array_values(array_filter($toEmails, fn($e) => (bool)filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (empty($toEmails)) {
        return ['ok' => false, 'message' => 'No valid agent email.'];
    }

    $vessel = trim((string)($survey['vessel_name'] ?? 'Vessel'));
    $port = trim((string)($survey['port_name'] ?? ''));
    $client = trim((string)($survey['company_name'] ?? ''));
    $agent = trim((string)($survey['agent_name'] ?? ''));
    $types = trim((string)($survey['type_name'] ?? ''));
    if ($types === '' && !empty($survey['survey_type_ids']) && function_exists('getCombinedSurveyTypeNames')) {
        try {
            $types = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'], '');
        } catch (Throwable $e) {}
    }
    if ($types === '') {
        $types = 'survey';
    }

    $adminName = trim((string)($adminUser['full_name'] ?? 'Survey Coordination'));
    if ($adminName === '') {
        $adminName = 'Survey Coordination';
    }
    $adminEmail = trim((string)($adminUser['email'] ?? ''));
    $adminPhone = trim((string)($adminUser['phone'] ?? ''));

    // Subject: Latest update of {Vessel} -{Survey Type} - {Port} - {client}
    $subjectParts = array_filter([
        $vessel !== '' ? $vessel : null,
        $types !== '' ? $types : null,
        $port !== '' ? $port : null,
        $client !== '' ? $client : null,
    ]);
    $subject = 'Latest update of ' . implode(' - ', $subjectParts);

    $safe = static function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    $phoneLine = $adminPhone !== '' ? $safe($adminPhone) . '<br>' : '';
    $phoneAlt = $adminPhone !== '' ? $adminPhone . "\n" : '';

    $html = '
    <div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#0f172a;line-height:1.55;max-width:560px;">
        <p>Dear sir/madam,</p>
        <p>Good day to you,</p>
        <p>We have been appointed by <strong>' . $safe($client !== '' ? $client : 'our client') . '</strong>,
        to conduct <strong>' . $safe($types) . '</strong> at <strong>' . $safe($port !== '' ? $port : 'the port') . '</strong>,
        in this regard, please advise the latest ETA / Berthing Schedule and approx. port stay.</p>
        <p style="margin-top:22px;">Thanks &amp; Regards,<br>
        <strong>' . $safe($adminName) . '</strong><br>
        ' . $phoneLine . '
        SURVEY COORDINATION TEAM<br>
        YMR MARINE SOLUTIONS LLP</p>
    </div>';

    $alt = "Dear sir/madam,\nGood day to you,\n\n"
        . "We have been appointed by " . ($client !== '' ? $client : 'our client')
        . ", to conduct " . $types
        . " at " . ($port !== '' ? $port : 'the port')
        . ", in this regard, please advise the latest ETA / Berthing Schedule and approx. port stay.\n\n"
        . "Thanks & Regards,\n"
        . $adminName . "\n"
        . $phoneAlt
        . "SURVEY COORDINATION TEAM\n"
        . "YMR MARINE SOLUTIONS LLP";

    $options = [
        'from_name' => $adminName,
        'reply_to_email' => $adminEmail,
        'reply_to_name' => $adminName,
    ];

    $primary = array_shift($toEmails);
    $cc = $toEmails; // remaining as CC
    $ok = sendHtmlEmail($primary, $agent !== '' ? $agent : 'Agent', $subject, $html, $alt, $cc, [], $options);
    if (!$ok) {
        $err = $GLOBALS['ysms_last_mail_error'] ?? 'SMTP send failed';
        return ['ok' => false, 'message' => $err];
    }
    return ['ok' => true, 'message' => 'Email sent to ' . $primary . (count($cc) ? ' (+' . count($cc) . ' CC)' : '')];
}
