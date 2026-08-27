<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['meta_admin_logged'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sessão expirada.']);
    exit;
}

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

try {
    $pdo = db();
    $tx = trim((string)($_POST['transaction_code'] ?? ''));
    $model = trim((string)($_POST['model'] ?? 'last_touch'));
    $campaignGroup = trim((string)($_POST['campaign_group'] ?? ''));
    $campaignName = trim((string)($_POST['campaign_name'] ?? ''));
    $adName = trim((string)($_POST['ad_name'] ?? ''));
    $sourceUserId = (int)($_POST['source_user_id'] ?? 0);
    $noAttribution = (int)($_POST['no_attribution'] ?? 0) === 1 || $campaignGroup === '__NO_ATTRIB__' || strtolower($campaignGroup) === 'nao_atribuir';
    if ($tx === '') {
        throw new RuntimeException('Transaction code obrigatório.');
    }
    if (!in_array($model, array('first_touch','last_touch'), true)) {
        $model = 'last_touch';
    }
    if ($campaignGroup === '' && !$noAttribution) {
        throw new RuntimeException('Campanha obrigatória.');
    }
    if ($noAttribution) {
        $campaignGroup = '';
        $campaignName = '';
        $adName = '';
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS manual_sale_attributions (
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
        UNIQUE KEY uq_tx_model (transaction_code, attribution_model)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $sql = "INSERT INTO manual_sale_attributions (
                transaction_code, attribution_model, campaign_group, campaign_group_norm,
                campaign_name, campaign_name_norm, ad_name, ad_name_norm,
                source_user_id, lead_utm_source, lead_utm_medium, lead_utm_campaign, lead_utm_term, lead_utm_content,
                assigned_by, notes, created_at, updated_at
            ) VALUES (
                :transaction_code, :attribution_model, :campaign_group, :campaign_group_norm,
                :campaign_name, :campaign_name_norm, :ad_name, :ad_name_norm,
                :source_user_id, :lead_utm_source, :lead_utm_medium, :lead_utm_campaign, :lead_utm_term, :lead_utm_content,
                :assigned_by, :notes, NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE
                campaign_group = VALUES(campaign_group),
                campaign_group_norm = VALUES(campaign_group_norm),
                campaign_name = VALUES(campaign_name),
                campaign_name_norm = VALUES(campaign_name_norm),
                ad_name = VALUES(ad_name),
                ad_name_norm = VALUES(ad_name_norm),
                source_user_id = VALUES(source_user_id),
                lead_utm_source = VALUES(lead_utm_source),
                lead_utm_medium = VALUES(lead_utm_medium),
                lead_utm_campaign = VALUES(lead_utm_campaign),
                lead_utm_term = VALUES(lead_utm_term),
                lead_utm_content = VALUES(lead_utm_content),
                assigned_by = VALUES(assigned_by),
                notes = VALUES(notes),
                updated_at = NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':transaction_code' => $tx,
        ':attribution_model' => $model,
        ':campaign_group' => $campaignGroup,
        ':campaign_group_norm' => normalize_match_key($campaignGroup),
        ':campaign_name' => $campaignName,
        ':campaign_name_norm' => normalize_match_key($campaignName),
        ':ad_name' => $adName,
        ':ad_name_norm' => normalize_match_key($adName),
        ':source_user_id' => $sourceUserId > 0 ? $sourceUserId : null,
        ':lead_utm_source' => trim((string)($_POST['lead_utm_source'] ?? '')),
        ':lead_utm_medium' => trim((string)($_POST['lead_utm_medium'] ?? '')),
        ':lead_utm_campaign' => trim((string)($_POST['lead_utm_campaign'] ?? '')),
        ':lead_utm_term' => trim((string)($_POST['lead_utm_term'] ?? '')),
        ':lead_utm_content' => trim((string)($_POST['lead_utm_content'] ?? '')),
        ':assigned_by' => trim((string)($_SESSION['meta_admin_user_email'] ?? $_SESSION['meta_admin_user_name'] ?? 'admin')),
        ':notes' => $noAttribution ? 'NO_ATTRIBUTION' : null,
    ]);
    echo json_encode(['ok' => true, 'message' => $noAttribution ? 'Venda marcada como não atribuir.' : 'Atribuição manual salva.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
