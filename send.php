<?php
// Dancker Systems – modtager kontaktformularen og sender den som mail til kontakt@.
// Intet gemmes: beskeden sendes kun videre som mail og glemmes derefter.
//
// Mailen sendes ved at logge ind på Simplys script-mailserver (websmtp.simply.com) som kontakt@, ligesom et
// mailprogram gør. Det er nødvendigt, fordi domænets DMARC-regel ellers afviser mailen.
// Login står i mail-config.php, som aldrig ligger i git (se UDGIV.md).

const MODTAGER = 'kontakt@danckersystems.dk';

// Kun POST (altså når formularen sendes). Alt andet sendes tilbage til kontaktsiden.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /kontakt.html', true, 303);
    exit;
}

// Hent et felt som ren tekst og fjern mellemrum i enderne
function felt(string $navn): string {
    return trim((string)($_POST[$navn] ?? ''));
}

// Fjern linjeskift, så ingen kan sætte ekstra mail-headers ind via et felt
function enLinje(string $tekst): string {
    return str_replace(["\r", "\n"], ' ', $tekst);
}

// Kod en header-tekst med æ, ø, å korrekt (fx emnet)
function kodHeader(string $tekst): string {
    return '=?UTF-8?B?' . base64_encode($tekst) . '?=';
}

// Send tilbage til formularen med fejlkode 2 og hvilket trin, der fejlede (ingen persondata)
function fejl(string $trin): void {
    error_log('send.php: fejl i trin ' . $trin);
    header('Location: /kontakt.html?fejl=2&trin=' . rawurlencode($trin) . '#kontaktformular', true, 303);
    exit;
}

// ---------- Spambeskyttelse ----------
// 1) "Honningkrukke": et skjult felt, som mennesker ikke ser. Robotter udfylder det ofte.
// 2) Tidstjek: formularen skal have været åben i mindst 3 sekunder.
$honning = felt('hjemmeside');
$start   = (int)felt('start');
if ($honning !== '' || ($start > 0 && time() - $start < 3)) {
    // Lad som om det lykkedes, så robotten ikke prøver igen
    header('Location: /tak.html', true, 303);
    exit;
}

// ---------- Tjek felterne ----------
$navn    = enLinje(felt('navn'));
$mail    = enLinje(felt('email'));
$firma   = enLinje(felt('virksomhed'));
$opgave  = enLinje(felt('opgave'));
$besked  = felt('besked');

$tilladteOpgaver = ['Ny hjemmeside', 'Forbedring af en eksisterende side', 'Et lille system', 'Andet'];

$ugyldig = $navn === '' || mb_strlen($navn) > 100
        || !filter_var($mail, FILTER_VALIDATE_EMAIL) || mb_strlen($mail) > 150
        || mb_strlen($firma) > 150
        || !in_array($opgave, $tilladteOpgaver, true)
        || $besked === '' || mb_strlen($besked) > 5000;

if ($ugyldig) {
    header('Location: /kontakt.html?fejl=1#kontaktformular', true, 303);
    exit;
}

// ---------- Byg mailen ----------
$emne = 'Forespørgsel: ' . $opgave . ' – ' . $navn;
$tekst = "Ny forespørgsel fra danckersystems.dk\n\n"
       . "Navn: $navn\n"
       . "E-mail: $mail\n"
       . 'Virksomhed: ' . ($firma !== '' ? $firma : '-') . "\n"
       . "Opgave: $opgave\n\n"
       . $besked . "\n";

// ---------- Find login (mail-config.php) ----------
// Filen kan ligge ét af to steder (det første, der findes, bruges):
//   1) én mappe over public_html (bedst: kan slet ikke nås fra nettet)
//   2) i public_html (beskyttet af .htaccess, og en PHP-fil viser alligevel intet, hvis den åbnes)
$cfg = null;
foreach ([dirname(__DIR__) . '/mail-config.php', __DIR__ . '/mail-config.php'] as $fil) {
    if (@is_file($fil)) {
        $cfg = require $fil;
        break;
    }
}
if (!is_array($cfg) || empty($cfg['bruger']) || empty($cfg['kode'])) {
    fejl('config');
}

