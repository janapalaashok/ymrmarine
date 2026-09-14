<?php
/**
 * Email Auto-Fill for the Assign Vessel form (assign_vessel.php).
 *
 * Two extraction paths, chosen automatically by ajax/email_autofill.php
 * based on whether an Anthropic API key is configured:
 *
 *   - AI path (extractSurveyInfoFromEmail + matchExtractedInfoToFormOptions):
 *     asks Claude to pull plain TEXT values out of the email (it never sees
 *     or guesses at database IDs), then fuzzy-matches that text against the
 *     real clients/ports/survey_types for this form. Handles free-form prose
 *     well; costs a small per-call API fee.
 *   - Rule-based path (extractSurveyInfoRuleBased): no API, no cost, no
 *     setup. Scans the email directly for occurrences of your *actual*
 *     client/port/survey-type names (so a match is only ever a real,
 *     existing option — nothing to "unmatch" afterward) plus label-based
 *     regexes ("Vessel: ...", "MV ..." etc.) for the free-text fields
 *     (Vessel Name, Agent Name) that have no fixed list to scan against.
 *     Reliable for clearly labelled emails and for prose that names a
 *     vessel/port/client already known to the system; less reliable for
 *     heavily paraphrased free text than the AI path.
 *
 * Both paths return the same shape (see matchExtractedInfoToFormOptions's
 * docblock) so ajax/email_autofill.php and the frontend don't need to know
 * which one ran. Nothing here is called from the page itself.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AnthropicException;

class EmailAutoFillException extends Exception
{
}

/**
 * Calls Claude to extract survey-assignment fields from raw pasted email
 * text. Returns the schema-validated array below, or throws
 * EmailAutoFillException with a user-safe message on any failure — the
 * caller (ajax/email_autofill.php) is responsible for catching it and never
 * lets a failure here lose the pasted email or touch existing form data.
 *
 * @return array{
 *   vessel_name: ?string,
 *   vessel_name_candidates: string[],
 *   client_name: ?string,
 *   agent_name: ?string,
 *   port_name: ?string,
 *   port_name_candidates: string[],
 *   survey_types: string[],
 *   remarks: ?string,
 *   unclear_or_conflicting: string[]
 * }
 */
function extractSurveyInfoFromEmail(string $emailText): array
{
    if (trim(ANTHROPIC_API_KEY) === '') {
        throw new EmailAutoFillException('Email Auto-Fill is not configured yet. Please contact an admin to set it up.');
    }

    $schema = [
        'type' => 'object',
        'properties' => [
            'vessel_name' => [
                'type' => ['string', 'null'],
                'description' => 'The single vessel name this survey request is for (e.g. "MV Pacific Dawn"), with any "MV"/"M.V."/"Ship" prefix kept as written. Null if no vessel name is found, or if several vessels are mentioned and it is not clear which one this specific request is for.',
            ],
            'vessel_name_candidates' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Every distinct vessel name mentioned anywhere in the email, in first-mentioned order. Empty array if none found.',
            ],
            'client_name' => [
                'type' => ['string', 'null'],
                'description' => 'The client/owner/charterer/principal company requesting or on whose behalf this survey is arranged. Null if not stated.',
            ],
            'agent_name' => [
                'type' => ['string', 'null'],
                'description' => 'The local/port/husbanding agent handling the vessel\'s port call (not the survey company itself). Null if not stated.',
            ],
            'port_name' => [
                'type' => ['string', 'null'],
                'description' => 'The single port/place/location where the survey will take place. Null if not stated, or if several ports are mentioned and it is unclear which one applies to this request.',
            ],
            'port_name_candidates' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Every distinct port/location mentioned anywhere in the email, in first-mentioned order. Empty array if none found.',
            ],
            'survey_types' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Every distinct survey type requested, as plain text close to how it was written (e.g. "On-Hire Bunker Survey", "Draft Survey", "Condition Survey"). Empty array if none found.',
            ],
            'remarks' => [
                'type' => ['string', 'null'],
                'description' => 'Any other operationally relevant instructions or context from the email worth carrying into a free-text Remarks field (timing, special requirements, ETA, etc.) — do not repeat what is already captured in the other fields, and never include the email signature/disclaimer. Null if there is nothing extra worth noting.',
            ],
            'unclear_or_conflicting' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Short, plain-language notes about anything ambiguous, missing, or contradictory a human should double-check before submitting (e.g. "Two vessel names mentioned — unclear which this request is for."). Empty array if there is nothing to flag.',
            ],
        ],
        'required' => [
            'vessel_name', 'vessel_name_candidates', 'client_name', 'agent_name',
            'port_name', 'port_name_candidates', 'survey_types', 'remarks', 'unclear_or_conflicting',
        ],
        'additionalProperties' => false,
    ];

    $systemPrompt = <<<'SYS'
