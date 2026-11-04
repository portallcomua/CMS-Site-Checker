<?php
/*
Plugin Name: CMS Site Checker
Description: Пошук реальних сайтів за CMS, платформою, країною, мовою та типом.
Version: 3.0.0
Author: UA Tools
Text Domain: cms-site-checker
*/

if (!defined('ABSPATH')) exit;

final class CMS_Site_Checker {

    const VERSION = '3.0.0';
    const OPT = 'cms_sc3_data';
    const NONCE = 'cms_sc3_nonce';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('wp_ajax_cms_sc3_search', [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_cms_sc3_check', [__CLASS__, 'ajax_check']);
        add_action('wp_ajax_cms_sc3_delete', [__CLASS__, 'ajax_delete']);
        add_action('wp_ajax_cms_sc3_campaign', [__CLASS__, 'ajax_campaign']);
        add_action('admin_init', [__CLASS__, 'export']);
    }

    private static function defaults() {
        return [
            'campaigns' => [],
            'active' => '',
        ];
    }

    private static function data() {
        $d = get_option(self::OPT, []);
        return wp_parse_args(is_array($d) ? $d : [], self::defaults());
    }

    private static function save($d) {
        update_option(self::OPT, $d, false);
    }

    public static function menu() {
        add_menu_page(
            'CMS Site Checker',
            'CMS Checker',
            'manage_options',
            'cms-checker',
            [__CLASS__, 'page'],
            'dashicons-search',
            30
        );
    }

    private static function guard() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Недостатньо прав'], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private static function campaign(&$d) {
        $id = $d['active'];

        if (!$id || empty($d['campaigns'][$id])) {
            $id = 'main';
            $d['active'] = $id;

            if (empty($d['campaigns'][$id])) {
                $d['campaigns'][$id] = [
                    'name' => 'Основний список',
                    'created' => current_time('mysql'),
                    'sites' => [],
                ];
            }
        }

        return $d['campaigns'][$id];
    }

    /*
     * Пошукові запити формуються окремо за фільтрами.
     */
    private static function build_queries($f) {
        $queries = [];

        $country = $f['country'];
        $type = $f['type'];
        $cms = $f['cms'];
        $platform = $f['platform'];
        $language = $f['language'];
        $topic = $f['topic'];
        $custom = $f['custom'];

        $countryTerms = [
            'ua' => 'Україна',
            'pl' => 'Poland',
            'de' => 'Germany',
            'us' => 'United States',
            'gb' => 'United Kingdom',
            'all' => '',
        ];

        $typeTerms = [
            'shop' => 'інтернет-магазин',
            'catalog' => 'каталог товарів',
            'corporate' => 'офіційний сайт компанії',
            'services' => 'послуги компанії',
            'portal' => 'інформаційний портал',
            'all' => '',
        ];

        $languageTerms = [
            'uk' => 'українською мовою',
            'en' => 'English',
            'ru' => 'російською мовою',
            'pl' => 'po polsku',
            'all' => '',
        ];

        $parts = [];

        if (!empty($countryTerms[$country])) {
            $parts[] = $countryTerms[$country];
        }

        if (!empty($typeTerms[$type])) {
            $parts[] = $typeTerms[$type];
        }

        if ($cms !== 'all') {
            $parts[] = $cms;
        }

        if ($platform !== 'all') {
            $parts[] = $platform;
        }

        if (!empty($languageTerms[$language])) {
            $parts[] = $languageTerms[$language];
        }

        if ($topic) {
            $parts[] = $topic;
        }

        if ($custom) {
            $parts[] = $custom;
        }

        $base = implode(' ', $parts);

        if ($base) {
            $queries[] = $base;
        }

        if ($platform === 'WooCommerce') {
            $queries[] = 'site:.ua "wp-content/plugins/woocommerce" ' . $topic;
            $queries[] = 'site:.ua "woocommerce" "кошик" ' . $topic;
            $queries[] = 'site:.ua "add-to-cart" ' . $topic;
        }

        if ($platform === 'HivePress') {
            $queries[] = 'site:.ua "HivePress" каталог';
            $queries[] = 'site:.ua "hp-listing"';
        }

        if ($cms === 'Joomla') {
            $queries[] = 'site:.ua Joomla компанія';
            $queries[] = 'site:.ua "com_content"';
        }

        return array_values(array_unique(array_filter($queries)));
    }

