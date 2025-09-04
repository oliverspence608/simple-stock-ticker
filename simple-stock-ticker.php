<?php
/**
 * Plugin Name: Simple Stock Ticker
 * Plugin URI:  https://github.com/oliverspence608/simple-stock-ticker
 * Description: A lightweight WordPress plugin to display real-time stock ticker data.
 * Version:     1.0.0
 * Author:      Oliver & Spence Dev Team
 * Author URI:  https://github.com/oliverspence608
 * License:     GPL2
 */

if (!defined('ABSPATH')) exit;

/** ========== Helpers ========== */

/** Fetch quote via FMP and cache for 60 minutes. Tries a few symbol formats for TSXV. */
function sst_fetch_quote_fmp($symbol, $apikey) {
    $cache_key = 'sst_q_' . md5($symbol);
    $cached = get_transient($cache_key);
    if ($cached) return $cached;

    $base = 'https://financialmodelingprep.com/api/v3/quote/';
    $cands = array_unique([$symbol, str_replace('TSXV:', '', $symbol), str_replace('.V','', $symbol).'.V', 'TSXV:'.str_replace('.V','',$symbol)]);

    foreach ($cands as $sym) {
        $url = $base . rawurlencode($sym) . '?apikey=' . rawurlencode($apikey);
        $resp = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($resp)) continue;
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($data) && !empty($data[0]) && isset($data[0]['price'])) {
            set_transient($cache_key, $data[0], HOUR_IN_SECONDS);
            return $data[0];
        }
    }
    return null;
}

/** Derive pretty fields from FMP quote row */
function sst_format_quote($row) {
    if (!$row) return null;
    $name   = $row['name'] ?? ($row['symbol'] ?? '');
    $price  = isset($row['price']) ? (float)$row['price'] : null;
    $chg    = isset($row['change']) ? (float)$row['change'] : 0.0;
    $chgPct = isset($row['changesPercentage']) ? (float)$row['changesPercentage'] : 0.0; // often like 1.23 (not 0.0123)
    $cur    = $row['currency'] ?? 'CAD';
    return [
        'name' => $name,
        'price' => $price,
        'change' => $chg,
        'changePct' => $chgPct,
        'currency' => $cur,
        'symbol' => $row['symbol'] ?? ''
    ];
}

/** Resolve API key: shortcode attr > constant > option */
function sst_get_apikey($attr_key = '') {
    if (!empty($attr_key)) return $attr_key;
    if (defined('SST_FMP_API_KEY') && SST_FMP_API_KEY) return SST_FMP_API_KEY;
    $opt = get_option('sst_fmp_api_key');
    return $opt ? $opt : '';
}


// Twelve Data fetcher (TSXV uses SYMBOL:TSXV, e.g., MUR:TSXV)
function sst_fetch_quote_twelve($symbol, $apikey) {
    if (!$apikey) return null;
    // Normalize to SYMBOL:TSXV if caller passed MUR.V
    $sym = strpos($symbol, ':') !== false ? $symbol : (str_ireplace('.V','',$symbol) . ':TSXV');
    $url = 'https://api.twelvedata.com/quote?symbol=' . rawurlencode($sym) . '&apikey=' . rawurlencode($apikey);
    $resp = wp_remote_get($url, ['timeout' => 12]);
    if (is_wp_error($resp)) return null;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || !empty($data['code']) || !isset($data['price'])) return null;

    return [
        'name'      => $data['name'] ?? $sym,
        'price'     => isset($data['price']) ? (float)$data['price'] : null,
        'change'    => isset($data['change']) ? (float)$data['change'] : 0,
        'changePct' => isset($data['percent_change']) ? (float)$data['percent_change'] : 0,
        'currency'  => $data['currency'] ?? 'CAD',
        'symbol'    => $sym,
    ];
}


/** ========== Banner (text, hourly refresh) ========== */
/**
 * Banner: TradingView Ticker Tape (compact, one symbol) with width + align controls
 */