You are helping staff at YMR Marine Solutions, a marine survey company, turn a
pasted client/agent email into structured data for a survey-assignment form.

Extract ONLY information that is reasonably present in the email. Never
invent, assume, or guess a value that isn't actually supported by the text.
If something isn't stated clearly enough to be confident, leave it null (or
an empty array) rather than filling in a best guess.

Field terminology used interchangeably in real emails — treat all of these as
referring to the same concept:
- Vessel: "Vessel", "Vessel Name", "MV", "M.V.", "Ship"
- Client: "Client", "Owner", "Charterer", "Principal"
- Agent: "Agent", "Local Agent", "Port Agent", "Husbanding Agent" (this is
  NOT the survey company and NOT the client)
- Port: "Port", "Port Name", "Place", "Location"
- Survey Type: "Survey Type", "Survey", "Type of Survey", "Required Survey"

The email may be written as a clear list of labelled fields, or as free-flowing
prose describing the same information in any order — extract the meaning, not
just exact label matches.

Ignore signatures, disclaimers, letterheads, and boilerplate footer text.

If multiple vessels or multiple ports are mentioned and it is not clear which
one this specific request is about, put all of them in the "_candidates"
array and leave the singular field null — do not guess which one is meant.
Note the ambiguity in "unclear_or_conflicting".
SYS;

    $client = new Client(apiKey: ANTHROPIC_API_KEY);

    try {
        $message = $client->messages->create(
            model: ANTHROPIC_MODEL,
            maxTokens: 2048,
            system: $systemPrompt,
            messages: [
                ['role' => 'user', 'content' => "Extract survey-assignment information from this pasted email:\n\n" . $emailText],
            ],
            outputConfig: [
                'effort' => 'low', // classification/extraction — low effort is the cost-appropriate default
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $schema,
                ],
            ],
            requestOptions: ['timeout' => 25.0],
        );
    } catch (APITimeoutException $e) {
        error_log('email_autofill: Anthropic API timeout: ' . $e->getMessage());
        throw new EmailAutoFillException('The email analysis took too long. Please try again.');
    } catch (RateLimitException $e) {
        error_log('email_autofill: Anthropic API rate limited: ' . $e->getMessage());
        throw new EmailAutoFillException('Auto-Fill is busy right now. Please wait a moment and try again.');
    } catch (AuthenticationException $e) {
        error_log('email_autofill: Anthropic API authentication failed: ' . $e->getMessage());
        throw new EmailAutoFillException('Email Auto-Fill is not configured correctly. Please contact an admin.');
    } catch (APIStatusException $e) {
        error_log('email_autofill: Anthropic API error: ' . $e->getMessage());
        throw new EmailAutoFillException('Could not analyze the email right now. Please try again.');
    } catch (APIConnectionException $e) {
        error_log('email_autofill: Anthropic API connection error: ' . $e->getMessage());
        throw new EmailAutoFillException('Could not reach the Auto-Fill service. Please check your connection and try again.');
    } catch (AnthropicException $e) {
        error_log('email_autofill: Anthropic SDK error: ' . $e->getMessage());
        throw new EmailAutoFillException('Could not analyze the email right now. Please try again.');
    }

    $jsonText = null;
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $jsonText = $block->text;
            break;
        }
    }
    if ($jsonText === null || trim($jsonText) === '') {
        error_log('email_autofill: empty response from model');
        throw new EmailAutoFillException('The email could not be analyzed. Please try again or fill the form manually.');
    }

    $data = json_decode($jsonText, true);
    if (!is_array($data)) {
        error_log('email_autofill: invalid JSON in model response');
        throw new EmailAutoFillException('The email could not be analyzed. Please try again or fill the form manually.');
    }

    // Defensive normalization — never trust the model's shape blindly, even
    // though structured outputs should already guarantee it.
    $str = function ($v): ?string {
        if (!is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    };
    $strArr = function ($v) use ($str): array {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $item) {
            $s = $str($item);
            if ($s !== null) $out[] = $s;
        }
        return $out;
    };

    return [
        'vessel_name' => $str($data['vessel_name'] ?? null),
        'vessel_name_candidates' => $strArr($data['vessel_name_candidates'] ?? []),
        'client_name' => $str($data['client_name'] ?? null),
        'agent_name' => $str($data['agent_name'] ?? null),
        'port_name' => $str($data['port_name'] ?? null),
        'port_name_candidates' => $strArr($data['port_name_candidates'] ?? []),
        'survey_types' => $strArr($data['survey_types'] ?? []),
        'remarks' => $str($data['remarks'] ?? null),
        'unclear_or_conflicting' => $strArr($data['unclear_or_conflicting'] ?? []),
    ];
}