    /*
     * Пошук кандидатів через DuckDuckGo та Bing.
     */
    private static function search_engine($query, $engine, $limit) {
        $urls = [];

        $targets = [];

        if ($engine === 'ddg' || $engine === 'all') {
            $targets[] = [
                'https://html.duckduckgo.com/html/?q=' . rawurlencode($query),
                'ddg'
            ];
        }

        if ($engine === 'bing' || $engine === 'all') {
            $targets[] = [
                'https://www.bing.com/search?q=' . rawurlencode($query),
                'bing'
            ];
        }

        foreach ($targets as $target) {
            $response = wp_remote_get($target[0], [
                'timeout' => 12,
                'redirection' => 3,
                'user-agent' => 'Mozilla/5.0 (compatible; CMSSiteChecker/3.0)',
                'headers' => [
                    'Accept-Language' => 'uk-UA,uk;q=0.9,en;q=0.8'
                ],
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            if (wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }

            $html = wp_remote_retrieve_body($response);

            if (!$html) continue;

            if ($target[1] === 'ddg') {
                preg_match_all(
                    '~class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is',
                    $html,
                    $matches
                );
            } else {
                preg_match_all(
                    '~<li class="b_algo".*?<h2><a href="([^"]+)"[^>]*>(.*?)</a>~is',
                    $html,
                    $matches
                );
            }

            foreach (($matches[1] ?? []) as $i => $href) {
                $href = html_entity_decode($href);

                if (strpos($href, 'uddg=') !== false) {
                    parse_str((string) wp_parse_url($href, PHP_URL_QUERY), $params);
                    $href = $params['uddg'] ?? $href;
                }

                $host = strtolower((string) wp_parse_url($href, PHP_URL_HOST));

                if (!$host) continue;

                $host = preg_replace('/^www\./i', '', $host);

                if (
                    preg_match('/(duckduckgo|bing|google)\.com$/i', $host) ||
                    preg_match('/(facebook|instagram|youtube|linkedin|wikipedia)\./i', $host)
                ) {
                    continue;
                }

                if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) {
                    continue;
                }

                $url = 'https://' . $host . '/';

                $urls[$host] = [
                    'url' => $url,
                    'source' => $target[1],
                    'title' => sanitize_text_field(
                        wp_strip_all_tags($matches[2][$i] ?? '')
                    ),
                ];

                if (count($urls) >= $limit) break;
            }
        }

        return array_values($urls);
    }

    /*
     * Захист від звернень до локальних IP та внутрішніх адрес.
     */
    private static function safe_url($url) {
        $url = esc_url_raw($url);

        if (!wp_http_validate_url($url)) {
            return false;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!$host) return false;

        if (
            $host === 'localhost' ||
            substr($host, -6) === '.local' ||
            filter_var($host, FILTER_VALIDATE_IP)
        ) {
            return false;
        }

        $ip = gethostbyname($host);

        if ($ip === $host) return false;

        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false
        ) {
            return false;
        }

        return $url;
    }

    /*
     * Перевірка безпосередньо сайту.
     */
    private static function inspect($url) {
        $url = self::safe_url($url);

        if (!$url) {
            return [
                'status' => 'Адресу відхилено',
                'cms' => 'Не визначено',
                'platform' => '',
                'confidence' => 'Unknown',
            ];
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 4,
            'limit_response_size' => 800000,
            'sslverify' => true,
            'user-agent' => 'Mozilla/5.0 (compatible; CMSSiteChecker/3.0)',
        ]);

