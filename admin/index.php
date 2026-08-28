<?php
session_start();

$sessionTimeout = 7200; // 2 horas de inatividade

if (empty($_SESSION['meta_admin_logged'])) {
    header('Location: /gestaotrafego/login.php');
    exit;
}

if (!empty($_SESSION['meta_admin_last_activity']) && (time() - (int)$_SESSION['meta_admin_last_activity']) > $sessionTimeout) {
    session_unset();
    session_destroy();
    header('Location: /gestaotrafego/login.php?expired=1');
    exit;
}

$_SESSION['meta_admin_last_activity'] = time();

require_once __DIR__ . '/../includes/bootstrap.php';

if (!function_exists('normalize_match_key')) {
function normalize_match_key($value)
{
    $value = trim((string)$value);
    if ($value === '') { return ''; }
    if (function_exists('mb_strtolower')) { $value = mb_strtolower($value, 'UTF-8'); } else { $value = strtolower($value); }
    $map = array('á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y');
    $value = strtr($value, $map);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) { $value = $converted; }
    }
    $value = preg_replace('/[^a-z0-9]+/i', '', $value);
    return $value !== null ? $value : '';
}
}

function attribution_failure_label($reason)
{
    $map = array(
        'lead_nao_encontrado' => 'Lead não encontrado',
        'campanha_vazia' => 'Campanha do lead vazia',
        'campanha_nao_encontrada' => 'Campanha não encontrada na Meta',
        'conjunto_nao_encontrado' => 'Conjunto não encontrado na Meta',
        'anuncio_nao_encontrado' => 'Anúncio não encontrado na Meta',
        'atribuicao_manual_pendente' => 'Aguardando atribuição manual',
    );
    $reason = trim((string)$reason);
    return $map[$reason] ?? ($reason !== '' ? $reason : 'Pendente');
}

function detect_pending_reason(array $row, array $metaHierarchy)
{
    $leadCampaign = (string)($row['lead_utm_medium'] ?? '');
    $leadAdset = (string)($row['lead_utm_campaign'] ?? '');
    $leadAd = (string)($row['lead_utm_content'] ?? '');
    if ($leadCampaign === '' && $leadAdset === '' && $leadAd === '') {
        return 'lead_nao_encontrado';
    }
    $campaignNorm = normalize_match_key($leadCampaign);
    if ($campaignNorm === '') { return 'campanha_vazia'; }
    $campaignMatched = '';
    foreach ($metaHierarchy as $campaignName => $adsets) {
        if (normalize_match_key($campaignName) === $campaignNorm) { $campaignMatched = $campaignName; break; }
    }
    if ($campaignMatched === '') { return 'campanha_nao_encontrada'; }
    if ($leadAdset === '') { return 'atribuicao_manual_pendente'; }
    $adsetNorm = normalize_match_key($leadAdset);
    $adsetMatched = '';
    foreach (($metaHierarchy[$campaignMatched] ?? array()) as $adsetName => $ads) {
        if (normalize_match_key($adsetName) === $adsetNorm) { $adsetMatched = $adsetName; break; }
    }
    if ($adsetMatched === '') { return 'conjunto_nao_encontrado'; }
    if ($leadAd === '') { return 'atribuicao_manual_pendente'; }
    $adNorm = normalize_match_key($leadAd);
    foreach ((($metaHierarchy[$campaignMatched] ?? array())[$adsetMatched] ?? array()) as $adName) {
        if (normalize_match_key($adName) === $adNorm) { return ''; }
    }
    return 'anuncio_nao_encontrado';
}


function bucket_key_from_date($date, $granularity)
{
    $ts = strtotime((string)$date);
    if ($ts === false) {
        return (string)$date;
    }
    if ($granularity === 'year') {
        return date('Y', $ts);
    }
    if ($granularity === 'month') {
        return date('Y-m', $ts);
    }
    if ($granularity === 'week') {
        return date('o', $ts) . '-W' . date('W', $ts);
    }
    return date('Y-m-d', $ts);
}

function bucket_sort_value($bucketKey, $granularity)
{
    if ($granularity === 'year') {
        return strtotime($bucketKey . '-01-01');
    }
    if ($granularity === 'month') {
        return strtotime($bucketKey . '-01');
    }
    if ($granularity === 'week') {
        $parts = explode('-W', $bucketKey);
        if (count($parts) === 2) {
            $year = (int)$parts[0];
            $week = (int)$parts[1];
            $dt = new DateTime();
            $dt->setISODate($year, $week);
            return $dt->getTimestamp();
        }
    }
    return strtotime($bucketKey);
}

function merge_daily_buckets(array $metaRows, array $salesRows, $granularity)
{
    $bucket = array();
    foreach ($metaRows as $row) {
        $key = bucket_key_from_date($row['report_date'], $granularity);
        if (!isset($bucket[$key])) {
            $bucket[$key] = array(
                'label' => $key,
                'spend' => 0.0,
                'spend_real' => 0.0,
                'leads' => 0,
                'sales' => 0,
                'revenue' => 0.0,
                'cpm_sum' => 0.0,
                'cpm_rows' => 0,
                'frequency_sum' => 0.0,
                'frequency_rows' => 0,
                'cpc_sum' => 0.0,
                'cpc_rows' => 0,
            );
        }
        $bucket[$key]['spend'] += (float)$row['spend'];
        $bucket[$key]['spend_real'] += (float)($row['spend_real'] ?? $row['spend']);
        $bucket[$key]['leads'] += (int)$row['leads'];
        $bucket[$key]['cpm_sum'] += (float)$row['cpm'];
        $bucket[$key]['cpm_rows'] += 1;
        $bucket[$key]['frequency_sum'] += (float)$row['frequency'];
        $bucket[$key]['frequency_rows'] += 1;
        $bucket[$key]['cpc_sum'] += (float)$row['cpc'];
        $bucket[$key]['cpc_rows'] += 1;
    }
    foreach ($salesRows as $row) {
        $key = bucket_key_from_date($row['report_date'], $granularity);
        if (!isset($bucket[$key])) {
            $bucket[$key] = array(
                'label' => $key,
                'spend' => 0.0,
                'spend_real' => 0.0,
                'leads' => 0,
                'sales' => 0,
                'revenue' => 0.0,
                'cpm_sum' => 0.0,
                'cpm_rows' => 0,
                'frequency_sum' => 0.0,
                'frequency_rows' => 0,
                'cpc_sum' => 0.0,
                'cpc_rows' => 0,
            );
        }
        $bucket[$key]['sales'] += (int)$row['sales'];
        $bucket[$key]['revenue'] += (float)$row['revenue'];
    }

    uasort($bucket, function ($a, $b) use ($granularity) {
        return bucket_sort_value($a['label'], $granularity) <=> bucket_sort_value($b['label'], $granularity);
    });

    return array_values($bucket);
}

function merge_real_buckets(array $metaRows, array $leadRows, array $salesRows, $granularity)
{
    $bucket = array();
    foreach ($metaRows as $row) {
        $key = bucket_key_from_date($row['report_date'], $granularity);
        if (!isset($bucket[$key])) {
            $bucket[$key] = array(
                'label' => $key,
                'spend' => 0.0,
                'spend_real' => 0.0,
                'leads' => 0,
                'sales' => 0,
                'revenue' => 0.0,
                'cpm_sum' => 0.0,
                'cpm_rows' => 0,
                'frequency_sum' => 0.0,
                'frequency_rows' => 0,
                'cpc_sum' => 0.0,
                'cpc_rows' => 0,
            );
        }
        $bucket[$key]['spend'] += (float)$row['spend'];
        $bucket[$key]['spend_real'] += (float)($row['spend_real'] ?? $row['spend']);
        $bucket[$key]['cpm_sum'] += (float)$row['cpm'];
        $bucket[$key]['cpm_rows'] += 1;
        $bucket[$key]['frequency_sum'] += (float)$row['frequency'];
        $bucket[$key]['frequency_rows'] += 1;
        $bucket[$key]['cpc_sum'] += (float)$row['cpc'];
        $bucket[$key]['cpc_rows'] += 1;
    }
    foreach ($leadRows as $row) {
        $key = bucket_key_from_date($row['report_date'], $granularity);
        if (!isset($bucket[$key])) {
            $bucket[$key] = array(
                'label' => $key,
                'spend' => 0.0,
                'spend_real' => 0.0,
                'leads' => 0,
                'sales' => 0,
                'revenue' => 0.0,
                'cpm_sum' => 0.0,
                'cpm_rows' => 0,
                'frequency_sum' => 0.0,
                'frequency_rows' => 0,
                'cpc_sum' => 0.0,
                'cpc_rows' => 0,
            );
        }
        $bucket[$key]['leads'] += (int)$row['leads'];
    }
    foreach ($salesRows as $row) {
        $key = bucket_key_from_date($row['report_date'], $granularity);
        if (!isset($bucket[$key])) {
            $bucket[$key] = array(
                'label' => $key,
                'spend' => 0.0,
                'spend_real' => 0.0,
                'leads' => 0,
                'sales' => 0,
                'revenue' => 0.0,
                'cpm_sum' => 0.0,
                'cpm_rows' => 0,
                'frequency_sum' => 0.0,
                'frequency_rows' => 0,
                'cpc_sum' => 0.0,
                'cpc_rows' => 0,
            );
        }
        $bucket[$key]['sales'] += (int)$row['sales'];
        $bucket[$key]['revenue'] += (float)$row['revenue'];
    }

    uasort($bucket, function ($a, $b) use ($granularity) {
        return bucket_sort_value($a['label'], $granularity) <=> bucket_sort_value($b['label'], $granularity);
    });

    return array_values($bucket);
}

function get_array_param($key)
{
    $value = $_GET[$key] ?? array();
    if (is_array($value)) {
        return array_values(array_filter(array_map(function ($v) { return trim((string)$v); }, $value), function ($v) { return $v !== ''; }));
    }
    $value = trim((string)$value);
    return $value === '' ? array() : array($value);
}

function build_in_condition(array $values, $prefix, array &$params, $column)
{
    if (!$values) {
        return '';
    }
    $parts = array();
    foreach (array_values($values) as $i => $value) {
        $key = ':' . $prefix . '_' . $i;
        $parts[] = $key;
        $params[$key] = $value;
    }
    return ' AND ' . $column . ' IN (' . implode(',', $parts) . ') ';
}

function campaign_matches_selected($campaignValue, array $selectedCampaigns)
{
    if (!$selectedCampaigns) {
        return true;
    }
    $candidateMap = array();
    foreach ($selectedCampaigns as $item) {
        $norm = normalize_match_key($item);
        if ($norm !== '') {
            $candidateMap[$norm] = $item;
        }
    }
    $rawNorm = normalize_match_key($campaignValue);
    if ($rawNorm === '' || !$candidateMap) {
        return false;
    }
    return best_fuzzy_key_match($rawNorm, $candidateMap, 74.0) !== '';
}

function normalize_integration_ids($integrationIds): array
{
    $ids = is_array($integrationIds) ? $integrationIds : array($integrationIds);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids ?: array(0);
}

function integration_filter_sql($integrationIds): string
{
    return ' integration_id IN (' . implode(',', normalize_integration_ids($integrationIds)) . ') ';
}

function fetch_meta_real_daily_rows(PDO $pdo, $integrationIds, $dateFrom, $dateTo, array $campaignFilters = array(), $adsetFilter = '')
{
    $spreadExpr = "mtd.spend * (1 + (CASE WHEN mi.currency_code = 'USD' THEN mi.currency_spread_percent ELSE 0 END) / 100)";
    if ($adsetFilter !== '') {
        $sql = "SELECT mtd.report_date, SUM(mtd.spend) AS spend, SUM($spreadExpr) AS spend_real, SUM(mtd.impressions) AS impressions, SUM(mtd.reach) AS reach, AVG(mtd.frequency) AS frequency, SUM(mtd.clicks) AS clicks, CASE WHEN SUM(mtd.clicks) > 0 THEN SUM(mtd.spend) / SUM(mtd.clicks) ELSE 0 END AS cpc, CASE WHEN SUM(mtd.impressions) > 0 THEN (SUM(mtd.spend) / SUM(mtd.impressions)) * 1000 ELSE 0 END AS cpm FROM meta_adset_daily mtd JOIN meta_integrations mi ON mi.id = mtd.integration_id WHERE " . integration_filter_sql($integrationIds) . " AND mtd.report_date BETWEEN :date_from AND :date_to AND mtd.adset_name = :adset_name";
        $params = array(':date_from' => $dateFrom, ':date_to' => $dateTo, ':adset_name' => $adsetFilter);
        if ($campaignFilters) {
            $sql .= build_in_condition($campaignFilters, 'campaign', $params, 'campaign_name');
        }
        $sql .= ' GROUP BY mtd.report_date ORDER BY mtd.report_date';
    } else {
        $sql = "SELECT mtd.report_date, SUM(mtd.spend) AS spend, SUM($spreadExpr) AS spend_real, SUM(mtd.impressions) AS impressions, SUM(mtd.reach) AS reach, AVG(mtd.frequency) AS frequency, SUM(mtd.clicks) AS clicks, CASE WHEN SUM(mtd.clicks) > 0 THEN SUM(mtd.spend) / SUM(mtd.clicks) ELSE 0 END AS cpc, CASE WHEN SUM(mtd.impressions) > 0 THEN (SUM(mtd.spend) / SUM(mtd.impressions)) * 1000 ELSE 0 END AS cpm FROM meta_campaign_daily mtd JOIN meta_integrations mi ON mi.id = mtd.integration_id WHERE " . integration_filter_sql($integrationIds) . " AND mtd.report_date BETWEEN :date_from AND :date_to";
        $params = array(':date_from' => $dateFrom, ':date_to' => $dateTo);
        if ($campaignFilters) {
            $sql .= build_in_condition($campaignFilters, 'campaign', $params, 'campaign_name');
        }
        $sql .= ' GROUP BY mtd.report_date ORDER BY mtd.report_date';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_attr_lead_daily_rows(PDO $pdo, $start, $end, array $campaignFilters = array(), $adsetFilter = '')
{
    $sql = 'SELECT DATE(created_at) AS report_date, utm_campaign_group, utm_campaign_name, COUNT(*) AS leads FROM attribution_leads WHERE created_at BETWEEN :start_dt AND :end_dt GROUP BY DATE(created_at), utm_campaign_group, utm_campaign_name ORDER BY DATE(created_at) ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array('start_dt' => $start . ' 00:00:00', 'end_dt' => $end . ' 23:59:59'));
    $rows = $stmt->fetchAll();
    $bucket = array();
    foreach ($rows as $row) {
        if (!campaign_matches_selected($row['utm_campaign_group'] ?? '', $campaignFilters)) {
            continue;
        }
        if ($adsetFilter !== '' && (string)($row['utm_campaign_name'] ?? '') !== $adsetFilter) {
            continue;
        }
        $date = (string)$row['report_date'];
        if (!isset($bucket[$date])) {
            $bucket[$date] = array('report_date' => $date, 'leads' => 0);
        }
        $bucket[$date]['leads'] += (int)$row['leads'];
    }
    ksort($bucket);
    return array_values($bucket);
}

function fetch_attr_sales_daily_rows(PDO $pdo, $model, $start, $end, array $campaignFilters = array(), $adsetFilter = '', $productFilter = '')
{
    $sql = "SELECT s.transaction_code, COALESCE(s.payment_confirmed_at, m.sale_date) AS effective_dt, m.campaign_group, m.campaign_name, m.product_name, m.revenue_value
            FROM attribution_matches m
            INNER JOIN attribution_sales s ON s.id = m.sale_id
            WHERE m.attribution_model = :model AND m.sale_date BETWEEN :start_dt AND :end_dt
            ORDER BY COALESCE(s.payment_confirmed_at, m.sale_date) DESC, m.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array('model' => $model, 'start_dt' => $start . ' 00:00:00', 'end_dt' => $end . ' 23:59:59'));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $bucket = array();
    $seen = array();
    foreach ($rows as $row) {
        $tx = trim((string)($row['transaction_code'] ?? ''));
        if ($tx === '' || isset($seen[$tx])) { continue; }
        if (!campaign_matches_selected($row['campaign_group'] ?? '', $campaignFilters)) { continue; }
        if ($adsetFilter !== '' && (string)($row['campaign_name'] ?? '') !== $adsetFilter) { continue; }
        if ($productFilter !== '' && (string)($row['product_name'] ?? '') !== $productFilter) { continue; }
        $seen[$tx] = true;
        $date = date('Y-m-d', strtotime((string)($row['effective_dt'] ?? 'now')));
        if (!isset($bucket[$date])) { $bucket[$date] = array('report_date' => $date, 'sales' => 0, 'revenue' => 0.0); }
        $bucket[$date]['sales'] += 1;
        $bucket[$date]['revenue'] += (float)($row['revenue_value'] ?? 0);
    }
    ksort($bucket);
    return array_values($bucket);
}



function fetch_precise_attributed_sales_summary(PDO $pdo, $model, $start, $end, array $campaignFilters = array(), $adsetFilter = '', $productFilter = '')
{
    $sql = "SELECT s.transaction_code, s.producer_net, s.net_revenue, COALESCE(s.payment_confirmed_at, s.transaction_date) AS effective_dt, s.id,
                   m.campaign_group, m.campaign_name, m.product_name
            FROM hotmart_sales_live s
            INNER JOIN attribution_sales a ON a.transaction_code COLLATE utf8mb4_unicode_ci = s.transaction_code COLLATE utf8mb4_unicode_ci
            INNER JOIN attribution_matches m ON m.sale_id = a.id AND m.attribution_model = :model
            WHERE s.transaction_date BETWEEN :start_dt AND :end_dt
              AND s.status IN ('Aprovado','APPROVED','PURCHASE_APPROVED','Completo','COMPLETE','COMPLETED','PURCHASE_COMPLETE')
            ORDER BY COALESCE(s.payment_confirmed_at, s.transaction_date) DESC, s.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':model' => $model,
        ':start_dt' => $start . ' 00:00:00',
        ':end_dt' => $end . ' 23:59:59',
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $seen = array();
    $summary = array('sales' => 0, 'revenue' => 0.0);
    foreach ($rows as $row) {
        $code = trim((string)($row['transaction_code'] ?? ''));
        if ($code === '' || isset($seen[$code])) { continue; }
        if (!campaign_matches_selected($row['campaign_group'] ?? '', $campaignFilters)) { continue; }
        if ($adsetFilter !== '' && (string)($row['campaign_name'] ?? '') !== $adsetFilter) { continue; }
        if ($productFilter !== '' && (string)($row['product_name'] ?? '') !== $productFilter) { continue; }
        $seen[$code] = true;
        $summary['sales'] += 1;
        $summary['revenue'] += (float)($row['producer_net'] ?? $row['net_revenue'] ?? 0);
    }
    return $summary;
}

function fetch_source_users_by_ids(PDO $pdo, array $ids)
{
    $clean = array();
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) { $clean[$id] = $id; }
    }
    if (!$clean) { return array(); }
    $params = array();
    $placeholders = array();
    foreach (array_values($clean) as $i => $id) {
        $key = ':id_' . $i;
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $sql = 'SELECT id, nome, email, telefone, utm_source, utm_medium, utm_campaign, utm_term, utm_content, created_at FROM users WHERE id IN (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = array();
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['id']] = $row;
    }
    return $out;
}

