<?php
// Ustaw strefę czasową
date_default_timezone_set('Europe/Warsaw');

/**
 * KONFIGURACJA
 */

// Data pierwszego dnia ostatniej miesiączki (dzień 1 cyklu)
// ZMIEŃ, gdy zacznie się nowy okres
$lastPeriodStart = '2025-11-28';

// Długość cyklu w dniach (dostosuj pod Wasz realny, jeśli jest inny)
$cycleLength = 28;

// Ile dni przed startem fazy wysłać maila
$reminderDaysBefore = 3;

// Mail odbiorcy (Twój)
$userEmail = 'arkadiusz@allemedia.pl';

// Adres nadawcy (musi istnieć na Twoim serwerze)
$fromEmail = 'powiadomienia@twojadomena.pl'; // <- ZMIEŃ na swoją domenę

// Definicje faz w obrębie jednego cyklu
// Tutaj od razu uwzględniamy Hashimoto i wkładkę hormonalną
$phases = [
    [
        'key'   => 'menstruacja',
        'name'  => 'Miesiączka (krwawienie)',
        'start_day' => 1,
        'end_day'   => 5,
        'description_base' =>
            'Książkowo: najniższe libido, ciało się oczyszcza, możliwe bóle brzucha i mniejsza energia.',
        'description_hashimoto' =>
            'Przy Hashimoto: zmęczenie i „przytłoczenie” mogą być wyraźniejsze, większa potrzeba snu i spokoju, nastrój może lecieć w dół.',
        'description_iud' =>
            'Przy wkładce hormonalnej: krwawienie może być skąpe lub zanikowe, ale poczucie „ciężkości” i niskie libido nadal mogą się pojawiać.',
    ],
    [
        'key'   => 'folikularna',
        'name'  => 'Faza folikularna (po okresie)',
        'start_day' => 6,
        'end_day'   => 12,
        'description_base' =>
            'Książkowo: estrogen rośnie, rośnie też energia, poprawia się nastrój i zwykle pojawia się większa ochota na bliskość.',
        'description_hashimoto' =>
            'Przy Hashimoto: powrót energii może być wolniejszy, zamiast „wow, odżyłam” częściej jest „trochę lepiej, ale dalej jestem zmęczona”. Libido może rosnąć wolniej.',
        'description_iud' =>
            'Przy wkładce hormonalnej: stale obecny progestagen trochę „przydusza” naturalny wzrost libido – ciało fizycznie może być gotowe, ale impuls seksualny jest delikatniejszy.',
    ],
    [
        'key'   => 'owulacja',
        'name'  => 'Owulacja (teoretyczny szczyt)',
        'start_day' => 13,
        'end_day'   => 16,
        'description_base' =>
            'Książkowo: szczyt estrogenów i testosteronu, to zwykle najwyższe libido w cyklu.',
        'description_hashimoto' =>
            'Przy Hashimoto: szczyt może być słabszy albo trudniejszy do poczucia – zamiast „mega ochoty” może być tylko lekka poprawa nastroju lub neutralność.',
        'description_iud' =>
            'Przy wkładce hormonalnej: owulacja bywa osłabiona albo czasem znika, więc naturalny „pik libido” może być bardzo spłaszczony albo niewyczuwalny.',
    ],
    [
        'key'   => 'lutealna',
        'name'  => 'Faza lutealna (przed okresem)',
        'start_day' => 17,
        'end_day'   => 28,
        'description_base' =>
            'Książkowo: rośnie progesteron, pojawia się PMS, łatwiej o wahania nastroju i spadek libido.',
        'description_hashimoto' =>
            'Przy Hashimoto: to często najtrudniejsza faza. Zmęczenie, mgła mózgowa, drażliwość i obniżony nastrój mogą być mocniejsze, a libido wyraźnie niższe.',
        'description_iud' =>
            'Przy wkładce hormonalnej: cały cykl jest trochę jak przedokresowy – progestagen z wkładki utrzymuje organizm w stanie „mini lutealnym”, więc spadek libido jest częstszy i dłuższy.',
    ],
];

/**
 * POMOCNICZE FUNKCJE
 */