function sst_shortcode_banner($atts) {
    $a = shortcode_atts([
        'symbols'      => 'TSXV:MUR,OTC:MURMF', // NEW: comma-separated list
        'theme'        => 'dark',               // "light" | "dark"
        'transparent'  => 'true',               // "true" | "false"
        'display_mode' => 'regular',            // "regular" | "compact"
        'height'       => '28px',               // wrapper height
        'width'        => '450px',              // optional: "220px" or "20%"
        'align'        => '',                   // optional: left|center|right
    ], $atts, 'stock_ticker_banner');

    // Build symbols array for TradingView
    $list = array_filter(array_map('trim', explode(',', $a['symbols'])));
    $tv_symbols = array_map(function($s){
        return ['proName' => $s, 'title' => $s];
    }, $list);

    // Wrapper style (optional width/align controls)
    $style = 'height:' . esc_attr($a['height']) . ';overflow:hidden;';
    if ($a['width']) $style .= 'width:' . esc_attr($a['width']) . ';';
    if ($a['align'] === 'center')       $style .= 'margin:0 auto;';
    elseif ($a['align'] === 'right')    $style .= 'margin-left:auto;margin-right:0;';

    ob_start(); ?>
    <div class="tradingview-widget-container sst-tape" style="<?php echo $style; ?>">
      <div class="tradingview-widget-container__widget"></div>
    </div>
    <script async src="https://s3.tradingview.com/external-embedding/embed-widget-ticker-tape.js">
    {
      "symbols": <?php echo wp_json_encode($tv_symbols); ?>,
      "showSymbolLogo": false,
      "colorTheme": "<?php echo esc_js($a['theme']); ?>",
      "isTransparent": <?php echo ($a['transparent'] === 'true' ? 'true' : 'false'); ?>,
      "displayMode": "<?php echo esc_js($a['display_mode']); ?>",
      "locale": "en"
    }
    </script>
    <style>.sst-tape{line-height:1}</style>
    <?php
    return ob_get_clean();
}
add_shortcode('stock_ticker_banner', 'sst_shortcode_banner');

/** AJAX: fresh quote (server-side) */
add_action('wp_ajax_sst_quote_td', 'sst_ajax_quote_td');
add_action('wp_ajax_nopriv_sst_quote_td', 'sst_ajax_quote_td');
function sst_ajax_quote_td() {
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'sst_quote_td')) wp_send_json(['ok'=>false,'err'=>'bad_nonce']);
    $symbol = isset($_GET['symbol']) ? sanitize_text_field($_GET['symbol']) : '';
    if (!$symbol) wp_send_json(['ok'=>false,'err'=>'no_symbol']);

    // Resolve key the same way as the shortcode (no HTML exposure)
    $key = (defined('SST_TWELVE_API_KEY') && SST_TWELVE_API_KEY) ? SST_TWELVE_API_KEY : get_option('sst_twelve_api_key', '');
    if (!$key) wp_send_json(['ok'=>false,'err'=>'no_key']);

    // Bypass cache on AJAX to pull fresh, then update transient
    $row = sst_fetch_quote_twelve($symbol, $key, false);
    if (!$row) wp_send_json(['ok'=>false]);
    set_transient('sst_td_' . md5(sst_td_normalize_symbol($symbol)), $row, HOUR_IN_SECONDS);
    wp_send_json(['ok'=>true,'data'=>$row]);
}