/**
 * Lowercases, strips punctuation, and drops a handful of very common
 * corporate suffixes — so "XYZ Shipping Pvt Ltd" and "xyz shipping" line up
 * as the same thing without needing an exact string match.
 */
function ymrNormalizeForMatch(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\s]/', ' ', $s) ?? $s;
    $s = preg_replace('/\b(pvt|private|ltd|limited|llc|inc|co|company|corp|corporation)\b/', ' ', $s) ?? $s;
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    return $s;
}

/**
 * Fuzzy-matches $needle against $rows[*][$field], returning the best match
 * only if it clears a reasonable confidence bar — otherwise null, so a
 * shaky guess never gets silently inserted. Exact/substring matches score
 * highest; a pure Levenshtein-style similarity is capped lower since it's
 * more likely to be a false positive between two short, unrelated names.
 */
function ymrFuzzyMatchOne(?string $needle, array $rows, string $field): ?array
{
    if ($needle === null || trim($needle) === '') {
        return null;
    }
    $needleNorm = ymrNormalizeForMatch($needle);
    if ($needleNorm === '') {
        return null;
    }

    $best = null;
    $bestScore = 0.0;
    foreach ($rows as $row) {
        $hay = (string)($row[$field] ?? '');
        $hayNorm = ymrNormalizeForMatch($hay);
        if ($hayNorm === '') {
            continue;
        }

        if ($hayNorm === $needleNorm) {
            $score = 1.0;
        } elseif (strpos($hayNorm, $needleNorm) !== false || strpos($needleNorm, $hayNorm) !== false) {
            $shorter = min(strlen($needleNorm), strlen($hayNorm));
            $longer = max(strlen($needleNorm), strlen($hayNorm), 1);
            $score = 0.75 + 0.2 * ($shorter / $longer);
        } else {
            similar_text($needleNorm, $hayNorm, $pct);
            $score = ($pct / 100.0) * 0.85;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $row;
        }
    }

    if ($best !== null && $bestScore >= 0.55) {
        return ['row' => $best, 'score' => round($bestScore, 2)];
    }
    return null;
}

/**
 * Matches the raw text extracted from the email against the real clients /
 * ports / survey_types already loaded for this form (same arrays the page
 * itself renders its dropdowns from), so nothing gets pre-filled unless it
 * maps to an option that genuinely exists.
 *
 * @param array $extracted   Return value of extractSurveyInfoFromEmail()
 * @param array $clients     Rows with at least id, company_name
 * @param array $ports       Rows with at least id, port_name
 * @param array $surveyTypes Rows with at least id, type_name
 */
function matchExtractedInfoToFormOptions(array $extracted, array $clients, array $ports, array $surveyTypes): array
{
    $clientMatch = ymrFuzzyMatchOne($extracted['client_name'], $clients, 'company_name');
    $portMatch = ymrFuzzyMatchOne($extracted['port_name'], $ports, 'port_name');

    $matchedTypes = [];
    $unmatchedTypes = [];
    $seenIds = [];
    foreach ($extracted['survey_types'] as $rawType) {
        $m = ymrFuzzyMatchOne($rawType, $surveyTypes, 'type_name');
        if ($m !== null) {
            $id = (int)$m['row']['id'];
            if (!in_array($id, $seenIds, true)) {
                $seenIds[] = $id;
                $matchedTypes[] = ['id' => $id, 'name' => $m['row']['type_name'], 'raw' => $rawType, 'score' => $m['score']];
            }
        } else {
            $unmatchedTypes[] = $rawType;
        }
    }

    return [
        'vessel_name' => $extracted['vessel_name'],
        'vessel_name_candidates' => $extracted['vessel_name_candidates'],
        'agent_name' => $extracted['agent_name'],
        'remarks' => $extracted['remarks'],
        'unclear_or_conflicting' => $extracted['unclear_or_conflicting'],
        'client' => $clientMatch ? [
            'id' => (int)$clientMatch['row']['id'],
            'name' => $clientMatch['row']['company_name'],
            'raw' => $extracted['client_name'],
            'score' => $clientMatch['score'],
        ] : null,
        'client_raw_unmatched' => $clientMatch ? null : $extracted['client_name'],
        'port' => $portMatch ? [
            'id' => (int)$portMatch['row']['id'],
            'name' => $portMatch['row']['port_name'],
            'raw' => $extracted['port_name'],
            'score' => $portMatch['score'],
        ] : null,
        'port_raw_unmatched' => $portMatch ? null : $extracted['port_name'],
        'port_name_candidates' => $extracted['port_name_candidates'],
        'survey_types' => $matchedTypes,
        'survey_types_unmatched' => $unmatchedTypes,
    ];
}

