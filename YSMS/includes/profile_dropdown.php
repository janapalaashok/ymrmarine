<?php
$profile_avatar = 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=100&q=80';

// 🌟 సెషన్ మీద మాత్రమే ఆధారపడకుండా, ప్రతి పేజీ లోడ్‌లోనూ DB నుండి ఫ్రెష్‌గా ఫోటో ఫెచ్ చేయడం.
// (profile.php లో ఫోటో మారినప్పుడు session వెంటనే అప్‌డేట్ అయినా, వేరే పేజీ/టాబ్/రిక్వెస్ట్‌లో
//  session ఎప్పుడైనా పాతదిగా మిగిలిపోతే కూడా ఇక్కడ ఎప్పుడూ నిజమైన DB విలువే కనిపిస్తుంది.)
if (!empty($_SESSION['user_id'])) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $current_user_row = $stmt->fetch();

        $fresh_avatar = null;
        if (!empty($current_user_row['avatar'])) {
            $fresh_avatar = $current_user_row['avatar'];
        } elseif (!empty($current_user_row['profile_pic'])) {
            $fresh_avatar = $current_user_row['profile_pic'];
        }

        if ($fresh_avatar && strpos($fresh_avatar, 'uploads/') === 0 && file_exists(__DIR__ . '/../' . $fresh_avatar)) {
            $profile_avatar = $fresh_avatar;
            $_SESSION['avatar'] = $fresh_avatar; // సెషన్‌ను కూడా తాజాగా ఉంచడం, తర్వాతి రిక్వెస్ట్‌ల కోసం
        }
    } catch (Exception $e) {
        error_log('profile_dropdown.php avatar fetch error: ' . $e->getMessage());
        // DB ఎర్రర్ వస్తే, కనీసం సెషన్‌లో ఉన్నదైనా చూపించడం (పూర్తిగా placeholderకి పడిపోకుండా)
        if (!empty($_SESSION['avatar']) && strpos($_SESSION['avatar'], 'uploads/') === 0 && file_exists(__DIR__ . '/../' . $_SESSION['avatar'])) {
            $profile_avatar = $_SESSION['avatar'];
        }
    }
}
?>
<div class="profile-menu-wrap" data-testid="global-profile-menu">
    <button type="button" class="profile-menu-trigger" aria-label="Open profile menu" aria-expanded="false" data-testid="profile-menu-button">
        <img src="<?= sanitize($profile_avatar) ?>" class="profile-avatar" alt="Profile">
    </button>
    <div class="profile-dropdown-menu" role="menu" data-testid="profile-dropdown-menu">
        <a href="profile.php" class="profile-dropdown-item" role="menuitem" data-testid="profile-menu-my-profile-link">
            <i class="fa-regular fa-user"></i><span>My Profile</span>
        </a>
        <a href="logout.php" class="profile-dropdown-item logout-item" role="menuitem" data-testid="profile-menu-logout-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i><span>Logout</span>
        </a>
    </div>
</div>