<?php
/*
 * Enquête Elfsteden-log – © 2026 Richard Hendriksen, PD3RAH – www.pd3rah.nl
 * Gemaakt met behulp van Claude (AI van Anthropic).
 *
 * Plaatsen op: https://www.pd3rah.nl/elfsteden-enquete.php
 * Verstuurt de antwoorden via de mailserver van je eigen hosting naar MAIL_TO
 * en bewaart ze ook als reservekopie in de map enquete-antwoorden/ (afgeschermd).
 */

// ===== INSTELLINGEN =====
const MAIL_TO   = 'info@pd3rah.nl';
const MAIL_FROM = 'info@pd3rah.nl';   // moet een adres op je eigen domein zijn (anders kans op spam-map)
const DATA_DIR  = __DIR__ . '/enquete-antwoorden';
const MAX_PER_UUR = 5;                // maximaal aantal inzendingen per IP-adres per uur
// ========================

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Amsterdam');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function clean($s, $max = 2000) {
    $s = str_replace(["\r\n", "\r"], "\n", trim((string)$s));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return mb_substr($s, 0, $max);
}
function oneLine($s, $max = 100) { return clean(str_replace("\n", ' ', (string)$s), $max); }
function post($k) { return $_POST[$k] ?? ''; }
function val($k) { return h(post($k)); }
function chk($k, $v) {
    $p = $_POST[$k] ?? null;
    if (is_array($p)) return in_array($v, $p, true) ? ' checked' : '';
    return ($p === $v) ? ' checked' : '';
}
function choice($k, array $allowed) { $v = post($k); return in_array($v, $allowed, true) ? $v : ''; }

function ensureDataDir() {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0750, true);
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
    $ix = DATA_DIR . '/index.html';
    if (!file_exists($ix)) @file_put_contents($ix, '');
}
function rateLimited($ip) {
    ensureDataDir();
    $f = DATA_DIR . '/.ratelimit.json';
    $now = time();
    $data = [];
    if (is_file($f)) { $data = json_decode((string)@file_get_contents($f), true) ?: []; }
    foreach ($data as $k => $list) {
        $data[$k] = array_values(array_filter((array)$list, function ($t) use ($now) { return $t > $now - 3600; }));
        if (!$data[$k]) unset($data[$k]);
    }
    $key = hash('sha256', $ip);              // geen IP-adressen opslaan, alleen een hash
    $hits = $data[$key] ?? [];
    if (count($hits) >= MAX_PER_UUR) return true;
    $hits[] = $now; $data[$key] = $hits;
    @file_put_contents($f, json_encode($data), LOCK_EX);
    return false;
}

