<?php
// Dancker Systems – modtager kontaktformularen og sender den som mail til kontakt@.
// Intet gemmes: beskeden sendes kun videre som mail og glemmes derefter.
// Kører på Simplys webhotel (PHP + mail()).

const MODTAGER = 'kontakt@danckersystems.dk';
const AFSENDER = 'kontakt@danckersystems.dk'; // Skal være en adresse på eget domæne, ellers afvises mailen ofte som spam

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

// ---------- Byg og send mailen ----------
$emne = 'Forespørgsel: ' . $opgave . ' – ' . $navn;
$tekst = "Ny forespørgsel fra danckersystems.dk\n\n"
       . "Navn: $navn\n"
       . "E-mail: $mail\n"
       . 'Virksomhed: ' . ($firma !== '' ? $firma : '-') . "\n"
       . "Opgave: $opgave\n\n"
       . $besked . "\n";

$headers = implode("\r\n", [
    'From: Dancker Systems <' . AFSENDER . '>',
    'Reply-To: ' . $mail,               // Tryk "Svar" i mailen, så svarer du direkte til kunden
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'MIME-Version: 1.0',
]);

$emneKodet = '=?UTF-8?B?' . base64_encode($emne) . '?=';
$sendt = mail(MODTAGER, $emneKodet, $tekst, $headers, '-f' . AFSENDER);

header('Location: ' . ($sendt ? '/tak.html' : '/kontakt.html?fejl=2#kontaktformular'), true, 303);
exit;
