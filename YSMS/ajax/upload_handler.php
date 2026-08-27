<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../includes/upload_validation.php';
checkAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $survey_id = (int)$_POST['survey_id'];
    $current_status = $_POST['current_status'];
    $target_dir = __DIR__ . "/../uploads/";
    
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $db = getDB();

// Ensure date columns hold time (DATETIME)
try {
    $db->exec("ALTER TABLE surveys MODIFY COLUMN assign_date DATETIME NULL");
    $db->exec("ALTER TABLE surveys MODIFY COLUMN survey_completed_date DATETIME NULL");
    $db->exec("ALTER TABLE surveys MODIFY COLUMN report_uploaded_date DATETIME NULL");
} catch (Exception $e) { /* ignore if already datetime or no permission */ }
    require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';
    $files_uploaded = 0;
    $uploaded_labels = [];
    $uploaded_attachments = []; // full paths for email attachments

    // అప్‌లోడ్ అయ్యే ఫైల్ ఎక్స్‌టెన్షన్ అనుమతించదగినదో కాదో చెక్ చేసే హెల్పర్ (మాలిషియస్ స్క్రిప్ట్ అప్‌లోడ్ ఆపడానికి)
    $isAllowedExt = function ($filename, $allowed) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $allowed, true);
    };

    // 1. PDF ప్రాసెసింగ్
    if (isset($_FILES['pdf_report']) && $_FILES['pdf_report']['error'] == 0 && $isAllowedExt($_FILES['pdf_report']['name'], ['pdf'])
        && upload_validate($_FILES['pdf_report'], UPLOAD_MIMES_PDF) === '') {
        $pdf_orig = basename($_FILES['pdf_report']['name']);
        $pdf_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $pdf_orig);
        if (move_uploaded_file($_FILES['pdf_report']['tmp_name'], $target_dir . $pdf_name)) {
            $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report PDF', ?, '1.2 MB')");
            $stmt->execute([$survey_id, $pdf_orig, "uploads/" . $pdf_name]);
            $files_uploaded++;
            $uploaded_labels[] = 'PDF: ' . $pdf_name;
            $uploaded_attachments[] = ['path' => $target_dir . $pdf_name, 'name' => $pdf_name];
        }
    }

    // 2. 📂 కంపల్సరీ EXCEL ప్రాసెసింగ్ మరియు TABLES 54 ట్యాబ్ లోని O21 (Recovery), M20 (VLSFO), O20 (LSMGO) వాల్యూస్ రీడింగ్
    $recovery_val = 0.00;
    $vlsfo_val = 0.00;
    $lsmgo_val = 0.00;
    if (isset($_FILES['excel_report']) && $_FILES['excel_report']['error'] == 0 && $isAllowedExt($_FILES['excel_report']['name'], ['xlsx', 'xls', 'xlsm'])
        && upload_validate($_FILES['excel_report'], UPLOAD_MIMES_XLSX) === '') {
        $excel_orig = basename($_FILES['excel_report']['name']);
        $excel_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $excel_orig);
        $full_target_path = $target_dir . $excel_name;
        
        if (move_uploaded_file($_FILES['excel_report']['tmp_name'], $full_target_path)) {
            
            // --- ఆప్షన్ ఎ: PhpSpreadsheet ద్వారా అఫీషియల్ రీడింగ్ ప్రయత్నం ---
            $found_via_phpspreadsheet = false;
            if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($full_target_path);
                    
                    // ట్యాబ్ పేరు "TABLES 54" తో మొదలయ్యే ఏ షీట్ అయినా (TABLES 54, TABLES 54B, స్పేస్‌లతో సహా) మ్యాచ్ చేసేలా
                    $worksheet = null;
                    
                    foreach ($spreadsheet->getSheetNames() as $name) {
                        if (strpos(trim(strtoupper($name)), 'TABLES 54') === 0) {
                            $worksheet = $spreadsheet->getSheetByName($name);
                            break;
                        }
                    }
                    
                    if (!$worksheet) {
                        $worksheet = $spreadsheet->getActiveSheet();
                    }
                    
                    // ఫార్ములా లేదా మ్యాన్యువల్ ఎంట్రీ ఏదైనా ఫైనల్ క్యాలిక్యులేటెడ్ వాల్యూ తీసుకోవడం
                    $raw_recovery = $worksheet->getCell('O21')->getCalculatedValue();
                    $raw_vlsfo = $worksheet->getCell('M20')->getCalculatedValue();
                    $raw_lsmgo = $worksheet->getCell('O20')->getCalculatedValue();
                    
                    // ఒకవేళ వాల్యూ లో స్ట్రింగ్స్ లేదా కరెన్సీ సింబల్స్ ఉంటే క్లీన్ చేసి, నెగటివ్ అయితే ABS() తో పాజిటివ్ చేయడం
                    $recovery_val = abs((float)preg_replace('/[^0-9.\-]/', '', (string)$raw_recovery));
                    $vlsfo_val = abs((float)preg_replace('/[^0-9.\-]/', '', (string)$raw_vlsfo));
                    $lsmgo_val = abs((float)preg_replace('/[^0-9.\-]/', '', (string)$raw_lsmgo));
                    $found_via_phpspreadsheet = true;
                } catch (Exception $e) { 
                    $recovery_val = 0.00; 
                    $vlsfo_val = 0.00;
                    $lsmgo_val = 0.00;
                }
            }
            
            // --- ఆప్షన్ బి: ఒకవేళ PhpSpreadsheet లేకపోయినా/అన్ని విలువలు 0 వచ్చినా ఫైల్ డేటా నుండి నేరుగా స్కాన్ చేసే ఫాల్‌బ్యాక్ ---
            if (!$found_via_phpspreadsheet || ($recovery_val == 0.00 && $vlsfo_val == 0.00 && $lsmgo_val == 0.00)) {
                try {
                    // ఎక్సెల్ ఫైల్ జిప్ కంటెంట్‌ను రీడ్ చేసి అందులోని షీట్స్ డేటాను రా గా స్కాన్ చేయడం
                    $zip = new ZipArchive();
                    if ($zip->open($full_target_path) === TRUE) {
                        // సెల్ రిఫరెన్స్ ని ఎక్సెల్ షీట్ XML నుండి రా గా చదివే హెల్పర్ (ఫార్ములా/మ్యాన్యువల్ రెండూ కవర్ చేస్తుంది)
                        $readCellRaw = function ($content, $cellRef) {
                            if (strpos($content, 'r="' . $cellRef . '"') === false) return null;
                            if (preg_match('/r="' . preg_quote($cellRef, '/') . '"[^>]*><v>([^<]+)<\/v>/', $content, $m)) {
                                return (float)$m[1];
                            }
                            if (preg_match('/r="' . preg_quote($cellRef, '/') . '"[^>]*>.*?<v>([^<]+)<\/v>/s', $content, $m)) {
                                return (float)$m[1];
                            }
                            return null;
                        };
                        
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $filename = $zip->getNameIndex($i);
                            if (strpos($filename, 'xl/worksheets/sheet') !== false) {
                                $fp = $zip->getStream($filename);
                                $content = stream_get_contents($fp);
                                fclose($fp);
                                
                                $found_o21 = $readCellRaw($content, 'O21');
                                $found_m20 = $readCellRaw($content, 'M20');
                                $found_o20 = $readCellRaw($content, 'O20');
                                
                                if ($found_o21 !== null || $found_m20 !== null || $found_o20 !== null) {
                                    if ($found_o21 !== null) $recovery_val = abs($found_o21);
                                    if ($found_m20 !== null) $vlsfo_val = abs($found_m20);
                                    if ($found_o20 !== null) $lsmgo_val = abs($found_o20);
                                    break;
                                }
                            }
                        }
                        $zip->close();
                    }
                } catch (Exception $e) {
                    // నో యాక్షన్, జీరో అలాగే ఉంటుంది
                }
            }

            // అప్‌లోడ్స్ టేబుల్ లో ఎంట్రీ
            $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report Excel', ?, '245 KB')");
            $stmt->execute([$survey_id, $excel_orig, "uploads/" . $excel_name]);
            
            // surveys టేబుల్ లో recovery_amount, vlsfo_recovery, lsmgo_recovery ని అప్‌డేట్ చేయడం
            $stmt_rec = $db->prepare("UPDATE surveys SET recovery_amount = ?, vlsfo_recovery = ?, lsmgo_recovery = ? WHERE id = ?");
            $stmt_rec->execute([$recovery_val, $vlsfo_val, $lsmgo_val, $survey_id]);
            
            $files_uploaded++;
            $uploaded_labels[] = 'Excel: ' . $excel_orig;
            $uploaded_attachments[] = ['path' => $full_target_path, 'name' => $excel_orig];
        }
    }

    // 3. WORD (.docx) ప్రాసెసింగ్
    if (isset($_FILES['word_report']) && $_FILES['word_report']['error'] == 0 && $isAllowedExt($_FILES['word_report']['name'], ['doc', 'docx'])
        && upload_validate($_FILES['word_report'], UPLOAD_MIMES_DOC) === '') {
        $word_orig = basename($_FILES['word_report']['name']);
        $word_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $word_orig);
        if (move_uploaded_file($_FILES['word_report']['tmp_name'], $target_dir . $word_name)) {
            $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report Word', ?, '512 KB')");
            $stmt->execute([$survey_id, $word_orig, "uploads/" . $word_name]);
            $files_uploaded = 3; // Word అప్‌లోడ్ అయిందని గుర్తుగా 3 ఇస్తున్నాం
            $uploaded_labels[] = 'Word: ' . $word_name;
            $uploaded_attachments[] = ['path' => $target_dir . $word_name, 'name' => $word_name];
        }
    }

    // 4. ఎక్స్‌ట్రా ఫైల్స్
    if (isset($_FILES['extra_files'])) {
        $extraMimes = array_merge(UPLOAD_MIMES_PDF, UPLOAD_MIMES_DOC, UPLOAD_MIMES_XLSX, UPLOAD_MIMES_IMAGE, UPLOAD_MIMES_ZIP);
        foreach ($_FILES['extra_files']['name'] as $key => $name) {
            if ($_FILES['extra_files']['error'][$key] == 0 && $isAllowedExt($name, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'jpg', 'jpeg', 'png', 'zip'])) {
                $extraFile = [
                    'name' => $name,
                    'type' => $_FILES['extra_files']['type'][$key] ?? '',
                    'tmp_name' => $_FILES['extra_files']['tmp_name'][$key],
                    'error' => $_FILES['extra_files']['error'][$key],
                    'size' => $_FILES['extra_files']['size'][$key] ?? 0,
                ];
                if (upload_validate($extraFile, $extraMimes) !== '') {
                    continue;
                }
                $extra_orig = basename($name);
                $extra_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $extra_orig);
                if (move_uploaded_file($_FILES['extra_files']['tmp_name'][$key], $target_dir . $extra_name)) {
                    $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Field Report', ?, '512 KB')");
                    $stmt->execute([$survey_id, $extra_orig, "uploads/" . $extra_name]);
                    $uploaded_labels[] = 'Extra: ' . $extra_name;
                    $uploaded_attachments[] = ['path' => $target_dir . $extra_name, 'name' => $extra_name];
                }
            }
        }
    }

    // Helper: build app base URL for email links
    $ysmsAppBase = (function () {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['PHP_SELF'] ?? ''))), '/');
        return $scheme . '://' . $host . $base;
    })();

    // ⚙️ వర్క్‌ఫ్లో & సెషన్ అలర్ట్ మెసేజ్ లాజిక్
    if ($current_status === 'Pending Vessel' && $files_uploaded >= 2) {
        $update = $db->prepare("UPDATE surveys SET status = 'Pending Report', survey_completed_date = NOW(), status_updated_by = ? WHERE id = ?");
        $update->execute([$_SESSION['user_id'], $survey_id]);
        $_SESSION['flash_msg'] = "Pending vessel is uploaded and moved to pending report";
        try {
            $ctx = loadSurveyNotifyContext($db, $survey_id);
            $ctx['new_status'] = 'Pending Report';
            $ctx['stage_label'] = 'Survey completed — formal report pending';
            $ctx['files'] = $uploaded_labels;
            $ctx['attachments'] = $uploaded_attachments;
            $ctx['app_url'] = $ysmsAppBase . '/reports.php';
            // Prefer logged-in uploader profile for Reply-To
            if (!empty($_SESSION['user_id'])) {
                try {
                    $u = $db->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
                    $u->execute([(int)$_SESSION['user_id']]);
                    $ur = $u->fetch(PDO::FETCH_ASSOC);
                    if ($ur) {
                        if (!empty($ur['full_name'])) $ctx['surveyor_name'] = $ur['full_name'];
                        if (!empty($ur['email'])) $ctx['surveyor_email'] = trim($ur['email']);
                    }
                } catch (Throwable $ignore) {}
            }
            $note = notifyAdminsOfReportUpload($db, $ctx);
            if ($note !== '') {
                $_SESSION['flash_msg'] .= ' · ' . $note;
            }
            try {
                $v = $ctx['vessel_name'] ?? 'Vessel';
                $who = $_SESSION['full_name'] ?? 'Surveyor';
                notifyAllAdmins($db, 'Survey documents uploaded',
                    $who . ' uploaded files for ' . $v . ' (Pending Report).',
                    'upload', 'reports.php', (int)($_SESSION['user_id'] ?? 0));
            } catch (Throwable $ne) { error_log('upload in-app notif: '.$ne->getMessage()); }
        } catch (Throwable $e) {
            error_log('upload_handler admin notify (pending report): ' . $e->getMessage());
        }
        header("Location: ../reports.php");
        exit;
    } 
    
    // 🌟 వర్డ్ రిపోర్ట్ అప్‌లోడ్ చేసినా, లేదా యూజర్ "Continue without report" కన్ఫర్మ్ చేసినా ముందుకు వెళ్లేలా
    $no_report_confirmed = isset($_POST['no_report_confirmed']) && $_POST['no_report_confirmed'] === '1';
    $word_report_missing = !isset($_FILES['word_report']) || $_FILES['word_report']['error'] != 0 || empty($_FILES['word_report']['name']);

    if ($current_status === 'Pending Report' && ($files_uploaded === 3 || ($word_report_missing && $no_report_confirmed))) {
        $update = $db->prepare("UPDATE surveys SET status = 'Completed', report_uploaded_date = NOW(), notes = 'All surveys and reports are verified and uploaded successfully.', status_updated_by = ? WHERE id = ?");
        $update->execute([$_SESSION['user_id'], $survey_id]);
        $_SESSION['flash_msg'] = "Pending report is completed";
        try {
            $ctx = loadSurveyNotifyContext($db, $survey_id);
            $ctx['new_status'] = 'Completed';
            $ctx['stage_label'] = 'Report uploaded — survey completed';
            $ctx['files'] = $uploaded_labels;
            $ctx['attachments'] = $uploaded_attachments;
            $ctx['app_url'] = $ysmsAppBase . '/completed.php';
            if (!empty($_SESSION['user_id'])) {
                try {
                    $u = $db->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
                    $u->execute([(int)$_SESSION['user_id']]);
                    $ur = $u->fetch(PDO::FETCH_ASSOC);
                    if ($ur) {
                        if (!empty($ur['full_name'])) $ctx['surveyor_name'] = $ur['full_name'];
                        if (!empty($ur['email'])) $ctx['surveyor_email'] = trim($ur['email']);
                    }
                } catch (Throwable $ignore) {}
            }
            $note = notifyAdminsOfReportUpload($db, $ctx);
            if ($note !== '') {
                $_SESSION['flash_msg'] .= ' · ' . $note;
            }
            try {
                $v = $ctx['vessel_name'] ?? 'Vessel';
                $who = $_SESSION['full_name'] ?? 'Surveyor';
                notifyAllAdmins($db, 'Report uploaded — completed',
                    $who . ' completed report upload for ' . $v . '.',
                    'report', 'completed.php', (int)($_SESSION['user_id'] ?? 0));
            } catch (Throwable $ne) { error_log('report in-app notif: '.$ne->getMessage()); }
        } catch (Throwable $e) {
            error_log('upload_handler admin notify (completed): ' . $e->getMessage());
        }
        header("Location: ../completed.php");
        exit;
    }
}

header("Location: ../index.php");
exit;