/** ========== TradingView card (unchanged) ========== */
/** Usage: [stock_ticker_card symbol="TSXV:MUR" theme="light"] */
function sst_shortcode_card($atts) {
    $atts = shortcode_atts([
        'symbol' => 'TSXV:MUR',
        'theme'  => 'light',
        'width'  => '100%',
    ], $atts, 'stock_ticker_card');

    ob_start(); ?>
    <div class="tradingview-widget-container" style="max-width:360px;">
      <div class="tradingview-widget-container__widget"></div>
    </div>
    <script async src="https://s3.tradingview.com/external-embedding/embed-widget-single-quote.js">
    {
      "symbol": "<?php echo esc_js($atts['symbol']); ?>",
      "width": "<?php echo esc_js($atts['width']); ?>",
      "isTransparent": false,
      "colorTheme": "<?php echo esc_js($atts['theme']); ?>",
      "locale": "en"
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('stock_ticker_card', 'sst_shortcode_card');

/** ========== TradingView Symbol Info (optional) ========== */
/** Usage: [stock_ticker_details symbol="TSXV:MUR" theme="dark" width="100%"] */
function sst_shortcode_details($atts) {
    $a = shortcode_atts([
        'symbol' => 'TSXV:MUR',
        'theme'  => 'dark',
        'width'  => '100%',
    ], $atts, 'stock_ticker_details');

    ob_start(); ?>
    <div class="tradingview-widget-container sst-symbol-info" style="width:<?php echo esc_attr($a['width']); ?>;overflow:hidden;">
      <div class="tradingview-widget-container__widget"></div>
    </div>
    <script async src="https://s3.tradingview.com/external-embedding/embed-widget-symbol-info.js">
    {
      "symbol": "<?php echo esc_js($a['symbol']); ?>",
      "width": "<?php echo esc_js($a['width']); ?>",
      "locale": "en",
      "colorTheme": "<?php echo esc_js($a['theme']); ?>",
      "isTransparent": true
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('stock_ticker_details', 'sst_shortcode_details');

// --- Twelve Data: normalize & fetch ------------------------------------------
if (!function_exists('sst_fetch_quote_twelve')) {
  function sst_td_normalize_symbol($symbol) {
    $s = trim($symbol);
    if (strpos($s, ':') !== false) return $s;            // already like MUR:TSXV
    if (preg_match('/\.V$/i', $s)) return preg_replace('/\.V$/i','', $s) . ':TSXV';
    if (stripos($s, 'TSXV:') === 0) return substr($s, 5) . ':TSXV';
    return $s . ':TSXV'; // default to TSXV if no exchange given
  }

  function sst_fetch_quote_twelve($symbol, $apikey, $use_cache = true) {
    if (!$apikey) return null;
    $sym = sst_td_normalize_symbol($symbol);
    $cache_key = 'sst_td_' . md5($sym);
    if ($use_cache && ($c = get_transient($cache_key))) return $c;

    $url  = 'https://api.twelvedata.com/quote?symbol=' . rawurlencode($sym) . '&apikey=' . rawurlencode($apikey);
    $resp = wp_remote_get($url, ['timeout' => 12]);
    if (is_wp_error($resp)) return null;

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || isset($data['code']) || !isset($data['price'])) return null;

    $row = [
      'symbol'    => $sym,
      'name'      => $data['name'] ?? $sym,
      'price'     => isset($data['price']) ? (float)$data['price'] : null,
      'change'    => isset($data['change']) ? (float)$data['change'] : 0.0,
      'changePct' => isset($data['percent_change']) ? (float)$data['percent_change'] : 0.0,
      'currency'  => $data['currency'] ?? 'CAD',
    ];
    set_transient($cache_key, $row, HOUR_IN_SECONDS);
    return $row;
  }
}


// ==== Admin Settings Page =====================================================
add_action('admin_init', 'sst_register_settings');
function sst_register_settings() {
    // API keys
    register_setting('sst_options', 'sst_twelve_api_key');  // NEW
    register_setting('sst_options', 'sst_fmp_api_key');     // keep if you still want FMP as fallback

    // Defaults
    register_setting('sst_options', 'sst_default_symbol');  // e.g., MUR:TSXV
    register_setting('sst_options', 'sst_default_theme');   // light | dark
    register_setting('sst_options', 'sst_provider');        // NEW: twelve | fmp
}

add_action('admin_menu', 'sst_add_settings_menu');
function sst_add_settings_menu() {
    add_options_page(
        'Stock Tickers',
        'Stock Tickers',
        'manage_options',
        'sst-settings',
        'sst_settings_page_html'
    );
}

function sst_settings_page_html() {
    if (!current_user_can('manage_options')) return;
    $provider = get_option('sst_provider', 'twelve');
    ?>
    <div class="wrap">
      <h1>Simple Stock Tickers – Settings</h1>
      <form method="post" action="options.php" style="max-width:780px;">
        <?php settings_fields('sst_options'); ?>
        <table class="form-table" role="presentation">

          <tr>
            <th scope="row"><label for="sst_provider">Data Provider</label></th>
            <td>
              <select id="sst_provider" name="sst_provider">
                <option value="twelve" <?php selected($provider, 'twelve'); ?>>Twelve Data (recommended for TSXV)</option>
                <option value="fmp"     <?php selected($provider, 'fmp'); ?>>Financial Modeling Prep</option>
              </select>
              <p class="description">Banner will fetch from this provider by default.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="sst_twelve_api_key">Twelve Data API Key</label></th>
            <td>
              <input type="text" id="sst_twelve_api_key" name="sst_twelve_api_key"
                     value="<?php echo esc_attr(get_option('sst_twelve_api_key', '')); ?>"
                     class="regular-text" placeholder="paste Twelve Data key">
              <p class="description">Example banner symbol format: <code>MUR:TSXV</code></p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="sst_fmp_api_key">FMP API Key (optional)</label></th>
            <td>
              <input type="text" id="sst_fmp_api_key" name="sst_fmp_api_key"
                     value="<?php echo esc_attr(get_option('sst_fmp_api_key', '')); ?>"
                     class="regular-text" placeholder="paste FMP key (if used)">
              <p class="description">Only needed if you choose FMP as provider.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="sst_default_symbol">Default Symbol</label></th>
            <td>
              <input type="text" id="sst_default_symbol" name="sst_default_symbol"
                     value="<?php echo esc_attr(get_option('sst_default_symbol', 'MUR:TSXV')); ?>"
                     class="regular-text" placeholder="e.g., MUR:TSXV">
              <p class="description">Used by shortcodes when no symbol is provided.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="sst_default_theme">Default Banner Theme</label></th>
            <td>
              <?php $t = get_option('sst_default_theme', 'dark'); ?>
              <select id="sst_default_theme" name="sst_default_theme">
                <option value="light" <?php selected($t, 'light'); ?>>Light</option>
                <option value="dark"  <?php selected($t, 'dark');  ?>>Dark</option>
              </select>
            </td>
          </tr>

        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
}

// “Settings” link on Plugins list
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
    $links[] = '<a href="' . admin_url('options-general.php?page=sst-settings') . '">Settings</a>';
    return $links;
});




