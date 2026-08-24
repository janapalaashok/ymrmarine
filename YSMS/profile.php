<?php
require_once 'config/config.php';
checkAuth();

$db = getDB();

// Ensure first_name / last_name / dob columns for profile edit limits
try {
    $colsMap = [];
    foreach ($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $colsMap[strtolower($c['Field'])] = true;
    }
    if (empty($colsMap['first_name'])) $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(80) DEFAULT NULL");
    if (empty($colsMap['last_name']))  $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(80) DEFAULT NULL");
    if (empty($colsMap['dob']))        $db->exec("ALTER TABLE users ADD COLUMN dob DATE DEFAULT NULL");
} catch (Exception $e) { error_log('profile col ensure: '.$e->getMessage()); }
$has_first_name_col = true;
$has_last_name_col = true;
$has_dob_col = true;

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// 🌟 SAFETY NET: 'profile_pic' / 'avatar' కాలమ్‌లు లేకపోతే ఆటోమేటిక్‌గా జోడించడం
// (POST హ్యాండ్లింగ్ కంటే ముందే చేయాలి - లేకపోతే ఫోటో డిస్క్‌కి అప్‌లోడ్ అయినా,
//  DB లో ఈ కాలమ్‌లు లేనందున UPDATE query వాటిని స్కిప్ చేసేస్తుంది, ఫోటో మారినట్టు కనపడదు)
// 🌟 ముఖ్యమైన నోట్: హెడర్/ప్రొఫైల్ డ్రాప్‌డౌన్ (includes/profile_dropdown.php) మరియు login.php
// రెండూ 'avatar' కాలమ్ + $_SESSION['avatar'] నే వాడతాయి - కాబట్టి ఫోటో ఎక్కడ అప్‌లోడ్ చేసినా,
// ఆ రెండు కాలమ్‌లు (profile_pic, avatar) రెండింటిలోనూ ఒకే పాత్ సేవ్ అవ్వాలి.
try {
    if (!$db->query("SHOW COLUMNS FROM users LIKE 'profile_pic'")->fetch()) {
        $db->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch()) {
        $db->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('profile.php avatar column check/add error: ' . $e->getMessage());
}

// ⚙️ 1. ప్రొఫైల్ డేటా & ఫోటో అప్‌డేట్ సబ్మిషన్ లాజిక్
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $uploaded_pic_path = null;

    // 🌟 Profile picture upload works for ALL roles (including Surveyor)
    if (isset($_FILES['profile_image']) && is_array($_FILES['profile_image']) && (int)$_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "uploads/profile/";
        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0777, true);
        }
        $file_ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($file_ext, $allowed_exts, true)) {
            $new_pic_name = "user_" . $user_id . "_" . time() . "." . $file_ext;
            $full_target_path = $target_dir . $new_pic_name;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $full_target_path)) {
                $uploaded_pic_path = $full_target_path;
            } else {
                $error = "Could not save the uploaded photo. Please check that uploads/profile/ is writable.";
            }
        } else {
            $error = "Invalid image format. Allowed: JPG, PNG, WEBP.";
        }
    }

    // Surveyor profile limited fields: first name, last name, DOB (+ photo)
    if (empty($error) && (($_SESSION['role'] ?? '') === 'Surveyor')) {
        // Photo-only submit (camera form) does not send first_name/last_name
        $isPhotoOnly = $uploaded_pic_path && !isset($_POST['first_name']) && !isset($_POST['last_name']);

        if ($isPhotoOnly) {
            try {
                $has_pic = $db->query("SHOW COLUMNS FROM users LIKE 'profile_pic'")->fetch();
                $has_avatar = $db->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch();
                $query_parts = [];
                $params = [];
                if ($has_pic) { $query_parts[] = "profile_pic = ?"; $params[] = $uploaded_pic_path; }
                if ($has_avatar) { $query_parts[] = "avatar = ?"; $params[] = $uploaded_pic_path; }
                if (!empty($query_parts)) {
                    $params[] = $user_id;
                    $sql = "UPDATE users SET " . implode(", ", $query_parts) . " WHERE id = ?";
                    $db->prepare($sql)->execute($params);
                    $_SESSION['avatar'] = $uploaded_pic_path;
                    $success = "Profile photo updated successfully!";
                } else {
                    $error = "Could not update photo columns.";
                }
            } catch (Exception $e) {
                $error = 'Failed to update profile photo.';
                error_log('surveyor photo update: '.$e->getMessage());
            }
        } else {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name  = trim($_POST['last_name'] ?? '');
            $dob        = trim($_POST['dob'] ?? '');
            if ($first_name === '' || $last_name === '') {
                $error = 'First name and last name are required.';
            } else {
                $full_name = trim($first_name . ' ' . $last_name);
                $dobVal = ($dob !== '') ? $dob : null;
                try {
                    $has_pic = $db->query("SHOW COLUMNS FROM users LIKE 'profile_pic'")->fetch();
                    $has_avatar = $db->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch();
                    $query_parts = ['full_name = ?', 'first_name = ?', 'last_name = ?', 'dob = ?'];
                    $params = [$full_name, $first_name, $last_name, $dobVal];
                    if ($has_pic && $uploaded_pic_path) { $query_parts[] = "profile_pic = ?"; $params[] = $uploaded_pic_path; }
                    if ($has_avatar && $uploaded_pic_path) { $query_parts[] = "avatar = ?"; $params[] = $uploaded_pic_path; }
                    $params[] = $user_id;
                    $sql = "UPDATE users SET " . implode(", ", $query_parts) . " WHERE id = ?";
                    $db->prepare($sql)->execute($params);
                    $_SESSION['full_name'] = $full_name;
                    if ($uploaded_pic_path) {
                        $_SESSION['avatar'] = $uploaded_pic_path;
                    }
                    $success = 'Profile updated successfully.';
                } catch (Exception $e) {
                    $error = 'Failed to update profile.';
                    error_log('surveyor profile update: '.$e->getMessage());
                }
            }
        }
    } elseif (empty($error)) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';

    if (empty($error)) {
        if (empty($full_name)) {
            $error = "Full Name field is required.";
        } else {
            try {
                $has_email = $db->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
                $has_phone = $db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();
                $has_pic = $db->query("SHOW COLUMNS FROM users LIKE 'profile_pic'")->fetch();
                $has_avatar = $db->query("SHOW COLUMNS FROM users LIKE 'avatar'")->fetch();

                // 🌟 కరెక్ట్ సింటాక్స్ బిల్డింగ్ (ఎర్రర్ రాకుండా అరే లాజిక్)
                $query_parts = ["full_name = ?"];
                $params = [$full_name];

                if ($has_email && !empty($email)) {
                    $query_parts[] = "email = ?";
                    $params[] = $email;
                }
                if ($has_phone && !empty($phone)) {
                    $query_parts[] = "phone = ?";
                    $params[] = $phone;
                }
                if ($has_pic && $uploaded_pic_path) {
                    $query_parts[] = "profile_pic = ?";
                    $params[] = $uploaded_pic_path;
                }
                // 🌟 హెడర్/డ్రాప్‌డౌన్ అవతార్ ఇదే కాలమ్ నుండి వస్తుంది (login.php లో session కి లోడ్ అవుతుంది)
                // కాబట్టి కొత్త ఫోటో అప్‌లోడ్ అయినప్పుడు ఇక్కడ కూడా అప్‌డేట్ చేయాలి.
                if ($has_avatar && $uploaded_pic_path) {
                    $query_parts[] = "avatar = ?";
                    $params[] = $uploaded_pic_path;
                }

                $params[] = $user_id; // WHERE కండిషన్ ఐడీ కోసం
                
                $sql = "UPDATE users SET " . implode(", ", $query_parts) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $result = $stmt->execute($params);

                if ($result) {
                    $_SESSION['full_name'] = $full_name;
                    // 🌟 అప్‌లోడ్ చేసిన వెంటనే, రీ-లాగిన్ అవ్వకుండానే హెడర్/డ్రాప్‌డౌన్‌లో కొత్త ఫోటో కనిపించడానికి
                    if ($uploaded_pic_path) {
                        $_SESSION['avatar'] = $uploaded_pic_path;
                    }
                    $success = "Changes saved successfully!";
                } else {
                    $error = "Failed to update profile data.";
                }
            } catch (Exception $e) {
                $error = "Database Notice: " . $e->getMessage();
            }
        }
    }
    } // end non-surveyor update_profile
} // end update_profile POST

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];

    if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
        $error = "All password fields are required.";
    } elseif ($new_pass !== $confirm_pass) {
        $error = "New password and Confirm password do not match.";
    } else {
        try {
            // పాత పాస్‌వర్డ్ వెరిఫికేషన్
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $hashed_password = $stmt->fetchColumn();

            // మీ సిస్టమ్ password_verify వాడుతుంటే అది, లేదా ఎండ్‌క్రిప్షన్ లేకపోతే డైరెక్ట్ మ్యాచ్ చెక్
            if (password_verify($current_pass, $hashed_password) || $current_pass === $hashed_password) {
                // కొత్త పాస్‌వర్డ్ అప్‌డేట్ (సేఫ్ సైడ్ హాష్ చేయడం లేదా డైరెక్ట్ సేవ్ మీ config బట్టి)
                $new_hashed = function_exists('password_hash') ? password_hash($new_pass, PASSWORD_BCRYPT) : $new_pass;
                
                $update_pass = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($update_pass->execute([$new_hashed, $user_id])) {
                    $success = "Password updated successfully!";
                } else {
                    $error = "Failed to update password.";
                }
            } else {
                $error = "Current password is incorrect.";
            }
        } catch (Exception $e) {
            $error = "Password Error: " . $e->getMessage();
        }
    }
}

