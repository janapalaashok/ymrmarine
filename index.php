<?php
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/service_helpers.php';
ensureServicePageColumns(getDB());
seedServicePagesIfNeeded(getDB());

$pdo = getDB();

// Fetch all dynamic data
$hero = $pdo->query('SELECT * FROM hero WHERE id = 1')->fetch() ?: [];
$about = $pdo->query('SELECT * FROM about WHERE id = 1')->fetch() ?: [];
$services = $pdo->query('SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$whyUs = $pdo->query('SELECT * FROM why_us WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$team = $pdo->query('SELECT * FROM team WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$ports = $pdo->query('SELECT * FROM ports WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$testimonials = $pdo->query('SELECT * FROM testimonials WHERE is_active = 1 ORDER BY sort_order')->fetchAll();

$address = getSetting('address');
$mapEmbed = getSetting('map_embed');
$footerText = getSetting('footer_text');
$copyright = getSetting('copyright');
?>

<!-- ════════ HERO ════════ -->
<section class="hero" id="home">
  <div class="hero-bg" style="<?= !empty($hero['bg_image']) ? "background-image: linear-gradient(135deg, rgba(11,30,45,0.97) 0%, rgba(11,30,45,0.75) 50%, rgba(11,30,45,0.3) 100%), url('" . e($hero['bg_image']) . "'); background-size:cover; background-position:center;" : '' ?>"></div>
  <div class="hero-content">
    <div class="hero-left">
      <div class="hero-eyebrow"><?= e($hero['eyebrow'] ?? 'Your Global Survey Partner') ?></div>
      <h1 class="hero-h1">
        <?= e(str_replace($hero['title_highlight'] ?? 'Right.', '', $hero['title'] ?? 'Marine Surveys Done Right.')) ?>
        <em><?= e($hero['title_highlight'] ?? 'Right.') ?></em>
      </h1>
      <p class="hero-sub"><?= $hero['subtitle'] ?? '' ?></p>
      <div class="hero-cta">
        <a href="<?= e($hero['btn1_link'] ?? 'contact.php') ?>" class="btn-primary-hero">
          <?= e($hero['btn1_text'] ?? 'Request a Survey') ?> <i class="fas fa-arrow-right"></i>
        </a>
        <a href="<?= e($hero['btn2_link'] ?? '#services') ?>" class="btn-ghost-hero">
          <?= e($hero['btn2_text'] ?? 'Our Services') ?>
        </a>
      </div>
      <div class="hero-stats">
        <div>
          <div class="hstat-val"><?= e($hero['stat1_value'] ?? '18+') ?></div>
          <div class="hstat-label"><?= e($hero['stat1_label'] ?? 'Years Active') ?></div>
        </div>
        <div>
          <div class="hstat-val"><?= e($hero['stat2_value'] ?? '500+') ?></div>
          <div class="hstat-label"><?= e($hero['stat2_label'] ?? 'Surveys Done') ?></div>
        </div>
        <div>
          <div class="hstat-val"><?= e($hero['stat3_value'] ?? '100+') ?></div>
          <div class="hstat-label"><?= e($hero['stat3_label'] ?? 'Clients') ?></div>
        </div>
        <div>
          <div class="hstat-val"><?= e($hero['stat4_value'] ?? '24/7') ?></div>
          <div class="hstat-label"><?= e($hero['stat4_label'] ?? 'Availability') ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="ticker-wrap">
    <div class="ticker-track" id="ticker"></div>
  </div>
</section>

<!-- ════════ ABOUT ════════ -->
<section id="about">
  <div class="section">
    <div class="about-grid">
      <div class="reveal">
        <div class="tag"><?= e($about['tag'] ?? 'Who We Are') ?></div>
        <h2 class="sec-h2"><?= e($about['title'] ?? 'Trusted marine expertise since 2006') ?></h2>
        
        <!-- Shortened description (max 2 paragraphs) -->
        <?php
        $body1 = trim($about['body'] ?? '');
        $body2 = trim($about['body2'] ?? '');
        if ($body1) {
            $para1 = explode("\n\n", $body1)[0];
            echo '<p class="sec-body">' . nl2br(e($para1)) . '</p>';
        }
        if ($body2) {
            $para2 = explode("\n\n", $body2)[0];
            echo '<p class="sec-body" style="margin-top:1rem;">' . nl2br(e($para2)) . '</p>';
        }
        ?>
                  <!-- Read more button -->
        <a href="https://ymrmarine.in/about-us.php" target="_blank" class="read-more-btn">
          Read More <i class="fas fa-arrow-right"></i>
        </a>



        <div class="about-stats">
          <div class="astat"><div class="astat-val"><?= e($about['stat1_value'] ?? '18+') ?></div><div class="astat-label"><?= e($about['stat1_label'] ?? '') ?></div></div>
          <div class="astat"><div class="astat-val"><?= e($about['stat2_value'] ?? '5000+') ?></div><div class="astat-label"><?= e($about['stat2_label'] ?? '') ?></div></div>
          <div class="astat"><div class="astat-val"><?= e($about['stat3_value'] ?? '100+') ?></div><div class="astat-label"><?= e($about['stat3_label'] ?? '') ?></div></div>
          <div class="astat"><div class="astat-val"><?= e($about['stat4_value'] ?? '24/7') ?></div><div class="astat-label"><?= e($about['stat4_label'] ?? '') ?></div></div>
        </div>
      </div>
      <div class="about-img-stack reveal reveal-delay-2">
        <img src="<?= e($about['img_main'] ?: 'https://images.unsplash.com/photo-1693045734143-e3ee9235ec62?q=80&w=1632&auto=format&fit=crop') ?>" alt="Vessel survey" class="about-img-main">
        <?php if (!empty($about['img_secondary'])): ?>
        <img src="<?= e($about['img_secondary']) ?>" alt="Port" class="about-img-secondary">
        <?php endif; ?>
        <div class="cert-pill">
          <div class="cert-icon"><i class="fas fa-check"></i></div>
          <div class="cert-text">
            <div class="t1"><?= e($about['cert_title'] ?? 'Experienced Surveyors') ?></div>
            <div class="t2"><?= e($about['cert_subtitle'] ?? 'Quality Assured') ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<!-- ════════ SERVICES ════════ -->
<div class="section-alt">
<section id="services">
  <div class="section">
    <div class="reveal">
      <div class="tag">What We Do</div>
      <h2 class="sec-h2">Comprehensive survey<br>& consultancy services</h2>
    </div>
    <div class="services-grid">
      <?php foreach ($services as $i => $s):
        $svcHref = servicePageUrl($s);
        $svcLabel = 'Learn more';
      ?>
      <div class="svc-card <?= $s['is_featured'] ? 'featured' : '' ?> reveal reveal-delay-<?= ($i % 3) + 1 ?>">
        <?php if ($s['badge']): ?><span class="svc-badge"><?= e($s['badge']) ?></span><?php endif; ?>
        <div class="svc-num"><?= e($s['code']) ?></div>
        <div class="svc-icon-wrap"><i class="fas <?= e($s['icon']) ?>"></i></div>
        <div class="svc-title"><?= e($s['title']) ?></div>
        <p class="svc-desc"><?= e($s['description']) ?></p>
        <a href="<?= e($svcHref) ?>" class="svc-link"><?= e($svcLabel) ?> <i class="fas fa-arrow-right"></i></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
</div>

<!-- ════════ WHY US ════════ -->
<section id="why-us">
  <div class="section">
    <div class="reveal">
      <div class="tag">Our Edge</div>
      <h2 class="sec-h2">Why shipowners &amp;<br>traders choose <em>YMR</em></h2>
    </div>
    <div class="why-grid">
      <?php foreach ($whyUs as $i => $w): ?>
      <div class="why-item reveal reveal-delay-<?= ($i % 4) + 1 ?>">
        <div class="why-icon"><i class="fas <?= e($w['icon']) ?>"></i></div>
        <div>
          <div class="why-title"><?= e($w['title']) ?></div>
          <p class="why-body"><?= e($w['body']) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ════════ TEAM ════════ -->
<section id="team">
  <div class="section">
    <div class="reveal">
      <div class="tag">The People Behind It</div>
      <h2 class="sec-h2">Meet our team</h2>
      <p class="sec-body">A compact, experienced team keeping every survey accurate, on time, and dispute-proof.</p>
    </div>
    <div class="team-grid">
      <?php foreach ($team as $i => $m): ?>
      <div class="team-card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
        <?php if ($m['photo']): ?>
          <img src="<?= e($m['photo']) ?>" alt="<?= e($m['name']) ?>" style="width:84px;height:84px;border-radius:50%;object-fit:cover;margin:0 auto 1.1rem;display:block;">
        <?php else: ?>
          <div class="team-avatar"><?= e($m['avatar_initials']) ?></div>
        <?php endif; ?>
        <div class="team-name"><?= e($m['name']) ?></div>
        <div class="team-role"><?= e($m['role']) ?></div>
        <p class="team-bio"><?= e($m['bio']) ?></p>
        <div class="team-social">
          <a href="https://www.linkedin.com/company/ymr-marine-solutions-llp" aria-label="Email" target="_blank"><i class="fas fa-envelope"></i></a>
          <a href="#contact" aria-label="Phone"><i class="fas fa-phone"></i></a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ════════ PORTS ════════ -->
<div class="section-alt">
<section id="ports">
  <div class="section">
    <div class="reveal ports-intro">
      <div class="tag">Coverage</div>
      <h2 class="sec-h2">Major ports covered<br>across the globe</h2>
      <p class="sec-body">From major ports across the globe, we mobilise quickly to deliver reliable marine surveying services wherever our clients operate.</p>
    </div>
    <div class="ports-grid">
      <?php foreach ($ports as $i => $p): ?>
      <div class="port-card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
        <div class="port-anchor"><i class="fas fa-anchor"></i></div>
        <div class="port-name"><?= e($p['name']) ?></div>
        <div class="port-state"><?= e($p['state']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="reveal">
      <div class="ports-extra"><i class="fas fa-ship"></i> Extended coverage — Europe on request</div>
    </div>
  </div>
</section>
</div>

<!-- ════════ TESTIMONIALS ════════ -->
<section id="testimonials">
  <div class="section">
    <div class="reveal">
      <div class="tag">Client Voice</div>
      <h2 class="sec-h2">What our clients say</h2>
    </div>
    <div class="testi-grid">
      <?php foreach ($testimonials as $i => $t): ?>
      <div class="testi-card reveal reveal-delay-<?= ($i % 4) + 1 ?>">
        <div class="testi-stars">
          <?php
          $full = floor($t['rating']);
          $half = ($t['rating'] - $full) >= 0.5;
          for ($s = 0; $s < $full; $s++) echo '<i class="fas fa-star"></i>';
          if ($half) echo '<i class="fas fa-star-half-alt"></i>';
          ?>
        </div>
        <p class="testi-quote"><?= e($t['quote']) ?></p>
        <div class="testi-author">
          <div class="testi-avatar"><?= e($t['avatar_initials']) ?></div>
          <div>
            <div class="testi-name"><?= e($t['author_name']) ?></div>
            <div class="testi-role"><?= e($t['author_role']) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ════════ CONTACT ════════ -->
<div class="section-alt">
<section id="contact">
  <div class="section">
    <div class="contact-grid">
      <div class="reveal">
        <div class="tag">Get In Touch</div>
        <h2 class="sec-h2">Start your survey<br>request today</h2>
        <p class="sec-body" style="margin-bottom:2rem;">Our team is available 24/7. Fill the form and we'll confirm your survey within the hour.</p>
        <div class="contact-info">
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-building"></i></div>
            <div><div class="ct">Office</div><span class="cv"><?= e($address) ?></span></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-envelope"></i></div>
            <div><div class="ct">Email</div><a href="mailto:<?= e($email) ?>" class="cv"><?= e($email) ?></a></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-phone"></i></div>
            <div><div class="ct">Phone</div><a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>" class="cv"><?= e($phone) ?></a></div>
          </div>
          <div class="contact-card">
            <div class="contact-icon"><i class="fas fa-clock"></i></div>
            <div><div class="ct">Availability</div><span class="cv">24 / 7 — including weekends & holidays</span></div>
          </div>
        </div>
        <?php if ($mapEmbed): ?>
        <div class="map-frame">
          <iframe src="<?= e($mapEmbed) ?>" width="600" height="250" style="border:0;" allowfullscreen loading="lazy"></iframe>
        </div>
        <?php endif; ?>
      </div>
      <div class="reveal reveal-delay-2">
        <form id="contactForm" method="POST" action="process_contact.php">
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Your Name</label>
              <input type="text" name="name" class="form-input" placeholder="John Smith" required>
            </div>
            <div class="form-group">
              <label class="form-label">Company</label>
              <input type="text" name="company" class="form-input" placeholder="ABC Shipping Ltd" required>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-input" placeholder="you@company.com" required>
            </div>
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-input" placeholder="+91 00000 00000">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Service Required</label>
            <select name="service" class="form-select form-input">
              <option value="">Select a service…</option>
              <?php foreach ($services as $s): ?>
              <option><?= e($s['title']) ?></option>
              <?php endforeach; ?>
              <option>Other</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Port / Location</label>
            <input type="text" name="port" class="form-input" placeholder="e.g. Visakhapatnam">
          </div>
          <div class="form-group">
            <label class="form-label">Message</label>
            <textarea name="message" class="form-textarea" placeholder="Tell us more about your requirements, vessel name, ETA…"></textarea>
          </div>
          <button type="submit" class="btn-submit">Send Request <i class="fas fa-arrow-right"></i></button>
        </form>
      </div>
    </div>
  </div>
</section>
</div>

<!-- ════════ FOOTER ════════ -->
<footer>
  <div class="footer-inner">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="logo-text">
          <?php if ($logo): ?><img src="<?= e($logo) ?>" width="40" height="40" alt=""><?php endif; ?>
          <span class="a">YMR</span> <span class="b">MARINE</span>
        </div>
        <p><?= e($footerText) ?></p>
        <div class="footer-social">
          <a href="https://www.linkedin.com/company/ymr-marine-solutions-llp" aria-label="LinkedIn" target="_blank"><i class="fab fa-linkedin-in"></i></a>
          <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
          <a href="mailto:<?= e($email) ?>" aria-label="Email"><i class="fas fa-envelope"></i></a>
        </div>
      </div>
      <div class="footer-col">
        <h6>Company</h6>
        <ul>
          <li><a href="about-us.php">About Us</a></li>
          <li><a href="#why-us">Why Choose Us</a></li>
          <li><a href="#team">Our Team</a></li>
          <li><a href="ports.php">Port Coverage</a></li>
          <li><a href="contact.php">Contact</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h6>Services</h6>
        <ul>
          <?php foreach (array_slice($services, 0, 6) as $s): ?>
          <li><a href="<?= e(servicePageUrl($s)) ?>"><?= e($s['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="footer-col">
        <h6>Contact</h6>
        <ul>
          <li><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li>
          <li><a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>"><?= e($phone) ?></a></li>
          <li><a href="#contact"><?= e($address) ?></a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <p><?= e($copyright) ?></p>
      <p>Designed for excellence in maritime services</p>
    </div>
  </div>
</footer>

<div class="toast" id="toast">
  <i class="fas fa-check-circle"></i>
  <span>Request sent! We'll be in touch within the hour.</span>
</div>

<script>
const ports = <?= json_encode(array_column($ports, 'name')) ?>;
const track = document.getElementById('ticker');
let html = '';
[...ports, ...ports].forEach(p => {
  html += `<span class="ticker-item"><span class="dot"></span>${p}</span>`;
});
track.innerHTML = html;

const nav = document.getElementById('mainNav');
window.addEventListener('scroll', () => nav.classList.toggle('scrolled', window.scrollY > 40), { passive: true });

const toggle = document.getElementById('navToggle');
const mobile = document.getElementById('navMobile');
toggle.addEventListener('click', () => {
  toggle.classList.toggle('open');
  mobile.classList.toggle('open');
});
mobile.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
  toggle.classList.remove('open');
  mobile.classList.remove('open');
}));

document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const id = a.getAttribute('href');
    if (id === '#') return;
    const t = document.querySelector(id);
    if (!t) return;
    e.preventDefault();
    window.scrollTo({ top: t.getBoundingClientRect().top + scrollY - (nav.offsetHeight + 16), behavior: 'smooth' });
  });
});

const revealEls = document.querySelectorAll('.reveal');
const io = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
}, { threshold: 0.12 });
revealEls.forEach(el => io.observe(el));

document.getElementById('contactForm').addEventListener('submit', function(e) {
  // Let it submit normally to process_contact.php, or use AJAX if preferred
});
</script>
</body>
</html>