        if (is_wp_error($response)) {
            return [
                'status' => 'Помилка з’єднання',
                'cms' => 'Не визначено',
                'platform' => '',
                'confidence' => 'Unknown',
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $html = wp_remote_retrieve_body($response);

        if (!$html) {
            return [
                'status' => 'Порожня сторінка',
                'cms' => 'Не визначено',
                'platform' => '',
                'confidence' => 'Unknown',
            ];
        }

        $cms = 'Не визначено';
        $version = '';
        $platforms = [];

        $tests = [
            'WordPress' => [
                '/wp-content/',
                '/wp-includes/',
                'wp-json',
            ],
            'Joomla' => [
                '/media/system/js/',
                '/media/templates/',
                'com_content',
            ],
            'Drupal' => [
                '/sites/default/',
                'drupal-settings-json',
            ],
            'OpenCart' => [
                'catalog/view/',
                'route=common/home',
                'index.php?route=product/',
            ],
            'Shopify' => [
                'cdn.shopify.com',
                'Shopify.theme',
            ],
        ];

        $cmsEvidence = [];

        foreach ($tests as $name => $needles) {
            foreach ($needles as $needle) {
                if (stripos($html, $needle) !== false) {
                    $cmsEvidence[$name] = true;
                }
            }
        }

        if (count($cmsEvidence) === 1) {
            $cms = array_key_first($cmsEvidence);
        } elseif (isset($cmsEvidence['WordPress'])) {
            $cms = 'WordPress';
        } elseif (count($cmsEvidence) > 1) {
            $cms = 'Кілька ознак';
        }

        $wooCount = 0;

        foreach ([
            'wp-content/plugins/woocommerce',
            'woocommerce',
            'wc-ajax',
            'add-to-cart',
            'wp_woocommerce_session',
        ] as $needle) {
            if (stripos($html, $needle) !== false) {
                $wooCount++;
            }
        }

        if ($wooCount >= 2) {
            $platforms[] = 'WooCommerce';
        }

        if (stripos($html, 'hivepress') !== false) {
            $platforms[] = 'HivePress';
        }

        if (stripos($html, 'easy digital downloads') !== false ||
            stripos($html, 'edd-cart') !== false) {
            $platforms[] = 'Easy Digital Downloads';
        }

        if (stripos($html, 'opencart') !== false ||
            stripos($html, 'route=checkout') !== false && $cms === 'OpenCart') {
            $platforms[] = 'OpenCart';
        }

        if (preg_match(
            '~<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)~i',
            $html,
            $generator
        )) {
            if (preg_match('/([0-9]+(?:\.[0-9]+)+)/', $generator[1], $v)) {
                $version = $v[1];
            }
        }

        $title = '';

        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
            $title = sanitize_text_field(
                html_entity_decode(wp_strip_all_tags($m[1]))
            );
        }

        $emails = [];

        preg_match_all(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            $html,
            $emailMatches
        );

        foreach (($emailMatches[0] ?? []) as $email) {
            $email = sanitize_email($email);

            if ($email && !preg_match('/example\.|\.png$|\.jpg$/i', $email)) {
                $emails[] = $email;
            }
        }

        $phones = [];

        preg_match_all(
            '/(?:\+?\d[\d\s().-]{7,}\d)/',
            $html,
            $phoneMatches
        );

        foreach (($phoneMatches[0] ?? []) as $phone) {
            $phones[] = sanitize_text_field($phone);
        }

        $language = '';

        if (preg_match('~<html[^>]+lang=["\']([^"\']+)~i', $html, $lm)) {
            $language = strtolower(substr($lm[1], 0, 2));
        }

        $isShop = (
            $wooCount >= 2 ||
            stripos($html, 'add to cart') !== false ||
            stripos($html, 'додати в кошик') !== false ||
            stripos($html, 'купити') !== false ||
            stripos($html, 'shopping-cart') !== false
        );

        $isArticle = (
            preg_match('/(blog|article|news|новини|статті|статья)/i', $url) ||
            preg_match('/(top \d+|best \d+|список магазинів|каталог сайтів)/i', $title)
        );

        $country = 'Не підтверджено';

        if (
            $language === 'uk' ||
            stripos($html, 'грн') !== false ||
            stripos($html, 'Україна') !== false ||
            preg_match('/\.ua$/i', wp_parse_url($url, PHP_URL_HOST))
        ) {
            $country = 'Ймовірно Україна';
        }

        $confidence = 'Unknown';

        if ($cms !== 'Не визначено' && $cms !== 'Кілька ознак') {
            $confidence = 'Likely';
        }

        if ($wooCount >= 2) {
            $confidence = 'Confirmed';
        }

        return [
            'status' => ($code >= 200 && $code < 400)
                ? 'Доступний HTTP ' . $code
                : 'HTTP ' . $code,
            'cms' => $cms,
            'version' => $version,
            'platform' => implode(', ', array_unique($platforms)),
            'title' => $title,
            'language' => $language,
            'country' => $country,
            'site_type' => $isShop ? 'Інтернет-магазин' : 'Не підтверджено',
            'is_article' => $isArticle ? 1 : 0,
            'confidence' => $confidence,
            'email' => implode(', ', array_unique($emails)),
            'phone' => reset($phones) ?: '',
            'checked' => current_time('mysql'),
        ];
    }