/**
 * Finds the value following the first matching label at the start of a
 * line — "Vessel: MV ABC", "Vessel Name - MV ABC", case-insensitive — for
 * free-text fields that have no fixed list to scan against. Tries labels in
 * the given order and returns the first hit, so callers should list more
 * specific labels (e.g. "husbanding agent") before generic ones ("agent").
 */
function ymrExtractByLabel(string $text, array $labels): ?string
{
    foreach ($labels as $label) {
        $pattern = '/^[ \t]*\b' . preg_quote($label, '/') . '\b[ \t]*[:\-][ \t]*(.+)$/mi';
        if (preg_match($pattern, $text, $m)) {
            $val = trim($m[1], " \t\n\r\0\x0B.,;");
            if ($val !== '') {
                return $val;
            }
        }
    }
    return null;
}

/**
 * Catches a vessel name written inline in prose rather than as a labelled
 * field — "...survey for MV ABC at..." — by matching an MV/M.V./M-V/Ship
 * prefix followed by 1-5 capitalized words, keeping the prefix as written
 * (the app's own normalizeVesselName() reconciles the exact prefix form at
 * submit time, so this doesn't need to).
 */
function ymrExtractVesselFromProse(string $text): ?string
{
    if (preg_match('/\b(?:M\.?\s?\/?\s?V\.?|Ship)\s+([A-Z][A-Za-z0-9]*(?:[\s\-][A-Z][A-Za-z0-9]*){0,4})/u', $text, $m)) {
        return trim($m[0]);
    }
    return null;
}

/**
 * Scans $text for any occurrence of $rows[*][$field] (normalized the same
 * way as ymrFuzzyMatchOne, whole-word/phrase match only) and returns every
 * distinct row found, longest name first — so "Mundra Port" is preferred
 * over a shorter row that happens to be a substring of it. This is the core
 * of the no-API extraction path: since $rows is the real, closed list of
 * clients/ports/survey_types, a hit here is always a genuinely valid
 * option, with nothing left to fuzzy-match afterward.
 */
function ymrScanTextForEntities(string $text, array $rows, string $field): array
{
    $textNorm = ' ' . ymrNormalizeForMatch($text) . ' ';
    $candidates = [];
    foreach ($rows as $row) {
        $name = (string)($row[$field] ?? '');
        $nameNorm = ymrNormalizeForMatch($name);
        // Skip names too short to match reliably (avoids e.g. a 2-letter
        // port code matching almost anything).
        if (mb_strlen($nameNorm) < 3) {
            continue;
        }
        if (preg_match('/(?<=\s)' . preg_quote($nameNorm, '/') . '(?=\s)/u', $textNorm)) {
            $candidates[] = ['row' => $row, 'len' => mb_strlen($nameNorm)];
        }
    }
    usort($candidates, fn($a, $b) => $b['len'] <=> $a['len']);
    return array_map(fn($c) => $c['row'], $candidates);
}

/**
 * Same idea as ymrScanTextForEntities but for a single best match (client,
 * port) — returns null if nothing in the real list appears in the text.
 */
function ymrScanTextForEntity(string $text, array $rows, string $field): ?array
{
    $matches = ymrScanTextForEntities($text, $rows, $field);
    return $matches[0] ?? null;
}

/**
 * No-API-key extraction path — pure PHP, no external call, no cost. See the
 * file-level docblock for how this differs from the AI path. Returns the
 * same shape as matchExtractedInfoToFormOptions().
 */
