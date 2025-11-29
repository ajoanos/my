<?php
// Ustaw strefę czasową
date_default_timezone_set('Europe/Warsaw');

/**
 * KONFIGURACJA
 */

// Plik z historią wszystkich pierwszych dni miesiączki (zapisywany automatycznie)
$historyFile = __DIR__ . '/period_history.json';

// Data pierwszego dnia ostatniej miesiączki (dzień 1 cyklu)
// Wartość początkowa – zostanie nadpisana ostatnim wpisem z historii, jeśli istnieje
$lastPeriodStart = '2025-11-28';

// Domyślna długość cyklu w dniach (gdy brak historii do wyliczeń)
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

function loadPeriodHistory(string $file): array {
    if (!file_exists($file)) {
        return [];
    }

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }

    $validDates = [];
    foreach ($data as $date) {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if ($dt && $dt->format('Y-m-d') === $date) {
            $validDates[] = $date;
        }
    }

    sort($validDates);
    return array_values(array_unique($validDates));
}

function savePeriodHistory(string $file, array $dates): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($file, json_encode(array_values($dates), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function computeAverageCycleLength(array $dates): ?int {
    if (count($dates) < 2) {
        return null;
    }

    $lengths = [];
    for ($i = 1; $i < count($dates); $i++) {
        $prev = new DateTime($dates[$i - 1]);
        $current = new DateTime($dates[$i]);
        $diff = (int)$prev->diff($current)->format('%r%a');
        if ($diff > 0) {
            $lengths[] = $diff;
        }
    }

    if (empty($lengths)) {
        return null;
    }

    return (int)round(array_sum($lengths) / count($lengths));
}

function getPolishWeekday(DateTime $date): string {
    $names = [
        1 => 'Poniedziałek',
        2 => 'Wtorek',
        3 => 'Środa',
        4 => 'Czwartek',
        5 => 'Piątek',
        6 => 'Sobota',
        7 => 'Niedziela',
    ];

    return $names[(int)$date->format('N')] ?? '';
}

function getCycleDay(DateTime $cycleStart): int {
    $today = new DateTime('today');
    $diffDays = (int)$cycleStart->diff($today)->format('%r%a');
    return $diffDays + 1; // dzień cyklu = różnica + 1
}

function getCycleDayForDate(DateTime $cycleStart, DateTime $targetDate): int {
    $diffDays = (int)$cycleStart->diff($targetDate)->format('%r%a');
    return $diffDays + 1;
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
 * LOGIKA POWIADOMIEŃ I HISTORII
 */

$periodHistory = loadPeriodHistory($historyFile);
$historyMessage = null;
$historyError = null;
$testMailMessage = null;
$testMailError = null;
$sendTestEmailRequested = false;

$today = new DateTime('today');
$todayStr = $today->format('Y-m-d');
$todayDayOfMonth = $today->format('j');
$todayName = getPolishWeekday($today);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['period_start'])) {
        $newDate = trim($_POST['period_start']);
        $dt = DateTime::createFromFormat('Y-m-d', $newDate);

        if ($dt && $dt->format('Y-m-d') === $newDate) {
            if (!in_array($newDate, $periodHistory, true)) {
                $periodHistory[] = $newDate;
                sort($periodHistory);
                savePeriodHistory($historyFile, $periodHistory);
                $historyMessage = 'Dodano nowy pierwszy dzień miesiączki: ' . $newDate;
            } else {
                $historyMessage = 'Ta data jest już w historii: ' . $newDate;
            }
        } else {
            $historyError = 'Podaj poprawną datę w formacie RRRR-MM-DD.';
        }
    }

    if (isset($_POST['send_test_email'])) {
        $sendTestEmailRequested = true;
    }
}

// Ustalane na podstawie historii (jeśli istnieje)
$latestPeriodStart = !empty($periodHistory) ? end($periodHistory) : $lastPeriodStart;
$historyCycleLength = computeAverageCycleLength($periodHistory);
$effectiveCycleLength = $historyCycleLength ?: $cycleLength;

$currentCycleStart = getCurrentCycleStart($latestPeriodStart, $effectiveCycleLength);
$currentCycleDay   = getCycleDay($currentCycleStart);
$currentPhase      = getCurrentPhase($phases, $currentCycleDay, $effectiveCycleLength);

