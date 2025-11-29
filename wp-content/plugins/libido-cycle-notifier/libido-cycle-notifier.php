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

        $libidoData = [];
        $labels = [];
        for ($d = 1; $d <= $effectiveCycle; $d++) {
            $libidoData[] = $this->calculate_libido_score($d, $effectiveCycle);
            $labelDate    = clone $cycleStart;
            $labelDate->modify('+' . ($d - 1) . ' days');
            $labels[] = ['Dzień ' . $d, $this->get_polish_weekday($labelDate)];
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

        for ($d = 1; $d <= $effectiveCycle; $d++) {
            $data[] = $this->calculate_libido_score($d, $effectiveCycle);
            $labelDate    = clone $cycleStart;
            $labelDate->modify('+' . ($d - 1) . ' days');
            $labels[] = ['Dzień ' . $d, $this->get_polish_weekday($labelDate)];
        }

        ob_start();
        ?>
        <div class="aclibido-chart-wrap" style="max-width:800px;margin:0 auto;">
            <canvas id="aclibidoChartFront" height="220"></canvas>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            (function() {
                const ctx = document.getElementById('aclibidoChartFront');
                if (!ctx) return;
                const data = <?php echo wp_json_encode($data); ?>;
                const labels = <?php echo wp_json_encode($labels); ?>;
                const currentDay = <?php echo (int) $cycleDay; ?>;

                const markerPlugin = {
                    id: 'aclibidoMarkerFront',
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

        $lines = [
            'Cześć Arek,',
            '',
            'Za około ' . $reminderDays . ' dni szacunkowo zacznie się faza:',
            $phase['name'],
            '',
            'Start fazy (orientacyjnie): ' . $phaseStart->format('Y-m-d'),
            'Początek tego cyklu (dzień 1 miesiączki): ' . $cycleStart->format('Y-m-d'),
            '',
            'Jak wygląda ta faza:',
            '',
            '- Bazowo: ' . $phase['desc_base'],
            '- Przy Hashimoto: ' . $phase['desc_hashimoto'],
            '- Przy wkładce hormonalnej: ' . $phase['desc_iud'],
            '',
            'Model libido w tej fazie jest przybliżony na podstawie badań o Hashimoto i levonorgestrelowych wkładkach.',
            'To nie jest diagnoza medyczna, tylko orientacyjny „radar nastroju i libido”.',
            '',
            'Traktuj to jako podpowiedź, kiedy warto dać jej więcej przestrzeni, a kiedy większą szansę na bliskość 😊',
        ];

        $message = implode("\n", $lines);

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        wp_mail($email, $subject, $message, $headers);
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