function getCurrentCycleStart(string $lastPeriodStart, int $cycleLength): DateTime {
    $today = new DateTime('today');
    $lastStart = new DateTime($lastPeriodStart);

    $diffDays = (int)$lastStart->diff($today)->format('%r%a'); // może być ujemne
    if ($diffDays < 0) {
        // Jeśli podana data ostatniej miesiączki jest w przyszłości – przyjmij ją jako aktualny cykl
        return $lastStart;
    }

    $cyclesPassed = intdiv($diffDays, $cycleLength);
    $cycleStart = clone $lastStart;
    if ($cyclesPassed > 0) {
        $cycleStart->modify('+' . ($cyclesPassed * $cycleLength) . ' days');
    }

    return $cycleStart;
}

function getCycleDay(DateTime $cycleStart): int {
    $today = new DateTime('today');
    $diffDays = (int)$cycleStart->diff($today)->format('%r%a');
    return $diffDays + 1; // dzień cyklu = różnica + 1
}

function getCurrentPhase(array $phases, int $cycleDay, int $cycleLength): ?array {
    if ($cycleDay < 1 || $cycleDay > $cycleLength) {
        return null;
    }
    foreach ($phases as $phase) {
        if ($cycleDay >= $phase['start_day'] && $cycleDay <= $phase['end_day']) {
            return $phase;
        }
    }
    return null;
}

function buildPhaseFullDescription(array $phase): string {
    $parts = [
        'Bazowo: ' . $phase['description_base'],
        'Przy Hashimoto: ' . $phase['description_hashimoto'],
        'Przy wkładce hormonalnej: ' . $phase['description_iud'],
    ];
    return implode("\n- ", $parts);
}

function sendPhaseReminderEmail(
    string $userEmail,
    string $fromEmail,
    array $phase,
    DateTime $phaseStart,
    int $reminderDaysBefore,
    DateTime $cycleStart
): bool {
    $subject = 'Przypomnienie o fazie cyklu (Hashimoto + wkładka): ' . $phase['name'];

    $bodyLines = [
        'Cześć Arek,',
        '',
        'Za około ' . $reminderDaysBefore . ' dni (szacunkowo) zacznie się faza:',
        $phase['name'],
        '',
        'Start tej fazy (orientacyjnie): ' . $phaseStart->format('Y-m-d'),
        'Początek tego cyklu (dzień 1 miesiączki): ' . $cycleStart->format('Y-m-d'),
        '',
        'Jak ta faza wygląda książkowo i jak może się zmieniać przy Hashimoto i wkładce hormonalnej:',
        '',
        '- ' . buildPhaseFullDescription($phase),
        '',
        'Pamiętaj:',
        '- Hashimoto może obniżać energię i libido niezależnie od samej fazy.',
        '- Wkładka hormonalna spłaszcza „piki” i często obniża ochotę na seks przez cały cykl.',
        '- Spadki ochoty zwykle nie są o Tobie ani o Waszej relacji – to miks hormonów, tarczycy i antykoncepcji.',
        '',
        'Ten mail został wygenerowany automatycznie przez skrypt faz-cyklu 😊',
    ];

    $message = implode("\n", $bodyLines);

    $headers = [];
    $headers[] = 'From: ' . $fromEmail;
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    $headersStr = implode("\r\n", $headers);

    return mail($userEmail, $subject, $message, $headersStr);
}

/**
 * LOGIKA POWIADOMIEŃ
 */

$today = new DateTime('today');
$todayStr = $today->format('Y-m-d');

$currentCycleStart = getCurrentCycleStart($lastPeriodStart, $cycleLength);
$currentCycleDay   = getCycleDay($currentCycleStart);
$currentPhase      = getCurrentPhase($phases, $currentCycleDay, $cycleLength);

// Wysyłanie maili – sprawdzamy bieżący i następny cykl
$log = [];
for ($cycleOffset = 0; $cycleOffset <= 1; $cycleOffset++) {
    $cycleStart = clone $currentCycleStart;
    if ($cycleOffset > 0) {
        $cycleStart->modify('+' . ($cycleOffset * $cycleLength) . ' days');
    }

    foreach ($phases as $phase) {
        $phaseStart = clone $cycleStart;
        $phaseStart->modify('+' . ($phase['start_day'] - 1) . ' days');

        $reminderDate = clone $phaseStart;
        $reminderDate->modify('-' . $reminderDaysBefore . ' days');

        if ($reminderDate->format('Y-m-d') === $todayStr) {
            $ok = sendPhaseReminderEmail(
                $userEmail,
                $fromEmail,
                $phase,
                $phaseStart,
                $reminderDaysBefore,
                $cycleStart
            );
            $log[] = [
                'phase'         => $phase['name'],
                'cycle_start'   => $cycleStart->format('Y-m-d'),
                'phase_start'   => $phaseStart->format('Y-m-d'),
                'reminder_date' => $reminderDate->format('Y-m-d'),
                'sent'          => $ok,
            ];
        }
    }
}