// 🌟 1.2 SAFETY NET: business_card_path / id_card_path కాలమ్‌లు లేకపోతే ఆటోమేటిక్‌గా జోడించడం
// (SELECT కంటే ముందు చేయడం వల్ల మొదటి రిక్వెస్ట్‌లోనే కొత్త కాలమ్‌లు $user లో అందుబాటులో ఉంటాయి)
try {
    if (!$db->query("SHOW COLUMNS FROM users LIKE 'business_card_path'")->fetch()) {
        $db->exec("ALTER TABLE users ADD COLUMN business_card_path VARCHAR(255) DEFAULT NULL");
    }
    if (!$db->query("SHOW COLUMNS FROM users LIKE 'id_card_path'")->fetch()) {
        $db->exec("ALTER TABLE users ADD COLUMN id_card_path VARCHAR(255) DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('profile.php card column check/add error: ' . $e->getMessage());
}

// ⚙__ 3. డేటాబేస్ నుండి తాజా యూజర్ రికార్డును లోడ్ చేయడం
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$has_email_col = $db->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
$has_phone_col = $db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch();

$business_card_path = (!empty($user['business_card_path']) && is_file($user['business_card_path'])) ? $user['business_card_path'] : '';
$id_card_path = (!empty($user['id_card_path']) && is_file($user['id_card_path'])) ? $user['id_card_path'] : '';

$current_name = !empty($user['full_name']) ? $user['full_name'] : $_SESSION['full_name'];
$current_email = ($has_email_col && !empty($user['email'])) ? $user['email'] : '';
$current_phone = ($has_phone_col && isset($user['phone'])) ? $user['phone'] : '';
$current_username = !empty($user['username']) ? $user['username'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'User');
$current_role = !empty($user['role']) ? $user['role'] : (isset($_SESSION['role']) ? $_SESSION['role'] : 'Surveyor');
$joining_date = !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : date('d M Y');

$avatar_url = "https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=100&q=80";
if (!empty($user['avatar']) && file_exists($user['avatar'])) {
    $avatar_url = $user['avatar'];
} elseif (!empty($user['profile_pic']) && file_exists($user['profile_pic'])) {
    $avatar_url = $user['profile_pic'];
}

include 'includes/header.php';
?>

<style>
    .profile-card { background: white; border-radius: 16px; padding: 20px; margin: 15px; border: 1px solid var(--border-color); }
    .profile-form-control { width: 100%; padding: 11px 13px; border: 1px solid var(--border-color); border-radius: 10px; font-size: 13.5px; background: #f8fafc; outline: none; margin-bottom: 12px; }
    .profile-form-control:focus { border-color: #3b32b3; background: #ffffff; }
    .profile-locked-control { width: 100%; padding: 11px 13px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13.5px; background: #f1f5f9; color: #64748b; margin-bottom: 12px; cursor: not-allowed; }
    .save-profile-btn { background: #3b32b3; color: white; border: none; width: 100%; padding: 12px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .logout-zone-btn { background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2; width: 100%; padding: 12px; border-radius: 10px; font-size: 14px; font-weight: 600; text-align: center; display: block; text-decoration: none; margin-top: 15px; }
    #imageUploadInput { display: none; }

    /* Desktop only: 2 cards per row */
    @media (min-width: 992px) {
        .profile-desktop-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            max-width: 1100px;
            margin: 8px auto 0;
            padding: 0 20px 24px;
            align-items: start;
        }
        .profile-desktop-grid > .profile-card {
            margin: 0;
            height: 100%;
        }
        .profile-desktop-grid > .profile-full-row {
            grid-column: 1 / -1;
        }
        .profile-photo-block {
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }
        .logout-zone-btn {
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
        }
    }
</style>

<div class="scroll-content">
    <div class="bg-white p-3 d-flex align-items-center gap-2 border-bottom">
        <a href="index.php" class="text-dark"><i class="fa-solid fa-chevron-left"></i></a>
        <h5 class="fw-bold m-0 text-center flex-grow-1" style="font-size: 16px;">Account Settings</h5>
        <div class="d-flex align-items-center gap-2" style="flex-shrink:0;">
            <?php include 'includes/notifications_bell.php'; ?>
            <?php include 'includes/profile_dropdown.php'; ?>
        </div>
    </div>

    <!-- Alerts Notification Box -->
    <?php if(!empty($success)): ?><div class="alert alert-success mx-3 mt-3 py-2 text-center" style="font-size:12px; border-radius:8px; background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;"><i class="fa-solid fa-circle-check me-1"></i> <?= $success ?></div><?php endif; ?>
    <?php if(!empty($error)): ?><div class="alert alert-danger mx-3 mt-3 py-2 text-center" style="font-size:12px; border-radius:8px; background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $error ?></div><?php endif; ?>

    <!-- ✍️ ఫోటో అప్‌లోడ్ కాంపోనెంట్ (ఫోటో పై క్లిక్ చేస్తే ఆటో సబ్మిట్ అవుతుంది) -->
    <form action="profile.php" method="POST" enctype="multipart/form-data" id="photoForm" class="profile-photo-block">
        <input type="hidden" name="update_profile" value="1">
        <input type="hidden" name="full_name" value="<?= sanitize($current_name) ?>">
        <input type="hidden" name="email" value="<?= sanitize($current_email) ?>">
        <input type="hidden" name="phone" value="<?= sanitize($current_phone) ?>">
        <div class="text-center mt-4">
            <div class="mx-auto position-relative" style="width: 90px; height: 90px; cursor: pointer;" onclick="document.getElementById('imageUploadInput').click();">
                <img src="<?= $avatar_url ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 3px solid #3b32b3;">
                <span class="position-absolute bottom-0 end-0 bg-primary text-white d-flex justify-content-center align-items-center rounded-circle shadow" style="width: 28px; height: 28px; border: 2px solid white; font-size: 11px;">
                    <i class="fa-solid fa-camera"></i>
                </span>
                <input type="file" name="profile_image" id="imageUploadInput" accept="image/*" onchange="document.getElementById('photoForm').submit();">
            </div>
            <h4 class="fw-bold text-dark mt-2 mb-0" style="font-size: 16px;"><?= sanitize($current_name) ?></h4>
            <p class="text-muted small m-0 mt-1"><span class="badge bg-light text-secondary border px-2 py-0.5"><?= sanitize($current_role) ?></span></p>
        </div>
    </form>

    <div class="profile-desktop-grid">
    <!-- 🔓 SECTION A: PERSONAL INFO (మార్చుకోగలిగేవి) -->
    <div class="profile-card shadow-sm mb-1">
        <div class="fw-bold text-dark mb-2" style="font-size: 12.5px;"><i class="fa-solid fa-user-pen text-primary me-1"></i> Personal Information</div>
        <form action="profile.php" method="POST">
            <?php
                $isSurveyor = (($_SESSION['role'] ?? '') === 'Surveyor');
                $cur_first = $user['first_name'] ?? '';
                $cur_last  = $user['last_name'] ?? '';
                if ($cur_first === '' && $cur_last === '' && $current_name !== '') {
                    $parts = explode(' ', $current_name, 2);
                    $cur_first = $parts[0] ?? '';
                    $cur_last = $parts[1] ?? '';
                }
                $cur_dob = $user['dob'] ?? '';
            ?>
            <?php if ($isSurveyor): ?>
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">First Name *</label>
                <input type="text" name="first_name" class="profile-form-control" value="<?= sanitize($cur_first) ?>" required>
            </div>
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">Last Name *</label>
                <input type="text" name="last_name" class="profile-form-control" value="<?= sanitize($cur_last) ?>" required>
            </div>
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">Date of Birth</label>
                <input type="date" name="dob" class="profile-form-control" value="<?= sanitize($cur_dob) ?>">
            </div>
            <?php else: ?>
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">Full Name *</label>
                <input type="text" name="full_name" class="profile-form-control" value="<?= sanitize($current_name) ?>" required>
            </div>
            <?php endif; ?>
            <?php if ($has_email_col): ?>
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">Email Address *</label>
                <input type="email" name="email" class="profile-form-control" value="<?= sanitize($current_email) ?>" required>
            </div>
            <?php endif; ?>
            <?php if ($has_phone_col): ?>
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">Phone Number</label>
                <input type="text" name="phone" class="profile-form-control" value="<?= sanitize($current_phone) ?>" placeholder="Enter mobile number">
            </div>
            <?php endif; ?>
            <button type="submit" name="update_profile" class="save-profile-btn mt-1"><i class="fa-solid fa-floppy-disk me-1"></i> Save Profile Details</button>
        </form>
    </div>

    <!-- 🔐 SECTION B: CHANGE PASSWORD (కొత్త ఆప్షన్) -->
    <div class="profile-card shadow-sm mt-2">
        <div class="fw-bold text-dark mb-2" style="font-size: 12.5px;"><i class="fa-solid fa-key text-warning me-1"></i> Change Password</div>
        <form action="profile.php" method="POST">
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">Current Password</label>
                <input type="password" name="current_password" class="profile-form-control" placeholder="••••••••" required>
            </div>
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">New Password</label>
                <input type="password" name="new_password" class="profile-form-control" placeholder="Minimum 6 characters" required>
            </div>
            <div>
                <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">Confirm New Password</label>
                <input type="password" name="confirm_password" class="profile-form-control" placeholder="Re-type new password" required>
            </div>
            <button type="submit" name="change_password" class="save-profile-btn mt-1" style="background: #eab308; color: #000;"><i class="fa-solid fa-lock-open me-1"></i> Update Security Password</button>
        </form>
    </div>

    <!-- 🔒 SECTION C: LOCKED PARAMETERS (మార్చడానికి వీలులేనివి) -->
    <div class="profile-card shadow-sm mt-2">
        <div class="fw-bold text-muted mb-2" style="font-size: 12.5px;"><i class="fa-solid fa-lock text-secondary me-1"></i> Locked System Parameters</div>
        <div>
            <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">System Username</label>
            <input type="text" class="profile-locked-control" value="<?= sanitize($current_username) ?>" readonly>
        </div>
        <div>
            <label class="text-secondary mb-1 d-block" style="font-size: 11.5px;">Date of Joining</label>
            <input type="text" class="profile-locked-control" value="<?= $joining_date ?>" readonly>
        </div>
    </div>

    <!-- 🌟 1.2 SECTION D: BUSINESS CARD / ID CARD DOWNLOADS (Admin uploads via Admin Controls > ID / Business Cards; everyone can only download their own) -->
    <?php if ($business_card_path || $id_card_path): ?>
    <div class="profile-card shadow-sm mt-2">
        <div class="fw-bold text-dark mb-2" style="font-size: 12.5px;"><i class="fa-solid fa-id-card text-primary me-1"></i> My Cards</div>
        <?php if ($business_card_path): ?>
            <a href="<?= sanitize($business_card_path) ?>" download class="save-profile-btn mt-1 d-block text-decoration-none" style="background: #1e3a8a; margin-bottom: 10px;" data-testid="profile-download-business-card"><i class="fa-solid fa-download me-1"></i> Download Business Card</a>
        <?php endif; ?>
        <?php if ($id_card_path): ?>
            <a href="<?= sanitize($id_card_path) ?>" download class="save-profile-btn mt-1 d-block text-decoration-none" style="background: #0f766e;" data-testid="profile-download-id-card"><i class="fa-solid fa-download me-1"></i> Download ID Card</a>
        <?php endif; ?>
    </div>
    <?php elseif ($current_role === 'Admin'): ?>
    <div class="profile-card shadow-sm mt-2">
        <div class="fw-bold text-dark mb-2" style="font-size: 12.5px;"><i class="fa-solid fa-id-card text-primary me-1"></i> My Cards</div>
        <p class="text-muted small m-0">No cards uploaded yet. Go to <a href="admin_surveyor_cards.php">Admin Controls &gt; ID / Business Cards</a> to add them.</p>
    </div>
    <?php endif; ?>

    </div><!-- /.profile-desktop-grid -->

    <!-- 🛑 SECTION E: LOGOUT BUTTON -->
    <div class="px-3 mb-4">
        <a href="logout.php" class="logout-zone-btn shadow-sm"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Log Out Account</a>
    </div>
</div>

<?php 
include 'includes/nav.php';
include 'includes/footer.php';
?>