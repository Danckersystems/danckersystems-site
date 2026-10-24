<?php
// Dancker Systems – modtager kontaktformularen og sender den som mail til kontakt@.
// Intet gemmes: beskeden sendes kun videre som mail og glemmes derefter.
//
// Mailen sendes ved at logge ind på Simplys mailserver (SMTP) som kontakt@, ligesom et
// mailprogram gør. Det er nødvendigt, fordi domænets DMARC-regel ellers afviser mailen.
// Login står i filen mail-config.php, som ligger UDEN FOR public_html og aldrig i git.

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

// Kod en header-tekst med æ, ø, å korrekt (fx emnet og navnet)
function kodHeader(string $tekst): string {
    return '=?UTF-8?B?' . base64_encode($tekst) . '?=';
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

$fejl = $navn === '' || mb_strlen($navn) > 100
     || !filter_var($mail, FILTER_VALIDATE_EMAIL) || mb_strlen($mail) > 150
     || mb_strlen($firma) > 150
     || !in_array($opgave, $tilladteOpgaver, true)
     || $besked === '' || mb_strlen($besked) > 5000;

if ($fejl) {
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

// ---------- Send via SMTP ----------
// mail-config.php ligger én mappe over public_html og ser sådan ud:
//   <?php return ['vaert' => 'smtp.simply.com', 'port' => 587,
//                 'bruger' => 'kontakt@danckersystems.dk', 'kode' => 'DIN-KODE'];
$configFil = dirname(__DIR__) . '/mail-config.php';
if (!is_file($configFil)) {
    error_log('send.php: mail-config.php mangler');
    header('Location: /kontakt.html?fejl=2#kontaktformular', true, 303);
    exit;
}
$cfg = require $configFil;

$sendt = smtpSend($cfg, MODTAGER, $mail, $emne, $tekst);

header('Location: ' . ($sendt ? '/tak.html' : '/kontakt.html?fejl=2#kontaktformular'), true, 303);
exit;


// =====================================================================
// En lille SMTP-klient: forbinder, skifter til kryptering (STARTTLS),
// logger ind og afleverer mailen. Returnerer true, hvis serveren tog imod den.
// Ved fejl skrives kun SMTP-svaret i serverens fejllog (ingen persondata).
// =====================================================================
function smtpSend(array $cfg, string $til, string $svarTil, string $emne, string $tekst): bool {
    $fra  = $cfg['bruger'];
    $fp = @stream_socket_client('tcp://' . $cfg['vaert'] . ':' . (int)$cfg['port'], $nr, $fejltekst, 15);
    if (!$fp) { error_log("send.php: kan ikke forbinde: $fejltekst"); return false; }
    stream_set_timeout($fp, 15);

    // Læs et (evt. flerlinjet) svar og tjek, at koden er den forventede
    $svar = function (string $forventet) use ($fp): bool {
        $linje = '';
        while (($l = fgets($fp, 515)) !== false) {
            $linje = $l;
            if (isset($l[3]) && $l[3] === ' ') break;   // sidste linje i svaret
        }
        if (strpos($linje, $forventet) !== 0) { error_log('send.php: SMTP-svar: ' . trim($linje)); return false; }
        return true;
    };
    $skriv = function (string $kommando) use ($fp): void { fwrite($fp, $kommando . "\r\n"); };

    $ok = $svar('220')
       && ($skriv('EHLO danckersystems.dk') || true) && $svar('250')
       && ($skriv('STARTTLS') || true) && $svar('220')
       && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
       && ($skriv('EHLO danckersystems.dk') || true) && $svar('250')
       && ($skriv('AUTH LOGIN') || true) && $svar('334')
       && ($skriv(base64_encode($cfg['bruger'])) || true) && $svar('334')
       && ($skriv(base64_encode($cfg['kode'])) || true) && $svar('235')
       && ($skriv("MAIL FROM:<$fra>") || true) && $svar('250')
       && ($skriv("RCPT TO:<$til>") || true) && $svar('250')
       && ($skriv('DATA') || true) && $svar('354');

    if ($ok) {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . kodHeader('Dancker Systems – formular') . " <$fra>",
            "To: <$til>",
            "Reply-To: <$svarTil>",                 // Tryk "Svar", så svarer du direkte til kunden
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
        $ok = $svar('250');
    }

    $skriv('QUIT');
    fclose($fp);
    return $ok;
}