/**
 * PROSTE WYPISANIE NA STRONIE – podgląd dla Ciebie
 */
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Fazy cyklu – Hashimoto + wkładka</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; background:#fafafa; }
        .box { background:#fff; border: 1px solid #ddd; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        h1, h2, h3 { margin-top: 0; }
        .tag { display: inline-block; padding: 4px 10px; border-radius: 999px; background:#f0f0f0; font-size: 12px; margin-right: 8px; margin-bottom:4px; }
        .log-ok { color: green; }
        .log-fail { color: red; }
        .phase { margin-bottom: 12px; }
        .phase h3 { margin-bottom: 4px; }
        .phase p { margin: 3px 0; font-size: 14px; }
        code { background:#eee; padding:2px 4px; border-radius:4px; }
    </style>
</head>
<body>
    <h1>Apka faz cyklu – Hashimoto + wkładka</h1>

    <div class="box">
        <h2>Aktualny stan</h2>
        <p><strong>Dzisiaj:</strong> <?= htmlspecialchars($todayStr) ?></p>
        <p><strong>Początek ostatniego wyliczonego cyklu (dzień 1 miesiączki):</strong>
            <?= htmlspecialchars($currentCycleStart->format('Y-m-d')) ?></p>
        <p><strong>Dzień cyklu:</strong> <?= $currentCycleDay ?></p>

        <?php if ($currentPhase): ?>
            <p><strong>Aktualna faza:</strong> <?= htmlspecialchars($currentPhase['name']) ?></p>
            <p><span class="tag">Bazowo</span> <?= htmlspecialchars($currentPhase['description_base']) ?></p>
            <p><span class="tag">Hashimoto</span> <?= htmlspecialchars($currentPhase['description_hashimoto']) ?></p>
            <p><span class="tag">Wkładka</span> <?= htmlspecialchars($currentPhase['description_iud']) ?></p>
        <?php else: ?>
            <p><strong>Aktualna faza:</strong> poza zakresem (sprawdź długość cyklu / datę miesiączki).</p>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>Log dzisiejszych powiadomień</h2>
        <?php if (empty($log)): ?>
            <p>Dzisiaj nie był zaplanowany żaden mail przypominający.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($log as $entry): ?>
                    <li>
                        Faza: <strong><?= htmlspecialchars($entry['phase']) ?></strong>,
                        cykl od: <?= htmlspecialchars($entry['cycle_start']) ?>,
                        start fazy: <?= htmlspecialchars($entry['phase_start']) ?>,
                        data przypomnienia: <?= htmlspecialchars($entry['reminder_date']) ?> –
                        <?php if ($entry['sent']): ?>
                            <span class="log-ok">MAIL WYSŁANY ✅</span>
                        <?php else: ?>
                            <span class="log-fail">BŁĄD WYSYŁKI ❌ (sprawdź funkcję mail() / serwer SMTP)</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>Konfiguracja / jak używać</h2>
        <ol>
            <li>W tym pliku ustaw:
                <ul>
                    <li><code>$lastPeriodStart</code> – data pierwszego dnia miesiączki (np. 2025-11-28).</li>
                    <li><code>$cycleLength</code> – realna długość jej cyklu (np. 28–30).</li>
                    <li><code>$reminderDaysBefore</code> – ile dni przed fazą chcesz maila.</li>
                    <li><code>$userEmail</code> – Twój mail (np. arkadiusz@allemedia.pl).</li>
                    <li><code>$fromEmail</code> – istniejący adres z Twojej domeny.</li>
                </ul>
            </li>
            <li>Na serwerze ustaw CRON, który raz dziennie odpali ten plik, np.:<br>
                <code>0 8 * * * /usr/bin/php /sciezka/do/index.php &gt;/dev/null 2&gt;&amp;1</code>
            </li>
            <li>Za każdym razem, gdy zacznie się nowa miesiączka – zaktualizuj <code>$lastPeriodStart</code> w pliku.</li>
        </ol>
        <p style="font-size:13px; color:#666;">
            To są szacunki na podstawie regularnego cyklu. Hashimoto i wkładka mogą przesuwać fazy –
            traktuj to jako orientacyjny kompas, nie dokładny zegarek 😉
        </p>
    </div>
</body>
</html>