$timelinePhases = [];
foreach ($phases as $phase) {
    $phaseStart = min($phase['start_day'], $effectiveCycleLength);
    $phaseEnd = min($phase['end_day'], $effectiveCycleLength);
    if ($phaseEnd < $phaseStart) {
        continue;
    }

    $length = $phaseEnd - $phaseStart + 1;
    $timelinePhases[] = [
        'label' => $phase['name'],
        'length' => $length,
    ];
}

if ($sendTestEmailRequested) {
    $subject = 'Test: powiadomienia fazy cyklu';
    $body = [
        'To jest testowy mail z panelu faz cyklu.',
        'Jeśli go widzisz, funkcja mail() jest skonfigurowana poprawnie.',
        '',
        'Aktualne ustawienia:',
        '• Adres odbiorcy: ' . $userEmail,
        '• Adres nadawcy: ' . $fromEmail,
        '• Dzień cyklu: ' . $currentCycleDay,
        '• Data dzisiaj: ' . $todayStr,
    ];

    $headers = [];
    $headers[] = 'From: ' . $fromEmail;
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    $sent = mail($userEmail, $subject, implode("\n", $body), implode("\r\n", $headers));

    if ($sent) {
        $testMailMessage = 'Wysłano testowego maila na adres: ' . $userEmail . ' (sprawdź skrzynkę i spam).';
    } else {
        $testMailError = 'Nie udało się wysłać testowego maila. Sprawdź ustawienia serwera pocztowego.';
    }
}

$todayPercent = max(0, min(100, (($currentCycleDay - 1) / $effectiveCycleLength) * 100));

$upcomingWeek = [];
for ($i = 0; $i < 7; $i++) {
    $date = (clone $today)->modify('+' . $i . ' days');
    $cycleDay = getCycleDayForDate($currentCycleStart, $date);
    $upcomingWeek[] = [
        'date' => $date->format('Y-m-d'),
        'weekday' => getPolishWeekday($date),
        'cycle_day' => $cycleDay,
    ];
}