function extractSurveyInfoRuleBased(string $emailText, array $clients, array $ports, array $surveyTypes): array
{
    $vesselName = ymrExtractByLabel($emailText, ['vessel name', 'vessel', 'ship name', 'ship'])
        ?? ymrExtractVesselFromProse($emailText);
    $agentName = ymrExtractByLabel($emailText, ['husbanding agent', 'husband agent', 'port agent', 'local agent', 'agent name', 'agent']);

    $portRows = ymrScanTextForEntities($emailText, $ports, 'port_name');
    $portDistinct = [];
    foreach ($portRows as $r) {
        if (!in_array($r['port_name'], $portDistinct, true)) $portDistinct[] = $r['port_name'];
    }
    $port = null;
    $portCandidates = [];
    if (count($portDistinct) === 1) {
        $port = ['id' => (int)$portRows[0]['id'], 'name' => $portRows[0]['port_name'], 'raw' => $portRows[0]['port_name'], 'score' => 1.0];
    } elseif (count($portDistinct) > 1) {
        $portCandidates = $portDistinct;
    }
    $portRawUnmatched = null;
    if (!$port && !$portCandidates) {
        // The DB-scan above only catches the port's full name written out
        // (e.g. "Mundra Port"); a labelled but abbreviated value ("Port:
        // Mundra") needs the same fuzzy match the AI path uses.
        $portLabelRaw = ymrExtractByLabel($emailText, ['port name', 'port', 'place', 'location']);
        $fuzzyPort = ymrFuzzyMatchOne($portLabelRaw, $ports, 'port_name');
        if ($fuzzyPort !== null) {
            $port = ['id' => (int)$fuzzyPort['row']['id'], 'name' => $fuzzyPort['row']['port_name'], 'raw' => $portLabelRaw, 'score' => $fuzzyPort['score']];
        } else {
            $portRawUnmatched = $portLabelRaw;
        }
    }

    $clientRows = !empty($clients) ? ymrScanTextForEntities($emailText, $clients, 'company_name') : [];
    $client = $clientRows ? ['id' => (int)$clientRows[0]['id'], 'name' => $clientRows[0]['company_name'], 'raw' => $clientRows[0]['company_name'], 'score' => 1.0] : null;
    $clientRawUnmatched = null;
    if (!$client) {
        $clientLabelRaw = ymrExtractByLabel($emailText, ['client name', 'client', 'owner', 'charterer', 'principal']);
        $fuzzyClient = ymrFuzzyMatchOne($clientLabelRaw, $clients, 'company_name');
        if ($fuzzyClient !== null) {
            $client = ['id' => (int)$fuzzyClient['row']['id'], 'name' => $fuzzyClient['row']['company_name'], 'raw' => $clientLabelRaw, 'score' => $fuzzyClient['score']];
        } else {
            $clientRawUnmatched = $clientLabelRaw;
        }
    }

    $surveyRows = ymrScanTextForEntities($emailText, $surveyTypes, 'type_name');
    $matchedTypes = [];
    $seenIds = [];
    foreach ($surveyRows as $row) {
        $id = (int)$row['id'];
        if (!in_array($id, $seenIds, true)) {
            $seenIds[] = $id;
            $matchedTypes[] = ['id' => $id, 'name' => $row['type_name'], 'raw' => $row['type_name'], 'score' => 1.0];
        }
    }
    // Same abbreviated-label gap as port/client above — "Survey: On-Hire
    // Bunker" (without the word "Survey") won't phrase-match the DB scan,
    // so fuzzy-match each comma/"+"/"and"-separated piece of a labelled
    // value as a fallback when the scan found nothing at all.
    if (empty($matchedTypes)) {
        $surveyLabelRaw = ymrExtractByLabel($emailText, ['survey type', 'type of survey', 'required survey', 'survey']);
        if ($surveyLabelRaw !== null) {
            foreach (preg_split('/\s*(?:\+|,|&|\band\b)\s*/i', $surveyLabelRaw) as $piece) {
                $piece = trim($piece);
                if ($piece === '') continue;
                $fuzzyType = ymrFuzzyMatchOne($piece, $surveyTypes, 'type_name');
                if ($fuzzyType !== null) {
                    $id = (int)$fuzzyType['row']['id'];
                    if (!in_array($id, $seenIds, true)) {
                        $seenIds[] = $id;
                        $matchedTypes[] = ['id' => $id, 'name' => $fuzzyType['row']['type_name'], 'raw' => $piece, 'score' => $fuzzyType['score']];
                    }
                }
            }
        }
    }

    $notes = [];
    if (count($portDistinct) > 1) {
        $notes[] = 'Multiple ports mentioned (' . implode(', ', $portDistinct) . ') — unclear which one this request is for.';
    }

    return [
        'vessel_name' => $vesselName,
        'vessel_name_candidates' => $vesselName ? [$vesselName] : [],
        'agent_name' => $agentName,
        'remarks' => null, // free-text remarks aren't reliably extractable without an LLM — left blank rather than guessed
        'unclear_or_conflicting' => $notes,
        'client' => $client,
        'client_raw_unmatched' => $clientRawUnmatched,
        'port' => $port,
        'port_raw_unmatched' => $portRawUnmatched,
        'port_name_candidates' => $portCandidates,
        'survey_types' => $matchedTypes,
        'survey_types_unmatched' => [],
    ];
}