function build_utm_inline_text(array $row, array $map)
{
    $labels = array(
        'source' => 'src',
        'medium' => 'med',
        'campaign' => 'camp',
        'camp_group' => 'grp',
        'conjunto' => 'conj',
        'anuncio' => 'ad',
        'term' => 'term',
        'content' => 'content',
    );
    $parts = array();
    foreach ($map as $labelKey => $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value === '') { continue; }
        $parts[] = ($labels[$labelKey] ?? $labelKey) . ': ' . $value;
    }
    return $parts ? implode(' | ', $parts) : '—';
}

function payment_type_label($value)
{
    $value = strtoupper(trim((string)$value));
    if ($value === 'PIX') return 'PIX';
    if ($value === 'BILLET') return 'Boleto';
    if ($value === 'CREDIT_CARD') return 'Cartão';
    if ($value === 'HOTMART_INSTALLMENTS') return 'Cartão parcelado';
    return $value !== '' ? $value : 'Não identificado';
}

function event_is_approved($eventName, $status = '')
{
    $eventName = strtoupper(trim((string)$eventName));
    $status = strtoupper(trim((string)$status));
    return in_array($eventName, array('PURCHASE_APPROVED','PURCHASE_COMPLETE'), true) || in_array($status, array('APROVADO','APPROVED','COMPLETED','COMPLETE','COMPLETO'), true);
}

function sale_effective_date(array $row)
{
    foreach (array('transaction_date','payment_confirmed_at','sale_date','received_at') as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') { return $value; }
    }
    return '';
}

function sale_revenue_value(array $row)
{
    foreach (array('gross_revenue','revenue_value','net_revenue','producer_net','price_value_1','price_value_2') as $field) {
        if (isset($row[$field]) && $row[$field] !== null && $row[$field] !== '') {
            return (float)$row[$field];
        }
    }
    return 0.0;
}

function non_completed_event_label($eventName)
{
    $map = array(
        'PURCHASE_BILLET_PRINTED' => 'Boleto gerado',
        'PURCHASE_OUT_OF_SHOPPING_CART' => 'Abandono de carrinho',
        'PURCHASE_EXPIRED' => 'Compra expirada',
        'PURCHASE_DELAYED' => 'Compra atrasada',
        'PURCHASE_CANCELED' => 'Compra cancelada',
        'PURCHASE_REFUNDED' => 'Reembolso',
        'PURCHASE_CHARGEBACK' => 'Chargeback',
    );
    $eventName = strtoupper(trim((string)$eventName));
    return $map[$eventName] ?? ($eventName !== '' ? $eventName : 'Outro');
}

function ensure_manual_attribution_table(PDO $pdo)
{
    static $done = false;
    if ($done) { return; }
    $sql = "CREATE TABLE IF NOT EXISTS manual_sale_attributions (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_code VARCHAR(80) NOT NULL,
        attribution_model ENUM('first_touch','last_touch') NOT NULL DEFAULT 'last_touch',
        campaign_group VARCHAR(255) DEFAULT NULL,
        campaign_group_norm VARCHAR(255) DEFAULT NULL,
        campaign_name VARCHAR(255) DEFAULT NULL,
        campaign_name_norm VARCHAR(255) DEFAULT NULL,
        ad_name VARCHAR(255) DEFAULT NULL,
        ad_name_norm VARCHAR(255) DEFAULT NULL,
        source_user_id INT(10) UNSIGNED DEFAULT NULL,
        lead_utm_source VARCHAR(255) DEFAULT NULL,
        lead_utm_medium VARCHAR(255) DEFAULT NULL,
        lead_utm_campaign VARCHAR(255) DEFAULT NULL,
        lead_utm_term VARCHAR(255) DEFAULT NULL,
        lead_utm_content VARCHAR(255) DEFAULT NULL,
        assigned_by VARCHAR(255) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tx_model (transaction_code, attribution_model),
        KEY idx_campaign_group_norm (campaign_group_norm),
        KEY idx_campaign_name_norm (campaign_name_norm),
        KEY idx_ad_name_norm (ad_name_norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
    $done = true;
}


function fetch_manual_attribution_map_by_transaction_codes(PDO $pdo, $model, array $codes)
{
    ensure_manual_attribution_table($pdo);
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), function($v){ return $v !== ''; })));
    if (!$codes) { return array(); }
    $params = array(':model' => $model);
    $holders = array();
    foreach ($codes as $i => $code) {
        $key = ':mtx_' . $i;
        $holders[] = $key;
        $params[$key] = $code;
    }
    $sql = "SELECT transaction_code, campaign_group, campaign_name, ad_name, source_user_id, lead_utm_source, lead_utm_medium, lead_utm_campaign, lead_utm_term, lead_utm_content, created_at, notes
            FROM manual_sale_attributions
            WHERE attribution_model = :model AND transaction_code IN (" . implode(',', $holders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = (string)$row['transaction_code'];
        $row['match_id'] = 'manual:' . $code;
        $row['manual_override'] = 1;
        $row['sale_matched_user_id'] = $row['source_user_id'] ?? null;
        $row['no_attribution'] = strtoupper(trim((string)($row['notes'] ?? ''))) === 'NO_ATTRIBUTION' ? 1 : 0;
        $out[$code] = $row;
    }
    return $out;
}


function fetch_meta_hierarchy(PDO $pdo, $integrationIds)
{
    $hier = array();
    if (!$integrationIds) { return $hier; }
    $stmt = $pdo->prepare("SELECT campaign_name, adset_name FROM meta_adset_daily WHERE " . integration_filter_sql($integrationIds) . " AND campaign_name <> '' AND adset_name <> '' GROUP BY campaign_name, adset_name ORDER BY campaign_name, adset_name");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $campaign = trim((string)$row['campaign_name']);
        $adset = trim((string)$row['adset_name']);
        if ($campaign === '' || $adset === '') { continue; }
        if (!isset($hier[$campaign])) { $hier[$campaign] = array(); }
        if (!isset($hier[$campaign][$adset])) { $hier[$campaign][$adset] = array(); }
    }
    $stmt = $pdo->prepare("SELECT campaign_name, adset_name, ad_name FROM meta_ad_daily WHERE " . integration_filter_sql($integrationIds) . " AND campaign_name <> '' AND adset_name <> '' AND ad_name <> '' GROUP BY campaign_name, adset_name, ad_name ORDER BY campaign_name, adset_name, ad_name");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $campaign = trim((string)$row['campaign_name']);
        $adset = trim((string)$row['adset_name']);
        $ad = trim((string)$row['ad_name']);
        if ($campaign === '' || $adset === '' || $ad === '') { continue; }
        if (!isset($hier[$campaign])) { $hier[$campaign] = array(); }
        if (!isset($hier[$campaign][$adset])) { $hier[$campaign][$adset] = array(); }
        if (!in_array($ad, $hier[$campaign][$adset], true)) { $hier[$campaign][$adset][] = $ad; }
    }
    ksort($hier);
    foreach ($hier as $campaign => &$adsets) {
        ksort($adsets);
        foreach ($adsets as &$ads) { sort($ads); }
        unset($ads);
    }
    unset($adsets);
    return $hier;
}



function fetch_total_sales_summary(PDO $pdo, $start, $end, $productFilter = '')
{
    $sql = "SELECT transaction_code, product_name, producer_net, net_revenue, transaction_date, payment_confirmed_at, id
            FROM hotmart_sales_live
            WHERE status IN ('APPROVED','COMPLETE','COMPLETED','Aprovado','Completo','PURCHASE_APPROVED','PURCHASE_COMPLETE')
              AND transaction_date BETWEEN :start_dt AND :end_dt
            ORDER BY COALESCE(payment_confirmed_at, transaction_date) DESC, id DESC";
    $params = array(':start_dt' => $start . ' 00:00:00', ':end_dt' => $end . ' 23:59:59');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $seen = array();
    $sales = 0;
    $revenue = 0.0;
    foreach ($rows as $row) {
        $tx = trim((string)($row['transaction_code'] ?? ''));
        if ($tx === '' || isset($seen[$tx])) { continue; }
        if ($productFilter !== '' && (string)($row['product_name'] ?? '') !== $productFilter) { continue; }
        $seen[$tx] = true;
        $sales++;
        $revenue += (float)($row['producer_net'] ?? $row['net_revenue'] ?? 0);
    }
    return array('sales' => $sales, 'revenue' => $revenue);
}

function fetch_meta_period_summary(PDO $pdo, $integrationIds, $start, $end)
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(mad.spend),0) AS spend,
                                  COALESCE(SUM(mad.spend * (1 + (CASE WHEN mi.currency_code = 'USD' THEN mi.currency_spread_percent ELSE 0 END) / 100)),0) AS spend_real,
                                  COALESCE(SUM(mad.impressions),0) AS impressions,
                                  COALESCE(SUM(mad.reach),0) AS reach,
                                  COALESCE(SUM(mad.clicks),0) AS clicks,
                                  COALESCE(SUM(mad.leads),0) AS leads
                           FROM meta_account_daily mad
                           JOIN meta_integrations mi ON mi.id = mad.integration_id
                           WHERE " . integration_filter_sql($integrationIds) . "
                             AND mad.report_date BETWEEN :start AND :end");
    $stmt->execute(array(':start' => $start, ':end' => $end));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
    return array(
        'spend' => (float)($row['spend'] ?? 0),
        'spend_real' => (float)($row['spend_real'] ?? ($row['spend'] ?? 0)),
        'impressions' => (float)($row['impressions'] ?? 0),
        'reach' => (float)($row['reach'] ?? 0),
        'clicks' => (float)($row['clicks'] ?? 0),
        'leads' => (int)($row['leads'] ?? 0),
    );
}

function fetch_source_users_by_sales(PDO $pdo, array $salesRows)
{
    $ids = array();
    $emails = array();
    foreach ($salesRows as $row) {
        $uid = (int)($row['matched_user_id'] ?? $row['sale_matched_user_id'] ?? $row['hotmart_matched_user_id'] ?? 0);
        if ($uid > 0) { $ids[$uid] = $uid; }
        $email = strtolower(trim((string)($row['buyer_email'] ?? '')));
        if ($email !== '') { $emails[$email] = $email; }
    }
    $out = array();
    if ($ids) {
        $out = fetch_source_users_by_ids($pdo, array_values($ids));
    }
    if ($emails) {
        $params = array(); $holders = array();
        foreach (array_values($emails) as $i => $email) {
            $k=':em_' . $i; $holders[]=$k; $params[$k]=$email;
        }
        $stmt = $pdo->prepare('SELECT id, nome, email, telefone, utm_source, utm_medium, utm_campaign, utm_term, utm_content, created_at FROM users WHERE LOWER(email) IN (' . implode(',', $holders) . ')');
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['id']] = $row;
        }
    }
    return $out;
}


function fetch_attribution_match_map_by_transaction_codes(PDO $pdo, $model, array $codes)
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), function($v){ return $v !== ''; })));
    if (!$codes) { return array(); }
    $params = array(':model' => $model);
    $holders = array();
    foreach ($codes as $i => $code) {
        $key = ':tx_' . $i;
        $holders[] = $key;
        $params[$key] = $code;
    }
    $sql = "SELECT s.transaction_code, s.id AS sale_id, s.sale_date, s.payment_confirmed_at, s.product_name, s.gross_revenue, s.net_revenue, s.producer_net,
                   s.matched_user_id AS sale_matched_user_id, s.utm_source, s.utm_campaign_group, s.utm_campaign_name, s.utm_ad_name, s.utm_term,
                   m.id AS match_id, m.campaign_group, m.campaign_name, m.ad_name, m.revenue_value
            FROM attribution_sales s
            LEFT JOIN attribution_matches m ON m.sale_id = s.id AND m.attribution_model = :model
            WHERE s.transaction_code IN (" . implode(',', $holders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(string)$row['transaction_code']] = $row;
    }
    $manual = fetch_manual_attribution_map_by_transaction_codes($pdo, $model, $codes);
    foreach ($manual as $tx => $row) {
        $base = isset($out[$tx]) ? $out[$tx] : array('transaction_code' => $tx);
        $base['match_id'] = $row['match_id'];
        $base['campaign_group'] = $row['campaign_group'] ?? '';
        $base['campaign_name'] = $row['campaign_name'] ?? '';
        $base['ad_name'] = $row['ad_name'] ?? '';
        $base['manual_override'] = 1;
        $base['sale_matched_user_id'] = $row['source_user_id'] ?? ($base['sale_matched_user_id'] ?? null);
        $base['lead_utm_source'] = $row['lead_utm_source'] ?? '';
        $base['lead_utm_medium'] = $row['lead_utm_medium'] ?? '';
        $base['lead_utm_campaign'] = $row['lead_utm_campaign'] ?? '';
        $base['lead_utm_term'] = $row['lead_utm_term'] ?? '';
        $base['lead_utm_content'] = $row['lead_utm_content'] ?? '';
        $out[$tx] = $base;
    }
    return $out;
}


function fetch_hotmart_live_map_by_transaction_codes(PDO $pdo, array $codes)
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), function($v){ return $v !== ''; })));
    if (!$codes) { return array(); }
    $params = array();
    $holders = array();
    foreach ($codes as $i => $code) {
        $key = ':tx_' . $i;
        $holders[] = $key;
        $params[$key] = $code;
    }
    $sql = "SELECT transaction_code, webhook_event, status, transaction_date, payment_confirmed_at, gross_revenue, net_revenue, producer_net, matched_user_id AS hotmart_matched_user_id,
                   utm_source AS hotmart_utm_source, utm_medium AS hotmart_utm_medium, utm_campaign AS hotmart_utm_campaign, utm_term AS hotmart_utm_term, utm_content AS hotmart_utm_content,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(raw_payload_json AS JSON), '$.data.purchase.payment.type')) AS purchase_payment_type,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(raw_payload_json AS JSON), '$.data.purchase.installments_number')) AS installments_1,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(raw_payload_json AS JSON), '$.data.purchase.installments')) AS installments_2,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(raw_payload_json AS JSON), '$.data.purchase.price.value')) AS price_value_1,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(raw_payload_json AS JSON), '$.data.purchase.order_value')) AS price_value_2
            FROM hotmart_sales_live
            WHERE transaction_code IN (" . implode(',', $holders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = array();
    foreach ($stmt->fetchAll() as $row) {
        $out[(string)$row['transaction_code']] = $row;
    }
    return $out;
}

function fetch_sales_dashboard_rows(PDO $pdo, $model, $start, $end)
{
    $src = source_db();

    $sourceSql = "SELECT id, transaction_code, status, transaction_date, payment_confirmed_at, product_name, gross_revenue, net_revenue, producer_net,
                   buyer_name, buyer_email, buyer_phone_raw, buyer_phone_norm, matched_user_id, match_method,
                   utm_source, utm_medium, utm_campaign, utm_term, utm_content
            FROM hotmart_sales
            WHERE (
                (transaction_date BETWEEN :start_dt_1 AND :end_dt_1)
                OR (payment_confirmed_at BETWEEN :start_dt_2 AND :end_dt_2)
            )
            ORDER BY COALESCE(payment_confirmed_at, transaction_date) DESC, id DESC";
    $sourceStmt = $src->prepare($sourceSql);
    $sourceParams = array(
        ':start_dt_1' => $start . ' 00:00:00', ':end_dt_1' => $end . ' 23:59:59',
        ':start_dt_2' => $start . ' 00:00:00', ':end_dt_2' => $end . ' 23:59:59',
    );
    $sourceStmt->execute($sourceParams);
    $rows = $sourceStmt->fetchAll();

    $attrSql = "SELECT source_sale_id AS id, transaction_code, sale_status AS status, sale_date AS transaction_date, payment_confirmed_at, product_name,
                       gross_revenue, net_revenue, producer_net, buyer_name, buyer_email, buyer_phone_raw, buyer_phone_norm, matched_user_id, match_method,
                       utm_source, '' AS utm_medium, utm_campaign_group AS utm_campaign, utm_term, '' AS utm_content
                FROM attribution_sales
                WHERE sale_date BETWEEN :start_dt AND :end_dt
                ORDER BY sale_date DESC, id DESC";
    $attrStmt = $pdo->prepare($attrSql);
    $attrStmt->execute(array(':start_dt' => $start . ' 00:00:00', ':end_dt' => $end . ' 23:59:59'));
    $attrRows = $attrStmt->fetchAll();

    $rowsByCode = array();
    foreach ($rows as $row) {
        $code = trim((string)($row['transaction_code'] ?? ''));
        if ($code === '') { continue; }
        $rowsByCode[$code] = $row;
    }
    foreach ($attrRows as $row) {
        $code = trim((string)($row['transaction_code'] ?? ''));
        if ($code === '') { continue; }
        if (!isset($rowsByCode[$code])) {
            $rowsByCode[$code] = $row;
        } else {
            foreach (array('transaction_date','payment_confirmed_at','product_name','gross_revenue','net_revenue','producer_net','buyer_name','buyer_email','buyer_phone_raw','buyer_phone_norm','matched_user_id','match_method','utm_source','utm_medium','utm_campaign','utm_term','utm_content') as $field) {
                if ((!isset($rowsByCode[$code][$field]) || $rowsByCode[$code][$field] === null || $rowsByCode[$code][$field] === '') && isset($row[$field]) && $row[$field] !== null && $row[$field] !== '') {
                    $rowsByCode[$code][$field] = $row[$field];
                }
            }
        }
    }

    if (!$rowsByCode) { return array(); }
    $codes = array_keys($rowsByCode);
    $attrMap = fetch_attribution_match_map_by_transaction_codes($pdo, $model, $codes);
    $liveMap = fetch_hotmart_live_map_by_transaction_codes($pdo, $codes);

    foreach ($rowsByCode as $code => &$row) {
        $attr = $attrMap[$code] ?? array();
        $live = $liveMap[$code] ?? array();
        $row = array_merge($live, $attr, $row);
        $row['source_matched_user_id'] = $row['matched_user_id'] ?? null;
        $row['campaign_group'] = $attr['campaign_group'] ?? '';
        $row['campaign_name'] = $attr['campaign_name'] ?? '';
        $row['ad_name'] = $attr['ad_name'] ?? '';
        $row['match_id'] = $attr['match_id'] ?? null;
        $row['sale_matched_user_id'] = $attr['sale_matched_user_id'] ?? null;
        $row['hotmart_matched_user_id'] = $live['hotmart_matched_user_id'] ?? null;
        $row['purchase_payment_type'] = $live['purchase_payment_type'] ?? '';
        $row['webhook_event'] = $live['webhook_event'] ?? '';
        $row['effective_date'] = sale_effective_date($row);
        $row['transaction_code'] = $code;
    }
    unset($row);

    usort($rowsByCode, function ($a, $b) {
        return strcmp((string)($b['effective_date'] ?? ''), (string)($a['effective_date'] ?? ''));
    });

    return array_values($rowsByCode);
}


