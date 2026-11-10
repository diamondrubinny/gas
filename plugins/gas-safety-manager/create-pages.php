<?php
// Standalone page creator — run via: wp eval-file, or php with wp-load
// Creates the two pages if missing. Safe to run multiple times.
$pages = array(
  array('title'=>'מערכת גזים','name'=>'gas-app','content'=>'[gas_app]','opt'=>'gsm_app_page_id'),
  array('title'=>'פרטי ציוד','name'=>'bridge','content'=>'[gas_bridge]','opt'=>'gsm_bridge_page_id'),
);
foreach($pages as $p){
  $existing = get_page_by_path($p['name']);
  if($existing){
    update_option($p['opt'], $existing->ID);
    echo "קיים: {$p['title']} (ID {$existing->ID}) → /{$p['name']}\n";
    continue;
  }
  $id = wp_insert_post(array(
    'post_title'=>$p['title'],'post_name'=>$p['name'],
    'post_content'=>$p['content'],'post_status'=>'publish','post_type'=>'page'
  ));
  if($id && !is_wp_error($id)){
    update_option($p['opt'], $id);
    echo "נוצר: {$p['title']} (ID {$id}) → /{$p['name']}\n";
  } else {
    echo "נכשל: {$p['title']}\n";
  }
}
echo "בוצע.\n";