// Wysyłanie maili – sprawdzamy bieżący i następny cykl
$log = [];
for ($cycleOffset = 0; $cycleOffset <= 1; $cycleOffset++) {
    $cycleStart = clone $currentCycleStart;
    if ($cycleOffset > 0) {
        $cycleStart->modify('+' . ($cycleOffset * $effectiveCycleLength) . ' days');
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
        form.history { display:flex; gap:12px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
        .msg { padding:8px 12px; border-radius:8px; margin-bottom:8px; font-size:14px; }
        .msg.ok { background:#ecfdf3; color:#166534; border:1px solid #bbf7d0; }
        .msg.error { background:#fef2f2; color:#991b1b; border:1px solid #fecdd3; }
        .history-list { list-style: none; padding-left: 0; margin:0; }
        .history-list li { padding:6px 0; border-bottom:1px solid #eee; font-size:14px; }
        .timeline { position: relative; width: 100%; height: 42px; background:#f5f5f5; border-radius: 10px; display:flex; overflow:hidden; border:1px solid #e5e5e5; }
        .timeline-phase { display:flex; align-items:center; justify-content:center; font-size:12px; color:#333; padding:0 6px; border-right:1px solid #e5e5e5; box-sizing:border-box; }
        .timeline-phase:last-child { border-right: none; }
        .timeline-marker { position:absolute; top:-6px; width:2px; background:#e11d48; height:54px; left:0; display:flex; justify-content:center; }
        .timeline-marker span { position:absolute; top:-22px; left:50%; transform:translateX(-50%); font-size:12px; background:#e11d48; color:#fff; padding:2px 6px; border-radius:6px; white-space:nowrap; }
        .week-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; }
        .week-card { border:1px solid #e5e5e5; background:#fff; border-radius:10px; padding:10px; font-size:14px; }
    </style>
</head>
<body>
    <h1>Apka faz cyklu – Hashimoto + wkładka</h1>

    <div class="box">
        <h2>Aktualny stan</h2>
        <p><strong>Dzisiaj:</strong> <?= htmlspecialchars($todayStr) ?> (<?= htmlspecialchars($todayName) ?>)</p>
        <p><strong>Szacowana długość cyklu:</strong> <?= $effectiveCycleLength ?> dni
            <?php if ($historyCycleLength): ?>
                <span class="tag">Wyliczone ze średniej historii</span>
            <?php else: ?>
                <span class="tag">Domyślne ustawienie</span>
            <?php endif; ?>
        </p>
        <p><strong>Początek ostatniego wyliczonego cyklu (dzień 1 miesiączki):</strong>
            <?= htmlspecialchars($currentCycleStart->format('Y-m-d')) ?> (<?= htmlspecialchars(getPolishWeekday($currentCycleStart)) ?>)
        </p>
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
        <h2>Historia pierwszych dni miesiączki</h2>
        <form class="history" method="post">
            <label for="period_start"><strong>Dodaj nowy pierwszy dzień:</strong></label>
            <input type="date" id="period_start" name="period_start" value="<?= htmlspecialchars($todayStr) ?>">
            <button type="submit">Zapisz do historii</button>
        </form>
        <form method="post" style="margin-top:12px;">
            <button type="submit" name="send_test_email" value="1">Wyślij testowego maila</button>
        </form>
        <?php if ($historyMessage): ?>
            <div class="msg ok"><?= htmlspecialchars($historyMessage) ?></div>
        <?php endif; ?>
        <?php if ($historyError): ?>
            <div class="msg error"><?= htmlspecialchars($historyError) ?></div>
        <?php endif; ?>
        <?php if ($testMailMessage): ?>
            <div class="msg ok"><?= htmlspecialchars($testMailMessage) ?></div>
        <?php endif; ?>
        <?php if ($testMailError): ?>
            <div class="msg error"><?= htmlspecialchars($testMailError) ?></div>
        <?php endif; ?>
        <p><strong>Ostatni zapisany początek cyklu:</strong> <?= htmlspecialchars($latestPeriodStart) ?></p>
        <?php if ($historyCycleLength): ?>
            <p><strong>Średnia długość cyklu z historii:</strong> <?= $historyCycleLength ?> dni</p>
        <?php endif; ?>
        <?php if (empty($periodHistory)): ?>
            <p>Brak zapisanych dat – dodaj pierwszy wpis, aby wyliczać średnią długość cyklu.</p>
        <?php else: ?>
            <ul class="history-list">
                <?php foreach (array_reverse($periodHistory) as $dateStr): ?>
                    <?php $dateObj = new DateTime($dateStr); ?>
                    <li>
                        <?= htmlspecialchars($dateStr) ?> (<?= htmlspecialchars(getPolishWeekday($dateObj)) ?>)
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>Wykres cyklu</h2>
        <div class="timeline">
            <?php foreach ($timelinePhases as $phase): ?>
                <?php $width = ($phase['length'] / $effectiveCycleLength) * 100; ?>
                <div class="timeline-phase" style="width: <?= $width ?>%">
                    <?= htmlspecialchars($phase['label']) ?>
                </div>
            <?php endforeach; ?>
            <div class="timeline-marker" style="left: <?= $todayPercent ?>%">
                <span>Dzisiaj • dzień cyklu: <?= $currentCycleDay ?> • dzień miesiąca: <?= $todayDayOfMonth ?></span>
            </div>
        </div>
        <p>Aktualny dzień cyklu: <strong><?= $currentCycleDay ?></strong> (<?= htmlspecialchars($todayName) ?>)</p>
        <p>Dzień miesiąca (numer dnia w kalendarzu): <strong><?= $todayDayOfMonth ?></strong> (<?= htmlspecialchars($todayStr) ?>)</p>
    </div>

    <div class="box">
        <h2>Nadchodzące dni (z nazwą dnia)</h2>
        <div class="week-grid">
            <?php foreach ($upcomingWeek as $entry): ?>
                <div class="week-card">
                    <strong><?= htmlspecialchars($entry['weekday']) ?></strong><br>
                    <?= htmlspecialchars($entry['date']) ?><br>
                    Dzień cyklu: <?= $entry['cycle_day'] ?>
                </div>
            <?php endforeach; ?>
        </div>
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