function event_status_is_sale($eventName, $status)
{
    $event = strtoupper(trim((string)$eventName));
    $status = strtoupper(trim((string)$status));
    $saleEvents = array('PURCHASE_APPROVED', 'PURCHASE_COMPLETE');
    $saleStatuses = array('APROVADO', 'APPROVED', 'PURCHASE_APPROVED', 'PURCHASE_COMPLETE', 'COMPLETE', 'COMPLETO');
    return in_array($event, $saleEvents, true) || in_array($status, $saleStatuses, true);
}

function fetch_general_sales_rows(PDO $pdo, $model, $start, $end)
{
    $sql = "SELECT h.transaction_code, h.webhook_event, h.status, h.transaction_date, h.payment_confirmed_at,
                   h.buyer_name, h.buyer_email, h.buyer_phone_raw, h.buyer_phone_norm,
                   h.product_name, h.gross_revenue, h.net_revenue, h.producer_net,
                   h.matched_user_id AS hotmart_matched_user_id,
                   h.utm_source AS hotmart_utm_source, h.utm_medium AS hotmart_utm_medium,
                   h.utm_campaign AS hotmart_utm_campaign, h.utm_term AS hotmart_utm_term, h.utm_content AS hotmart_utm_content,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(h.raw_payload_json AS JSON), '$.data.purchase.payment.type')) AS purchase_payment_type,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(h.raw_payload_json AS JSON), '$.data.purchase.installments_number')) AS installments_1,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(h.raw_payload_json AS JSON), '$.data.purchase.installments')) AS installments_2,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(h.raw_payload_json AS JSON), '$.data.purchase.price.value')) AS price_value_1,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(h.raw_payload_json AS JSON), '$.data.purchase.order_value')) AS price_value_2
            FROM hotmart_sales_live h
            WHERE h.transaction_date BETWEEN :start_dt AND :end_dt
              AND h.status IN ('Aprovado','APPROVED','PURCHASE_APPROVED','Completo','COMPLETE','COMPLETED','PURCHASE_COMPLETE')
            ORDER BY h.transaction_date DESC, h.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':start_dt' => $start . ' 00:00:00',
        ':end_dt' => $end . ' 23:59:59',
    ));
    $baseRows = $stmt->fetchAll();
    if (!$baseRows) {
        return array();
    }

    $rows = array();
    foreach ($baseRows as $row) {
        $code = trim((string)($row['transaction_code'] ?? ''));
        if ($code === '') { continue; }
        if (!isset($rows[$code])) {
            $rows[$code] = $row;
        }
    }

    $codes = array_keys($rows);
    $attrMap = fetch_attribution_match_map_by_transaction_codes($pdo, $model, $codes);
    foreach ($rows as $code => &$row) {
        $attr = $attrMap[$code] ?? array();
        $row['match_id'] = $attr['match_id'] ?? null;
        $row['campaign_group'] = $attr['campaign_group'] ?? '';
        $row['is_no_attribution'] = !empty($attr['no_attribution']);
        $row['campaign_name'] = $attr['campaign_name'] ?? '';
        $row['ad_name'] = $attr['ad_name'] ?? '';
        $row['sale_matched_user_id'] = $attr['sale_matched_user_id'] ?? null;
        $row['manual_override'] = !empty($attr['manual_override']);
        $row['lead_utm_source'] = $attr['lead_utm_source'] ?? '';
        $row['lead_utm_medium'] = $attr['lead_utm_medium'] ?? '';
        $row['lead_utm_campaign'] = $attr['lead_utm_campaign'] ?? '';
        $row['lead_utm_term'] = $attr['lead_utm_term'] ?? '';
        $row['lead_utm_content'] = $attr['lead_utm_content'] ?? '';
        $row['effective_date'] = sale_effective_date($row);
        $row['effective_revenue'] = sale_revenue_value($row);
        $row['is_approved'] = true;
        $row['is_attributed'] = empty($row['is_no_attribution']) && (!empty($row['match_id']) || trim((string)$row['campaign_group']) !== '' || trim((string)$row['campaign_name']) !== '' || trim((string)$row['ad_name']) !== '');
        $row['payment_type_label'] = payment_type_label($row['purchase_payment_type'] ?? '');
    }
    unset($row);

    $userMap = fetch_source_users_by_sales(source_db(), array_values($rows));
    foreach ($rows as &$row) {
        $uid = (int)($row['sale_matched_user_id'] ?? $row['hotmart_matched_user_id'] ?? 0);
        $user = isset($userMap[$uid]) ? $userMap[$uid] : array();
        if (empty($user) && !empty($row['buyer_email'])) {
            foreach ($userMap as $candidate) {
                if (strtolower((string)($candidate['email'] ?? '')) === strtolower((string)$row['buyer_email'])) { $user = $candidate; break; }
            }
        }
        if (trim((string)($row['lead_utm_source'] ?? '')) === '') {
            $row['lead_utm_source'] = $user['utm_source'] ?? '';
            $row['lead_utm_medium'] = $user['utm_medium'] ?? '';
            $row['lead_utm_campaign'] = $user['utm_campaign'] ?? '';
            $row['lead_utm_term'] = $user['utm_term'] ?? '';
            $row['lead_utm_content'] = $user['utm_content'] ?? '';
            $row['source_user_id'] = $user['id'] ?? ($row['sale_matched_user_id'] ?? $row['hotmart_matched_user_id'] ?? null);
        } else {
            $row['source_user_id'] = $uid > 0 ? $uid : null;
        }
        if (empty($row['is_attributed'])) {
            $row['pending_reason_code'] = detect_pending_reason($row, $GLOBALS['metaHierarchyForPending'] ?? array());
            $row['pending_reason'] = attribution_failure_label($row['pending_reason_code']);
        } else {
            $row['pending_reason_code'] = '';
            $row['pending_reason'] = '';
        }
    }
    unset($row);

    usort($rows, function ($a, $b) {
        return strcmp((string)($b['effective_date'] ?? ''), (string)($a['effective_date'] ?? ''));
    });

    return array_values($rows);
}

function fetch_non_completed_event_rows(PDO $pdo, $start, $end)
{
    $sql = "SELECT webhook_event AS event_name, transaction_code, transaction_date, payment_confirmed_at, product_name, gross_revenue, net_revenue, producer_net,
                   utm_campaign, utm_source,
                   JSON_UNQUOTE(JSON_EXTRACT(CAST(raw_payload_json AS JSON), '$.data.purchase.payment.type')) AS purchase_payment_type
            FROM hotmart_sales_live
            WHERE transaction_date BETWEEN :start_dt AND :end_dt
              AND webhook_event NOT IN ('PURCHASE_APPROVED','PURCHASE_COMPLETE')
            ORDER BY transaction_date DESC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':start_dt' => $start . ' 00:00:00', ':end_dt' => $end . ' 23:59:59'));
    return $stmt->fetchAll();
}

function fetch_sales_listing_rows(PDO $pdo, $model, $start, $end, array $campaignFilters = array(), $adsetFilter = '', $productFilter = '')
{
    $sql = "SELECT m.sale_date, m.campaign_group, m.campaign_name, m.ad_name, m.product_name, m.revenue_value,
                   s.transaction_code, s.buyer_name, s.buyer_email, s.matched_user_id,
                   s.utm_source AS attributed_utm_source, s.utm_campaign_group AS attributed_utm_campaign_group,
                   s.utm_campaign_name AS attributed_utm_campaign_name, s.utm_ad_name AS attributed_utm_ad_name,
                   s.utm_term AS attributed_utm_term
            FROM attribution_matches m
            INNER JOIN attribution_sales s ON s.id = m.sale_id
            WHERE m.attribution_model = :model AND m.sale_date BETWEEN :start_dt AND :end_dt
            ORDER BY m.sale_date DESC, m.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':model' => $model, ':start_dt' => $start . ' 00:00:00', ':end_dt' => $end . ' 23:59:59'));
    $rows = $stmt->fetchAll();
    $filtered = array();
    foreach ($rows as $row) {
        if (!campaign_matches_selected($row['campaign_group'] ?? '', $campaignFilters)) {
            continue;
        }
        if ($adsetFilter !== '' && (string)($row['campaign_name'] ?? '') !== $adsetFilter) {
            continue;
        }
        if ($productFilter !== '' && (string)($row['product_name'] ?? '') !== $productFilter) {
            continue;
        }
        $filtered[] = $row;
    }

    $existingCodes = array();
    foreach ($filtered as $row) {
        $code = (string)($row['transaction_code'] ?? '');
        if ($code !== '') { $existingCodes[$code] = true; }
    }

    ensure_manual_attribution_table($pdo);
    $manualSql = "SELECT h.transaction_code, h.buyer_name, h.buyer_email, h.product_name, h.producer_net, h.transaction_date AS sale_date,
                         m.campaign_group, m.campaign_name, m.ad_name, m.source_user_id AS matched_user_id,
                         '' AS attributed_utm_source, '' AS attributed_utm_campaign_group, '' AS attributed_utm_campaign_name, '' AS attributed_utm_ad_name, '' AS attributed_utm_term,
                         m.lead_utm_source, m.lead_utm_medium, m.lead_utm_campaign, m.lead_utm_term, m.lead_utm_content
                  FROM manual_sale_attributions m
                  INNER JOIN hotmart_sales_live h ON h.transaction_code COLLATE utf8mb4_unicode_ci = m.transaction_code COLLATE utf8mb4_unicode_ci
                  WHERE m.attribution_model = :model
                    AND h.transaction_date BETWEEN :start_dt AND :end_dt
                    AND h.status IN ('Aprovado','APPROVED','PURCHASE_APPROVED','Completo','COMPLETE','COMPLETED','PURCHASE_COMPLETE')";
    $manualStmt = $pdo->prepare($manualSql);
    $manualStmt->execute(array(':model' => $model, ':start_dt' => $start . ' 00:00:00', ':end_dt' => $end . ' 23:59:59'));
    foreach ($manualStmt->fetchAll(PDO::FETCH_ASSOC) as $mrow) {
        $code = (string)($mrow['transaction_code'] ?? '');
        if ($code === '' || isset($existingCodes[$code])) { continue; }
        if (!campaign_matches_selected($mrow['campaign_group'] ?? '', $campaignFilters)) { continue; }
        if ($adsetFilter !== '' && (string)($mrow['campaign_name'] ?? '') !== $adsetFilter) { continue; }
        if ($productFilter !== '' && (string)($mrow['product_name'] ?? '') !== $productFilter) { continue; }
        $filtered[] = $mrow;
        $existingCodes[$code] = true;
    }

    if ($filtered) {
        $codes = array_values(array_unique(array_filter(array_map(function($r){ return (string)($r['transaction_code'] ?? ''); }, $filtered))));
        if ($codes) {
            $liveMap = fetch_hotmart_live_map_by_transaction_codes($pdo, $codes);
            $attrMap = fetch_attribution_match_map_by_transaction_codes($pdo, $model, $codes);
            foreach ($filtered as &$row) {
                $code = (string)($row['transaction_code'] ?? '');
                $live = $liveMap[$code] ?? array();
                $attr = $attrMap[$code] ?? array();
                if ((int)($row['matched_user_id'] ?? 0) <= 0 && !empty($live['hotmart_matched_user_id'])) {
                    $row['matched_user_id'] = $live['hotmart_matched_user_id'];
                }
                if ((int)($row['matched_user_id'] ?? 0) <= 0 && !empty($attr['sale_matched_user_id'])) {
                    $row['matched_user_id'] = $attr['sale_matched_user_id'];
                }
                if (!empty($attr['lead_utm_source']) && trim((string)($row['lead_utm_source'] ?? '')) === '') {
                    $row['lead_utm_source'] = $attr['lead_utm_source'];
                    $row['lead_utm_medium'] = $attr['lead_utm_medium'] ?? '';
                    $row['lead_utm_campaign'] = $attr['lead_utm_campaign'] ?? '';
                    $row['lead_utm_term'] = $attr['lead_utm_term'] ?? '';
                    $row['lead_utm_content'] = $attr['lead_utm_content'] ?? '';
                }
            }
            unset($row);
        }
    }

    $userMap = fetch_source_users_by_sales(source_db(), $filtered);
    foreach ($filtered as &$row) {
        $uid = (int)($row['matched_user_id'] ?? 0);
        $user = isset($userMap[$uid]) ? $userMap[$uid] : array();
        if (empty($user) && !empty($row['buyer_email'])) {
            foreach ($userMap as $candidate) {
                if (strtolower((string)($candidate['email'] ?? '')) === strtolower((string)$row['buyer_email'])) { $user = $candidate; break; }
            }
        }
        if (trim((string)($row['lead_utm_source'] ?? '')) === '') {
            $row['lead_utm_source'] = $user['utm_source'] ?? '';
            $row['lead_utm_medium'] = $user['utm_medium'] ?? '';
            $row['lead_utm_campaign'] = $user['utm_campaign'] ?? '';
            $row['lead_utm_term'] = $user['utm_term'] ?? '';
            $row['lead_utm_content'] = $user['utm_content'] ?? '';
        }
        $row['campaign_group'] = trim((string)($row['campaign_group'] ?? '')) !== '' ? $row['campaign_group'] : 'SEM ATRIBUIÇÃO';
        $row['campaign_name'] = trim((string)($row['campaign_name'] ?? '')) !== '' ? $row['campaign_name'] : 'SEM ATRIBUIÇÃO';
        $row['ad_name'] = trim((string)($row['ad_name'] ?? '')) !== '' ? $row['ad_name'] : 'SEM ATRIBUIÇÃO';
    }
    unset($row);

    return $filtered;
}


function group_top_rows_nested(array $rows)
{
    $out = array();
    foreach ($rows as $row) {
        $campaign = (string)$row['campaign_group'];
        $adset = (string)$row['campaign_name'];
        $ad = (string)$row['ad_name'];
        if (!isset($out[$campaign])) {
            $out[$campaign] = array('metrics' => array('spend'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0), 'children' => array());
        }
        foreach (array('spend','leads','sales','revenue') as $m) {
            $out[$campaign]['metrics'][$m] += (float)$row[$m];
        }
        if (!isset($out[$campaign]['children'][$adset])) {
            $out[$campaign]['children'][$adset] = array('metrics' => array('spend'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0), 'children' => array());
        }
        foreach (array('spend','leads','sales','revenue') as $m) {
            $out[$campaign]['children'][$adset]['metrics'][$m] += (float)$row[$m];
        }
        if (!isset($out[$campaign]['children'][$adset]['children'][$ad])) {
            $out[$campaign]['children'][$adset]['children'][$ad] = array('metrics' => array('spend'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0));
        }
        foreach (array('spend','leads','sales','revenue') as $m) {
            $out[$campaign]['children'][$adset]['children'][$ad]['metrics'][$m] += (float)$row[$m];
        }
    }

    foreach ($out as $campaign => $cData) {
        $out[$campaign]['metrics']['cpl'] = $cData['metrics']['leads'] > 0 ? $cData['metrics']['spend'] / $cData['metrics']['leads'] : 0;
        $out[$campaign]['metrics']['cac'] = $cData['metrics']['sales'] > 0 ? $cData['metrics']['spend'] / $cData['metrics']['sales'] : 0;
        $out[$campaign]['metrics']['roas'] = $cData['metrics']['spend'] > 0 ? $cData['metrics']['revenue'] / $cData['metrics']['spend'] : 0;
        foreach ($out[$campaign]['children'] as $adset => $aData) {
            $out[$campaign]['children'][$adset]['metrics']['cpl'] = $aData['metrics']['leads'] > 0 ? $aData['metrics']['spend'] / $aData['metrics']['leads'] : 0;
            $out[$campaign]['children'][$adset]['metrics']['cac'] = $aData['metrics']['sales'] > 0 ? $aData['metrics']['spend'] / $aData['metrics']['sales'] : 0;
            $out[$campaign]['children'][$adset]['metrics']['roas'] = $aData['metrics']['spend'] > 0 ? $aData['metrics']['revenue'] / $aData['metrics']['spend'] : 0;
            foreach ($out[$campaign]['children'][$adset]['children'] as $ad => $dData) {
                $out[$campaign]['children'][$adset]['children'][$ad]['metrics']['cpl'] = $dData['metrics']['leads'] > 0 ? $dData['metrics']['spend'] / $dData['metrics']['leads'] : 0;
                $out[$campaign]['children'][$adset]['children'][$ad]['metrics']['cac'] = $dData['metrics']['sales'] > 0 ? $dData['metrics']['spend'] / $dData['metrics']['sales'] : 0;
                $out[$campaign]['children'][$adset]['children'][$ad]['metrics']['roas'] = $dData['metrics']['spend'] > 0 ? $dData['metrics']['revenue'] / $dData['metrics']['spend'] : 0;
            }
        }
    }
    return $out;
}

function sort_nested_groups(array $grouped, $sort, $dir)
{
    $factor = $dir === 'asc' ? 1 : -1;
    uasort($grouped, function ($a, $b) use ($sort, $factor) {
        $av = isset($a['metrics'][$sort]) ? $a['metrics'][$sort] : 0;
        $bv = isset($b['metrics'][$sort]) ? $b['metrics'][$sort] : 0;
        if ($av == $bv) { return 0; }
        return ($av < $bv ? -1 : 1) * $factor;
    });
    foreach ($grouped as $campaign => $cData) {
        uasort($grouped[$campaign]['children'], function ($a, $b) use ($sort, $factor) {
            $av = isset($a['metrics'][$sort]) ? $a['metrics'][$sort] : 0;
            $bv = isset($b['metrics'][$sort]) ? $b['metrics'][$sort] : 0;
            if ($av == $bv) { return 0; }
            return ($av < $bv ? -1 : 1) * $factor;
        });
        foreach ($grouped[$campaign]['children'] as $adset => $aData) {
            uasort($grouped[$campaign]['children'][$adset]['children'], function ($a, $b) use ($sort, $factor) {
                $av = isset($a['metrics'][$sort]) ? $a['metrics'][$sort] : 0;
                $bv = isset($b['metrics'][$sort]) ? $b['metrics'][$sort] : 0;
                if ($av == $bv) { return 0; }
                return ($av < $bv ? -1 : 1) * $factor;
            });
        }
    }
    return $grouped;
}

function sort_link($params, $field, $label, $currentSort, $currentDir)
{
    $params['sort'] = $field;
    $params['dir'] = ($currentSort === $field && $currentDir === 'desc') ? 'asc' : 'desc';
    $arrow = '';
    if ($currentSort === $field) {
        $arrow = $currentDir === 'desc' ? ' ↓' : ' ↑';
    }
    return '<a class="sort-link" href="?' . h(http_build_query($params)) . '">' . h($label . $arrow) . '</a>';
}