$fout = '';
$bedankt = isset($_GET['bedankt']);
$progVersie = preg_match('/^\d+\.\d+\.\d+$/', $_GET['v'] ?? '') ? $_GET['v'] : '';
$type = $_GET['t'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $progVersie = preg_match('/^\d+\.\d+\.\d+$/', post('pv')) ? post('pv') : '';
    // --- spamcontrole ---
    $honey = post('website');                       // verborgen veld: mensen vullen dit niet in
    $start = (int) post('ts');
    $tooFast = $start > 0 && (time() - $start) < 3; // binnen 3 seconden ingevuld = robot
    if ($honey !== '' || $tooFast) {
        header('Location: ?bedankt=1'); exit;       // robot: doen alsof het gelukt is
    }

    $antw = [
        'Call'                 => strtoupper(oneLine(post('call'), 20)),
        'Naam'                 => oneLine(post('naam'), 80),
        'E-mail'               => oneLine(post('email'), 120),
        'Versie'               => choice('versie', ['Windows (geïnstalleerd)', 'Browserversie', 'Weet ik niet']),
        'Band'                 => implode(', ', array_values(array_intersect((array)($_POST['band'] ?? []), ['80 m', '2 m']))),
        'Totaaloordeel'        => choice('oordeel', ['1', '2', '3', '4', '5']),
        'Gebruiksgemak'        => choice('gemak', ['1', '2', '3', '4', '5']),
        'Handleiding duidelijk'=> choice('handleiding', ['Ja', 'Deels', 'Nee', 'Niet gelezen']),
        'Wat was goed'         => clean(post('goed')),
        'Wat kan beter'        => clean(post('beter')),
        'Fouten of problemen'  => clean(post('fout')),
        'Volgend jaar weer'    => choice('volgendjaar', ['Ja', 'Misschien', 'Nee']),
        'Contact toegestaan'   => post('contact') === 'ja' ? 'Ja' : 'Nee',
        'Programmaversie'      => $progVersie ?: 'onbekend',
    ];
    foreach (['Totaaloordeel', 'Gebruiksgemak'] as $k) if ($antw[$k] !== '') $antw[$k] .= ' van 5';

    $inhoud = array_filter($antw, function ($v, $k) {
        return $v !== '' && !in_array($k, ['Call', 'Naam', 'E-mail', 'Versie', 'Contact toegestaan', 'Programmaversie'], true);
    }, ARRAY_FILTER_USE_BOTH);

    if (!$inhoud) {
        $fout = 'Vul minstens één vraag in.';
    } elseif ($antw['E-mail'] !== '' && !filter_var($antw['E-mail'], FILTER_VALIDATE_EMAIL)) {
        $fout = 'Het e-mailadres lijkt niet te kloppen.';
    } elseif (rateLimited($_SERVER['REMOTE_ADDR'] ?? '')) {
        $fout = 'Je hebt de enquête al een paar keer verstuurd. Probeer het later opnieuw.';
    } else {
        $datum = date('Y-m-d H:i');
        // --- e-mail opbouwen ---
        $tekst = "Nieuwe enquête Elfsteden-log ($datum)\n" . str_repeat('=', 44) . "\n\n";
        foreach ($antw as $k => $v) {
            if ($v === '') continue;
            $tekst .= (strpos($v, "\n") !== false || mb_strlen($v) > 60) ? "$k:\n  " . str_replace("\n", "\n  ", $v) . "\n\n" : "$k: $v\n";
        }
        $subject = 'Enquête Elfsteden-log' . ($antw['Call'] ? ' – ' . $antw['Call'] : '');
        $headers = [
            'From: Elfsteden-log enquête <' . MAIL_FROM . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Elfsteden-log-enquete',
        ];
        if ($antw['E-mail'] !== '') $headers[] = 'Reply-To: ' . $antw['E-mail'];  // al gevalideerd, dus geen header-injectie
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = @mail(MAIL_TO, $encSubject, $tekst, implode("\r\n", $headers), '-f' . MAIL_FROM);
        if (!$ok) $ok = @mail(MAIL_TO, $encSubject, $tekst, implode("\r\n", $headers));

        // --- reservekopie (ook als de mail mislukt) ---
        ensureDataDir();
        $csv = DATA_DIR . '/antwoorden.csv';
        $nieuw = !file_exists($csv);
        if ($fh = @fopen($csv, 'a')) {
            if (flock($fh, LOCK_EX)) {
                if ($nieuw) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, array_merge(['Datum', 'Mail verstuurd'], array_keys($antw)), ';'); }
                fputcsv($fh, array_merge([$datum, $ok ? 'ja' : 'nee'], array_values($antw)), ';');
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        }

        if ($ok || file_exists($csv)) { header('Location: ?bedankt=1'); exit; }
        $fout = 'Versturen is niet gelukt. Probeer het later opnieuw of mail je antwoorden naar ' . MAIL_TO . '.';
    }
}
$ts = time();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Enquête Elfsteden-log – PD3RAH</title>
<style>
:root{--acc:#1d5fa8;--ink:#1c2430;--mut:#6b7684;--line:#d9dee4;--ok:#1f7a3d;--warn:#b3261e}
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f8;color:var(--ink)}
header{background:var(--acc);color:#fff;padding:18px 20px}
header h1{margin:0;font-size:22px}header p{margin:4px 0 0;opacity:.9;font-size:14px}
main{max-width:720px;margin:0 auto;padding:20px}
.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px 20px;margin-bottom:14px}
.q{margin-bottom:18px}.q:last-child{margin-bottom:0}
.q>label,.q>.lbl{display:block;font-weight:600;margin-bottom:6px}
.opt{color:var(--mut);font-weight:400;font-size:13px}
input[type=text],input[type=email],textarea{width:100%;font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:6px}
textarea{min-height:90px;resize:vertical}
input:focus,textarea:focus{outline:2px solid var(--acc);outline-offset:-1px}
.choices{display:flex;flex-wrap:wrap;gap:8px}
.choices label{display:flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:6px;padding:6px 12px;cursor:pointer;font-weight:400}
.choices input{accent-color:var(--acc)}
.stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:2px}
.stars input{position:absolute;opacity:0;width:0}
.stars label{font-size:32px;line-height:1;color:#c9d0d8;cursor:pointer;padding:0 2px}
.stars input:checked~label,.stars label:hover,.stars label:hover~label{color:#e0a400}
.stars input:focus-visible+label{outline:2px solid var(--acc);border-radius:4px}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:560px){.row2{grid-template-columns:1fr}}
button{font:inherit;font-weight:600;padding:10px 22px;border:none;border-radius:6px;background:var(--acc);color:#fff;cursor:pointer}
.hp{position:absolute;left:-5000px;width:1px;height:1px;overflow:hidden}
.err{color:var(--warn);font-weight:600;margin-top:12px}
.small{font-size:13px;color:var(--mut)}
.done{text-align:center;padding:30px 20px}.done h2{color:var(--ok);margin-top:0}
footer{text-align:center;font-size:13px;color:var(--mut);padding:10px 20px 30px}
footer a{color:var(--acc)}
</style>
</head>
<body>
<header>
  <h1>Enquête Elfsteden-log</h1>
  <p>Wat vond je van het logprogramma voor de 42e Friese Elfsteden Contest?</p>
</header>
<main>
<?php if ($bedankt): ?>
  <div class="card done">
    <h2>Bedankt!</h2>
    <p>Je antwoorden zijn verstuurd. Hartelijk dank voor je hulp bij het verbeteren van Elfsteden-log.</p>
    <p>73, Richard – PD3RAH</p>
  </div>
<?php else: ?>
<form method="post" action="elfsteden-enquete.php" accept-charset="UTF-8">
  <input type="hidden" name="ts" value="<?= $ts ?>">
  <input type="hidden" name="pv" value="<?= h($progVersie) ?>">
  <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

  <div class="card">
    <p style="margin-top:0">Bedankt dat je Elfsteden-log hebt gebruikt! Met jouw antwoorden kan ik het programma verbeteren. Invullen duurt ongeveer 2 minuten. Alle vragen zijn vrijwillig.</p>
    <div class="row2">
      <div class="q"><label for="call">Call <span class="opt">(optioneel)</span></label><input type="text" id="call" name="call" maxlength="20" autocomplete="off" style="text-transform:uppercase" value="<?= val('call') ?>"></div>
      <div class="q"><label for="naam">Naam <span class="opt">(optioneel)</span></label><input type="text" id="naam" name="naam" maxlength="80" value="<?= val('naam') ?>"></div>
    </div>
    <div class="q"><label for="email">E-mail <span class="opt">(optioneel, alleen als je een antwoord wilt)</span></label><input type="email" id="email" name="email" maxlength="120" value="<?= val('email') ?>"></div>
  </div>

  <div class="card">
    <div class="q"><span class="lbl">Welke versie heb je gebruikt?</span>
      <div class="choices">
        <?php $vDef = $_SERVER['REQUEST_METHOD'] !== 'POST' ? ($type === 'app' ? 'Windows (geïnstalleerd)' : ($type === 'web' ? 'Browserversie' : '')) : null; ?>
        <?php foreach (['Windows (geïnstalleerd)', 'Browserversie', 'Weet ik niet'] as $o): ?>
        <label><input type="radio" name="versie" value="<?= h($o) ?>"<?= $vDef !== null ? ($vDef === $o ? ' checked' : '') : chk('versie', $o) ?>> <?= h($o) ?></label>
        <?php endforeach; ?>
      </div></div>
    <div class="q"><span class="lbl">Op welke band(en) heb je gelogd?</span>
      <div class="choices">
        <label><input type="checkbox" name="band[]" value="80 m"<?= chk('band', '80 m') ?>> 80 m</label>
        <label><input type="checkbox" name="band[]" value="2 m"<?= chk('band', '2 m') ?>> 2 m</label>
      </div></div>
  </div>

  <div class="card">
    <?php foreach (['oordeel' => 'Wat is je totaaloordeel?', 'gemak' => 'Hoe makkelijk was het programma te gebruiken?'] as $n => $vraag): ?>
    <div class="q"><span class="lbl"><?= h($vraag) ?></span>
      <div class="stars">
        <?php for ($i = 5; $i >= 1; $i--): ?>
        <input type="radio" id="<?= $n . $i ?>" name="<?= $n ?>" value="<?= $i ?>"<?= chk($n, (string)$i) ?>><label for="<?= $n . $i ?>" title="<?= $i ?> van 5">★</label>
        <?php endfor; ?>
      </div></div>
    <?php endforeach; ?>
    <div class="q"><span class="lbl">Was de handleiding duidelijk?</span>
      <div class="choices">
        <?php foreach (['Ja', 'Deels', 'Nee', 'Niet gelezen'] as $o): ?>
        <label><input type="radio" name="handleiding" value="<?= h($o) ?>"<?= chk('handleiding', $o) ?>> <?= h($o) ?></label>
        <?php endforeach; ?>
      </div></div>
  </div>

  <div class="card">
    <div class="q"><label for="goed">Wat vond je goed?</label><textarea id="goed" name="goed" maxlength="2000"><?= val('goed') ?></textarea></div>
    <div class="q"><label for="beter">Wat moet er beter of wat mis je?</label><textarea id="beter" name="beter" maxlength="2000"><?= val('beter') ?></textarea></div>
    <div class="q"><label for="fout">Ben je fouten of problemen tegengekomen? <span class="opt">(bijv. verkeerde punten, vastlopen, export)</span></label><textarea id="fout" name="fout" maxlength="2000"><?= val('fout') ?></textarea></div>
  </div>

  <div class="card">
    <div class="q"><span class="lbl">Gebruik je Elfsteden-log volgend jaar weer?</span>
      <div class="choices">
        <?php foreach (['Ja', 'Misschien', 'Nee'] as $o): ?>
        <label><input type="radio" name="volgendjaar" value="<?= h($o) ?>"<?= chk('volgendjaar', $o) ?>> <?= h($o) ?></label>
        <?php endforeach; ?>
      </div></div>
    <div class="q choices"><label><input type="checkbox" name="contact" value="ja"<?= chk('contact', 'ja') ?>> Je mag contact met mij opnemen over mijn antwoorden</label></div>
    <button type="submit">Versturen</button>
    <?php if ($fout): ?><div class="err" role="alert"><?= h($fout) ?></div><?php endif; ?>
    <p class="small">Je antwoorden worden per e-mail naar Richard Hendriksen (PD3RAH) gestuurd en op de server van www.pd3rah.nl bewaard. Ze worden alleen gebruikt om Elfsteden-log te verbeteren en niet gedeeld met anderen.</p>
  </div>
</form>
<?php endif; ?>
</main>
<footer>Elfsteden-log · © 2026 Richard Hendriksen, PD3RAH · <a href="https://www.pd3rah.nl">www.pd3rah.nl</a></footer>
</body>
</html>
