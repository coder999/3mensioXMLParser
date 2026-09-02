<?php
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/ga.php';

$user = require_login();

$gaError = null;
$ga = null;
try {
    $ga = ga_dashboard_data(isset($_GET['refresh']));
} catch (Throwable $e) {
    $gaError = $e->getMessage();
}

function fmt_num(int $n): string
{
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 10000)   return round($n / 1000, 1) . 'K';
    return number_format($n);
}

function delta_html(int $cur, int $prev): string
{
    if ($prev <= 0) {
        return '<div class="delta"><span class="vs">no prior-period data</span></div>';
    }
    $pct = ($cur - $prev) / $prev * 100;
    $cls = $pct >= 0 ? 'up' : 'down';
    $sign = $pct >= 0 ? '+' : '−';
    return '<div class="delta ' . $cls . '">' . $sign . number_format(abs($pct), 1) . '%'
         . ' <span class="vs">vs previous 28 days</span></div>';
}

admin_header('Dashboard', 'dashboard');
?>
<h1>Dashboard</h1>
<p class="sub">Welcome back<?= $user['username'] ? ', ' . h($user['username']) : '' ?>. Keep an eye on site traffic.</p>
<?php flash_render(); ?>

<?php if ($ga !== null): ?>
  <?php if (empty($ga['daily'])): ?>
  <div class="card" style="margin-bottom:16px;">
    <h2>Google Analytics</h2>
    <p class="hint">No analytics data reported yet for the last 28 days.</p>
  </div>
  <?php else: ?>
  <?php
    $t = $ga['totals'];
    $p = $ga['prev'];
    $days = max(1, count($ga['daily']));
    $avg = (int)round($t['users'] / $days);
  ?>
  <div class="tiles">
    <div class="tile">
      <div class="label">Unique users, last 28 days</div>
      <div class="value"><?= fmt_num($t['users']) ?></div>
      <?= delta_html($t['users'], $p['users']) ?>
    </div>
    <div class="tile">
      <div class="label">Avg unique users per day</div>
      <div class="value"><?= fmt_num($avg) ?></div>
    </div>
    <div class="tile">
      <div class="label">Sessions</div>
      <div class="value"><?= fmt_num($t['sessions']) ?></div>
      <?= delta_html($t['sessions'], $p['sessions']) ?>
    </div>
    <div class="tile">
      <div class="label">Page views</div>
      <div class="value"><?= fmt_num($t['pageviews']) ?></div>
      <?= delta_html($t['pageviews'], $p['pageviews']) ?>
    </div>
  </div>

  <?php
    // ---- Unique-users-per-day line chart (inline SVG + hover tooltip) ----
    $daily = $ga['daily'];
    $n = count($daily);
    $W = 1000; $H = 300;
    $padL = 46; $padR = 14; $padT = 16; $padB = 30;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $maxV = 0;
    foreach ($daily as $d) { $maxV = max($maxV, $d['users']); }
    $yTop = 5;
    if ($maxV > 0) {
        $mag = pow(10, floor(log10($maxV)));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $maxV) { $yTop = (int)ceil($m * $mag); break; }
        }
    }

    $pts = [];
    foreach ($daily as $i => $d) {
        $x = $padL + ($n > 1 ? $plotW * $i / ($n - 1) : $plotW / 2);
        $y = $padT + $plotH * (1 - ($yTop > 0 ? $d['users'] / $yTop : 0));
        $pts[] = [round($x, 1), round($y, 1)];
    }
    $linePath = '';
    foreach ($pts as $i => $pt) {
        $linePath .= ($i === 0 ? 'M' : 'L') . $pt[0] . ' ' . $pt[1];
    }
    $baselineY = $padT + $plotH;
    $areaPath = $linePath . 'L' . $pts[$n - 1][0] . ' ' . $baselineY . 'L' . $pts[0][0] . ' ' . $baselineY . 'Z';

    $tipData = array_map(function ($d, $pt) {
        return [
            'x' => $pt[0], 'y' => $pt[1],
            'date'  => preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $d['date']),
            'users' => $d['users'], 'pageviews' => $d['pageviews'],
        ];
    }, $daily, $pts);
  ?>
  <div class="card chart-card" style="margin-bottom:16px;">
    <div class="chart-head">
      <h2>Unique users per day — last 28 days</h2>
      <span class="when">
        Updated <?= h(date('M j, g:i a', $ga['fetched_at'])) ?> ·
        <a href="?refresh=1">refresh</a>
      </span>
    </div>
    <div class="chart-wrap" id="usersChart">
      <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Line chart of unique users per day for the last 28 days">
        <?php for ($g = 0; $g <= 4; $g++):
            $gy = round($padT + $plotH * $g / 4, 1);
            $gv = (int)round($yTop * (4 - $g) / 4); ?>
          <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $W - $padR ?>" y2="<?= $gy ?>"
                stroke="var(--grid)" stroke-width="1"></line>
          <text x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end"
                font-size="11" fill="var(--muted)"><?= number_format($gv) ?></text>
        <?php endfor; ?>
        <line x1="<?= $padL ?>" y1="<?= $baselineY ?>" x2="<?= $W - $padR ?>" y2="<?= $baselineY ?>"
              stroke="var(--baseline)" stroke-width="1"></line>

        <?php foreach ($tipData as $i => $d):
            if ($i % 7 !== 0 && $i !== $n - 1) continue;
            if ($i === $n - 1 && ($n - 1) % 7 < 3 && $n > 7) continue; ?>
          <text x="<?= $d['x'] ?>" y="<?= $H - 8 ?>" text-anchor="middle"
                font-size="11" fill="var(--muted)"><?= h(date('M j', strtotime($d['date']))) ?></text>
        <?php endforeach; ?>

        <path d="<?= $areaPath ?>" fill="var(--series)" opacity="0.10"></path>
        <path d="<?= $linePath ?>" fill="none" stroke="var(--series)" stroke-width="2"
              stroke-linejoin="round" stroke-linecap="round"></path>
        <?php $last = $pts[$n - 1]; ?>
        <circle cx="<?= $last[0] ?>" cy="<?= $last[1] ?>" r="6.5" fill="var(--surface)"></circle>
        <circle cx="<?= $last[0] ?>" cy="<?= $last[1] ?>" r="4.5" fill="var(--series)"></circle>
        <text x="<?= min($last[0] + 10, $W - 4) ?>" y="<?= max($last[1] - 8, 12) ?>"
              font-size="12" font-weight="600" fill="var(--ink)"
              text-anchor="<?= $last[0] > $W - 60 ? 'end' : 'start' ?>"><?= number_format($daily[$n - 1]['users']) ?></text>

        <line id="crosshair" x1="0" y1="<?= $padT ?>" x2="0" y2="<?= $baselineY ?>"
              stroke="var(--baseline)" stroke-width="1" style="display:none"></line>
        <g id="hoverDot" style="display:none">
          <circle r="6.5" fill="var(--surface)"></circle>
          <circle r="4.5" fill="var(--series)"></circle>
        </g>
        <rect x="<?= $padL ?>" y="<?= $padT ?>" width="<?= $plotW ?>" height="<?= $plotH ?>"
              fill="transparent" id="hoverPad"></rect>
      </svg>
      <div class="chart-tip" id="chartTip"></div>
    </div>
  </div>
  <script>
  (function () {
    const data = <?= json_encode(array_values($tipData)) ?>;
    const wrap = document.getElementById('usersChart');
    const svg = wrap.querySelector('svg');
    const pad = document.getElementById('hoverPad');
    const cross = document.getElementById('crosshair');
    const dot = document.getElementById('hoverDot');
    const tip = document.getElementById('chartTip');
    const VBW = <?= $W ?>;

    function show(ev) {
      const rect = svg.getBoundingClientRect();
      const vx = (ev.clientX - rect.left) * VBW / rect.width;
      let best = 0, bestD = Infinity;
      data.forEach((d, i) => {
        const dd = Math.abs(d.x - vx);
        if (dd < bestD) { bestD = dd; best = i; }
      });
      const d = data[best];
      cross.setAttribute('x1', d.x); cross.setAttribute('x2', d.x);
      cross.style.display = '';
      dot.setAttribute('transform', 'translate(' + d.x + ',' + d.y + ')');
      dot.style.display = '';
      const dateStr = new Date(d.date + 'T12:00:00')
        .toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
      tip.innerHTML = '<div class="d">' + dateStr + '</div>' +
        '<div><span class="v">' + d.users.toLocaleString() + '</span> unique users</div>' +
        '<div class="d">' + d.pageviews.toLocaleString() + ' page views</div>';
      tip.style.display = 'block';
      const px = d.x * rect.width / VBW;
      const py = d.y * rect.height / <?= $H ?>;
      const tw = tip.offsetWidth;
      tip.style.left = Math.min(Math.max(px - tw / 2, 0), rect.width - tw) + 'px';
      tip.style.top = Math.max(py - tip.offsetHeight - 14, 0) + 'px';
    }
    function hide() {
      cross.style.display = 'none';
      dot.style.display = 'none';
      tip.style.display = 'none';
    }
    pad.addEventListener('mousemove', show);
    pad.addEventListener('mouseleave', hide);
  })();
  </script>

  <div class="grid cols-2">
    <div class="card">
      <h2>Top pages — last 28 days</h2>
      <?php if (empty($ga['top_pages'])): ?>
        <p class="hint">No page data reported yet.</p>
      <?php else: ?>
      <table class="data">
        <thead><tr><th>Page</th><th class="num">Views</th><th class="num">Users</th></tr></thead>
        <tbody>
        <?php foreach ($ga['top_pages'] as $pg): ?>
          <tr>
            <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($pg['path']) ?></td>
            <td class="num"><?= number_format($pg['views']) ?></td>
            <td class="num"><?= number_format($pg['users']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Daily detail</h2>
      <div style="max-height:340px;overflow-y:auto;">
        <table class="data">
          <thead><tr><th>Date</th><th class="num">Users</th><th class="num">Sessions</th><th class="num">Views</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($ga['daily']) as $d): ?>
            <tr>
              <td><?= h(date('D, M j', strtotime(preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $d['date'])))) ?></td>
              <td class="num"><?= number_format($d['users']) ?></td>
              <td class="num"><?= number_format($d['sessions']) ?></td>
              <td class="num"><?= number_format($d['pageviews']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($gaError !== null): ?>
  <div class="card" style="margin-bottom:16px;">
    <h2>Google Analytics</h2>
    <div class="flash flash-err" style="margin:0 0 10px;">Couldn't load analytics: <?= h($gaError) ?></div>
    <p class="hint">Check the <code class="k">GA4_PROPERTY_ID</code> GitHub Actions variable, confirm <code class="k">/var/www/ga-secrets/reader.json</code> is mounted and readable, and confirm the service account has Viewer access on the GA4 property.</p>
  </div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px;">
    <h2>Google Analytics — not connected yet</h2>
    <p class="hint">Once connected, this dashboard shows unique users per day, sessions, page views, and top pages.</p>
    <ol class="steps">
      <li>In <a href="https://analytics.google.com/" target="_blank" rel="noopener">Google Analytics</a>, find the GA4 property for 3mensio.marktuttlemd.com (measurement ID <code class="k">G-8LDBKP7S8S</code>) and note its numeric <b>Property ID</b> (Admin → Property settings).</li>
      <li>Confirm the fleet-wide reader service account (Admin → Property access management) has <b>Viewer</b> access on this property. The credential itself lives once at <code class="k">/etc/secrets/ga/reader.json</code> on the VPS and is mounted read-only into every site — it is not a per-site secret.</li>
      <li>Set the <code class="k">GA4_PROPERTY_ID</code> and <code class="k">GA4_MEASUREMENT_ID</code> GitHub Actions variables on the <code class="k">3mensioXMLParser</code> repo and redeploy.</li>
    </ol>
  </div>
<?php endif; ?>
<?php admin_footer();
