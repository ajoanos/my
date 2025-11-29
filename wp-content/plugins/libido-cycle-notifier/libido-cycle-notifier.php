<?php
/**
 * Plugin Name: Libido Cycle Notifier
 * Description: Wykres libido partnerki (Hashimoto + wkładka hormonalna) + powiadomienia mailowe X dni przed zmianą fazy cyklu.
 * Version: 1.0.0
 * Author: ChatGPT & Arek :)
 * License: GPL2+
 */

if (!defined('ABSPATH')) {
    exit;
}

class AC_Libido_Cycle_Notifier {
    private $option_name = 'aclibido_options';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('libido_cycle_chart', [$this, 'shortcode_chart']);

        add_action('aclibido_daily_event', [$this, 'cron_send_notifications']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function activate() {
        $defaults = $this->get_default_options();
        $current  = get_option($this->option_name, []);
        update_option($this->option_name, wp_parse_args($current, $defaults));

        if (!wp_next_scheduled('aclibido_daily_event')) {
            wp_schedule_event(time() + 3600, 'daily', 'aclibido_daily_event');
        }
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled('aclibido_daily_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'aclibido_daily_event');
        }
    }

    private function get_default_options() {
        return [
            'last_period_start'    => '',
            'cycle_length'         => 28,
            'reminder_days_before' => 3,
            'notify_email'         => get_option('admin_email'),
            'period_history'       => [],
        ];
    }

    private function get_options() {
        $defaults = $this->get_default_options();
        $opts     = get_option($this->option_name, []);
        $merged   = wp_parse_args($opts, $defaults);
        $merged['cycle_length']         = max(20, min(40, (int) $merged['cycle_length']));
        $merged['reminder_days_before'] = max(0, min(10, (int) $merged['reminder_days_before']));
        if (!is_array($merged['period_history'])) {
            $merged['period_history'] = [];
        }
        return $merged;
    }

    private function compute_average_cycle_length($dates) {
        if (!is_array($dates) || count($dates) < 2) {
            return null;
        }

        sort($dates);
        $lengths = [];
        for ($i = 1; $i < count($dates); $i++) {
            $prev    = DateTime::createFromFormat('Y-m-d', $dates[$i - 1]);
            $current = DateTime::createFromFormat('Y-m-d', $dates[$i]);
            if (!$prev || !$current) {
                continue;
            }

            $diff = (int) $prev->diff($current)->format('%r%a');
            if ($diff > 0) {
                $lengths[] = $diff;
            }
        }

        if (empty($lengths)) {
            return null;
        }

        return (int) round(array_sum($lengths) / count($lengths));
    }

    private function get_polish_weekday(DateTime $date) {
        $names = [
            1 => 'Poniedziałek',
            2 => 'Wtorek',
            3 => 'Środa',
            4 => 'Czwartek',
            5 => 'Piątek',
            6 => 'Sobota',
            7 => 'Niedziela',
        ];

        return $names[(int) $date->format('N')] ?? '';
    }

    private function get_phases() {
        return [
            [
                'key'   => 'menstruacja',
                'name'  => 'Miesiączka (krwawienie)',
                'start_day' => 1,
                'end_day'   => 5,
                'desc_base' => 'Książkowo: najniższe libido, ciało się oczyszcza, możliwe bóle brzucha, mniejsza energia.',
                'desc_hashimoto' => 'Przy Hashimoto: większe zmęczenie, „przytłoczenie”, większa potrzeba snu i spokoju, nastrój może mocniej lecieć w dół.',
                'desc_iud' => 'Przy wkładce hormonalnej: krwawienie może być skąpe lub zanikowe, ale poczucie ciężkości i niskie libido nadal mogą się pojawiać.',
            ],
            [
                'key'   => 'folikularna',
                'name'  => 'Faza folikularna (po okresie)',
                'start_day' => 6,
                'end_day'   => 12,
                'desc_base' => 'Książkowo: estrogen rośnie, rośnie energia i nastrój, zwykle większa ochota na bliskość.',
                'desc_hashimoto' => 'Przy Hashimoto: powrót energii wolniejszy; zamiast „odżyłam” częściej „trochę lepiej, ale dalej zmęczenie”. Libido może rosnąć wolniej.',
                'desc_iud' => 'Przy wkładce hormonalnej: stały progestagen trochę „przydusza” naturalny wzrost libido — impuls jest delikatniejszy.',
            ],
            [
                'key'   => 'owulacja',
                'name'  => 'Owulacja (teoretyczny szczyt)',
                'start_day' => 13,
                'end_day'   => 16,
                'desc_base' => 'Książkowo: szczyt estrogenów i testosteronu, najwyższe libido w cyklu.',
                'desc_hashimoto' => 'Przy Hashimoto: szczyt może być słabszy albo trudniejszy do zauważenia, zamiast „mega ochoty” lekka poprawa lub neutralność.',
                'desc_iud' => 'Przy wkładce hormonalnej: owulacja bywa osłabiona lub czasem zanika, więc „pik libido” jest spłaszczony albo niewyczuwalny.',
            ],
            [
                'key'   => 'lutealna',
                'name'  => 'Faza lutealna (przed okresem)',
                'start_day' => 17,
                'end_day'   => 28,
                'desc_base' => 'Książkowo: rośnie progesteron, PMS, łatwiej o wahania nastroju i spadek libido.',
                'desc_hashimoto' => 'Przy Hashimoto: to często najtrudniejsza faza – mocniejsze zmęczenie, mgła mózgowa, drażliwość, wyraźnie niższe libido.',
                'desc_iud' => 'Przy wkładce hormonalnej: cały cykl jest trochę jak „mini lutealny” – progestagen spłaszcza piki, więc spadek libido bywa dłuższy.',
            ],
        ];
    }

    private function get_polish_month_name($monthNumber) {
        $months = [
            1  => 'stycznia',
            2  => 'lutego',
            3  => 'marca',
            4  => 'kwietnia',
            5  => 'maja',
            6  => 'czerwca',
            7  => 'lipca',
            8  => 'sierpnia',
            9  => 'września',
            10 => 'października',
            11 => 'listopada',
            12 => 'grudnia',
        ];

        return $months[(int) $monthNumber] ?? '';
    }

    public function register_settings_page() {
        add_options_page(
            'Libido – cykl & libido',
            'Libido – cykl & libido',
            'manage_options',
            'aclibido-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->get_options();
        $phases  = $this->get_phases();

        $historyMessage = '';
        $historyError   = '';
        $testMessage    = '';
        $testError      = '';

        if (isset($_POST['aclibido_save'])) {
            check_admin_referer('aclibido_save_settings');

            $options['last_period_start']    = sanitize_text_field($_POST['last_period_start'] ?? '');
            $options['cycle_length']         = (int) ($_POST['cycle_length'] ?? 28);
            $options['reminder_days_before'] = (int) ($_POST['reminder_days_before'] ?? 3);
            $options['notify_email']         = sanitize_email($_POST['notify_email'] ?? '');

            $historyDates = $options['period_history'];
            $newDate      = $options['last_period_start'];
            $dt           = DateTime::createFromFormat('Y-m-d', $newDate);
            if ($dt && $dt->format('Y-m-d') === $newDate) {
                if (!in_array($newDate, $historyDates, true)) {
                    $historyDates[] = $newDate;
                }
            }
            sort($historyDates);
            $options['period_history'] = array_values(array_unique($historyDates));

            update_option($this->option_name, $options);

            echo '<div class="updated"><p>Ustawienia zapisane.</p></div>';
        }

        if (isset($_POST['aclibido_add_history'])) {
            check_admin_referer('aclibido_save_settings');
            $historyDates = $options['period_history'];
            $newDate      = sanitize_text_field($_POST['history_period_start'] ?? '');
            $dt           = DateTime::createFromFormat('Y-m-d', $newDate);
            if ($dt && $dt->format('Y-m-d') === $newDate) {
                if (!in_array($newDate, $historyDates, true)) {
                    $historyDates[]  = $newDate;
                    sort($historyDates);
                    $options['period_history'] = array_values(array_unique($historyDates));
                    update_option($this->option_name, $options);
                    $historyMessage = 'Dodano nową datę do historii: ' . esc_html($newDate);
                } else {
                    $historyMessage = 'Ta data jest już w historii: ' . esc_html($newDate);
                }
            } else {
                $historyError = 'Podaj poprawną datę w formacie RRRR-MM-DD.';
            }
        }

        $today = new DateTime('now', wp_timezone());
        $todayStr = $today->format('Y-m-d');
        $todayName = $this->get_polish_weekday($today);

        $historyDates      = $options['period_history'];
        $latestPeriodStart = !empty($historyDates) ? end($historyDates) : $options['last_period_start'];
        $historyCycle      = $this->compute_average_cycle_length($historyDates);
        $effectiveCycle    = $historyCycle ?: $options['cycle_length'];

        $cycleStart   = $this->get_current_cycle_start($latestPeriodStart, $effectiveCycle);
        $cycleDay     = $this->get_cycle_day($cycleStart, $effectiveCycle);
        $currentPhase = $this->get_phase_for_day($phases, $cycleDay, $effectiveCycle);

        if (isset($_POST['aclibido_send_test'])) {
            check_admin_referer('aclibido_save_settings');

            $testEmail = sanitize_email($_POST['notify_email'] ?? $options['notify_email']);
            if (empty($testEmail)) {
                $testError = 'Podaj adres e-mail, aby wysłać testowe powiadomienie.';
            } elseif (empty($latestPeriodStart)) {
                $testError = 'Uzupełnij datę pierwszego dnia miesiączki, aby wyliczyć fazy.';
            } else {
                [$nextPhase, $nextPhaseStart] = $this->get_next_phase_info($phases, $cycleStart, $effectiveCycle);
                $sent = $this->send_test_email($testEmail, $nextPhase, $nextPhaseStart, $options['reminder_days_before'], $cycleStart);
                if ($sent) {
                    $testMessage = 'Wysłano testowy email na adres ' . esc_html($testEmail) . ' (faza: ' . esc_html($nextPhase['name']) . ').';
                } else {
                    $testError = 'Nie udało się wysłać testowego maila. Sprawdź konfigurację SMTP.';
                }
            }
        }

        if (!empty($testMessage)) {
            echo '<div class="updated"><p>' . $testMessage . '</p></div>';
        }

        if (!empty($testError)) {
            echo '<div class="error"><p>' . esc_html($testError) . '</p></div>';
        }

        $libidoData = [];
        $labels = [];
        for ($d = 1; $d <= $effectiveCycle; $d++) {
            $libidoData[] = $this->calculate_libido_score($d, $effectiveCycle);
            $labelDate    = clone $cycleStart;
            $labelDate->modify('+' . ($d - 1) . ' days');
            $labels[] = [
                $labelDate->format('j') . ' ' . $this->get_polish_month_name($labelDate->format('n')),
                'Dzień cyklu ' . $d,
                $this->get_polish_weekday($labelDate)
            ];
        }

        ?>
        <div class="wrap">
            <h1>Libido – cykl partnerki (Hashimoto + wkładka)</h1>

            <style>
                .aclibido-box {
                    background:#fff;
                    border:1px solid #ddd;
                    border-radius:10px;
                    padding:16px 20px;
                    margin:16px 0;
                    box-shadow:0 1px 3px rgba(0,0,0,0.03);
                }
                .aclibido-grid {
                    display:grid;
                    grid-template-columns:1.1fr 1.2fr;
                    gap:20px;
                }
                .aclibido-tag {
                    display:inline-block;
                    padding:2px 8px;
                    border-radius:999px;
                    background:#f0f0f0;
                    font-size:11px;
                    margin-right:6px;
                    margin-bottom:4px;
                }
                .aclibido-phases p {
                    margin:4px 0;
                    font-size:13px;
                }
                .aclibido-label {
                    font-weight:600;
                }
                @media (max-width: 900px) {
                    .aclibido-grid {
                        grid-template-columns:1fr;
                    }
                }
            </style>

            <div class="aclibido-box aclibido-grid">
                <div>
                    <h2>Ustawienia cyklu</h2>
                    <form method="post">
                        <?php wp_nonce_field('aclibido_save_settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="last_period_start">Pierwszy dzień ostatniej miesiączki</label></th>
                                <td>
                                    <input type="date" id="last_period_start" name="last_period_start"
                                           value="<?php echo esc_attr($options['last_period_start']); ?>" />
                                    <p class="description">Np. 2025-11-28 (data, którą podała Ci partnerka).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cycle_length">Długość cyklu (dni)</label></th>
                                <td>
                                    <input type="number" id="cycle_length" name="cycle_length" min="20" max="40"
                                           value="<?php echo esc_attr($options['cycle_length']); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="notify_email">Mail do powiadomień</label></th>
                                <td>
                                    <input type="email" id="notify_email" name="notify_email" size="40"
                                           value="<?php echo esc_attr($options['notify_email']); ?>" />
                                    <p class="description" style="margin-top:6px;">
                                        <button class="button" name="aclibido_send_test" value="1">Wyślij testowy email</button>
                                        <span style="margin-left:8px;">Sprawdzisz, czy przyjdzie przypomnienie.</span>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="reminder_days_before">Powiadomienie przed fazą (dni)</label></th>
                                <td>
                                    <input type="number" id="reminder_days_before" name="reminder_days_before" min="0" max="10"
                                           value="<?php echo esc_attr($options['reminder_days_before']); ?>" />
                                    <p class="description">Np. 3 — mail wyjdzie 3 dni przed startem nowej fazy.</p>
                                </td>
                            </tr>
                        </table>

                        <p>
                            <input type="submit" name="aclibido_save" class="button button-primary" value="Zapisz ustawienia" />
                        </p>
                    </form>

                    <div class="aclibido-box" style="margin-top:16px;">
                        <h3>Aktualny stan</h3>
                        <p><span class="aclibido-label">Dzisiaj:</span> <?php echo esc_html($todayStr); ?> (<?php echo esc_html($todayName); ?>)</p>
                        <?php if (!empty($latestPeriodStart)): ?>
                            <p><span class="aclibido-label">Początek aktualnie liczonego cyklu:</span>
                                <?php echo esc_html($cycleStart->format('Y-m-d')); ?>
                                (<?php echo esc_html($this->get_polish_weekday($cycleStart)); ?>)
                            </p>
                            <p><span class="aclibido-label">Dzień cyklu:</span> <?php echo esc_html($cycleDay); ?></p>
                            <?php if ($historyCycle): ?>
                                <p><span class="aclibido-label">Średnia długość cyklu (z historii):</span> <?php echo esc_html($historyCycle); ?> dni</p>
                            <?php endif; ?>
                            <?php if ($currentPhase): ?>
                                <p><span class="aclibido-label">Aktualna faza:</span> <?php echo esc_html($currentPhase['name']); ?></p>
                            <?php else: ?>
                                <p><span class="aclibido-label">Aktualna faza:</span> poza zakresem (sprawdź długość cyklu / datę miesiączki).</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>Najpierw ustaw datę pierwszego dnia miesiączki.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h2>Wykres libido (Hashimoto + wkładka)</h2>
                    <canvas id="aclibidoChart" height="220"></canvas>
                    <p style="font-size:12px;color:#666;margin-top:8px;">
                        To jest model przybliżony – oparty na typowym przebiegu cyklu + korektach dla Hashimoto i wkładki.
                        Rzeczywiste libido partnerki może się różnić.
                    </p>
                </div>
            </div>

            <div class="aclibido-box">
                <h2>Historia pierwszych dni miesiączki</h2>
                <form method="post" style="margin-bottom:12px;">
                    <?php wp_nonce_field('aclibido_save_settings'); ?>
                    <input type="hidden" name="aclibido_add_history" value="1" />
                    <label for="history_period_start" class="aclibido-label">Dodaj nowy pierwszy dzień:</label>
                    <input type="date" id="history_period_start" name="history_period_start" value="<?php echo esc_attr($todayStr); ?>" />
                    <button class="button">Zapisz do historii</button>
                </form>
                <?php if (!empty($historyMessage)): ?>
                    <div class="updated" style="padding:8px 10px;"> <?php echo $historyMessage; ?> </div>
                <?php endif; ?>
                <?php if (!empty($historyError)): ?>
                    <div class="error" style="padding:8px 10px;"> <?php echo esc_html($historyError); ?> </div>
                <?php endif; ?>
                <p><span class="aclibido-label">Ostatni zapisany początek cyklu:</span> <?php echo esc_html($latestPeriodStart ?: '—'); ?></p>
                <?php if ($historyCycle): ?>
                    <p><span class="aclibido-label">Średnia długość cyklu z historii:</span> <?php echo esc_html($historyCycle); ?> dni</p>
                <?php endif; ?>
                <?php if (empty($historyDates)): ?>
                    <p>Brak zapisanych dat – dodaj pierwszy wpis, aby zacząć liczyć średnią.</p>
                <?php else: ?>
                    <ul style="margin:0; padding-left:16px;"> 
                        <?php foreach (array_reverse($historyDates) as $dateStr): ?>
                            <?php $dateObj = DateTime::createFromFormat('Y-m-d', $dateStr); ?>
                            <?php if ($dateObj): ?>
                                <li><?php echo esc_html($dateStr); ?> (<?php echo esc_html($this->get_polish_weekday($dateObj)); ?>)</li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="aclibido-box aclibido-phases">
                <h2>Opis faz (uwzględniając Hashimoto + wkładkę)</h2>
                <?php foreach ($phases as $phase): ?>
                    <div style="margin-bottom:10px;">
                        <h3><?php echo esc_html($phase['name']); ?> (dni <?php echo esc_html($phase['start_day'] . '–' . $phase['end_day']); ?>)</h3>
                        <p><span class="aclibido-tag">Bazowo</span> <?php echo esc_html($phase['desc_base']); ?></p>
                        <p><span class="aclibido-tag">Hashimoto</span> <?php echo esc_html($phase['desc_hashimoto']); ?></p>
                        <p><span class="aclibido-tag">Wkładka</span> <?php echo esc_html($phase['desc_iud']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="aclibido-box">
                <h2>Shortcode</h2>
                <p>Wstaw <code>[libido_cycle_chart]</code> na dowolnej stronie / wpisie, żeby pokazać wykres libido (bez panelu ustawień).</p>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script>
                (function() {
                    const ctx = document.getElementById('aclibidoChart');
                    if (!ctx) return;

                    const data = <?php echo wp_json_encode($libidoData); ?>;
                    const labels = <?php echo wp_json_encode($labels); ?>;
                    const currentDay = <?php echo (int) $cycleDay; ?>;

                    const markerPlugin = {
                        id: 'aclibidoMarker',
                        afterDraw(chart) {
                            const {ctx, chartArea, scales} = chart;
                            if (!scales?.x || !scales?.y) return;
                            const xPos = scales.x.getPixelForValue(currentDay - 1);
                            ctx.save();
                            ctx.strokeStyle = '#e11d48';
                            ctx.lineWidth = 2;
                            ctx.beginPath();
                            ctx.moveTo(xPos, chartArea.top);
                            ctx.lineTo(xPos, chartArea.bottom);
                            ctx.stroke();
                            ctx.fillStyle = '#e11d48';
                            ctx.font = '12px sans-serif';
                            ctx.textAlign = 'center';
                            ctx.fillText('Dziś', xPos, chartArea.top - 6);
                            ctx.restore();
                        }
                    };

                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Szacowane libido (0–100)',
                                data: data,
                                tension: 0.35,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            scales: {
                                x: {
                                    ticks: {
                                        callback: function(value) {
                                            return labels[value];
                                        }
                                    }
                                },
                                y: {
                                    suggestedMin: 0,
                                    suggestedMax: 100
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false
                                }
                            }
                        },
                        plugins: [markerPlugin]
                    });
                })();
            </script>
        </div>
        <?php
    }

    public function shortcode_chart() {
        $options = $this->get_options();

        $frontMessage = '';
        $frontError   = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aclibido_front_add'])) {
            $nonceOk = isset($_POST['aclibido_front_nonce']) && wp_verify_nonce($_POST['aclibido_front_nonce'], 'aclibido_front_add');
            if ($nonceOk) {
                $newDate = sanitize_text_field($_POST['next_cycle_start'] ?? '');
                $dt      = DateTime::createFromFormat('Y-m-d', $newDate);

                if ($dt && $dt->format('Y-m-d') === $newDate) {
                    $historyDates = $options['period_history'];
                    if (!in_array($newDate, $historyDates, true)) {
                        $historyDates[] = $newDate;
                        sort($historyDates);
                        $options['period_history'] = array_values(array_unique($historyDates));
                        update_option($this->option_name, $options);
                        $frontMessage = 'Dodano nowy pierwszy dzień miesiączki: ' . esc_html($newDate);
                    } else {
                        $frontMessage = 'Ta data jest już zapisana: ' . esc_html($newDate);
                    }
                } else {
                    $frontError = 'Podaj poprawną datę w formacie RRRR-MM-DD.';
                }
            } else {
                $frontError = 'Odśwież stronę i spróbuj ponownie (błąd walidacji).';
            }
        }

        $historyDates      = $options['period_history'];
        $latestPeriodStart = !empty($historyDates) ? end($historyDates) : $options['last_period_start'];
        $historyCycle      = $this->compute_average_cycle_length($historyDates);
        $effectiveCycle    = $historyCycle ?: $options['cycle_length'];

        if (empty($latestPeriodStart)) {
            return '<p>Najpierw ustaw datę pierwszego dnia miesiączki w Ustawienia &rarr; Libido – cykl & libido.</p>';
        }

        $data = [];
        $labels = [];
        $cycleStart = $this->get_current_cycle_start($latestPeriodStart, $effectiveCycle);
        $cycleDay   = $this->get_cycle_day($cycleStart, $effectiveCycle);

        $phaseBands = [];
        $phaseTooltip = [];

        foreach ($this->get_phases() as $phase) {
            $phaseBands[] = [
                'start' => $phase['start_day'],
                'end'   => $phase['end_day'],
                'color' => $this->get_phase_color($phase['key']),
                'label' => $phase['name'],
            ];
        }

        for ($d = 1; $d <= $effectiveCycle; $d++) {
            $data[] = $this->calculate_libido_score($d, $effectiveCycle);
            $labelDate    = clone $cycleStart;
            $labelDate->modify('+' . ($d - 1) . ' days');
            $labels[] = [
                $labelDate->format('j') . ' ' . $this->get_polish_month_name($labelDate->format('n')),
                'Dzień cyklu ' . $d,
                $this->get_polish_weekday($labelDate)
            ];

            $phaseForDay = $this->get_phase_for_day($this->get_phases(), $d, $effectiveCycle);
            $phaseName   = $phaseForDay['name'] ?? '—';
            $phaseTooltip[] = $labelDate->format('j') . ' ' . $this->get_polish_month_name($labelDate->format('n')) . ' – dzień ' . $d . ' (' . $phaseName . '): szacowane libido ' . $this->calculate_libido_score($d, $effectiveCycle) . '/100';
        }

        $calendarDays = [];
        $tz = wp_timezone();
        $firstOfMonth = new DateTime('first day of this month', $tz);
        $daysInMonth  = (int) $firstOfMonth->format('t');
        for ($i = 0; $i < $daysInMonth; $i++) {
            $dateObj = clone $firstOfMonth;
            $dateObj->modify('+' . $i . ' days');
            $cycleDayForDate = $this->get_cycle_day_for_date($cycleStart, $dateObj, $effectiveCycle);
            $phaseForDate    = $this->get_phase_for_day($this->get_phases(), $cycleDayForDate, $effectiveCycle);
            $calendarDays[] = [
                'day'       => (int) $dateObj->format('j'),
                'month'     => $this->get_polish_month_name($dateObj->format('n')),
                'weekday'   => $this->get_polish_weekday($dateObj),
                'cycle_day' => $cycleDayForDate,
                'phase'     => $phaseForDate['key'] ?? '',
                'color'     => $this->get_phase_color($phaseForDate['key'] ?? ''),
                'label'     => $phaseForDate['name'] ?? 'Poza zakresem',
                'date'      => $dateObj->format('Y-m-d'),
            ];
        }

        ob_start();
        ?>
        <div class="aclibido-front">
            <div class="aclibido-front__header">
                <div>
                    <h3 class="aclibido-front__title">Mapa libido w cyklu</h3>
                    <p class="aclibido-front__subtitle">Kolorowe tło pokazuje fazy cyklu, a linia – szacowane libido.</p>
                </div>
                <form method="post" class="aclibido-front__form">
                    <?php wp_nonce_field('aclibido_front_add', 'aclibido_front_nonce'); ?>
                    <input type="hidden" name="aclibido_front_add" value="1" />
                    <label for="next_cycle_start">Pierwszy dzień kolejnego cyklu</label>
                    <input type="date" id="next_cycle_start" name="next_cycle_start" value="<?php echo esc_attr(date('Y-m-d')); ?>" required />
                    <button class="aclibido-front__btn">Dodaj do historii</button>
                </form>
            </div>

            <?php if (!empty($frontMessage)): ?>
                <div class="aclibido-front__notice aclibido-front__notice--ok"><?php echo $frontMessage; ?></div>
            <?php endif; ?>
            <?php if (!empty($frontError)): ?>
                <div class="aclibido-front__notice aclibido-front__notice--error"><?php echo esc_html($frontError); ?></div>
            <?php endif; ?>

            <div class="aclibido-chart-wrap">
                <canvas id="aclibidoChartFront" height="260"></canvas>
            </div>
            <p class="aclibido-front__legend">
                Kolory faz: menstruacja (czerwony), folikularna (zielony), owulacja (pomarańcz), lutealna (żółty). Nałóż kursorem, aby zobaczyć podpowiedzi.
            </p>

            <div class="aclibido-calendar">
                <h4>Widok kalendarzowy</h4>
                        <div class="aclibido-calendar__grid">
                            <?php foreach ($calendarDays as $day): ?>
                                <div class="aclibido-calendar__cell" style="background: <?php echo esc_attr($day['color']); ?>22; border-color: <?php echo esc_attr($day['color']); ?>;">
                                    <div class="aclibido-calendar__date"><?php echo esc_html($day['day'] . ' ' . $day['month']); ?></div>
                                    <div class="aclibido-calendar__phase"><?php echo esc_html($day['label']); ?></div>
                                    <div class="aclibido-calendar__meta">Dzień cyklu: <?php echo esc_html($day['cycle_day']); ?> • <?php echo esc_html($day['weekday']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
        </div>
        <style>
            .aclibido-front { background:#fff; border:1px solid #e7e7e7; border-radius:16px; padding:18px; box-shadow:0 10px 30px rgba(0,0,0,0.05); font-family:'Inter', system-ui, -apple-system, sans-serif; }
            .aclibido-front__header { display:flex; gap:18px; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; }
            .aclibido-front__title { margin:0; font-size:20px; letter-spacing:-0.01em; }
            .aclibido-front__subtitle { margin:6px 0 0; color:#555; max-width:480px; }
            .aclibido-front__form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; background:#f8fafc; padding:10px 12px; border-radius:12px; border:1px solid #e2e8f0; }
            .aclibido-front__form label { font-weight:600; color:#111827; display:block; font-size:13px; }
            .aclibido-front__form input[type=date] { padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; min-width:180px; font-size:14px; }
            .aclibido-front__btn { background:linear-gradient(135deg,#ef4444,#f59e0b); border:none; color:#fff; padding:9px 14px; border-radius:10px; font-weight:700; cursor:pointer; box-shadow:0 6px 16px rgba(239,68,68,0.25); }
            .aclibido-front__btn:hover { transform:translateY(-1px); }
            .aclibido-front__notice { margin:12px 0; padding:10px 12px; border-radius:10px; font-weight:600; }
            .aclibido-front__notice--ok { background:#ecfdf3; color:#166534; border:1px solid #bbf7d0; }
            .aclibido-front__notice--error { background:#fef2f2; color:#b91c1c; border:1px solid #fecdd3; }
            .aclibido-chart-wrap { position:relative; margin-top:12px; }
            .aclibido-front__legend { color:#475569; font-size:13px; margin:8px 0 0; }
            .aclibido-calendar { margin-top:18px; }
            .aclibido-calendar h4 { margin:0 0 10px; }
            .aclibido-calendar__grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; }
            .aclibido-calendar__cell { border:1px solid #e2e8f0; border-radius:12px; padding:10px; background:#f8fafc; }
            .aclibido-calendar__date { font-size:18px; font-weight:700; color:#0f172a; }
            .aclibido-calendar__phase { font-size:13px; color:#334155; margin-top:2px; }
            .aclibido-calendar__meta { font-size:12px; color:#475569; margin-top:6px; }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            (function() {
                const canvas = document.getElementById('aclibidoChartFront');
                if (!canvas) return;
                const data = <?php echo wp_json_encode($data); ?>;
                const labels = <?php echo wp_json_encode($labels); ?>;
                const currentDay = <?php echo (int) $cycleDay; ?>;
                const bands = <?php echo wp_json_encode($phaseBands); ?>;
                const tooltipText = <?php echo wp_json_encode($phaseTooltip); ?>;
                const ctx = canvas.getContext('2d');

                const backgroundGradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
                backgroundGradient.addColorStop(0, 'rgba(239,68,68,0.16)');
                backgroundGradient.addColorStop(1, 'rgba(245,158,11,0.05)');

                const markerPlugin = {
                    id: 'aclibidoMarkerFront',
                    afterDraw(chart) {
                        const {ctx, chartArea, scales} = chart;
                        const meta = chart.getDatasetMeta(0);
                        if (!scales?.x || !scales?.y || !meta?.data?.length) return;
                        const xPos = scales.x.getPixelForValue(currentDay - 1);
                        ctx.save();
                        ctx.strokeStyle = '#e11d48';
                        ctx.lineWidth = 2;
                        ctx.beginPath();
                        ctx.moveTo(xPos, chartArea.top);
                        ctx.lineTo(xPos, chartArea.bottom);
                        ctx.stroke();
                        ctx.fillStyle = '#fff';
                        ctx.strokeStyle = '#e11d48';
                        ctx.lineWidth = 1.25;
                        const labelY = chartArea.top + 14;
                        const labelX = xPos - 18;
                        const labelW = 36;
                        const labelH = 22;
                        ctx.fillRect(labelX, labelY - 14, labelW, labelH);
                        ctx.strokeRect(labelX, labelY - 14, labelW, labelH);
                        ctx.fillStyle = '#e11d48';
                        ctx.font = '600 12px Inter, system-ui, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText('Dziś', xPos, labelY);
                        ctx.restore();
                    }
                };

                const phaseBackgroundPlugin = {
                    id: 'aclibidoPhaseBackground',
                    beforeDatasetsDraw(chart) {
                        const {ctx, chartArea} = chart;
                        const meta = chart.getDatasetMeta(0);
                        if (!meta?.data?.length) return;
                        const pointGap = meta.data[1] ? meta.data[1].x - meta.data[0].x : chartArea.width / Math.max(1, meta.data.length);

                        ctx.save();
                        bands.forEach((band) => {
                            const startIndex = Math.max(0, band.start - 1);
                            const endIndex = Math.min(meta.data.length - 1, band.end - 1);
                            const startX = meta.data[startIndex].x - pointGap / 2;
                            const endX = meta.data[endIndex].x + pointGap / 2;
                            const width = endX - startX;
                            const grad = ctx.createLinearGradient(startX, chartArea.top, endX, chartArea.top);
                            grad.addColorStop(0, band.color);
                            grad.addColorStop(1, band.color);
                            ctx.fillStyle = grad;
                            ctx.globalAlpha = 0.14;
                            ctx.fillRect(startX, chartArea.top, width, chartArea.bottom - chartArea.top);
                            ctx.globalAlpha = 1;
                            ctx.fillStyle = band.color;
                            ctx.font = '600 11px Inter, system-ui, sans-serif';
                            ctx.textAlign = 'center';
                            ctx.fillText(band.label, startX + width / 2, chartArea.bottom + 16);
                        });
                        ctx.restore();
                    }
                };

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Szacowane libido (0–100)',
                            data: data,
                            tension: 0.4,
                            fill: true,
                            backgroundColor: backgroundGradient,
                            borderColor: '#e11d48',
                            borderWidth: 3,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#e11d48',
                            pointBorderWidth: 2,
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            x: {
                                ticks: {
                                    callback: function(value) {
                                        return labels[value];
                                    },
                                    color: '#475569',
                                    font: { size: 11 }
                                },
                                grid: { display: false }
                            },
                            y: {
                                suggestedMin: 0,
                                suggestedMax: 100,
                                grid: { color: 'rgba(226,232,240,0.6)' },
                                ticks: { color: '#475569' }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#0f172a',
                                titleColor: '#f8fafc',
                                bodyColor: '#e2e8f0',
                                displayColors: false,
                                callbacks: {
                                    title: (items) => items.length ? labels[items[0].dataIndex].join(' • ') : '',
                                    label: (ctx) => tooltipText[ctx.dataIndex] || ''
                                }
                            }
                        }
                    },
                    plugins: [phaseBackgroundPlugin, markerPlugin]
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function cron_send_notifications() {
        $options = $this->get_options();

        if (empty($options['last_period_start']) || empty($options['notify_email'])) {
            return;
        }

        $phases = $this->get_phases();

        $tz     = wp_timezone();
        $today  = new DateTime('now', $tz);
        $todayStr = $today->format('Y-m-d');

        $historyDates   = $options['period_history'];
        $latestPeriod   = !empty($historyDates) ? end($historyDates) : $options['last_period_start'];
        $historyCycle   = $this->compute_average_cycle_length($historyDates);
        $cycleLength    = (int) ($historyCycle ?: $options['cycle_length']);
        $reminderDays = (int) $options['reminder_days_before'];

        $currentCycleStart = $this->get_current_cycle_start($latestPeriod, $cycleLength);

        for ($cycleOffset = 0; $cycleOffset <= 1; $cycleOffset++) {
            $cycleStart = clone $currentCycleStart;
            if ($cycleOffset > 0) {
                $cycleStart->modify('+' . ($cycleOffset * $cycleLength) . ' days');
            }

            foreach ($phases as $phase) {
                $phaseStart = clone $cycleStart;
                $phaseStart->modify('+' . ($phase['start_day'] - 1) . ' days');

                $reminderDate = clone $phaseStart;
                $reminderDate->modify('-' . $reminderDays . ' days');

                if ($reminderDate->format('Y-m-d') === $todayStr) {
                    $this->send_email_for_phase(
                        $options['notify_email'],
                        $phase,
                        $phaseStart,
                        $reminderDays,
                        $cycleStart
                    );
                }
            }
        }
    }

    private function send_email_for_phase($email, $phase, DateTime $phaseStart, $reminderDays, DateTime $cycleStart) {
        $subject = 'Faza cyklu za ' . $reminderDays . ' dni: ' . $phase['name'];
        $message = implode("\n", $this->build_phase_email_lines($phase, $phaseStart, $reminderDays, $cycleStart));
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        wp_mail($email, $subject, $message, $headers);
    }

    private function send_test_email($email, $phase, DateTime $phaseStart, $reminderDays, DateTime $cycleStart) {
        $subject = 'Test: przypomnienie o fazie cyklu – ' . $phase['name'];
        $message = implode("\n", $this->build_phase_email_lines($phase, $phaseStart, $reminderDays, $cycleStart, true));
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        return wp_mail($email, $subject, $message, $headers);
    }

    private function build_phase_email_lines($phase, DateTime $phaseStart, $reminderDays, DateTime $cycleStart, $isTest = false) {
        $lines = [];
        if ($isTest) {
            $lines[] = 'To jest testowy email z przypomnieniem o fazie cyklu.';
            $lines[] = 'Wysyłka testowa pomaga sprawdzić, czy dostajesz powiadomienia.';
        } else {
            $lines[] = 'Cześć Arek,';
        }

        $lines[] = '';
        $lines[] = 'Za około ' . $reminderDays . ' dni szacunkowo zacznie się faza:';
        $lines[] = $phase['name'];
        $lines[] = '';
        $lines[] = 'Start fazy (orientacyjnie): ' . $phaseStart->format('Y-m-d');
        $lines[] = 'Początek tego cyklu (dzień 1 miesiączki): ' . $cycleStart->format('Y-m-d');

        $lines[] = 'Najbliższy okres spodziewany: ' . $cycleStart->format('Y-m-d');
        if ($phase['key'] === 'menstruacja') {
            $lines[] = 'To przypomnienie o zbliżającej się miesiączce.';
        }

        $lines[] = '';
        $lines[] = 'Jak wygląda ta faza:';
        $lines[] = '';
        $lines[] = '- Bazowo: ' . $phase['desc_base'];
        $lines[] = '- Przy Hashimoto: ' . $phase['desc_hashimoto'];
        $lines[] = '- Przy wkładce hormonalnej: ' . $phase['desc_iud'];
        $lines[] = '';
        $lines[] = 'Model libido w tej fazie jest przybliżony na podstawie badań o Hashimoto i levonorgestrelowych wkładkach.';
        $lines[] = 'To nie jest diagnoza medyczna, tylko orientacyjny „radar nastroju i libido”.';
        $lines[] = '';
        $lines[] = 'Traktuj to jako podpowiedź, kiedy warto dać jej więcej przestrzeni, a kiedy większą szansę na bliskość 😊';

        return $lines;
    }

    private function get_next_phase_info($phases, DateTime $cycleStart, $cycleLength) {
        $currentDay = $this->get_cycle_day($cycleStart, $cycleLength);

        foreach ($phases as $phase) {
            if ($phase['start_day'] >= $currentDay) {
                $phaseStart = clone $cycleStart;
                $phaseStart->modify('+' . ($phase['start_day'] - 1) . ' days');
                return [$phase, $phaseStart];
            }
        }

        $nextCycleStart = clone $cycleStart;
        $nextCycleStart->modify('+' . $cycleLength . ' days');
        $firstPhase = $phases[0];
        $phaseStart = clone $nextCycleStart;
        $phaseStart->modify('+' . ($firstPhase['start_day'] - 1) . ' days');

        return [$firstPhase, $phaseStart];
    }

    private function get_current_cycle_start($lastPeriodStart, $cycleLength) {
        $tz = wp_timezone();

        try {
            $lastStart = new DateTime($lastPeriodStart, $tz);
        } catch (Exception $e) {
            return new DateTime('now', $tz);
        }

        $today = new DateTime('now', $tz);
        $diff  = (int) $lastStart->diff($today)->format('%r%a');

        if ($diff < 0) {
            return $lastStart;
        }

        $cyclesPassed = intdiv($diff, $cycleLength);
        $cycleStart   = clone $lastStart;
        if ($cyclesPassed > 0) {
            $cycleStart->modify('+' . ($cyclesPassed * $cycleLength) . ' days');
        }

        return $cycleStart;
    }

    private function get_cycle_day(DateTime $cycleStart, $cycleLength) {
        $tz = wp_timezone();
        $today = new DateTime('now', $tz);
        $diff  = (int) $cycleStart->diff($today)->format('%r%a');
        $day   = $diff + 1;
        if ($day < 1) {
            $day = 1;
        }
        if ($day > $cycleLength) {
            $day = $cycleLength;
        }
        return $day;
    }

    private function get_phase_for_day($phases, $day, $cycleLength) {
        if ($day < 1 || $day > $cycleLength) {
            return null;
        }
        foreach ($phases as $phase) {
            if ($day >= $phase['start_day'] && $day <= $phase['end_day']) {
                return $phase;
            }
        }
        return null;
    }

    private function get_cycle_day_for_date(DateTime $cycleStart, DateTime $targetDate, $cycleLength) {
        $diff  = (int) $cycleStart->diff($targetDate)->format('%r%a');
        $day   = ($diff % $cycleLength) + 1;
        if ($day < 1) {
            $day += $cycleLength;
        }
        if ($day > $cycleLength) {
            $day = $cycleLength;
        }
        return $day;
    }

    private function get_phase_color($phaseKey) {
        $map = [
            'menstruacja' => '#ef4444',
            'folikularna' => '#22c55e',
            'owulacja'    => '#f97316',
            'lutealna'    => '#fbbf24',
        ];
        return $map[$phaseKey] ?? '#94a3b8';
    }

    private function calculate_libido_score($day, $cycleLength) {
        $base = 40;

        if ($day >= 1 && $day <= 5) {
            $base = 20;
        } elseif ($day >= 6 && $day <= 12) {
            $progress = ($day - 6) / max(1, (12 - 6));
            $base = 30 + $progress * 40;
        } elseif ($day >= 13 && $day <= 16) {
            $map = [13 => 80, 14 => 90, 15 => 90, 16 => 80];
            $base = isset($map[$day]) ? $map[$day] : 80;
        } else {
            if ($day <= 22) {
                $progress = ($day - 17) / max(1, (22 - 17));
                $base = 70 - $progress * 30;
            } else {
                $progress = ($day - 23) / max(1, (28 - 23));
                $base = 40 - $progress * 20;
            }
        }

        $hashimotoFactor = 0.85;
        $score = $base * $hashimotoFactor;

        if ($day <= 5 || $day >= 17) {
            $score -= 5;
        }

        $towards = 50;
        $flattenFactor = 0.4;
        $score = $score + ($towards - $score) * $flattenFactor;

        if ($score < 5) {
            $score = 5;
        }
        if ($score > 95) {
            $score = 95;
        }

        return round($score, 0);
    }
}

new AC_Libido_Cycle_Notifier();
