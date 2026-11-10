<?php
// Run: php tests/bi-token-test.php plugins/gas-safety-manager
// Minimal WP stubs to exercise GSM_REST_BI token boundaries.
define('ABSPATH', '/tmp/');
define('DAY_IN_SECONDS', 86400); define('ARRAY_A','ARRAY_A');
$GLOBALS['opts'] = []; $GLOBALS['trans'] = []; $GLOBALS['routes'] = []; $GLOBALS['audit'] = [];
function get_option($k,$d=false){ return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k,$v,$a=null){ $GLOBALS['opts'][$k]=$v; return true; }
function get_transient($k){ return $GLOBALS['trans'][$k] ?? false; }
function set_transient($k,$v,$t){ $GLOBALS['trans'][$k]=$v; return true; }
function wp_unslash($v){ return $v; }
function sanitize_text_field($s){ return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function __($s,$d=null){ return $s; }
function get_bloginfo($k){ return 'Site'; }
function current_time($t){ return '2026-11-10 10:00:00'; }
function rest_ensure_response($d){ return $d; }
function register_rest_route($ns,$r,$a){ $GLOBALS['routes'][$r]=$a; }
class WP_Error { public $code; function __construct($c,$m='',$d=[]){ $this->code=$c; $this->data=$d; } }
class WP_REST_Server { const READABLE='GET'; const CREATABLE='POST'; }
class WP_REST_Request implements ArrayAccess {
  // Mirrors WP: header names are case-insensitive and '-' == '_'.
  private $p; private $h;
  function __construct($p, $h = array()){ $this->p=$p; $this->h=array(); foreach($h as $k=>$v){ $this->h[str_replace('-','_',strtolower($k))]=$v; } }
  function get_param($k){ return $this->p[$k] ?? null; }
  function get_header($k){ return $this->h[str_replace('-','_',strtolower($k))] ?? null; }
  function offsetExists($k):bool{ return isset($this->p[$k]); }
  function offsetGet($k):mixed{ return $this->p[$k] ?? null; }
  function offsetSet($k,$v):void{} function offsetUnset($k):void{}
}
class GSM_Auth { static function require_manage(){ return true; } }
class GSM_Audit { static function log(...$a){ $GLOBALS['audit'][]=$a; } }
class FakeDB { public $prefix='wp_'; function get_var($q){ return 5; } function get_results($q,$o=null){ return []; } function prepare($q,...$a){ return $q; } }
$GLOBALS['wpdb'] = new FakeDB();
class ExportReached extends Exception {}
class GSM_Equipment_Repo { static function list($a){ throw new ExportReached('equipment'); } }
$_SERVER['REMOTE_ADDR']='1.2.3.4';
require $argv[1].'/includes/class-gsm-rate-limit.php';
require $argv[1].'/includes/rest/class-gsm-rest-bi.php';

$pass=0; $fail=0;
function t($name,$ok){ global $pass,$fail; if($ok){$pass++; echo "PASS $name\n";} else {$fail++; echo "FAIL $name\n";} }
function resetRL(){ $GLOBALS['trans']=[]; }

$bi = new GSM_REST_BI(); $bi->register_routes();
t('routes public-token registered', isset($GLOBALS['routes']['/bi/public-token'], $GLOBALS['routes']['/bi/public-token/rotate']));
t('public-token routes require manage', $GLOBALS['routes']['/bi/public-token']['permission_callback']===['GSM_Auth','require_manage'] && $GLOBALS['routes']['/bi/public-token/rotate']['permission_callback']===['GSM_Auth','require_manage']);

$biTok  = GSM_REST_BI::token();
$pubTok = GSM_REST_BI::public_token();
t('tokens are distinct', $biTok !== $pubTok && strlen($pubTok)===32);
t('public token stable', GSM_REST_BI::public_token()===$pubTok);

// /bi/public
$r = $bi->public_summary(new WP_REST_Request(['token'=>$pubTok]));
t('/bi/public accepts public token', is_array($r) && $r['total_equipment']===5);
$r = $bi->public_summary(new WP_REST_Request(['token'=>$biTok]));
t('/bi/public REJECTS BI token', $r instanceof WP_Error && $r->code==='gsm_bi_token');
$r = $bi->public_summary(new WP_REST_Request([]));
t('/bi/public rejects missing token', $r instanceof WP_Error);
$r = $bi->public_summary(new WP_REST_Request(['token'=>['x']]));
t('/bi/public rejects array token (no TypeError)', $r instanceof WP_Error);

// /bi/data — the core leak
resetRL();
$r = $bi->export_data(new WP_REST_Request(['token'=>$pubTok,'set'=>'equipment','format'=>'json']));
t('/bi/data REJECTS public token', $r instanceof WP_Error && $r->code==='gsm_bi_token');
try { $bi->export_data(new WP_REST_Request(['token'=>$biTok,'set'=>'equipment','format'=>'json'])); t('/bi/data accepts BI token', false); }
catch (ExportReached $e) { t('/bi/data accepts BI token', true); }

// /bi/ical
resetRL();
$r = $bi->ical(new WP_REST_Request(['token'=>$pubTok]));
t('/bi/ical REJECTS public token', $r instanceof WP_Error && $r->code==='gsm_bi_token');
try { $bi->ical(new WP_REST_Request(['token'=>$biTok])); t('/bi/ical accepts BI token', false); }
catch (ExportReached $e) { t('/bi/ical accepts BI token', true); }

// rate limit on /bi/data: 60 allowed, 61st blocked
resetRL(); $last=null;
for($i=0;$i<61;$i++){ $last=$bi->export_data(new WP_REST_Request(['token'=>'bad','set'=>'equipment'])); }
t('/bi/data rate-limited after 60', $last instanceof WP_Error && $last->code==='gsm_rate_limited');
resetRL(); $last=null;
for($i=0;$i<61;$i++){ $last=$bi->ical(new WP_REST_Request(['token'=>'bad'])); }
t('/bi/ical rate-limited after 60', $last instanceof WP_Error && $last->code==='gsm_rate_limited');

// X-GSM-Token header (preferred over ?token=, keeps the secret out of access logs)
resetRL();
$r = $bi->public_summary(new WP_REST_Request([], ['X-GSM-Token'=>$pubTok]));
t('/bi/public accepts public token in X-GSM-Token header', is_array($r));
$r = $bi->public_summary(new WP_REST_Request([], ['X-GSM-Token'=>$biTok]));
t('/bi/public REJECTS BI token in header', $r instanceof WP_Error);
$r = $bi->export_data(new WP_REST_Request(['set'=>'equipment','format'=>'json'], ['X-GSM-Token'=>$pubTok]));
t('/bi/data REJECTS public token in header', $r instanceof WP_Error && $r->code==='gsm_bi_token');
try { $bi->export_data(new WP_REST_Request(['set'=>'equipment','format'=>'json'], ['x-gsm-token'=>$biTok])); t('/bi/data accepts BI token in header (any case)', false); }
catch (ExportReached $e) { t('/bi/data accepts BI token in header (any case)', true); }
$r = $bi->export_data(new WP_REST_Request(['set'=>'equipment','token'=>$biTok], ['X-GSM-Token'=>'wrong']));
t('header wins over query (wrong header + right query = rejected)', $r instanceof WP_Error);
$r = $bi->public_summary(new WP_REST_Request([], ['X-GSM-Token'=>'   ']));
t('blank header falls back to query (none) = rejected', $r instanceof WP_Error);

// rotation
resetRL();
$new = $bi->rotate_public_token()['token'];
t('rotate changes public token', $new!==$pubTok && GSM_REST_BI::public_token()===$new);
t('rotate leaves BI token intact', GSM_REST_BI::token()===$biTok);
$r = $bi->public_summary(new WP_REST_Request(['token'=>$pubTok]));
t('old public token rejected after rotate', $r instanceof WP_Error);
$r = $bi->public_summary(new WP_REST_Request(['token'=>$new]));
t('new public token accepted', is_array($r));
$bi->rotate_token();
t('BI rotate leaves public token intact', GSM_REST_BI::public_token()===$new);
t('rotations audited', count($GLOBALS['audit'])===2);

echo "\n$pass passed, $fail failed\n"; exit($fail?1:0);