    public static function ajax_search() {
        self::guard();

        $country = sanitize_key($_POST['country'] ?? 'ua');
        $type = sanitize_key($_POST['type'] ?? 'shop');
        $cms = sanitize_text_field(wp_unslash($_POST['cms'] ?? 'all'));
        $platform = sanitize_text_field(wp_unslash($_POST['platform'] ?? 'all'));
        $language = sanitize_key($_POST['language'] ?? 'uk');
        $topic = sanitize_text_field(wp_unslash($_POST['topic'] ?? ''));
        $custom = sanitize_text_field(wp_unslash($_POST['custom'] ?? ''));
        $engine = sanitize_key($_POST['engine'] ?? 'ddg');
        $limit = min(30, max(1, (int)($_POST['limit'] ?? 10)));
        $mode = sanitize_key($_POST['mode'] ?? 'expanded');

        $filters = compact(
            'country',
            'type',
            'cms',
            'platform',
            'language',
            'topic',
            'custom'
        );

        $queries = self::build_queries($filters);
        $candidates = [];

        foreach ($queries as $query) {
            $remaining = max(1, $limit - count($candidates));

            $results = self::search_engine(
                $query,
                $engine,
                $remaining
            );

            foreach ($results as $result) {
                $host = wp_parse_url($result['url'], PHP_URL_HOST);

                if ($host) {
                    $candidates[strtolower($host)] = $result;
                }
            }

            if (count($candidates) >= $limit) break;
        }

        $d = self::data();
        $campaign = &self::campaign($d);
        $added = 0;
        $verified = 0;

        foreach ($candidates as $candidate) {
            $info = self::inspect($candidate['url']);

            // Статті та сторінки зі списками сайтів не додаємо.
            if (!empty($info['is_article'])) {
                continue;
            }

            // Сувора перевірка платформи.
            if ($platform !== 'all') {
                if (stripos($info['platform'], $platform) === false) {
                    if ($mode === 'strict') continue;
                } else {
                    $verified++;
                }
            }

            // CMS перевіряється на самому сайті.
            if ($cms !== 'all' && $info['cms'] !== $cms) {
                if ($mode === 'strict') continue;
            }

            if ($type === 'shop' && $info['site_type'] !== 'Інтернет-магазин') {
                if ($mode === 'strict') continue;
            }

            if ($language !== 'all' && $info['language'] !== $language) {
                if ($mode === 'strict') continue;
            }

            if ($country === 'ua' && $info['country'] === 'Не підтверджено') {
                if ($mode === 'strict') continue;
            }

            $row = array_merge($candidate, $info);

            $key = md5($candidate['url']);

            if (isset($campaign['sites'][$key])) {
                continue;
            }

            $campaign['sites'][$key] = $row;
            $added++;
        }

        self::save($d);

        wp_send_json_success([
            'found' => count($candidates),
            'added' => $added,
            'verified' => $verified,
            'message' => 'Кандидатів: ' . count($candidates) .
                '. Додано після перевірки: ' . $added . '.',
        ]);
    }