$pdo = db();
ensure_meta_integration_schema($pdo);
$integrations = $pdo->query('SELECT * FROM meta_integrations ORDER BY status ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$activeIntegrations = array_values(array_filter($integrations, function ($row) {
    return ($row['status'] ?? 'active') === 'active';
}));
$allIntegrationIdsForStats = array_map(function ($row) { return (int)$row['id']; }, $integrations);
$integrationSyncedCampaignsCount = 0;
$integrationLastSyncOverall = null;
if ($allIntegrationIdsForStats) {
    $statsIdsSql = implode(',', $allIntegrationIdsForStats);
    $integrationSyncedCampaignsCount = (int) $pdo->query("SELECT COUNT(DISTINCT campaign_name) FROM meta_campaign_daily WHERE integration_id IN ($statsIdsSql) AND campaign_name <> ''")->fetchColumn();
    $integrationLastSyncOverall = $pdo->query("SELECT MAX(last_success_sync_at) FROM meta_integrations WHERE id IN ($statsIdsSql)")->fetchColumn();
}
$selectedIntegrationId = (int)($_GET['integration_id'] ?? 0);
$integrationIds = array_map(function ($row) { return (int)$row['id']; }, $selectedIntegrationId > 0 ? array_values(array_filter($integrations, function ($row) use ($selectedIntegrationId) {
    return (int)$row['id'] === $selectedIntegrationId;
})) : $activeIntegrations);
$integrationIds = array_values(array_filter($integrationIds));
$integration = $selectedIntegrationId > 0
    ? ($pdo->query('SELECT * FROM meta_integrations WHERE id = ' . (int)$selectedIntegrationId . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null)
    : ($activeIntegrations[0] ?? ($integrations[0] ?? null));
$integrationIdSql = $integrationIds ? implode(',', array_map('intval', $integrationIds)) : '0';
$selectedIntegrationLabel = $selectedIntegrationId > 0 && $integration ? (string)$integration['name'] : 'Todas as BMs ativas';

$today = date('Y-m-d');
$periodA = max(1, (int)($_GET['period_a'] ?? 7));
$periodB = max($periodA, (int)($_GET['period_b'] ?? 30));
$periodC = max($periodB, (int)($_GET['period_c'] ?? 90));
$modelParam = isset($_GET['model']) ? $_GET['model'] : 'last_touch';
$attributionModel = in_array($modelParam, array('first_touch', 'last_touch'), true) ? $modelParam : 'last_touch';
$campaignFilters = get_array_param('campaign');
$campaignFilter = count($campaignFilters) === 1 ? $campaignFilters[0] : '';
$adsetFilter = trim((string)($_GET['adset'] ?? ''));
$productFilter = trim((string)($_GET['product'] ?? ''));
$granularity = trim((string)($_GET['granularity'] ?? 'day'));
if (!in_array($granularity, array('day', 'week', 'month', 'year'), true)) {
    $granularity = 'day';
}
$rangeEnd = trim((string)($_GET['range_end'] ?? $today));
$rangeStart = trim((string)($_GET['range_start'] ?? date('Y-m-d', strtotime('-29 days'))));
$sort = trim((string)($_GET['sort'] ?? 'revenue'));
$dir = trim((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$allowedSort = array('spend','leads','sales','revenue','cac','roas','cpl');
if (!in_array($sort, $allowedSort, true)) { $sort = 'revenue'; }
$queryParams = $_GET;

$metaCards = array('spend'=>0.0,'impressions'=>0,'clicks'=>0,'leads'=>0,'cpm'=>0.0,'frequency'=>0.0);
$metaDaily = array();
$metaCampaignRows = array();
$recentRuns = array();
$recentAttrRuns = array();
$attrCards = array('spend'=>0.0,'spend_real'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0,'cpa'=>0.0,'frequency'=>0.0,'cpm'=>0.0,'cpc'=>0.0);
$attrTrendRows = array();
$attrDaily = array();
$attrTopRows = array();
$campaignOptions = array();
$adsetOptions = array();
$productOptions = array();
$topNested = array();
$manualPendingRows = array();
$metaHierarchy = array();

if ($integrationIds) {
    ensure_manual_attribution_table($pdo);
    $metaHierarchy = fetch_meta_hierarchy($pdo, $integrationIds);
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(mcd.spend),0) AS spend, COALESCE(SUM(mcd.spend * (1 + (CASE WHEN mi.currency_code = \'USD\' THEN mi.currency_spread_percent ELSE 0 END) / 100)),0) AS spend_real, COALESCE(SUM(mcd.impressions),0) AS impressions, COALESCE(SUM(mcd.clicks),0) AS clicks, COALESCE(SUM(mcd.leads),0) AS leads, CASE WHEN SUM(mcd.impressions) > 0 THEN (SUM(mcd.spend) / SUM(mcd.impressions)) * 1000 ELSE 0 END AS cpm, CASE WHEN SUM(mcd.reach) > 0 THEN SUM(mcd.impressions) / SUM(mcd.reach) ELSE 0 END AS frequency FROM meta_campaign_daily mcd JOIN meta_integrations mi ON mi.id = mcd.integration_id WHERE mcd.integration_id IN (' . $integrationIdSql . ') AND mcd.report_date = :report_date');
    $stmt->execute(array('report_date' => $today));
    $metaCards = $stmt->fetch() ?: $metaCards;

    $stmt = $pdo->prepare('SELECT mcd.report_date, SUM(mcd.spend) AS spend, SUM(mcd.spend * (1 + (CASE WHEN mi.currency_code = \'USD\' THEN mi.currency_spread_percent ELSE 0 END) / 100)) AS spend_real, SUM(mcd.leads) AS leads, CASE WHEN SUM(mcd.impressions) > 0 THEN (SUM(mcd.spend) / SUM(mcd.impressions)) * 1000 ELSE 0 END AS cpm, CASE WHEN SUM(mcd.reach) > 0 THEN SUM(mcd.impressions) / SUM(mcd.reach) ELSE 0 END AS frequency FROM meta_campaign_daily mcd JOIN meta_integrations mi ON mi.id = mcd.integration_id WHERE mcd.integration_id IN (' . $integrationIdSql . ') AND mcd.report_date BETWEEN :start AND :end GROUP BY mcd.report_date ORDER BY mcd.report_date ASC');
    $stmt->execute(array('start' => date('Y-m-d', strtotime('-29 days')), 'end' => $today));
    $metaDaily = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT report_date, campaign_name, SUM(spend) AS spend, SUM(impressions) AS impressions, SUM(reach) AS reach, CASE WHEN SUM(reach) > 0 THEN SUM(impressions) / SUM(reach) ELSE 0 END AS frequency, SUM(clicks) AS clicks, CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) * 100 ELSE 0 END AS ctr, CASE WHEN SUM(clicks) > 0 THEN SUM(spend) / SUM(clicks) ELSE 0 END AS cpc, CASE WHEN SUM(impressions) > 0 THEN SUM(spend) / SUM(impressions) * 1000 ELSE 0 END AS cpm, SUM(leads) AS leads, SUM(purchases) AS purchases FROM meta_campaign_daily WHERE integration_id IN (' . $integrationIdSql . ') AND report_date = :report_date GROUP BY report_date, campaign_name ORDER BY spend DESC, impressions DESC LIMIT 20');
    $stmt->execute(array('report_date' => $today));
    $metaCampaignRows = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM meta_sync_runs WHERE integration_id IN (' . $integrationIdSql . ') ORDER BY id DESC LIMIT 15');
    $stmt->execute();
    $recentRuns = $stmt->fetchAll();

    if (table_exists($pdo, 'attribution_runs')) {
        $recentAttrRuns = $pdo->query('SELECT * FROM attribution_runs ORDER BY id DESC LIMIT 20')->fetchAll();
    }

    $campaignStmt = $pdo->prepare("SELECT DISTINCT campaign_name FROM meta_campaign_daily WHERE integration_id IN (" . $integrationIdSql . ") AND campaign_name <> '' ORDER BY campaign_name");
    $campaignStmt->execute();
    $campaignOptions = array_column($campaignStmt->fetchAll(), 'campaign_name');

    $adsetSql = "SELECT DISTINCT campaign_name FROM attribution_campaign_daily WHERE attribution_model = :model AND campaign_name <> ''";
    $adsetParams = array('model' => $attributionModel);
    $adsetSql .= ' ORDER BY campaign_name';
    $adsetStmt = $pdo->prepare($adsetSql);
    $adsetStmt->execute($adsetParams);
    $adsetOptions = array_column($adsetStmt->fetchAll(), 'campaign_name');

    $productStmt = $pdo->prepare("SELECT DISTINCT product_name FROM attribution_matches WHERE attribution_model = :model AND product_name <> '' ORDER BY product_name");
    $productStmt->execute(array('model' => $attributionModel));
    $productOptions = array_column($productStmt->fetchAll(), 'product_name');

    $salesWhere = ' WHERE m.attribution_model = :model AND m.sale_date BETWEEN :start_dt AND :end_dt';
    $salesParams = array('model' => $attributionModel, 'start_dt' => $rangeStart . ' 00:00:00', 'end_dt' => $rangeEnd . ' 23:59:59');

    $metaAggRows = fetch_meta_real_daily_rows($pdo, $integrationIds, $rangeStart, $rangeEnd, $campaignFilters, $adsetFilter);
    $leadAggRows = fetch_attr_lead_daily_rows($pdo, $rangeStart, $rangeEnd, $campaignFilters, $adsetFilter);
    $salesAggRows = fetch_attr_sales_daily_rows($pdo, $attributionModel, $rangeStart, $rangeEnd, $campaignFilters, $adsetFilter, $productFilter);

    $attrDaily = merge_real_buckets($metaAggRows, $leadAggRows, $salesAggRows, $granularity);

    foreach ($attrDaily as $row) {
        $attrCards['spend'] += (float)$row['spend'];
        $attrCards['spend_real'] += (float)($row['spend_real'] ?? $row['spend']);
        $attrCards['leads'] += (int)$row['leads'];
        $attrCards['sales'] += (int)$row['sales'];
        $attrCards['revenue'] += (float)$row['revenue'];
        if (!empty($row['cpm_rows'])) {
            $attrCards['cpm'] += (float)$row['cpm_sum'];
        }
    }
    $metricRows = array_filter($attrDaily, function ($r) { return !empty($r['cpm_rows']) || !empty($r['frequency_rows']) || !empty($r['cpc_rows']); });
    $metricCount = count($metricRows);
    $attrCards['cpm'] = $metricCount > 0 ? array_sum(array_map(function ($r) { return $r['cpm_rows'] > 0 ? $r['cpm_sum'] / $r['cpm_rows'] : 0; }, $metricRows)) / $metricCount : 0;
    $attrCards['frequency'] = $metricCount > 0 ? array_sum(array_map(function ($r) { return $r['frequency_rows'] > 0 ? $r['frequency_sum'] / $r['frequency_rows'] : 0; }, $metricRows)) / $metricCount : 0;
    $attrCards['cpc'] = $metricCount > 0 ? array_sum(array_map(function ($r) { return $r['cpc_rows'] > 0 ? $r['cpc_sum'] / $r['cpc_rows'] : 0; }, $metricRows)) / $metricCount : 0;
    $attrCards['cpl'] = $attrCards['leads'] > 0 ? $attrCards['spend'] / $attrCards['leads'] : 0;

    $preciseAttributedSummary = fetch_precise_attributed_sales_summary($pdo, $attributionModel, $rangeStart, $rangeEnd, $campaignFilters, $adsetFilter, $productFilter);
    $attrCards['sales'] = (int)($preciseAttributedSummary['sales'] ?? 0);
    $attrCards['revenue'] = (float)($preciseAttributedSummary['revenue'] ?? 0);
    $attrCards['cac'] = $attrCards['sales'] > 0 ? $attrCards['spend_real'] / $attrCards['sales'] : 0;
    $attrCards['cpa'] = $attrCards['cac'];
    $attrCards['roas'] = $attrCards['spend'] > 0 ? $attrCards['revenue'] / $attrCards['spend'] : 0;

    $periods = array(
        array('label' => $periodA . 'd vs ' . $periodB . 'd', 'a' => $periodA, 'b' => $periodB),
        array('label' => $periodB . 'd vs ' . $periodC . 'd', 'a' => $periodB, 'b' => $periodC),
    );
    foreach ($periods as $period) {
        $currStart = date('Y-m-d', strtotime($rangeEnd . ' -' . ($period['a'] - 1) . ' days'));
        $baseStart = date('Y-m-d', strtotime($rangeEnd . ' -' . ($period['b'] - 1) . ' days'));

        $curMeta = fetch_meta_period_summary($pdo, $integrationIds, $currStart, $rangeEnd);
        $basMeta = fetch_meta_period_summary($pdo, $integrationIds, $baseStart, $rangeEnd);
        $curSales = fetch_total_sales_summary($pdo, $currStart, $rangeEnd, $productFilter);
        $basSales = fetch_total_sales_summary($pdo, $baseStart, $rangeEnd, $productFilter);

        $curSummary = array(
            'cac' => $curSales['sales'] > 0 ? $curMeta['spend_real'] / $curSales['sales'] : 0.0,
            'cpl' => $curMeta['leads'] > 0 ? $curMeta['spend'] / $curMeta['leads'] : 0.0,
            'roas' => $curMeta['spend'] > 0 ? $curSales['revenue'] / $curMeta['spend'] : 0.0,
            'cpm' => $curMeta['impressions'] > 0 ? ($curMeta['spend'] / $curMeta['impressions']) * 1000 : 0.0,
            'frequency' => $curMeta['reach'] > 0 ? $curMeta['impressions'] / $curMeta['reach'] : 0.0,
            'cpc' => $curMeta['clicks'] > 0 ? $curMeta['spend'] / $curMeta['clicks'] : 0.0,
        );
        $basSummary = array(
            'cac' => $basSales['sales'] > 0 ? $basMeta['spend_real'] / $basSales['sales'] : 0.0,
            'cpl' => $basMeta['leads'] > 0 ? $basMeta['spend'] / $basMeta['leads'] : 0.0,
            'roas' => $basMeta['spend'] > 0 ? $basSales['revenue'] / $basMeta['spend'] : 0.0,
            'cpm' => $basMeta['impressions'] > 0 ? ($basMeta['spend'] / $basMeta['impressions']) * 1000 : 0.0,
            'frequency' => $basMeta['reach'] > 0 ? $basMeta['impressions'] / $basMeta['reach'] : 0.0,
            'cpc' => $basMeta['clicks'] > 0 ? $basMeta['spend'] / $basMeta['clicks'] : 0.0,
        );

        $metrics = array();
        foreach (array('cac','cpl','roas','cpm','frequency','cpc') as $metricKey) {
            $cur = (float)$curSummary[$metricKey];
            $bas = (float)$basSummary[$metricKey];
            $change = (abs($bas) < 0.000001) ? null : pct_change($cur, $bas);
            $metrics[$metricKey] = array('current' => $cur, 'baseline' => $bas, 'change' => $change, 'direction' => trend_direction($metricKey, $change));
        }
        $attrTrendRows[] = array('label' => $period['label'], 'metrics' => $metrics);
    }


    $topNested = array();
    $topSummary = array('spend'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cpl'=>0.0,'cac'=>0.0,'roas'=>0.0);

    $campaignNormFilter = normalize_match_key($campaignFilter);
    $adsetNormFilter = normalize_match_key($adsetFilter);

    $metaCampaignOfficialByNorm = array();
    $metaAdsetOfficialByNorm = array();
    $metaAdOfficialByNorm = array();
    $metaAdsetSpendByNorm = array();
    $metaAdSpendByNorm = array();

    $metaCampaignStmt = $pdo->prepare("
        SELECT campaign_name, SUM(spend) AS spend
        FROM meta_campaign_daily
        WHERE integration_id IN ($integrationIdSql)
          AND report_date BETWEEN :start AND :end
        GROUP BY campaign_name
        ORDER BY SUM(spend) DESC, campaign_name ASC
    ");
    $metaCampaignStmt->execute(array(
        'start' => $rangeStart,
        'end' => $rangeEnd,
    ));
    while ($row = $metaCampaignStmt->fetch()) {
        $campaignName = (string)($row['campaign_name'] ?? '');
        $campaignNorm = normalize_match_key($campaignName);
        if ($campaignNorm === '') {
            continue;
        }
        if ($campaignNormFilter !== '' && $campaignNorm !== $campaignNormFilter) {
            continue;
        }
        $metaCampaignOfficialByNorm[$campaignNorm] = $campaignName;
        if (!isset($topNested[$campaignName])) {
            $topNested[$campaignName] = array(
                'metrics' => array('spend'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0),
                'children' => array(),
            );
        }
        $topNested[$campaignName]['metrics']['spend'] += (float)$row['spend'];
    }

    $metaAdsetStmt = $pdo->prepare("
        SELECT campaign_name, adset_name, SUM(spend) AS spend
        FROM meta_adset_daily
        WHERE integration_id IN ($integrationIdSql)
          AND report_date BETWEEN :start AND :end
        GROUP BY campaign_name, adset_name
        ORDER BY campaign_name ASC, SUM(spend) DESC, adset_name ASC
    ");
    $metaAdsetStmt->execute(array(
        'start' => $rangeStart,
        'end' => $rangeEnd,
    ));
    while ($row = $metaAdsetStmt->fetch()) {
        $campaignNorm = normalize_match_key((string)($row['campaign_name'] ?? ''));
        $adsetName = (string)($row['adset_name'] ?? '');
        $adsetNorm = normalize_match_key($adsetName);
        if ($campaignNorm === '' || !isset($metaCampaignOfficialByNorm[$campaignNorm]) || $adsetNorm === '') {
            continue;
        }
        if ($adsetNormFilter !== '' && $adsetNorm !== $adsetNormFilter) {
            continue;
        }
        $campaignName = $metaCampaignOfficialByNorm[$campaignNorm];
        if (!isset($metaAdsetOfficialByNorm[$campaignNorm])) {
            $metaAdsetOfficialByNorm[$campaignNorm] = array();
            $metaAdsetSpendByNorm[$campaignNorm] = array();
        }
        $metaAdsetOfficialByNorm[$campaignNorm][$adsetNorm] = $adsetName;
        $metaAdsetSpendByNorm[$campaignNorm][$adsetNorm] = (float)$row['spend'];

        if (!isset($topNested[$campaignName]['children'][$adsetName])) {
            $topNested[$campaignName]['children'][$adsetName] = array(
                'metrics' => array('spend'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0),
                'children' => array(),
            );
        }
        $topNested[$campaignName]['children'][$adsetName]['metrics']['spend'] = (float)$row['spend'];
    }

    $metaAdStmt = $pdo->prepare("
        SELECT campaign_name, adset_name, ad_name, SUM(spend) AS spend
        FROM meta_ad_daily
        WHERE integration_id IN ($integrationIdSql)
          AND report_date BETWEEN :start AND :end
        GROUP BY campaign_name, adset_name, ad_name
        ORDER BY campaign_name ASC, adset_name ASC, SUM(spend) DESC, ad_name ASC
    ");
    $metaAdStmt->execute(array(
        'start' => $rangeStart,
        'end' => $rangeEnd,
    ));
    while ($row = $metaAdStmt->fetch()) {
        $campaignNorm = normalize_match_key((string)($row['campaign_name'] ?? ''));
        $adsetNorm = normalize_match_key((string)($row['adset_name'] ?? ''));
        $adName = (string)($row['ad_name'] ?? '');
        $adNorm = normalize_match_key($adName);
        if ($campaignNorm === '' || !isset($metaCampaignOfficialByNorm[$campaignNorm]) || $adsetNorm === '' || $adNorm === '') {
            continue;
        }
        if ($adsetNormFilter !== '' && $adsetNorm !== $adsetNormFilter) {
            continue;
        }
        $campaignName = $metaCampaignOfficialByNorm[$campaignNorm];
        $adsetName = isset($metaAdsetOfficialByNorm[$campaignNorm][$adsetNorm]) ? $metaAdsetOfficialByNorm[$campaignNorm][$adsetNorm] : (string)($row['adset_name'] ?? '');
        if (!isset($metaAdOfficialByNorm[$campaignNorm])) {
            $metaAdOfficialByNorm[$campaignNorm] = array();
        }
        if (!isset($metaAdOfficialByNorm[$campaignNorm][$adsetNorm])) {
            $metaAdOfficialByNorm[$campaignNorm][$adsetNorm] = array();
            $metaAdSpendByNorm[$campaignNorm][$adsetNorm] = array();
        }
        $metaAdOfficialByNorm[$campaignNorm][$adsetNorm][$adNorm] = $adName;
        $metaAdSpendByNorm[$campaignNorm][$adsetNorm][$adNorm] = (float)$row['spend'];

        if (!isset($topNested[$campaignName]['children'][$adsetName])) {
            $topNested[$campaignName]['children'][$adsetName] = array(
                'metrics' => array('spend'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0),
                'children' => array(),
            );
        }
        if (!isset($topNested[$campaignName]['children'][$adsetName]['children'][$adName])) {
            $topNested[$campaignName]['children'][$adsetName]['children'][$adName] = array(
                'metrics' => array('spend'=>0.0,'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0),
            );
        }
        $topNested[$campaignName]['children'][$adsetName]['children'][$adName]['metrics']['spend'] = (float)$row['spend'];
    }

    $leadTopSql = "SELECT utm_campaign_group AS campaign_group, utm_campaign_name AS campaign_name, utm_ad_name AS ad_name, COUNT(*) AS leads FROM attribution_leads WHERE created_at BETWEEN :start_dt AND :end_dt";
    $leadTopParams = array('start_dt' => $rangeStart . ' 00:00:00', 'end_dt' => $rangeEnd . ' 23:59:59');
    if ($campaignFilter !== '') { $leadTopSql .= ' AND utm_campaign_group = :campaign'; $leadTopParams['campaign'] = $campaignFilter; }
    if ($adsetFilter !== '') { $leadTopSql .= ' AND utm_campaign_name = :adset'; $leadTopParams['adset'] = $adsetFilter; }
    $leadTopSql .= ' GROUP BY utm_campaign_group, utm_campaign_name, utm_ad_name';
    $leadTopStmt = $pdo->prepare($leadTopSql);
    $leadTopStmt->execute($leadTopParams);
    $leadTopRows = $leadTopStmt->fetchAll();

    $salesTopStmt = $pdo->prepare("SELECT h.transaction_code,
                                            COALESCE(h.payment_confirmed_at, h.transaction_date, m.sale_date) AS effective_dt,
                                            m.campaign_group, m.campaign_name, m.ad_name,
                                            COALESCE(h.producer_net, h.net_revenue, m.revenue_value, 0) AS revenue_value,
                                            h.id AS hotmart_id
                                     FROM hotmart_sales_live h
                                     INNER JOIN attribution_sales s ON s.transaction_code COLLATE utf8mb4_unicode_ci = h.transaction_code COLLATE utf8mb4_unicode_ci
                                     INNER JOIN attribution_matches m ON m.sale_id = s.id AND m.attribution_model = :model
                                     WHERE h.transaction_date BETWEEN :start_dt AND :end_dt
                                       AND h.status IN ('Aprovado','APPROVED','PURCHASE_APPROVED','Completo','COMPLETE','COMPLETED','PURCHASE_COMPLETE')");
    $salesTopStmt->execute(array(
        ':model' => $attributionModel,
        ':start_dt' => $rangeStart . ' 00:00:00',
        ':end_dt' => $rangeEnd . ' 23:59:59',
    ));
    $salesTopRowsRaw = $salesTopStmt->fetchAll(PDO::FETCH_ASSOC);
    $salesTopMap = array();
    foreach ($salesTopRowsRaw as $row) {
        $tx = trim((string)($row['transaction_code'] ?? ''));
        if ($tx === '') { continue; }
        if ($campaignFilter !== '' && (string)($row['campaign_group'] ?? '') !== $campaignFilter) { continue; }
        if ($adsetFilter !== '' && (string)($row['campaign_name'] ?? '') !== $adsetFilter) { continue; }
        if (!isset($salesTopMap[$tx]) || (int)($row['hotmart_id'] ?? 0) > (int)($salesTopMap[$tx]['hotmart_id'] ?? 0)) {
            $salesTopMap[$tx] = $row;
        }
    }
    $salesTopRows = array();
    foreach ($salesTopMap as $row) {
        $key = (string)($row['campaign_group'] ?? '') . '||' . (string)($row['campaign_name'] ?? '') . '||' . (string)($row['ad_name'] ?? '');
        if (!isset($salesTopRows[$key])) {
            $salesTopRows[$key] = array(
                'campaign_group'=>(string)($row['campaign_group'] ?? ''),
                'campaign_name'=>(string)($row['campaign_name'] ?? ''),
                'ad_name'=>(string)($row['ad_name'] ?? ''),
                'sales'=>0,
                'revenue'=>0.0
            );
        }
        $salesTopRows[$key]['sales'] += 1;
        $salesTopRows[$key]['revenue'] += (float)($row['revenue_value'] ?? 0);
    }
    $salesTopRows = array_values($salesTopRows);

    $applyTopMetric = function (&$topNestedRef, $metaCampaignOfficialByNormRef, $metaAdsetOfficialByNormRef, $metaAdsetSpendByNormRef, $metaAdOfficialByNormRef, $metaAdSpendByNormRef, $row, $metricKey, $metricValue, $revenueValue) use ($adsetNormFilter) {
        $campaignNormRaw = normalize_match_key((string)($row['campaign_group'] ?? ''));
        $campaignNorm = best_fuzzy_key_match($campaignNormRaw, $metaCampaignOfficialByNormRef, 78.0);
        if ($campaignNorm === '' || !isset($metaCampaignOfficialByNormRef[$campaignNorm])) {
            return;
        }
        $campaignName = $metaCampaignOfficialByNormRef[$campaignNorm];
        $adsetRaw = (string)($row['campaign_name'] ?? '');
        $adsetNormRaw = normalize_match_key($adsetRaw);
        $adRaw = (string)($row['ad_name'] ?? '');
        $adNormRaw = normalize_match_key($adRaw);

        if ($adsetNormFilter !== '' && $adsetNormRaw !== '' && best_fuzzy_key_match($adsetNormRaw, array($adsetNormFilter => $adsetNormFilter), 96.0) === '') {
            return;
        }

        $topNestedRef[$campaignName]['metrics'][$metricKey] += $metricValue;
        $topNestedRef[$campaignName]['metrics']['revenue'] += $revenueValue;

        if ($adsetNormRaw === '') {
            return;
        }

        $campaignAdsetMap = isset($metaAdsetOfficialByNormRef[$campaignNorm]) ? $metaAdsetOfficialByNormRef[$campaignNorm] : array();
        $adsetNorm = best_fuzzy_key_match($adsetNormRaw, $campaignAdsetMap, 74.0);
        if ($adsetNorm === '' || !isset($campaignAdsetMap[$adsetNorm])) {
            return;
        }
        $adsetName = $campaignAdsetMap[$adsetNorm];
        if (!isset($topNestedRef[$campaignName]['children'][$adsetName])) {
            $topNestedRef[$campaignName]['children'][$adsetName] = array(
                'metrics' => array(
                    'spend' => isset($metaAdsetSpendByNormRef[$campaignNorm][$adsetNorm]) ? (float)$metaAdsetSpendByNormRef[$campaignNorm][$adsetNorm] : 0.0,
                    'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0
                ),
                'children' => array(),
            );
        }
        $topNestedRef[$campaignName]['children'][$adsetName]['metrics'][$metricKey] += $metricValue;
        $topNestedRef[$campaignName]['children'][$adsetName]['metrics']['revenue'] += $revenueValue;

        if ($adNormRaw === '') {
            return;
        }

        $campaignAdMap = isset($metaAdOfficialByNormRef[$campaignNorm][$adsetNorm]) ? $metaAdOfficialByNormRef[$campaignNorm][$adsetNorm] : array();
        $adNorm = best_fuzzy_key_match($adNormRaw, $campaignAdMap, 72.0);
        if ($adNorm === '' || !isset($campaignAdMap[$adNorm])) {
            return;
        }
        $adName = $campaignAdMap[$adNorm];
        if (!isset($topNestedRef[$campaignName]['children'][$adsetName]['children'][$adName])) {
            $topNestedRef[$campaignName]['children'][$adsetName]['children'][$adName] = array(
                'metrics' => array(
                    'spend' => isset($metaAdSpendByNormRef[$campaignNorm][$adsetNorm][$adNorm]) ? (float)$metaAdSpendByNormRef[$campaignNorm][$adsetNorm][$adNorm] : 0.0,
                    'leads'=>0,'sales'=>0,'revenue'=>0.0,'cac'=>0.0,'roas'=>0.0,'cpl'=>0.0
                ),
            );
        }
        $topNestedRef[$campaignName]['children'][$adsetName]['children'][$adName]['metrics'][$metricKey] += $metricValue;
        $topNestedRef[$campaignName]['children'][$adsetName]['children'][$adName]['metrics']['revenue'] += $revenueValue;
    };

    foreach ($leadTopRows as $row) {
        $applyTopMetric($topNested, $metaCampaignOfficialByNorm, $metaAdsetOfficialByNorm, $metaAdsetSpendByNorm, $metaAdOfficialByNorm, $metaAdSpendByNorm, $row, 'leads', (int)$row['leads'], 0.0);
    }
    foreach ($salesTopRows as $row) {
        $applyTopMetric($topNested, $metaCampaignOfficialByNorm, $metaAdsetOfficialByNorm, $metaAdsetSpendByNorm, $metaAdOfficialByNorm, $metaAdSpendByNorm, $row, 'sales', (int)$row['sales'], (float)$row['revenue']);
    }

    $recalcNestedMetrics = function (&$node) use (&$recalcNestedMetrics) {
        $node['metrics']['cpl'] = $node['metrics']['leads'] > 0 ? $node['metrics']['spend'] / $node['metrics']['leads'] : 0.0;
        $node['metrics']['cac'] = $node['metrics']['sales'] > 0 ? $node['metrics']['spend'] / $node['metrics']['sales'] : 0.0;
        $node['metrics']['roas'] = $node['metrics']['spend'] > 0 ? $node['metrics']['revenue'] / $node['metrics']['spend'] : 0.0;
        if (!empty($node['children'])) {
            foreach ($node['children'] as &$child) {
                $recalcNestedMetrics($child);
            }
            unset($child);
        }
    };

    foreach ($topNested as $campaignName => &$campaignNode) {
        $recalcNestedMetrics($campaignNode);
        $topSummary['spend'] += (float)$campaignNode['metrics']['spend'];
        $topSummary['leads'] += (int)$campaignNode['metrics']['leads'];
        $topSummary['sales'] += (int)$campaignNode['metrics']['sales'];
        $topSummary['revenue'] += (float)$campaignNode['metrics']['revenue'];
    }
    unset($campaignNode);

    $topSummary['cpl'] = $topSummary['leads'] > 0 ? $topSummary['spend'] / $topSummary['leads'] : 0.0;
    $topSummary['cac'] = $topSummary['sales'] > 0 ? $topSummary['spend'] / $topSummary['sales'] : 0.0;
    $topSummary['roas'] = $topSummary['spend'] > 0 ? $topSummary['revenue'] / $topSummary['spend'] : 0.0;

        $salesRowsAll = fetch_sales_listing_rows($pdo, $attributionModel, $rangeStart, $rangeEnd, $campaignFilters, $adsetFilter, $productFilter);
    $salesPerPageOptions = array(10, 50, 100, 500);
    $salesPerPage = (int)($_GET['sales_per_page'] ?? 50);
    if (!in_array($salesPerPage, $salesPerPageOptions, true)) { $salesPerPage = 50; }
    $salesPage = max(1, (int)($_GET['sales_page'] ?? 1));
    $salesTotal = count($salesRowsAll);
    $salesTotalPages = max(1, (int)ceil($salesTotal / $salesPerPage));
    if ($salesPage > $salesTotalPages) { $salesPage = $salesTotalPages; }
    $salesRowsPage = array_slice($salesRowsAll, ($salesPage - 1) * $salesPerPage, $salesPerPage);

    $salesDashboardRowsRaw = fetch_sales_dashboard_rows($pdo, $attributionModel, $rangeStart, $rangeEnd);
    $salesDashboardRows = array();
    foreach ($salesDashboardRowsRaw as $row) {
        $row['payment_type_label'] = payment_type_label($row['purchase_payment_type'] ?? '');
        $row['is_approved'] = event_is_approved($row['webhook_event'] ?? '', $row['status'] ?? '');
        $row['is_attributed'] = !empty($row['match_id']);
        $row['effective_date'] = sale_effective_date($row);
        $row['effective_revenue'] = sale_revenue_value($row);
        $row['filter_campaign'] = trim((string)($row['campaign_group'] ?? '')) !== '' ? (string)$row['campaign_group'] : (string)($row['hotmart_utm_campaign'] ?? '');
        $row['filter_adset'] = trim((string)($row['campaign_name'] ?? '')) !== '' ? (string)$row['campaign_name'] : '';
        $row['filter_product'] = trim((string)($row['product_name'] ?? ''));
        if (!campaign_matches_selected($row['filter_campaign'], $campaignFilters)) {
            continue;
        }
        if ($adsetFilter !== '' && (string)$row['filter_adset'] !== $adsetFilter) {
            continue;
        }
        if ($productFilter !== '' && (string)$row['filter_product'] !== $productFilter) {
            continue;
        }
        $salesDashboardRows[] = $row;
    }

$GLOBALS['metaHierarchyForPending'] = $metaHierarchy;
    $generalSalesRowsRaw = fetch_general_sales_rows($pdo, $attributionModel, $rangeStart, $rangeEnd);
    $generalSalesRows = array();
    foreach ($generalSalesRowsRaw as $row) {
        $row['filter_campaign'] = trim((string)($row['campaign_group'] ?? '')) !== '' ? (string)$row['campaign_group'] : (string)($row['hotmart_utm_campaign'] ?? '');
        $row['filter_adset'] = trim((string)($row['campaign_name'] ?? ''));
        $row['filter_product'] = trim((string)($row['product_name'] ?? ''));
        if (!campaign_matches_selected($row['filter_campaign'], $campaignFilters)) {
            continue;
        }
        if ($adsetFilter !== '' && (string)$row['filter_adset'] !== $adsetFilter) {
            continue;
        }
        if ($productFilter !== '' && (string)$row['filter_product'] !== $productFilter) {
            continue;
        }
        $generalSalesRows[] = $row;
    }
    foreach ($generalSalesRows as $row) {
        if (empty($row['is_attributed']) && empty($row['is_no_attribution'])) {
            $manualPendingRows[] = $row;
        }
    }

    $allSalesCards = array('sales'=>0,'revenue'=>0.0,'roas'=>0.0,'cac'=>0.0,'cpm'=>0.0,'cpc'=>0.0,'frequency'=>0.0);
    $metaSpendTotal = (float)($attrCards['spend'] ?? 0);
    $metaSpendTotalReal = (float)($attrCards['spend_real'] ?? $metaSpendTotal);
    foreach ($generalSalesRows as $row) {
        $allSalesCards['sales']++;
        $allSalesCards['revenue'] += (float)($row['producer_net'] ?? 0);
    }
    $allSalesCards['roas'] = $metaSpendTotal > 0 ? $allSalesCards['revenue'] / $metaSpendTotal : 0.0;
    $allSalesCards['cac'] = $allSalesCards['sales'] > 0 ? $metaSpendTotalReal / $allSalesCards['sales'] : 0.0;
    $allSalesCards['cpm'] = (float)($attrCards['cpm'] ?? 0);
    $allSalesCards['cpc'] = (float)($attrCards['cpc'] ?? 0);
    $allSalesCards['frequency'] = (float)($attrCards['frequency'] ?? 0);

    $attribVsNon = array('labels'=>array('Atribuídas','Não atribuídas'),'counts'=>array(0,0),'revenue'=>array(0.0,0.0));
    $paymentAgg = array();
    $installmentAgg = array();
    foreach ($generalSalesRows as $row) {
        if (empty($row['is_approved'])) { continue; }
        $bucket = $row['is_attributed'] ? 0 : 1;
        $attribVsNon['counts'][$bucket] += 1;
        $attribVsNon['revenue'][$bucket] += (float)$row['effective_revenue'];
        $pay = $row['payment_type_label'];
        if (!isset($paymentAgg[$pay])) { $paymentAgg[$pay] = array('count'=>0,'revenue'=>0.0); }
        $paymentAgg[$pay]['count'] += 1;
        $paymentAgg[$pay]['revenue'] += (float)$row['effective_revenue'];
        $inst = trim((string)($row['installments_1'] ?? $row['installments_2'] ?? ''));
        if ($inst === '') { $inst = 'Não identificado'; }
        if (!isset($installmentAgg[$inst])) { $installmentAgg[$inst] = array('count'=>0,'revenue'=>0.0); }
        $installmentAgg[$inst]['count'] += 1;
        $installmentAgg[$inst]['revenue'] += (float)$row['effective_revenue'];
    }
    $paymentChart = array('labels'=>array(), 'counts'=>array(), 'revenue'=>array());
    foreach ($paymentAgg as $label => $agg) {
        $paymentChart['labels'][] = $label;
        $paymentChart['counts'][] = $agg['count'];
        $paymentChart['revenue'][] = round($agg['revenue'], 2);
    }
    $installmentChart = array('labels'=>array(), 'counts'=>array(), 'revenue'=>array());
    $order = array_map('strval', range(1,12));
    $orderedInstallments = array();
    foreach ($order as $i) { if (isset($installmentAgg[$i])) { $orderedInstallments[$i] = $installmentAgg[$i]; } }
    foreach ($installmentAgg as $k => $v) { if (!isset($orderedInstallments[$k])) { $orderedInstallments[$k] = $v; } }
    foreach ($orderedInstallments as $label => $agg) {
        $installmentChart['labels'][] = $label;
        $installmentChart['counts'][] = $agg['count'];
        $installmentChart['revenue'][] = round($agg['revenue'], 2);
    }

    $nonCompletedRows = fetch_non_completed_event_rows($pdo, $rangeStart, $rangeEnd);
    $nonCompletedChart = array('labels'=>array(), 'counts'=>array(), 'revenue'=>array());
    $eventAgg = array();
    foreach ($nonCompletedRows as $row) {
        $rowCampaign = trim((string)($row['utm_campaign'] ?? ''));
        if (!campaign_matches_selected($rowCampaign, $campaignFilters)) { continue; }
        if ($productFilter !== '' && trim((string)($row['product_name'] ?? '')) !== $productFilter) { continue; }
        $label = non_completed_event_label($row['event_name'] ?? '');
        if (!isset($eventAgg[$label])) { $eventAgg[$label] = array('count'=>0,'revenue'=>0.0); }
        $eventAgg[$label]['count'] += 1;
        $eventAgg[$label]['revenue'] += sale_revenue_value($row);
    }
    foreach ($eventAgg as $label => $agg) {
        $nonCompletedChart['labels'][] = $label;
        $nonCompletedChart['counts'][] = $agg['count'];
        $nonCompletedChart['revenue'][] = round($agg['revenue'], 2);
    }

    $delayBuckets = array();
    for ($i = 1; $i <= 60; $i++) { $delayBuckets[(string)$i] = 0; }
    $delayBuckets['+60'] = 0;
    $userIdsForDelay = array();
    foreach ($generalSalesRows as $row) {
        if (!empty($row['is_approved'])) {
            $uid = (int)($row['sale_matched_user_id'] ?: $row['hotmart_matched_user_id']);
            if ($uid > 0) { $userIdsForDelay[] = $uid; }
        }
    }
    $delayUserMap = fetch_source_users_by_ids(source_db(), $userIdsForDelay);
    foreach ($generalSalesRows as $row) {
        if (empty($row['is_approved'])) { continue; }
        $uid = (int)($row['sale_matched_user_id'] ?: $row['hotmart_matched_user_id']);
        if ($uid <= 0 || empty($delayUserMap[$uid]['created_at']) || empty($row['effective_date'])) { continue; }
        $leadTs = strtotime((string)$delayUserMap[$uid]['created_at']);
        $saleTs = strtotime((string)$row['effective_date']);
        if ($leadTs === false || $saleTs === false || $saleTs < $leadTs) { continue; }
        $days = (int)floor(($saleTs - $leadTs) / 86400);
        if ($days < 1) { $days = 1; }
        if ($days > 60) { $delayBuckets['+60']++; } else { $delayBuckets[(string)$days]++; }
    }
    $delayChart = array('labels'=>array_keys($delayBuckets), 'counts'=>array_values($delayBuckets));

    if (isset($_GET['download_sales']) && $_GET['download_sales'] === '1') {
        $limitReq = strtolower((string)($_GET['download_limit'] ?? '100'));
        $limitMap = array('100'=>100,'200'=>200,'500'=>500,'1000'=>1000,'all'=>0);
        $limitVal = $limitMap[$limitReq] ?? 100;
        $exportRows = $limitVal > 0 ? array_slice($salesRowsAll, 0, $limitVal) : $salesRowsAll;
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="vendas_dashboard_' . date('Ymd_His') . '.csv"');
        echo "HP;Nome;Email;Data;Valor;Campanha Facebook;Conjunto;Anuncio;Attr Source;Attr Grupo;Attr Conjunto;Attr Anuncio;Attr Term;Lead Source;Lead Medium;Lead Campaign;Lead Term;Lead Content\n";
        foreach ($exportRows as $row) {
            $line = array(
                $row['transaction_code'] ?? '',
                $row['buyer_name'] ?? '',
                $row['buyer_email'] ?? '',
                $row['sale_date'] ?? '',
                number_format((float)($row['revenue_value'] ?? 0), 2, ',', '.'),
                $row['campaign_group'] ?? '',
                $row['campaign_name'] ?? '',
                $row['ad_name'] ?? '',
                $row['attributed_utm_source'] ?? '',
                $row['attributed_utm_campaign_group'] ?? '',
                $row['attributed_utm_campaign_name'] ?? '',
                $row['attributed_utm_ad_name'] ?? '',
                $row['attributed_utm_term'] ?? '',
                $row['lead_utm_source'] ?? '',
                $row['lead_utm_medium'] ?? '',
                $row['lead_utm_campaign'] ?? '',
                $row['lead_utm_term'] ?? '',
                $row['lead_utm_content'] ?? '',
            );
            echo implode(';', array_map(function ($v) { return str_replace(array("\r", "\n", ';'), array(' ', ' ', ','), (string)$v); }, $line)) . "\n";
        }
        exit;
    }

$topNested = sort_nested_groups($topNested, $sort, $dir);
}

$metaChart = array(
    'labels' => array_map(function ($row) { return $row['report_date']; }, $metaDaily),
    'spend' => array_map(function ($row) { return round((float)($row['spend_real'] ?? $row['spend']), 2); }, $metaDaily),
    'leads' => array_map(function ($row) { return (int)$row['leads']; }, $metaDaily),
    'cpm' => array_map(function ($row) { return round((float)$row['cpm'], 2); }, $metaDaily),
    'frequency' => array_map(function ($row) { return round((float)$row['frequency'], 2); }, $metaDaily),
);
$attrChart = array(
    'labels' => array_map(function ($row) { return $row['label']; }, $attrDaily),
    'spend' => array_map(function ($row) { return round((float)($row['spend_real'] ?? $row['spend']), 2); }, $attrDaily),
    'sales' => array_map(function ($row) { return (int)$row['sales']; }, $attrDaily),
    'revenue' => array_map(function ($row) { return round((float)$row['revenue'], 2); }, $attrDaily),
    'cac' => array_map(function ($row) { return $row['sales'] > 0 ? round((float)($row['spend_real'] ?? $row['spend']) / $row['sales'], 2) : 0; }, $attrDaily),
    'roas' => array_map(function ($row) { return $row['spend'] > 0 ? round((float)$row['revenue'] / $row['spend'], 2) : 0; }, $attrDaily),
    'cpm' => array_map(function ($row) { return $row['cpm_rows'] > 0 ? round((float)$row['cpm_sum'] / $row['cpm_rows'], 2) : 0; }, $attrDaily),
    'frequency' => array_map(function ($row) { return $row['frequency_rows'] > 0 ? round((float)$row['frequency_sum'] / $row['frequency_rows'], 2) : 0; }, $attrDaily),
    'cpc' => array_map(function ($row) { return $row['cpc_rows'] > 0 ? round((float)$row['cpc_sum'] / $row['cpc_rows'], 2) : 0; }, $attrDaily),
);
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meta Ads Manager Pro - Painel de Atribuição</title>
    <script>
        (function () {
            try {
                if (localStorage.getItem('meta_admin_theme') === 'light') {
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            } catch (e) {}
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/css/app-pro.css">
    <style>
        html { font-size: 87.5%; }
        .child-row.hidden { display: none !important; }
        .sync-extra.hidden { display: none !important; }
        .hier-table .indent-1 { padding-left: 28px !important; }
        .hier-table .indent-2 { padding-left: 48px !important; }
        .toggle-row {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1);
            color: #94a3b8;
            border-radius: 6px;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            cursor: pointer;
            margin-right: 6px;
            transition: all 0.2s;
        }
        .toggle-row:hover { background: rgba(99,102,241,0.3); color: #fff; }
        .summary-row td { background: rgba(99,102,241,0.08) !important; font-weight: 700; color: #6366f1; border-top: 2px solid rgba(99,102,241,0.3); border-bottom: 2px solid rgba(99,102,241,0.3); }
        .trend.positive { color: #10b981; font-weight: 600; }
        .trend.negative { color: #f43f5e; font-weight: 600; }
        .trend.neutral { color: #94a3b8; }
        .multi-select { position: relative; }
        .multi-select-menu {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            margin-top: 6px;
            background: #111726;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 50;
            padding: 8px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        }
        .multi-select-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 13px;
            color: #cbd5e1;
            cursor: pointer;
        }
        .multi-select-option:hover { background: rgba(255,255,255,0.06); color: #fff; }
    </style>
</head>
<body class="bg-[#090d16] text-slate-100 min-h-screen">
<div class="app-shell" data-app-shell>
    <!-- Sidebar -->
    <aside class="app-sidebar" data-sidebar>
        <div class="p-5 flex items-center justify-between border-b border-white/10">
            <div class="flex items-center gap-3 brand-title">
                <div class="w-10 h-10 rounded-xl bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400 shadow-[0_0_15px_rgba(99,102,241,0.25)]">
                    <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
                </div>
                <div>
                    <h2 class="font-extrabold text-white text-base leading-tight">Meta Ads</h2>
                    <span class="text-[10px] uppercase tracking-wider font-semibold px-2 py-0.5 rounded bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">PRO v2.0</span>
                </div>
            </div>
            <button type="button" class="w-8 h-8 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center text-slate-400 hover:text-white" data-sidebar-toggle>
                <i data-lucide="menu" class="w-4 h-4"></i>
            </button>
        </div>

        <nav class="p-3 space-y-1.5 flex-1">
            <button type="button" class="nav-item group active" data-section-target="dashboard">
                <div class="w-8 h-8 rounded-lg bg-white/5 flex items-center justify-center text-slate-400 group-hover:bg-indigo-600/20 group-hover:text-indigo-400 transition-colors">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                </div>
                <span class="sidebar-label">Indicadores</span>
            </button>

            <button type="button" class="nav-item group" data-section-target="campaigns">
                <div class="w-8 h-8 rounded-lg bg-white/5 flex items-center justify-center text-slate-400 group-hover:bg-indigo-600/20 group-hover:text-indigo-400 transition-colors">
                    <i data-lucide="target" class="w-4 h-4"></i>
                </div>
                <span class="sidebar-label">Campanhas & Vendas</span>
            </button>

            <button type="button" class="nav-item group" data-section-target="settings">
                <div class="w-8 h-8 rounded-lg bg-white/5 flex items-center justify-center text-slate-400 group-hover:bg-indigo-600/20 group-hover:text-indigo-400 transition-colors">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                </div>
                <span class="sidebar-label">Configurações API</span>
            </button>
        </nav>

        <div class="p-4 border-t border-white/10 sidebar-footer-info">
            <div class="flex items-center gap-3 p-3 rounded-xl bg-slate-900/60 border border-white/5">
                <div class="pulse-dot"></div>
                <div class="text-xs">
                    <p class="font-medium text-slate-200"><?= $integration ? h((string)($integration['name'] ?? 'Meta Principal')) : 'Sem integração' ?></p>
                    <p class="text-slate-500 text-[11px]"><?= $integration ? 'Status: ' . h((string)($integration['status'] ?? 'ativa')) : 'Desconectado' ?></p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="app-main">
        <!-- Top App Header -->
        <header class="app-header">
            <div class="flex items-center gap-4">
                <button type="button" class="md:hidden w-9 h-9 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center text-slate-300" data-sidebar-toggle>
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <div>
                    <h1 class="text-lg font-bold text-white leading-tight">Dashboard Analítico & Atribuição</h1>
                    <p class="text-slate-400 text-xs hidden sm:block">Inteligência de Tráfego Pago Meta + Hotmart Live</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="button" class="btn-pro btn-secondary btn-sm" data-theme-toggle title="Alternar tema claro/escuro">
                    <i data-lucide="sun-moon" class="w-4 h-4"></i>
                </button>
                <a href="upload_hotmart.php" class="btn-pro btn-emerald btn-sm">
                    <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
                    <span class="hidden sm:inline">Importar CSV Hotmart</span>
                </a>

                <a href="../logout.php" class="btn-pro btn-secondary btn-sm" title="Sair da Conta">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                </a>
            </div>
        </header>

        <!-- Main Body Workspace -->
        <div class="p-4 md:p-6 max-w-[1700px] mx-auto w-full space-y-6">
            
            <div class="app-section hidden" data-app-section="settings">
            <!-- SECTION 1: CONFIGURAÇÕES DE INTEGRAÇÃO (data-app-group="settings") -->
            <section class="glass-card space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-white/10">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-600/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                            <i data-lucide="sliders" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-white">Configurar Integração Meta Graph API</h2>
                            <p class="text-xs text-slate-400">Credenciais de acesso, conta de anúncios e sincronizações em segundo plano</p>
                        </div>
                    </div>
                </div>

                <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Business Managers (BMs)</span>
                            <div class="kpi-icon-box"><i data-lucide="layers" class="w-4 h-4"></i></div>
                        </div>
                        <div class="kpi-value"><?= count($integrations) ?></div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Contas / BMs Ativas</span>
                            <div class="kpi-icon-box emerald"><i data-lucide="check-circle-2" class="w-4 h-4"></i></div>
                        </div>
                        <div class="kpi-value"><?= count($activeIntegrations) ?></div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Campanhas Sincronizadas</span>
                            <div class="kpi-icon-box cyan"><i data-lucide="target" class="w-4 h-4"></i></div>
                        </div>
                        <div class="kpi-value"><?= $integrationSyncedCampaignsCount ?></div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Último Sincronismo Geral</span>
                            <div class="kpi-icon-box amber"><i data-lucide="clock" class="w-4 h-4"></i></div>
                        </div>
                        <div class="kpi-value" style="font-size: 14px;"><?= h((string)($integrationLastSyncOverall ?: 'nunca')) ?></div>
                    </div>
                </div>

                <div class="integration-list">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-bold text-white">Integrações Meta Ads (Graph API)</h3>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="btn-pro btn-primary btn-sm" data-sync-all-integrations>
                                <i data-lucide="zap" class="w-4 h-4"></i>
                                <span>Sincronizar Todas Agora</span>
                            </button>
                            <button type="button" class="btn-pro btn-secondary btn-sm" data-new-integration>
                                <i data-lucide="plus" class="w-4 h-4"></i>
                                <span>Nova BM / Conta Meta</span>
                            </button>
                        </div>
                    </div>

                    <div id="integration-editor" class="integration-editor hidden">
                        <div class="flex items-center justify-between pb-3 border-b border-white/10 mb-4">
                            <h4 id="integration-editor-title" class="text-sm font-bold text-white flex items-center gap-2">
                                <i data-lucide="pencil" class="w-4 h-4 text-indigo-400"></i>
                                <span>Nova Integração Meta Ads</span>
                            </h4>
                            <button type="button" id="integration-editor-cancel" class="btn-pro btn-secondary btn-sm">
                                <span>Cancelar</span>
                            </button>
                        </div>
                        <form id="integration-form" class="space-y-6">
                            <input type="hidden" name="id" value="">

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div class="form-group">
                                    <label class="form-label">Nome da BM / Conta</label>
                                    <input type="text" name="name" placeholder="Ex.: BM - Dólar" required class="pro-input">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">ID da Conta de Anúncios (act_...)</label>
                                    <input type="text" name="ad_account_id" placeholder="act_123456789" required class="pro-input font-mono">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Meta App ID (opcional)</label>
                                    <input type="text" name="app_id" placeholder="123456789" class="pro-input font-mono">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Meta App Secret (opcional)</label>
                                    <input type="text" name="app_secret" placeholder="App Secret" class="pro-input font-mono" autocomplete="off">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Meta Access Token (Graph API)</label>
                                <textarea name="access_token" rows="3" placeholder="Manter token atual (deixe em branco para não alterar)" class="pro-textarea font-mono text-xs" autocomplete="off"></textarea>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                                <div class="form-group">
                                    <label class="form-label">Moeda da Conta de Anúncios</label>
                                    <select name="currency_code" class="pro-select" data-currency-select>
                                        <option value="BRL">BRL (Real)</option>
                                        <option value="USD">USD (Dólar - Conta Simple/Internacional)</option>
                                    </select>
                                </div>

                                <div class="form-group" data-currency-field>
                                    <label class="form-label">Spread da Conta % (margem simples/IOF)</label>
                                    <input type="number" name="currency_spread_percent" min="0" step="0.01" value="0" class="pro-input">
                                </div>

                                <div class="form-group" data-currency-field>
                                    <label class="form-label">Cotação USD Manual</label>
                                    <input type="number" name="manual_exchange_rate" min="0" step="0.0001" placeholder="Vazio = cotação automática" class="pro-input">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Intervalo Sync (min)</label>
                                    <input type="number" name="sync_interval_minutes" min="5" value="30" class="pro-input">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Status da Integração</label>
                                    <select name="status" class="pro-select">
                                        <option value="active">Ativa (Sincronizando)</option>
                                        <option value="inactive">Inativa (Pausada)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="form-group">
                                    <label class="form-label">Fuso Horário</label>
                                    <input type="text" name="timezone" value="America/Sao_Paulo" class="pro-input">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Histórico Meta (dias)</label>
                                    <input type="number" id="meta-history-days" min="1" max="180" value="30" class="pro-input">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Histórico Atribuição (dias)</label>
                                    <input type="number" id="attr-history-days" min="1" max="365" value="90" class="pro-input">
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 pt-4 border-t border-white/10">
                                <button type="submit" class="btn-pro btn-primary">
                                    <i data-lucide="save" class="w-4 h-4"></i>
                                    <span>Salvar Alterações</span>
                                </button>
                                <button type="button" id="btn-test" class="btn-pro btn-secondary">
                                    <i data-lucide="wifi" class="w-4 h-4"></i>
                                    <span>Testar Conexão Meta</span>
                                </button>
                                <button type="button" id="btn-sync" class="btn-pro btn-secondary">
                                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                    <span>Sincronizar Meta (3d)</span>
                                </button>
                                <button type="button" id="btn-attr-sync" class="btn-pro btn-secondary">
                                    <i data-lucide="git-merge" class="w-4 h-4"></i>
                                    <span>Sincronizar Atribuição (3d)</span>
                                </button>
                                <button type="button" id="btn-sync-all" class="btn-pro btn-emerald">
                                    <i data-lucide="zap" class="w-4 h-4"></i>
                                    <span>Sincronizar Tudo (3d)</span>
                                </button>
                                <button type="button" id="btn-sync-history" class="btn-pro btn-secondary text-xs">
                                    Carga Histórica Meta
                                </button>
                                <button type="button" id="btn-attr-history" class="btn-pro btn-secondary text-xs">
                                    Carga Histórica Atribuição
                                </button>
                            </div>
                        </form>
                        <div id="feedback" class="hidden"></div>
                    </div>

                    <div class="space-y-3 mt-3">
                        <?php if (!$integrations): ?>
                            <div class="text-sm text-slate-400 border border-white/10 rounded-lg p-4">Nenhuma integração cadastrada.</div>
                        <?php else: foreach ($integrations as $item): ?>
                            <?php
                                $itemCurrency = (string)($item['currency_code'] ?? 'BRL');
                                $itemSpread = (float)($item['currency_spread_percent'] ?? 0);
                                $itemRate = (float)($item['manual_exchange_rate'] ?? 0);
                            ?>
                            <div class="integration-row">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <strong class="text-white"><?= h((string)($item['name'] ?? 'Meta Principal')) ?></strong>
                                        <span class="badge <?= (($item['status'] ?? 'active') === 'active') ? 'badge-emerald' : 'badge-rose' ?>"><?= (($item['status'] ?? 'active') === 'active') ? 'Ativa' : 'Inativa' ?></span>
                                        <span class="badge badge-indigo"><?= h((string)($item['ad_account_id'] ?? '')) ?></span>
                                        <span class="badge badge-amber"><?= h($itemCurrency) ?><?= $itemCurrency === 'USD' ? ' + spread ' . number_format($itemSpread, 2, ',', '.') . '%' : '' ?></span>
                                    </div>
                                    <p class="text-xs text-slate-400 mt-1">
                                        Intervalo: <?= (int)($item['sync_interval_minutes'] ?? 30) ?> min |
                                        Último sincronismo: <?= h((string)($item['last_success_sync_at'] ?? $item['last_sync_at'] ?? 'nunca')) ?>
                                        <?= $itemRate > 0 ? ' | USD: R$ ' . number_format($itemRate, 4, ',', '.') : '' ?>
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" class="btn-pro btn-secondary btn-sm" data-sync-integration="<?= (int)$item['id'] ?>">
                                        <i data-lucide="zap" class="w-4 h-4"></i>
                                        <span>Sincronizar Agora</span>
                                    </button>
                                    <button type="button" class="btn-pro btn-secondary btn-sm" data-edit-integration='<?= h(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                                        <i data-lucide="pencil" class="w-4 h-4"></i>
                                        <span>Editar</span>
                                    </button>
                                    <button type="button" class="btn-pro btn-secondary btn-sm" data-toggle-integration='<?= h(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                                        <i data-lucide="<?= (($item['status'] ?? 'active') === 'active') ? 'pause' : 'play' ?>" class="w-4 h-4"></i>
                                        <span><?= (($item['status'] ?? 'active') === 'active') ? 'Pausar' : 'Ativar' ?></span>
                                    </button>
                                    <button type="button" class="btn-pro btn-danger btn-sm" data-delete-integration="<?= (int)$item['id'] ?>" data-integration-name="<?= h((string)($item['name'] ?? '')) ?>">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        <span>Excluir</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </section>

            </div>

            <div class="app-section" data-app-section="dashboard">
            <!-- SECTION 2: META HOJE - CARDS DE MÉTRICAS -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-white flex items-center gap-2">
                        <i data-lucide="clock" class="w-5 h-5 text-indigo-400"></i>
                        <span>Métricas em Tempo Real na Meta (Hoje)</span>
                    </h2>
                    <span class="badge badge-indigo">Atualização Direta</span>
                </div>

                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Gasto Meta Hoje</span>
                            <div class="kpi-icon-box amber"><i data-lucide="dollar-sign" class="w-5 h-5"></i></div>
                        </div>
                        <div class="kpi-value font-mono">R$ <?= number_format((float)($metaCards['spend_real'] ?? $metaCards['spend'] ?? 0), 2, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Impressões Hoje</span>
                            <div class="kpi-icon-box cyan"><i data-lucide="eye" class="w-5 h-5"></i></div>
                        </div>
                        <div class="kpi-value font-mono"><?= number_format((float)($metaCards['impressions'] ?? 0), 0, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Cliques Hoje</span>
                            <div class="kpi-icon-box emerald"><i data-lucide="mouse-pointer" class="w-5 h-5"></i></div>
                        </div>
                        <div class="kpi-value font-mono"><?= number_format((float)($metaCards['clicks'] ?? 0), 0, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Leads Meta Hoje</span>
                            <div class="kpi-icon-box indigo"><i data-lucide="users" class="w-5 h-5"></i></div>
                        </div>
                        <div class="kpi-value font-mono"><?= number_format((float)($metaCards['leads'] ?? 0), 0, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">CPM Médio Hoje</span>
                            <div class="kpi-icon-box rose"><i data-lucide="bar-chart-2" class="w-5 h-5"></i></div>
                        </div>
                        <div class="kpi-value font-mono">R$ <?= number_format((float)($metaCards['cpm'] ?? 0), 2, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-label">Frequência Hoje</span>
                            <div class="kpi-icon-box amber"><i data-lucide="repeat" class="w-5 h-5"></i></div>
                        </div>
                        <div class="kpi-value font-mono"><?= number_format((float)($metaCards['frequency'] ?? 0), 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: GRÁFICOS META 30 DIAS -->
            <section class="glass-card space-y-4">
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="trending-up" class="w-5 h-5 text-indigo-400"></i>
                    <span>Evolução Meta (Últimos 30 dias)</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5">
                        <h4 class="text-xs font-semibold text-slate-400 mb-3">Investimento Diário (R$)</h4>
                        <div class="h-64"><canvas id="metaSpendChart"></canvas></div>
                    </div>
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5">
                        <h4 class="text-xs font-semibold text-slate-400 mb-3">Custo por Mil Impressões (CPM)</h4>
                        <div class="h-64"><canvas id="metaCpmChart"></canvas></div>
                    </div>
                </div>
            </section>

            <!-- SECTION 4: FILTROS DO DASHBOARD REAL -->
            <section class="glass-card space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-white/10">
                    <h2 class="text-lg font-bold text-white flex items-center gap-2">
                        <i data-lucide="filter" class="w-5 h-5 text-emerald-400"></i>
                        <span>Filtros do Dashboard de Atribuição Real</span>
                    </h2>
                </div>

                <form method="get" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div class="form-group">
                        <label class="form-label">BM / Conta Meta</label>
                        <select name="integration_id" class="pro-select">
                            <option value="0" <?= $selectedIntegrationId === 0 ? 'selected' : '' ?>>Todas as BMs ativas</option>
                            <?php foreach ($integrations as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= $selectedIntegrationId === (int)$item['id'] ? 'selected' : '' ?>>
                                    <?= h((string)($item['name'] ?? 'Integração')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Modelo de Atribuição</label>
                        <select name="model" class="pro-select">
                            <option value="last_touch" <?= $attributionModel === 'last_touch' ? 'selected' : '' ?>>Last Touch (Último clique)</option>
                            <option value="first_touch" <?= $attributionModel === 'first_touch' ? 'selected' : '' ?>>First Touch (Primeiro clique)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Campanha</label>
                        <div class="multi-select" data-multi-select>
                            <button type="button" class="pro-input text-left truncate flex items-center justify-between" data-multi-select-trigger>
                                <span><?= $campaignFilters ? (count($campaignFilters) . ' selecionada(s)') : 'Todas as campanhas' ?></span>
                                <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400"></i>
                            </button>
                            <div class="multi-select-menu hidden" data-multi-select-menu>
                                <label class="multi-select-option">
                                    <input type="checkbox" data-select-all-campaigns <?= !$campaignFilters ? 'checked' : '' ?> class="rounded bg-slate-900 border-white/20 text-indigo-600">
                                    <span>Todas</span>
                                </label>
                                <?php foreach ($campaignOptions as $opt): ?>
                                    <label class="multi-select-option">
                                        <input type="checkbox" name="campaign[]" value="<?= h($opt) ?>" <?= in_array($opt, $campaignFilters, true) ? 'checked' : '' ?> class="rounded bg-slate-900 border-white/20 text-indigo-600">
                                        <span class="truncate"><?= h($opt) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Conjunto</label>
                        <select name="adset" class="pro-select">
                            <option value="">Todos</option>
                            <?php foreach ($adsetOptions as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $adsetFilter === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Produto Hotmart</label>
                        <select name="product" class="pro-select">
                            <option value="">Todos os produtos</option>
                            <?php foreach ($productOptions as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $productFilter === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Data Inicial</label>
                        <input type="date" name="range_start" value="<?= h($rangeStart) ?>" class="pro-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Data Final</label>
                        <input type="date" name="range_end" value="<?= h($rangeEnd) ?>" class="pro-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Eixo Temporal</label>
                        <select name="granularity" class="pro-select">
                            <option value="day" <?= $granularity === 'day' ? 'selected' : '' ?>>Por Dia</option>
                            <option value="week" <?= $granularity === 'week' ? 'selected' : '' ?>>Por Semana</option>
                            <option value="month" <?= $granularity === 'month' ? 'selected' : '' ?>>Por Mês</option>
                            <option value="year" <?= $granularity === 'year' ? 'selected' : '' ?>>Por Ano</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Período Comp. A</label>
                        <input type="number" name="period_a" min="1" value="<?= h((string)$periodA) ?>" class="pro-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Período Comp. B</label>
                        <input type="number" name="period_b" min="1" value="<?= h((string)$periodB) ?>" class="pro-input">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Período Comp. C</label>
                        <input type="number" name="period_c" min="1" value="<?= h((string)$periodC) ?>" class="pro-input">
                    </div>

                    <div class="col-span-full flex items-center justify-end">
                        <button type="submit" class="btn-pro btn-primary">
                            <i data-lucide="filter" class="w-4 h-4"></i>
                            <span>Aplicar Filtros Selecionados</span>
                        </button>
                    </div>
                </form>
            </section>

            <!-- SECTION 5: CARDS DE DESEMPENHO REAL (PERÍODO FILTRADO) -->
            <div class="space-y-4">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i data-lucide="pie-chart" class="w-5 h-5 text-indigo-400"></i>
                    <span>Resultados Reais Atribuídos no Período</span>
                </h2>

                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">Gasto no Período</span><div class="kpi-icon-box amber"><i data-lucide="dollar-sign" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono">R$ <?= number_format($attrCards['spend_real'], 2, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">Leads no Período</span><div class="kpi-icon-box cyan"><i data-lucide="user-plus" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono"><?= number_format($attrCards['leads'], 0, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">Vendas Atribuídas</span><div class="kpi-icon-box emerald"><i data-lucide="shopping-bag" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono"><?= number_format($attrCards['sales'], 0, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">Receita Atribuída</span><div class="kpi-icon-box emerald"><i data-lucide="trending-up" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono text-emerald-400">R$ <?= number_format($attrCards['revenue'], 2, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">ROAS Atribuído</span><div class="kpi-icon-box indigo"><i data-lucide="award" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono <?= $attrCards['roas'] >= 2 ? 'text-emerald-400' : ($attrCards['roas'] >= 1 ? 'text-amber-400' : 'text-rose-400') ?>"><?= number_format($attrCards['roas'], 2, ',', '.') ?>x</div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">CAC Reais</span><div class="kpi-icon-box rose"><i data-lucide="target" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono">R$ <?= number_format($attrCards['cac'], 2, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">CPL Reais</span><div class="kpi-icon-box cyan"><i data-lucide="user-check" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono">R$ <?= number_format($attrCards['cpl'], 2, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">CPM Reais</span><div class="kpi-icon-box amber"><i data-lucide="bar-chart" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono">R$ <?= number_format($attrCards['cpm'], 2, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">CPC Médio</span><div class="kpi-icon-box indigo"><i data-lucide="navigation" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono">R$ <?= number_format($attrCards['cpc'], 2, ',', '.') ?></div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header"><span class="kpi-label">Frequência</span><div class="kpi-icon-box rose"><i data-lucide="repeat" class="w-5 h-5"></i></div></div>
                        <div class="kpi-value font-mono"><?= number_format($attrCards['frequency'], 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>

            <!-- SECTION 6: INDICADORES GERAIS DE VENDAS -->
            <section class="glass-card space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-white/10">
                    <div>
                        <h2 class="text-base font-bold text-white">Consolidado Geral de Vendas Hotmart (Com + Sem Atribuição)</h2>
                        <p class="text-xs text-slate-400">Total absoluto registrado na Hotmart cruzado com investimento total da Meta</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
                    <div class="p-3 bg-slate-900/60 rounded-xl border border-white/5 text-center">
                        <span class="text-slate-400 text-xs block mb-1">Todas Vendas</span>
                        <span class="text-lg font-bold font-mono text-white"><?= number_format($allSalesCards['sales'], 0, ',', '.') ?></span>
                    </div>

                    <div class="p-3 bg-slate-900/60 rounded-xl border border-white/5 text-center">
                        <span class="text-slate-400 text-xs block mb-1">Receita Total</span>
                        <span class="text-lg font-bold font-mono text-emerald-400">R$ <?= number_format($allSalesCards['revenue'], 2, ',', '.') ?></span>
                    </div>

                    <div class="p-3 bg-slate-900/60 rounded-xl border border-white/5 text-center">
                        <span class="text-slate-400 text-xs block mb-1">ROAS Total</span>
                        <span class="text-lg font-bold font-mono text-indigo-400"><?= number_format($allSalesCards['roas'], 2, ',', '.') ?>x</span>
                    </div>

                    <div class="p-3 bg-slate-900/60 rounded-xl border border-white/5 text-center">
                        <span class="text-slate-400 text-xs block mb-1">CAC Total</span>
                        <span class="text-lg font-bold font-mono text-white">R$ <?= number_format($allSalesCards['cac'], 2, ',', '.') ?></span>
                    </div>

                    <div class="p-3 bg-slate-900/60 rounded-xl border border-white/5 text-center">
                        <span class="text-slate-400 text-xs block mb-1">CPM Total</span>
                        <span class="text-lg font-bold font-mono text-white">R$ <?= number_format($allSalesCards['cpm'], 2, ',', '.') ?></span>
                    </div>

                    <div class="p-3 bg-slate-900/60 rounded-xl border border-white/5 text-center">
                        <span class="text-slate-400 text-xs block mb-1">CPC Médio</span>
                        <span class="text-lg font-bold font-mono text-white">R$ <?= number_format($allSalesCards['cpc'], 2, ',', '.') ?></span>
                    </div>

                    <div class="p-3 bg-slate-900/60 rounded-xl border border-white/5 text-center">
                        <span class="text-slate-400 text-xs block mb-1">Frequência</span>
                        <span class="text-lg font-bold font-mono text-white"><?= number_format($allSalesCards['frequency'], 2, ',', '.') ?></span>
                    </div>
                </div>
            </section>

            <!-- SECTION 7: ANÁLISE DE VENDAS E EVENTOS -->
            <section class="glass-card space-y-6">
                <h2 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="bar-chart-3" class="w-5 h-5 text-indigo-400"></i>
                    <span>Análise Detalhada de Vendas, Pagamentos e Funil</span>
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5">
                        <h4 class="text-xs font-semibold text-slate-300 mb-3">Vendas Atribuídas x Não Atribuídas</h4>
                        <div class="h-64"><canvas id="salesAttributionPieChart"></canvas></div>
                    </div>
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5">
                        <h4 class="text-xs font-semibold text-slate-300 mb-3">Formas de Pagamento</h4>
                        <div class="h-64"><canvas id="salesPaymentBarChart"></canvas></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5">
                        <h4 class="text-xs font-semibold text-slate-300 mb-3">Distribuição de Parcelamento</h4>
                        <div class="h-64"><canvas id="salesInstallmentsBarChart"></canvas></div>
                    </div>
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5">
                        <h4 class="text-xs font-semibold text-slate-300 mb-3">Eventos Não Concluídos (Pix/Boleto Gerados)</h4>
                        <div class="h-64"><canvas id="nonCompletedEventsPieChart"></canvas></div>
                    </div>
                </div>

                <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5">
                    <h4 class="text-xs font-semibold text-slate-300 mb-3">Tempo entre Inscrição (Lead) e a Compra (Dias)</h4>
                    <div class="h-64"><canvas id="leadToSaleDelayBarChart"></canvas></div>
                </div>
            </section>

            <!-- SECTION 8: TABELA DE TENDÊNCIAS DE EFICIÊNCIA -->
            <section class="glass-card space-y-4">
                <h2 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="trending-up" class="w-5 h-5 text-emerald-400"></i>
                    <span>Tendências de Eficiência Comparativa (Períodos A, B, C)</span>
                </h2>

                <div class="table-container">
                    <table class="pro-table">
                        <thead>
                            <tr>
                                <th>Comparativo</th>
                                <th>CAC</th>
                                <th>CPL</th>
                                <th>ROAS</th>
                                <th>CPM</th>
                                <th>Frequência</th>
                                <th>CPC</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$attrTrendRows): ?>
                            <tr><td colspan="7" class="text-center text-slate-500 py-6">Sem dados atribuídos para cálculo de tendência.</td></tr>
                        <?php else: foreach ($attrTrendRows as $trend): ?>
                            <tr>
                                <td class="font-semibold text-white"><?= h($trend['label']) ?></td>
                                <?php foreach (array('cac','cpl','roas','cpm','frequency','cpc') as $metric): $m = $trend['metrics'][$metric]; $isMoney = in_array($metric, array('cac','cpl','cpm','cpc'), true); ?>
                                    <td>
                                        <div class="text-xs font-mono"><?= $isMoney ? 'R$ ' . number_format((float)$m['current'], 2, ',', '.') . ' / R$ ' . number_format((float)$m['baseline'], 2, ',', '.') : number_format((float)$m['current'], 2, ',', '.') . ' / ' . number_format((float)$m['baseline'], 2, ',', '.') ?></div>
                                        <span class="trend <?= h($m['direction']) ?> text-xs">
                                            <?= $m['change'] === null ? '—' : (($m['change'] > 0 ? '+' : '') . number_format($m['change'], 1, ',', '.') . '%') ?>
                                        </span>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- SECTION 9: REAL PERÍODO FILTRADO GRÁFICOS -->
            <section class="glass-card space-y-4">
                <h2 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="line-chart" class="w-5 h-5 text-indigo-400"></i>
                    <span>Receita e Eficiência do Período Filtrado</span>
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5"><div class="h-64"><canvas id="attrRevenueChart"></canvas></div></div>
                    <div class="bg-slate-900/60 p-4 rounded-xl border border-white/5"><div class="h-64"><canvas id="attrEfficiencyChart"></canvas></div></div>
                </div>
            </section>

            </div>

            <div class="app-section hidden" data-app-section="campaigns">
            <!-- SECTION 10: TABELA HIERÁRQUICA DE ESTRUTURA -->
            <section class="glass-card space-y-4" data-app-group="campaigns">
                <div class="flex items-center justify-between pb-3 border-b border-white/10">
                    <h2 class="text-base font-bold text-white flex items-center gap-2">
                        <i data-lucide="layers" class="w-5 h-5 text-indigo-400"></i>
                        <span>Desempenho Hierárquico: Campanhas → Conjuntos → Anúncios</span>
                    </h2>
                </div>

                <div class="table-container">
                    <table class="pro-table hier-table">
                        <thead>
                            <tr>
                                <th><?= sort_link($queryParams, 'revenue', 'Estrutura', $sort, $dir) ?></th>
                                <th><?= sort_link($queryParams, 'spend', 'Gasto', $sort, $dir) ?></th>
                                <th><?= sort_link($queryParams, 'leads', 'Leads', $sort, $dir) ?></th>
                                <th><?= sort_link($queryParams, 'sales', 'Vendas', $sort, $dir) ?></th>
                                <th><?= sort_link($queryParams, 'revenue', 'Receita', $sort, $dir) ?></th>
                                <th><?= sort_link($queryParams, 'cpl', 'CPL', $sort, $dir) ?></th>
                                <th><?= sort_link($queryParams, 'cac', 'CAC', $sort, $dir) ?></th>
                                <th><?= sort_link($queryParams, 'roas', 'ROAS', $sort, $dir) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$topNested): ?>
                            <tr><td colspan="8" class="text-center text-slate-500 py-6">Sem dados atribuídos para o filtro atual.</td></tr>
                        <?php else: ?>
                            <tr class="summary-row">
                                <td class="font-bold">Total Exibido</td>
                                <td class="font-mono">R$ <?= number_format($topSummary['spend'], 2, ',', '.') ?></td>
                                <td class="font-mono"><?= number_format($topSummary['leads'], 0, ',', '.') ?></td>
                                <td class="font-mono"><?= number_format($topSummary['sales'], 0, ',', '.') ?></td>
                                <td class="font-mono text-emerald-400">R$ <?= number_format($topSummary['revenue'], 2, ',', '.') ?></td>
                                <td class="font-mono">R$ <?= number_format($topSummary['cpl'], 2, ',', '.') ?></td>
                                <td class="font-mono">R$ <?= number_format($topSummary['cac'], 2, ',', '.') ?></td>
                                <td class="font-mono font-bold text-indigo-400"><?= number_format($topSummary['roas'], 2, ',', '.') ?>x</td>
                            </tr>
                            <?php $campaignIndex = 0; foreach ($topNested as $campaign => $cData): $campaignIndex++; $campaignId = 'c'.$campaignIndex; ?>
                                <tr class="level-campaign font-semibold">
                                    <td>
                                        <button type="button" class="toggle-row" data-target="<?= h($campaignId) ?>">▾</button>
                                        <span class="text-white"><?= h($campaign) ?></span>
                                    </td>
                                    <td class="font-mono">R$ <?= number_format($cData['metrics']['spend'], 2, ',', '.') ?></td>
                                    <td class="font-mono"><?= number_format($cData['metrics']['leads'], 0, ',', '.') ?></td>
                                    <td class="font-mono"><?= number_format($cData['metrics']['sales'], 0, ',', '.') ?></td>
                                    <td class="font-mono text-emerald-400">R$ <?= number_format($cData['metrics']['revenue'], 2, ',', '.') ?></td>
                                    <td class="font-mono">R$ <?= number_format($cData['metrics']['cpl'], 2, ',', '.') ?></td>
                                    <td class="font-mono">R$ <?= number_format($cData['metrics']['cac'], 2, ',', '.') ?></td>
                                    <td class="font-mono">
                                        <span class="badge <?= $cData['metrics']['roas'] >= 2 ? 'badge-emerald' : ($cData['metrics']['roas'] >= 1 ? 'badge-amber' : 'badge-rose') ?>">
                                            <?= number_format($cData['metrics']['roas'], 2, ',', '.') ?>x
                                        </span>
                                    </td>
                                </tr>
                                <?php $adsetIndex = 0; foreach ($cData['children'] as $adset => $aData): $adsetIndex++; $adsetId = $campaignId.'a'.$adsetIndex; ?>
                                    <tr class="level-adset child-row <?= h($campaignId) ?> text-slate-300">
                                        <td class="indent-1">
                                            <button type="button" class="toggle-row" data-target="<?= h($adsetId) ?>">▾</button>
                                            <span><?= h($adset) ?></span>
                                        </td>
                                        <td class="font-mono">R$ <?= number_format($aData['metrics']['spend'], 2, ',', '.') ?></td>
                                        <td class="font-mono"><?= number_format($aData['metrics']['leads'], 0, ',', '.') ?></td>
                                        <td class="font-mono"><?= number_format($aData['metrics']['sales'], 0, ',', '.') ?></td>
                                        <td class="font-mono text-emerald-400">R$ <?= number_format($aData['metrics']['revenue'], 2, ',', '.') ?></td>
                                        <td class="font-mono">R$ <?= number_format($aData['metrics']['cpl'], 2, ',', '.') ?></td>
                                        <td class="font-mono">R$ <?= number_format($aData['metrics']['cac'], 2, ',', '.') ?></td>
                                        <td class="font-mono"><?= number_format($aData['metrics']['roas'], 2, ',', '.') ?>x</td>
                                    </tr>
                                    <?php foreach ($aData['children'] as $ad => $dData): ?>
                                        <tr class="level-ad child-row <?= h($campaignId) ?> <?= h($adsetId) ?> text-slate-400 text-xs">
                                            <td class="indent-2">
                                                <i data-lucide="corner-down-right" class="w-3 h-3 inline-block mr-1 text-slate-500"></i>
                                                <span><?= h($ad) ?></span>
                                            </td>
                                            <td class="font-mono">R$ <?= number_format($dData['metrics']['spend'], 2, ',', '.') ?></td>
                                            <td class="font-mono"><?= number_format($dData['metrics']['leads'], 0, ',', '.') ?></td>
                                            <td class="font-mono"><?= number_format($dData['metrics']['sales'], 0, ',', '.') ?></td>
                                            <td class="font-mono">R$ <?= number_format($dData['metrics']['revenue'], 2, ',', '.') ?></td>
                                            <td class="font-mono">R$ <?= number_format($dData['metrics']['cpl'], 2, ',', '.') ?></td>
                                            <td class="font-mono">R$ <?= number_format($dData['metrics']['cac'], 2, ',', '.') ?></td>
                                            <td class="font-mono"><?= number_format($dData['metrics']['roas'], 2, ',', '.') ?>x</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- SECTION 11: CAMPANHAS META HOJE -->
            <section class="glass-card space-y-4">
                <h2 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="play-circle" class="w-5 h-5 text-indigo-400"></i>
                    <span>Campanhas Ativas na Meta Hoje</span>
                </h2>

                <div class="table-container">
                    <table class="pro-table">
                        <thead>
                            <tr>
                                <th>Campanha</th>
                                <th>Gasto</th>
                                <th>Impressões</th>
                                <th>Alcance</th>
                                <th>Freq.</th>
                                <th>Cliques</th>
                                <th>CTR</th>
                                <th>CPC</th>
                                <th>CPM</th>
                                <th>Leads</th>
                                <th>Compras Meta</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$metaCampaignRows): ?>
                            <tr><td colspan="11" class="text-center text-slate-500 py-6">Sem campanhas ativas capturadas hoje.</td></tr>
                        <?php else: foreach ($metaCampaignRows as $row): ?>
                            <tr>
                                <td class="font-medium text-white"><?= h((string)$row['campaign_name']) ?></td>
                                <td class="font-mono font-semibold text-amber-400">R$ <?= number_format((float)$row['spend'], 2, ',', '.') ?></td>
                                <td class="font-mono"><?= number_format((float)$row['impressions'], 0, ',', '.') ?></td>
                                <td class="font-mono"><?= number_format((float)$row['reach'], 0, ',', '.') ?></td>
                                <td class="font-mono"><?= number_format((float)$row['frequency'], 2, ',', '.') ?></td>
                                <td class="font-mono"><?= number_format((float)$row['clicks'], 0, ',', '.') ?></td>
                                <td class="font-mono"><?= number_format((float)$row['ctr'], 2, ',', '.') ?>%</td>
                                <td class="font-mono">R$ <?= number_format((float)$row['cpc'], 2, ',', '.') ?></td>
                                <td class="font-mono">R$ <?= number_format((float)$row['cpm'], 2, ',', '.') ?></td>
                                <td class="font-mono text-indigo-400 font-semibold"><?= number_format((float)$row['leads'], 0, ',', '.') ?></td>
                                <td class="font-mono text-emerald-400 font-semibold"><?= number_format((float)$row['purchases'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- SECTION 12: ÚLTIMAS VENDAS ATRIBUÍDAS -->
            <section class="glass-card space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-white/10">
                    <h2 class="text-base font-bold text-white flex items-center gap-2">
                        <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-400"></i>
                        <span>Últimas Vendas Atribuídas</span>
                    </h2>

                    <div class="flex items-center gap-3">
                        <form method="get" class="flex items-center gap-2 text-xs">
                            <?php foreach ($_GET as $k => $v): if (in_array($k, array('sales_per_page','sales_page','download_sales','download_limit'), true)) continue; if (is_array($v)) { foreach ($v as $vv) { ?><input type="hidden" name="<?= h($k) ?>[]" value="<?= h($vv) ?>"><?php } } else { ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php } endforeach; ?>
                            <span class="text-slate-400">Exibir:</span>
                            <select name="sales_per_page" onchange="this.form.submit()" class="pro-select text-xs py-1 px-2">
                                <?php foreach ($salesPerPageOptions as $opt): ?><option value="<?= $opt ?>" <?= $salesPerPage === $opt ? 'selected' : '' ?>><?= $opt ?></option><?php endforeach; ?>
                            </select>
                            <input type="hidden" name="sales_page" value="1">
                        </form>

                        <form method="get" class="flex items-center gap-2 text-xs">
                            <?php foreach ($_GET as $k => $v): if (in_array($k, array('download_sales','download_limit'), true)) continue; if (is_array($v)) { foreach ($v as $vv) { ?><input type="hidden" name="<?= h($k) ?>[]" value="<?= h($vv) ?>"><?php } } else { ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php } endforeach; ?>
                            <input type="hidden" name="download_sales" value="1">
                            <select name="download_limit" class="pro-select text-xs py-1 px-2">
                                <option value="100">100</option>
                                <option value="500">500</option>
                                <option value="all">Todas</option>
                            </select>
                            <button type="submit" class="btn-pro btn-secondary btn-sm">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                <span>Exportar</span>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="table-container">
                    <table class="pro-table text-xs">
                        <thead>
                            <tr>
                                <th>Transação HP</th>
                                <th>Comprador</th>
                                <th>E-mail</th>
                                <th>Data</th>
                                <th>Valor</th>
                                <th>Campanha Facebook</th>
                                <th>Conjunto</th>
                                <th>Anúncio</th>
                                <th>UTM Source</th>
                                <th>UTM Campaign</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$salesRowsPage): ?>
                            <tr><td colspan="10" class="text-center text-slate-500 py-6">Nenhuma venda encontrada para os filtros aplicados.</td></tr>
                        <?php else: foreach ($salesRowsPage as $row): ?>
                            <tr>
                                <td class="font-mono text-indigo-400"><?= h((string)($row['transaction_code'] ?? '')) ?></td>
                                <td class="font-medium text-white"><?= h((string)($row['buyer_name'] ?? '')) ?></td>
                                <td class="text-slate-400"><?= h((string)($row['buyer_email'] ?? '')) ?></td>
                                <td class="font-mono text-slate-400"><?= h((string)($row['sale_date'] ?? '')) ?></td>
                                <td class="font-mono font-semibold text-emerald-400">R$ <?= number_format((float)($row['revenue_value'] ?? 0), 2, ',', '.') ?></td>
                                <td><span class="badge badge-indigo"><?= h((string)($row['campaign_group'] ?? '')) ?></span></td>
                                <td><?= h((string)($row['campaign_name'] ?? '')) ?></td>
                                <td><?= h((string)($row['ad_name'] ?? '')) ?></td>
                                <td><?= h((string)($row['attributed_utm_source'] ?? '')) ?></td>
                                <td><?= h((string)($row['attributed_utm_campaign_name'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="flex items-center justify-between pt-3 text-xs text-slate-400">
                    <span>Exibindo <?= $salesTotal ? (($salesPage - 1) * $salesPerPage + 1) : 0 ?>–<?= min($salesPage * $salesPerPage, $salesTotal) ?> de <?= $salesTotal ?> registros</span>
                    <div class="flex items-center gap-1">
                        <?php $baseParams = $_GET; $baseParams['sales_per_page'] = $salesPerPage; for ($p=1; $p<=$salesTotalPages; $p++): if ($p > 7 && abs($p - $salesPage) > 2 && $p !== 1 && $p !== $salesTotalPages) continue; $baseParams['sales_page'] = $p; ?>
                            <a class="px-2.5 py-1 rounded-md border border-white/10 font-mono <?= $p === $salesPage ? 'bg-indigo-600 text-white border-indigo-500' : 'bg-slate-900 text-slate-400 hover:text-white' ?>" href="?<?= h(http_build_query($baseParams)) ?>"><?= $p ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            </section>

            <!-- SECTION 13: VENDAS SEM ATRIBUIÇÃO (ATRIBUIÇÃO MANUAL) -->
            <section class="glass-card space-y-4">
                <div class="pb-3 border-b border-white/10">
                    <h2 class="text-base font-bold text-white flex items-center gap-2">
                        <i data-lucide="help-circle" class="w-5 h-5 text-amber-400"></i>
                        <span>Vendas Sem Atribuição (Reconciliação Manual)</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">Associe manualmente as vendas pendentes aos anúncios da Meta Ads</p>
                </div>

                <div class="table-container">
                    <table class="pro-table text-xs">
                        <thead>
                            <tr>
                                <th>Transação</th>
                                <th>Comprador / Email</th>
                                <th>Data</th>
                                <th>Valor</th>
                                <th>Produto</th>
                                <th>UTM Lead Source</th>
                                <th>Causa Pendência</th>
                                <th>Campanha Manual</th>
                                <th>Conjunto Manual</th>
                                <th>Anúncio Manual</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$manualPendingRows): ?>
                            <tr><td colspan="11" class="text-center text-slate-500 py-6">Nenhuma venda pendente de atribuição.</td></tr>
                        <?php else: foreach ($manualPendingRows as $row): ?>
                            <tr data-manual-row
                                   data-transaction-code="<?= h((string)($row['transaction_code'] ?? '')) ?>"
                                   data-source-user-id="<?= h((string)($row['source_user_id'] ?? '')) ?>"
                                   data-lead-utm-source="<?= h((string)($row['lead_utm_source'] ?? '')) ?>"
                                   data-lead-utm-medium="<?= h((string)($row['lead_utm_medium'] ?? '')) ?>"
                                   data-lead-utm-campaign="<?= h((string)($row['lead_utm_campaign'] ?? '')) ?>"
                                   data-lead-utm-term="<?= h((string)($row['lead_utm_term'] ?? '')) ?>"
                                   data-lead-utm-content="<?= h((string)($row['lead_utm_content'] ?? '')) ?>">
                                <td class="font-mono text-amber-400"><?= h((string)($row['transaction_code'] ?? '')) ?></td>
                                <td>
                                    <div class="font-medium text-white"><?= h((string)($row['buyer_name'] ?? '')) ?></div>
                                    <div class="text-slate-500 text-[11px]"><?= h((string)($row['buyer_email'] ?? '')) ?></div>
                                </td>
                                <td class="font-mono text-slate-400"><?= h((string)($row['effective_date'] ?? $row['transaction_date'] ?? '')) ?></td>
                                <td class="font-mono text-emerald-400">R$ <?= number_format((float)($row['effective_revenue'] ?? $row['producer_net'] ?? 0), 2, ',', '.') ?></td>
                                <td><?= h((string)($row['product_name'] ?? '')) ?></td>
                                <td><span class="badge badge-amber"><?= h((string)($row['lead_utm_source'] ?? 'N/D')) ?></span></td>
                                <td class="text-slate-400"><?= h((string)($row['pending_reason'] ?? '')) ?></td>
                                <td>
                                    <select class="pro-select text-xs py-1" data-manual-campaign>
                                        <option value="">Selecione</option>
                                        <option value="__NO_ATTRIB__">Não atribuir</option>
                                        <?php foreach (array_keys($metaHierarchy) as $campaignName): ?>
                                            <option value="<?= h($campaignName) ?>"><?= h($campaignName) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><select class="pro-select text-xs py-1" data-manual-adset disabled><option value="">Selecione</option></select></td>
                                <td><select class="pro-select text-xs py-1" data-manual-ad disabled><option value="">Selecione</option></select></td>
                                <td>
                                    <button type="button" class="btn-pro btn-emerald btn-sm" data-save-manual>Salvar</button>
                                    <div class="text-[10px] mt-1" data-manual-feedback></div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            </div>

            <div class="app-section hidden" data-app-section="settings">
            <!-- SECTION 14 & 15: HISTÓRICO DE LOGS DE SINCRONIZAÇÃO -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <section class="glass-card space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                            <i data-lucide="history" class="w-4 h-4 text-indigo-400"></i>
                            <span>Logs - Sincronização Atribuição</span>
                        </h3>
                    </div>
                    <div class="table-container">
                        <table class="pro-table text-xs">
                            <thead><tr><th>ID</th><th>Tipo</th><th>Status</th><th>Horário</th></tr></thead>
                            <tbody>
                            <?php if (!$recentAttrRuns): ?>
                                <tr><td colspan="4" class="text-center text-slate-500 py-3">Sem registros.</td></tr>
                            <?php else: foreach (array_slice($recentAttrRuns, 0, 5) as $run): ?>
                                <tr>
                                    <td class="font-mono text-slate-400">#<?= (int)$run['id'] ?></td>
                                    <td><?= h((string)$run['run_type']) ?></td>
                                    <td><span class="badge <?= $run['status'] === 'completed' ? 'badge-emerald' : 'badge-rose' ?>"><?= h((string)$run['status']) ?></span></td>
                                    <td class="font-mono text-slate-400"><?= h((string)$run['started_at']) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="glass-card space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                            <i data-lucide="activity" class="w-4 h-4 text-indigo-400"></i>
                            <span>Logs - Sincronização Meta Graph API</span>
                        </h3>
                    </div>
                    <div class="table-container">
                        <table class="pro-table text-xs">
                            <thead><tr><th>ID</th><th>Escopo</th><th>Status</th><th>Linhas</th></tr></thead>
                            <tbody>
                            <?php if (!$recentRuns): ?>
                                <tr><td colspan="4" class="text-center text-slate-500 py-3">Sem registros.</td></tr>
                            <?php else: foreach (array_slice($recentRuns, 0, 5) as $run): ?>
                                <tr>
                                    <td class="font-mono text-slate-400">#<?= (int)$run['id'] ?></td>
                                    <td><?= h((string)$run['scope']) ?></td>
                                    <td><span class="badge <?= $run['status'] === 'completed' ? 'badge-emerald' : 'badge-rose' ?>"><?= h((string)$run['status']) ?></span></td>
                                    <td class="font-mono text-indigo-400"><?= (int)$run['rows_upserted'] ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            </div>

        </div>
    </main>
</div>

<!-- Modal Atribuição Manual Popup -->
<div id="modal-manual-attribution" class="modal-overlay">
    <div class="modal-container space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-white/10">
            <h3 class="text-base font-bold text-white">Atribuição Manual de Venda</h3>
            <button type="button" id="modal-close-btn" class="text-slate-400 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
        </div>
        <p id="modal-sale-info" class="text-xs text-slate-400 font-mono"></p>
        
        <form id="manual-attribution-form" class="space-y-4">
            <input type="hidden" name="sale_id" value="">
            <div class="form-group">
                <label class="form-label">Campanha Meta</label>
                <select name="campaign" class="pro-select" required>
                    <option value="">Selecione a campanha</option>
                    <?php foreach (array_keys($metaHierarchy) as $campaignName): ?>
                        <option value="<?= h($campaignName) ?>"><?= h($campaignName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="submit" class="btn-pro btn-emerald">Confirmar Atribuição</button>
            </div>
        </form>
    </div>
</div>

<script>
window.metaChartData = <?= json_encode($metaChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.attrChartData = <?= json_encode($attrChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.salesExtraCharts = <?= json_encode(array('attribVsNon'=>$attribVsNon,'payment'=>$paymentChart,'installments'=>$installmentChart,'nonCompleted'=>$nonCompletedChart,'delay'=>$delayChart), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.metaHierarchyData = <?= json_encode($metaHierarchy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.manualAttributionConfig = <?= json_encode(array('model'=>$attributionModel,'saveUrl'=>'../api/save_manual_attribution.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="../assets/js/app.js"></script>
<script src="../assets/js/app-pro.js"></script>
</body>
</html>