// ---------- Send ----------
// Prøv først porten fra filen (typisk 587 med STARTTLS). Kan den ikke forbinde, prøves 465 med SSL.
$resultat = smtpSend($cfg, MODTAGER, $mail, $emne, $tekst, (int)($cfg['port'] ?? 587));
if ($resultat === 'forbind') {
    $resultat = smtpSend($cfg, MODTAGER, $mail, $emne, $tekst, 465);
}

if ($resultat === 'ok') {
    header('Location: /tak.html', true, 303);
    exit;
}
fejl($resultat);


// =====================================================================
// En lille SMTP-klient. Port 465 bruger SSL fra start; andre porte skifter
// til kryptering med STARTTLS. Returnerer "ok" eller navnet på det trin,
// der fejlede: forbind, hilsen, starttls, tls, login, afsender, modtager, data, afvist.
// =====================================================================
function smtpSend(array $cfg, string $til, string $svarTil, string $emne, string $tekst, int $port): string {
    $fra   = $cfg['bruger'];
    // Simply kræver websmtp.simply.com til afsendelse fra webhotellet (smtp.simply.com er kun til mailprogrammer).
    // Serveren i mail-config.php ignoreres derfor med vilje.
    $vaert = 'websmtp.simply.com';
    $ssl   = $port === 465;

    $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $vaert . ':' . $port, $nr, $fejltekst, 15);
    if (!$fp) {
        error_log("send.php: kan ikke forbinde til $vaert:$port: $fejltekst");
        return 'forbind';
    }
    stream_set_timeout($fp, 15);

    // Læs et (evt. flerlinjet) svar og tjek, at koden er den forventede
    $svar = function (string $forventet) use ($fp): bool {
        $linje = '';
        while (($l = fgets($fp, 515)) !== false) {
            $linje = $l;
            if (isset($l[3]) && $l[3] === ' ') {
                break; // sidste linje i svaret
            }
        }
        if (strpos($linje, $forventet) !== 0) {
            error_log('send.php: SMTP-svar: ' . trim($linje));
            return false;
        }
        return true;
    };
    $skriv = function (string $kommando) use ($fp): void {
        fwrite($fp, $kommando . "\r\n");
    };
    $stop = function (string $trin) use ($fp, $skriv): string {
        $skriv('QUIT');
        fclose($fp);
        return $trin;
    };

    if (!$svar('220')) return $stop('hilsen');
    $skriv('EHLO danckersystems.dk');
    if (!$svar('250')) return $stop('hilsen');

    if (!$ssl) {
        $skriv('STARTTLS');
        if (!$svar('220')) return $stop('starttls');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $stop('tls');
        $skriv('EHLO danckersystems.dk');
        if (!$svar('250')) return $stop('tls');
    }

    $skriv('AUTH LOGIN');
    if (!$svar('334')) return $stop('login');
    $skriv(base64_encode($cfg['bruger']));
    if (!$svar('334')) return $stop('login');
    $skriv(base64_encode($cfg['kode']));
    if (!$svar('235')) return $stop('login');

    $skriv("MAIL FROM:<$fra>");
    if (!$svar('250')) return $stop('afsender');
    $skriv("RCPT TO:<$til>");
    if (!$svar('250')) return $stop('modtager');
    $skriv('DATA');
    if (!$svar('354')) return $stop('data');

    $headers = [
        'Date: ' . date('r'),
        'From: ' . kodHeader('Dancker Systems – formular') . " <$fra>",
        "To: <$til>",
        "Reply-To: <$svarTil>", // Tryk "Svar", så svarer du direkte til kunden
        'Subject: ' . kodHeader($emne),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@danckersystems.dk>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    // Linjer, der starter med et punktum, skal have et ekstra punktum (SMTP-regel)
    $krop = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $tekst));
    $krop = str_replace("\n", "\r\n", $krop);
    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $krop . "\r\n.\r\n");
    if (!$svar('250')) return $stop('afvist');

    return $stop('ok');
}
