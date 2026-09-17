<?php
/** Expects $chrome, optional $services for footer */
$c = $chrome;
$services = $services ?? [];

// wa.me needs digits only, with country code; assume a bare 10-digit
// number (no country code entered) is Indian and prefix 91.
$waDigits = preg_replace('/\D+/', '', $c['whatsapp'] ?? '');
if (strlen($waDigits) === 10) $waDigits = '91' . $waDigits;
$waText = rawurlencode('Hi YMR Marine, I would like to enquire about a survey.');
?>
<footer>
  <div class="footer-inner">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="logo-text">
          <?php if ($c['logo']): ?><img src="<?= e($c['logo']) ?>" width="40" height="40" alt=""><?php endif; ?>
          <span class="a">YMR</span> <span class="b">MARINE</span>
        </div>
        <p><?= e($c['footerText']) ?></p>
      </div>
      <div class="footer-col">
        <h6>Company</h6>
        <ul>
          <li><a href="about-us.php">About Us</a></li>
          <li><a href="index.php#why-us">Why Choose Us</a></li>
          <li><a href="index.php#team">Our Team</a></li>
          <li><a href="ports.php">Port Coverage</a></li>
          <li><a href="contact.php">Contact</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h6>Services</h6>
        <ul>
          <?php
          if (empty($services) && function_exists('getDB')) {
              try {
                  require_once __DIR__ . '/service_helpers.php';
                  $services = getDB()->query('SELECT title, slug FROM services WHERE is_active = 1 ORDER BY sort_order LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
              } catch (Exception $e) { $services = []; }
          }
          foreach (array_slice($services, 0, 6) as $fs):
              $href = function_exists('servicePageUrl') ? servicePageUrl($fs) : 'index.php#services';
          ?>
          <li><a href="<?= e($href) ?>"><?= e($fs['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="footer-col">
        <h6>Contact</h6>
        <ul>
          <li><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></li>
          <li><a href="tel:<?= e(preg_replace('/\s+/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a></li>
          <li><a href="contact.php"><?= e($c['address']) ?></a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <p><?= e($c['copyright']) ?></p>
      <p>Designed for excellence in maritime services</p>
    </div>
  </div>
</footer>
<?php if ($waDigits !== ''): ?>
<a href="https://wa.me/<?= e($waDigits) ?>?text=<?= e($waText) ?>"
   class="wa-float-btn" target="_blank" rel="noopener" aria-label="Chat with us on WhatsApp">
  <i class="fab fa-whatsapp"></i>
</a>
<style>
.wa-float-btn {
  position: fixed; right: 20px; bottom: 20px; z-index: 1000;
  width: 58px; height: 58px; border-radius: 50%;
  background: #25d366; color: #fff; display: flex; align-items: center; justify-content: center;
  font-size: 30px; box-shadow: 0 8px 22px rgba(0,0,0,.28);
  transition: transform .18s ease, box-shadow .18s ease;
}
.wa-float-btn:hover { transform: scale(1.08); box-shadow: 0 10px 28px rgba(0,0,0,.35); color: #fff; }
@media (max-width: 576px) { .wa-float-btn { right: 14px; bottom: 14px; width: 52px; height: 52px; font-size: 26px; } }
</style>
<?php endif; ?>
<script>
const nav = document.getElementById('mainNav');
window.addEventListener('scroll', () => nav.classList.toggle('scrolled', window.scrollY > 40), { passive: true });
const toggle = document.getElementById('navToggle');
const mobile = document.getElementById('navMobile');
if (toggle && mobile) {
  toggle.addEventListener('click', () => { toggle.classList.toggle('open'); mobile.classList.toggle('open'); });
  mobile.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
    toggle.classList.remove('open'); mobile.classList.remove('open');
  }));
}
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
if (revealEls.length) {
  const io = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
  }, { threshold: 0.12 });
  revealEls.forEach(el => io.observe(el));
}
</script>
</body>
</html>