    public static function ajax_check() {
        self::guard();

        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));

        if (!$url) {
            wp_send_json_error(['message' => 'Некоректна адреса']);
        }

        $info = self::inspect($url);

        $d = self::data();
        $id = $d['active'];

        if (empty($d['campaigns'][$id])) {
            wp_send_json_error(['message' => 'Немає активного списку']);
        }

        $key = md5($url);

        $old = $d['campaigns'][$id]['sites'][$key] ?? [];

        $d['campaigns'][$id]['sites'][$key] = array_merge(
            $old,
            ['url' => $url],
            $info
        );

        self::save($d);

        wp_send_json_success($d['campaigns'][$id]['sites'][$key]);
    }

    public static function ajax_delete() {
        self::guard();

        $key = sanitize_text_field($_POST['key'] ?? '');
        $d = self::data();
        $id = $d['active'];

        unset($d['campaigns'][$id]['sites'][$key]);

        self::save($d);

        wp_send_json_success();
    }

    public static function ajax_campaign() {
        self::guard();

        $d = self::data();
        $op = sanitize_key($_POST['op'] ?? '');

        if ($op === 'create') {
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));

            if (!$name) {
                wp_send_json_error(['message' => 'Вкажіть назву']);
            }

            $id = 'c' . wp_generate_password(8, false, false);

            $d['campaigns'][$id] = [
                'name' => $name,
                'created' => current_time('mysql'),
                'sites' => [],
            ];

            $d['active'] = $id;
        }

        if ($op === 'open') {
            $id = sanitize_key($_POST['id'] ?? '');

            if (empty($d['campaigns'][$id])) {
                wp_send_json_error(['message' => 'Список не знайдено']);
            }

            $d['active'] = $id;
        }

        if ($op === 'delete') {
            $id = $d['active'];
            unset($d['campaigns'][$id]);
            $d['active'] = '';
        }

        self::save($d);

        wp_send_json_success([
            'campaigns' => $d['campaigns'],
            'active' => $d['active'],
        ]);
    }

    public static function export() {
        if (
            !is_admin() ||
            !current_user_can('manage_options') ||
            empty($_GET['cms_sc3_export'])
        ) {
            return;
        }

        check_admin_referer('cms_sc3_export');

        $d = self::data();
        $id = sanitize_key($_GET['campaign'] ?? $d['active']);
        $sites = $d['campaigns'][$id]['sites'] ?? [];

        nocache_headers();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cms-sites.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'URL',
            'Назва',
            'CMS',
            'Версія',
            'Платформа',
            'Країна',
            'Мова',
            'Тип сайту',
            'Email',
            'Телефон',
            'Статус',
            'Впевненість',
            'Дата перевірки',
        ]);

        foreach ($sites as $s) {
            fputcsv($out, [
                $s['url'] ?? '',
                $s['title'] ?? '',
                $s['cms'] ?? '',
                $s['version'] ?? '',
                $s['platform'] ?? '',
                $s['country'] ?? '',
                $s['language'] ?? '',
                $s['site_type'] ?? '',
                $s['email'] ?? '',
                $s['phone'] ?? '',
                $s['status'] ?? '',
                $s['confidence'] ?? '',
                $s['checked'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;

        $d = self::data();
        $campaign = self::campaign($d);
        self::save($d);

        $id = $d['active'];
        $nonce = wp_create_nonce(self::NONCE);
        $ajax = admin_url('admin-ajax.php');

        $export = wp_nonce_url(
            admin_url(
                'admin.php?page=cms-checker&cms_sc3_export=1&campaign=' . $id
            ),
            'cms_sc3_export'
        );

        ?>
        <div class="wrap cms-sc3">
            <h1>CMS Site Checker 3.0</h1>

            <style>
                .cms-sc3{max-width:1250px}
                .sc3-box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin:15px 0}
                .sc3-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
                .sc3-grid input,.sc3-grid select{width:100%;max-width:100%}
                .sc3-sites{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
                .sc3-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:13px;overflow-wrap:anywhere}
                .sc3-card h3{margin-top:5px}
                .sc3-muted{color:#777;font-size:12px}
                .sc3-status{padding:8px 0;font-weight:600}
                @media(max-width:600px){.sc3-sites{grid-template-columns:1fr}.sc3-box{padding:11px}}
            </style>

            <div class="sc3-box">
                <h2>Пошук сайтів за фільтрами</h2>

                <div class="sc3-grid">
                    <label>Країна
                        <select id="sc3-country">
                            <option value="ua">Україна</option>
                            <option value="pl">Польща</option>
                            <option value="de">Німеччина</option>
                            <option value="us">США</option>
                            <option value="gb">Велика Британія</option>
                            <option value="all">Будь-яка</option>
                        </select>
                    </label>

                    <label>Тип сайту
                        <select id="sc3-type">
                            <option value="shop">Інтернет-магазин</option>
                            <option value="catalog">Каталог</option>
                            <option value="corporate">Корпоративний</option>
                            <option value="services">Послуги</option>
                            <option value="portal">Портал</option>
                            <option value="all">Будь-який</option>
                        </select>
                    </label>

                    <label>CMS
                        <select id="sc3-cms">
                            <option value="all">Будь-яка CMS</option>
                            <option>WordPress</option>
                            <option>Joomla</option>
                            <option>Drupal</option>
                            <option>OpenCart</option>
                            <option>Shopify</option>
                        </select>
                    </label>

                    <label>Платформа
                        <select id="sc3-platform">
                            <option value="all">Будь-яка</option>
                            <option>WooCommerce</option>
                            <option>HivePress</option>
                            <option>Easy Digital Downloads</option>
                            <option>OpenCart</option>
                        </select>
                    </label>

                    <label>Мова
                        <select id="sc3-language">
                            <option value="uk">Українська</option>
                            <option value="en">Англійська</option>
                            <option value="ru">Російська</option>
                            <option value="pl">Польська</option>
                            <option value="all">Будь-яка</option>
                        </select>
                    </label>

                    <label>Тематика
                        <input id="sc3-topic" placeholder="Одяг, меблі, електроніка...">
                    </label>

                    <label>Додатковий запит
                        <input id="sc3-custom" placeholder="Ваші ключові слова">
                    </label>

                    <label>Пошуковик
                        <select id="sc3-engine">
                            <option value="ddg">DuckDuckGo</option>
                            <option value="bing">Bing</option>
                            <option value="all">DuckDuckGo + Bing</option>
                        </select>
                    </label>

                    <label>Кількість кандидатів
                        <select id="sc3-limit">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="30">30</option>
                        </select>
                    </label>

                    <label>Режим
                        <select id="sc3-mode">
                            <option value="strict">Суворий — тільки збіги</option>
                            <option value="expanded" selected>Розширений — можливі збіги</option>
                        </select>
                    </label>
                </div>

                <p>
                    <button class="button button-primary" id="sc3-search">
                        Знайти та перевірити сайти
                    </button>
                </p>

                <div class="sc3-status" id="sc3-status" aria-live="polite"></div>

                <p class="sc3-muted">
                    Пошуковики можуть обмежувати автоматичні запити.
                    Країна та мова визначаються за доступними ознаками,
                    а не гарантуються лише доменом.
                </p>
            </div>

            <div class="sc3-box">
                <h2>Кампанії</h2>

                <div class="sc3-grid">
                    <select id="sc3-campaign">
                        <?php foreach ($d['campaigns'] as $cid => $cc): ?>
                            <option value="<?php echo esc_attr($cid); ?>"
                                <?php selected($id, $cid); ?>>
                                <?php echo esc_html($cc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input id="sc3-new-name" placeholder="Назва нового списку">

                    <button class="button" id="sc3-create">Створити список</button>
                    <button class="button" id="sc3-delete-campaign">Видалити активний список</button>
                </div>
            </div>

            <div class="sc3-box">
                <h2>Результати: <?php echo count($campaign['sites']); ?></h2>

                <p>
                    <a class="button button-primary" href="<?php echo esc_url($export); ?>">
                        Експорт CSV
                    </a>
                </p>

                <div class="sc3-sites" id="sc3-sites">
                    <?php foreach ($campaign['sites'] as $key => $s): ?>
                        <article class="sc3-card" data-key="<?php echo esc_attr($key); ?>">
                            <h3>
                                <a href="<?php echo esc_url($s['url']); ?>"
                                   target="_blank" rel="noopener">
                                    <?php echo esc_html($s['url']); ?>
                                </a>
                            </h3>

                            <strong><?php echo esc_html($s['title'] ?? ''); ?></strong>

                            <p>
                                CMS: <?php echo esc_html($s['cms'] ?? 'Не визначено'); ?>
                                <?php echo esc_html($s['version'] ?? ''); ?>
                            </p>

                            <p>
                                Платформа: <?php echo esc_html($s['platform'] ?? '—'); ?>
                            </p>

                            <p>
                                Країна: <?php echo esc_html($s['country'] ?? '—'); ?><br>
                                Мова: <?php echo esc_html($s['language'] ?? '—'); ?><br>
                                Тип: <?php echo esc_html($s['site_type'] ?? '—'); ?>
                            </p>

                            <p>
                                Email: <?php echo esc_html($s['email'] ?? '—'); ?><br>
                                Телефон: <?php echo esc_html($s['phone'] ?? '—'); ?>
                            </p>

                            <p>
                                Статус: <?php echo esc_html($s['status'] ?? '—'); ?><br>
                                Впевненість: <?php echo esc_html($s['confidence'] ?? 'Unknown'); ?>
                            </p>

                            <p class="sc3-muted">
                                Перевірено: <?php echo esc_html($s['checked'] ?? '—'); ?>
                            </p>

                            <button class="button sc3-check">Перевірити ще раз</button>
                            <button class="button sc3-delete">Видалити</button>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <script>
        (function(){
            const ajax = <?php echo wp_json_encode($ajax); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;
            const $ = s => document.querySelector(s);

            async function api(action, data = {}) {
                const form = new FormData();
                form.append('action', 'cms_sc3_' + action);
                form.append('nonce', nonce);

                Object.keys(data).forEach(k => form.append(k, data[k]));

                const response = await fetch(ajax, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin'
                });

                return response.json();
            }

            $('#sc3-search').onclick = async function(){
                const btn = this;
                btn.disabled = true;
                $('#sc3-status').textContent = 'Шукаю кандидатів і перевіряю їхні сайти...';

                try {
                    const result = await api('search', {
                        country: $('#sc3-country').value,
                        type: $('#sc3-type').value,
                        cms: $('#sc3-cms').value,
                        platform: $('#sc3-platform').value,
                        language: $('#sc3-language').value,
                        topic: $('#sc3-topic').value,
                        custom: $('#sc3-custom').value,
                        engine: $('#sc3-engine').value,
                        limit: $('#sc3-limit').value,
                        mode: $('#sc3-mode').value
                    });

                    if (!result.success) {
                        throw new Error(result.data?.message || 'Помилка пошуку');
                    }

                    $('#sc3-status').textContent = result.data.message;
                    location.reload();

                } catch(e) {
                    $('#sc3-status').textContent = e.message;
                } finally {
                    btn.disabled = false;
                }
            };

            $('#sc3-create').onclick = async function(){
                const name = $('#sc3-new-name').value.trim();

                if (!name) return alert('Вкажіть назву списку');

                const r = await api('campaign', {
                    op: 'create',
                    name: name
                });

                if (r.success) location.reload();
            };

            $('#sc3-campaign').onchange = async function(){
                const r = await api('campaign', {
                    op: 'open',
                    id: this.value
                });

                if (r.success) location.reload();
            };

            $('#sc3-delete-campaign').onclick = async function(){
                if (!confirm('Видалити активну кампанію разом із її сайтами?')) return;

                const r = await api('campaign', {op: 'delete'});

                if (r.success) location.reload();
            };

            $('#sc3-sites').addEventListener('click', async function(e){
                const card = e.target.closest('.sc3-card');
                if (!card) return;

                const key = card.dataset.key;
                const url = card.querySelector('h3 a').href;

                if (e.target.classList.contains('sc3-delete')) {
                    if (!confirm('Видалити сайт зі списку?')) return;

                    const r = await api('delete', {key: key});

                    if (r.success) card.remove();
                }

                if (e.target.classList.contains('sc3-check')) {
                    e.target.disabled = true;

                    try {
                        const r = await api('check', {url: url});

                        if (r.success) {
                            alert('Перевірку завершено. Оновіть сторінку.');
                            location.reload();
                        } else {
                            alert(r.data?.message || 'Помилка');
                        }
                    } catch(err) {
                        alert(err.message);
                    }

                    e.target.disabled = false;
                }
            });
        })();
        </script>
        <?php
    }
}

CMS_Site_Checker::init();