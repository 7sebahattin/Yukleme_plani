<?php
// =========================================================
// config/whatsapp_config.php — WhatsApp Yükleme Planı modülü: MERKEZİ AYAR
//
// Gizli değerler (API anahtarları, webhook belirteci) depoya GİRMEZ:
//   config/local.php (gitignore'lu) içinde define('EVOLUTION_API_KEY', '…'); gibi tanımlanır
//   (HKS_CRED_KEY / MAIL_TRANSLATE_KEY ile aynı desen) ya da ortam değişkeni olarak verilir.
// Burada her sabit YALNIZ tanımlı değilse varsayılanla tanımlanır → local.php her zaman kazanır.
//
// Sabitler:
//   EVOLUTION_API_URL        Evolution API adresi                       (varsayılan http://localhost:8080)
//   EVOLUTION_API_KEY        Evolution global API anahtarı              (varsayılan '')
//   EVOLUTION_INSTANCE_NAME  WhatsApp oturum (instance) adı             (varsayılan yukleme_plani_bot)
//   WEBHOOK_SECRET_TOKEN     Webhook'u doğrulayan gizli anahtar         (varsayılan '' → webhook HİÇBİR isteği kabul etmez)
//   ALLOWED_GROUP_JIDS       Dinlenecek grup JID dizisi                 (varsayılan [] → tüm gruplar; panelden seçim DB'de, onu ezer)
//   AI_PROVIDER              'groq' | 'gemini'                          (varsayılan groq; panelden seçim DB'de, onu ezer)
//   AI_API_KEY               Groq / Google AI Studio anahtarı           (varsayılan '')
//   AI_MODEL                 Model adı (groq: llama-4-scout, gemini: gemini-2.5-flash)
//   AI_MIN_CONFIDENCE        Otomatik taslak güven eşiği 0-100          (varsayılan 75)
// =========================================================
declare(strict_types=1);

if (!defined('EVOLUTION_API_URL') && !defined('EVOLUTION_API_KEY') && is_file(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

/** Ortam değişkeni (boş/yoksa varsayılan). */
function wa_env(string $ad, string $varsayilan = ''): string
{
    $v = getenv($ad);
    return ($v === false || $v === '') ? $varsayilan : (string)$v;
}

if (!defined('EVOLUTION_API_URL'))       define('EVOLUTION_API_URL', rtrim(wa_env('EVOLUTION_API_URL', 'http://localhost:8080'), '/'));
if (!defined('EVOLUTION_API_KEY'))       define('EVOLUTION_API_KEY', wa_env('EVOLUTION_API_KEY'));
if (!defined('EVOLUTION_INSTANCE_NAME')) define('EVOLUTION_INSTANCE_NAME', wa_env('EVOLUTION_INSTANCE_NAME', 'yukleme_plani_bot'));
if (!defined('WEBHOOK_SECRET_TOKEN'))    define('WEBHOOK_SECRET_TOKEN', wa_env('WEBHOOK_SECRET_TOKEN'));
if (!defined('ALLOWED_GROUP_JIDS')) {
    define('ALLOWED_GROUP_JIDS', array_values(array_filter(array_map('trim', explode(',', wa_env('ALLOWED_GROUP_JIDS'))), 'strlen')));
}
if (!defined('AI_PROVIDER')) {
    $__p = strtolower(wa_env('AI_PROVIDER', 'groq'));
    define('AI_PROVIDER', in_array($__p, ['groq', 'gemini'], true) ? $__p : 'groq');
    unset($__p);
}
if (!defined('AI_API_KEY'))  define('AI_API_KEY', wa_env('AI_API_KEY'));
if (!defined('AI_MODEL'))    define('AI_MODEL', wa_env('AI_MODEL', AI_PROVIDER === 'gemini' ? 'gemini-2.5-flash' : 'meta-llama/llama-4-scout-17b-16e-instruct'));
if (!defined('AI_MIN_CONFIDENCE')) define('AI_MIN_CONFIDENCE', max(0, min(100, (int)wa_env('AI_MIN_CONFIDENCE', '75'))));

/** Sağlayıcıya göre varsayılan model (panelden sağlayıcı değişince AI_MODEL başka sağlayıcıya ait kalmasın). */
function wa_varsayilan_model(string $saglayici): string
{
    return $saglayici === 'gemini' ? 'gemini-2.5-flash' : 'meta-llama/llama-4-scout-17b-16e-instruct';
}

/**
 * Etkin AI modeli: sabitteki model, sabitteki sağlayıcıyla uyumluysa o; panelden sağlayıcı değiştirilmişse
 * (sabit başka sağlayıcıya aitse) yeni sağlayıcının varsayılan modeli.
 */
function wa_etkin_model(string $saglayici): string
{
    if ($saglayici === AI_PROVIDER) return AI_MODEL;
    return wa_varsayilan_model($saglayici);
}
