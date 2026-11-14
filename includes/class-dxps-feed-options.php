<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class DXPS_Feed_Options {
 const DEFAULTS = array('shipping_mode'=>'account','shipping_rows'=>array(),'shipping_policy_url'=>'','return_policy_url'=>'','return_policy_label'=>'','return_policy_confirmed'=>0,'identifier_mode'=>'preserve','identifier_confirmed'=>0,'category_map'=>array(),'include_children'=>0,'description_source'=>'full','image_limit'=>10,'min_price'=>'','max_price'=>'','sale_only'=>0,'exclude_virtual'=>0,'title_prefix'=>'','title_suffix'=>'');
 public static function sanitize($in,$out) {
  $out=array_merge(self::DEFAULTS,$out);
  foreach(array('shipping_mode'=>array('account','manual'),'identifier_mode'=>array('preserve','none','required'),'description_source'=>array('full','short')) as $k=>$opts) if(in_array($in[$k]??'', $opts,true))$out[$k]=$in[$k];
  foreach(array('return_policy_confirmed','identifier_confirmed','include_children','sale_only','exclude_virtual') as $k)$out[$k]=empty($in[$k])?0:1;
  foreach(array('title_prefix','title_suffix','return_policy_label') as $k)$out[$k]=DXPS_Feed_Product::text($in[$k]??'',100);
  foreach(array('shipping_policy_url','return_policy_url') as $k){$v=$in[$k]??'';if(!is_string($v))return new WP_Error('policy','Invalid policy URL.');$out[$k]=esc_url_raw($v);if($v&&!DXPS_Feed_Product::valid_url($out[$k]))return new WP_Error('policy','Use a complete HTTP/HTTPS policy URL.');}
  if('none'===$out['identifier_mode']&&!$out['identifier_confirmed'])return new WP_Error('identifiers','Confirm that manufacturers assigned no GTIN/MPN before choosing the no-identifiers policy.');
  if($out['return_policy_label']&&!$out['return_policy_confirmed'])return new WP_Error('returns','Confirm this exact return policy label exists in Merchant Center. A feed label does not create a return policy.');
  if($out['return_policy_label']&&!in_array($out['platform'],array('google','generic'),true))return new WP_Error('returns','Return policy labels are supported in Google and Generic profiles. Configure returns in the other platform account.');
  $out['image_limit']=max(0,min(10,absint($in['image_limit']??10)));
  foreach(array('min_price','max_price') as $k){$v=$in[$k]??'';if(''!==$v&&(!is_scalar($v)||!is_numeric($v)||!is_finite((float)$v)||(float)$v<0))return new WP_Error('price','Price filters must be nonnegative numbers.');$out[$k]=''===$v?'':(string)(float)$v;}
  if(''!==$out['min_price']&&''!==$out['max_price']&&(float)$out['min_price']>(float)$out['max_price'])return new WP_Error('price','Minimum price exceeds maximum price.');
  foreach(array_slice((array)($in['category_map']??array()),0,500,true) as $id=>$value)if(absint($id)&&is_scalar($value)&&trim((string)$value))$out['category_map'][absint($id)]=DXPS_Feed_Product::text($value,250);
  if('manual'===$out['shipping_mode']){
   foreach(array_slice((array)($in['shipping_rows']??array()),0,20) as $r){
    if(!is_array($r))return new WP_Error('shipping','Invalid shipping row.');
    if(empty($r['country'])&&''===($r['price']??''))continue;
    $country=strtoupper(sanitize_text_field($r['country']??''));
    if(!isset(WC()->countries->get_countries()[$country]))return new WP_Error('shipping','Select a valid destination country.');
    if(!isset($r['price'])||!is_numeric($r['price'])||!is_finite((float)$r['price'])||(float)$r['price']<0)return new WP_Error('shipping','Every shipping row needs an actual nonnegative price.');
    $row=array('country'=>$country,'region'=>DXPS_Feed_Product::text($r['region']??'',100),'service'=>DXPS_Feed_Product::text($r['service']??'',100),'price'=>number_format((float)$r['price'],2,'.','').' '.get_woocommerce_currency());
    if(preg_match('/[:,]/',$row['region'].$row['service']))return new WP_Error('shipping','Region and service cannot contain commas or colons.');
    foreach(array('min_handling_time','max_handling_time','min_transit_time','max_transit_time') as $k){$v=$r[$k]??'';if(''===$v)continue;if(!is_scalar($v)||!preg_match('/^\d{1,3}$/',(string)$v))return new WP_Error('shipping','Delivery times must be whole nonnegative days (0–999).');$row[$k]=(int)$v;}
    foreach(array('handling','transit') as $k){$min='min_'.$k.'_time';$max='max_'.$k.'_time';if(isset($row[$min])!==isset($row[$max])||(isset($row[$min])&&$row[$min]>$row[$max]))return new WP_Error('shipping','Supply both minimum and maximum delivery days in increasing order.');}
    $out['shipping_rows'][]=$row;
   }
   if(!$out['shipping_rows'])return new WP_Error('shipping','Add a shipping row or use account-level shipping.');
  }
  return $out;
 }
 public static function enrich($row,$p,$base,$cfg){
  $c=array_merge(self::DEFAULTS,$cfg);
  $specific=false;
  foreach(array('_dxps_google_category','_wc_gla_google_category','feed_google_category','wpfoof-google_product_category') as $key)if($p->get_meta($key)||$base->get_meta($key))$specific=true;
  if(!$specific){$cats=$base->get_category_ids();usort($cats,function($a,$b){$depth=count(get_ancestors($b,'product_cat','taxonomy'))-count(get_ancestors($a,'product_cat','taxonomy'));return $depth?:$a-$b;});foreach($cats as $id)if(!empty($c['category_map'][$id])){$row['google_product_category']=$c['category_map'][$id];break;}}
  if('short'===$c['description_source']&&$base->get_short_description())$row['description']=DXPS_Feed_Product::text($base->get_short_description());
  $row['title']=DXPS_Feed_Product::text(trim($c['title_prefix'].' '.$row['title'].' '.$c['title_suffix']),150);
  $row['additional_image_link']=array_slice($row['additional_image_link']??array(),0,$c['image_limit']);
  if('manual'===$c['shipping_mode'])$row['shipping']=$c['shipping_rows'];
  $label=$p->get_meta('_dxps_return_policy_label')?:$c['return_policy_label'];
  if($label&&$c['return_policy_confirmed']&&in_array($c['platform'],array('google','generic'),true))$row['return_policy_label']=DXPS_Feed_Product::text($label,100);
  return $row;
 }
 public static function discover(){
  $cached=get_transient('dxps_policy_discovery');if(is_array($cached))return $cached;
  $result=array('pages'=>array(),'shipping'=>array(),'scanned'=>0,'at'=>time(),'limit'=>1000);
  $pages=get_posts(array('post_type'=>'page','post_status'=>'publish','numberposts'=>1000,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>false));
  foreach(array_filter(array_unique(array((int)get_option('woocommerce_terms_page_id'),(int)get_option('wp_page_for_privacy_policy')))) as $pid){$page=get_post($pid);if($page&&'publish'===$page->post_status&&!in_array($page,$pages))$pages[]=$page;}
  foreach($pages as $p){if($p->post_password)continue;$result['scanned']++;$title=$p->post_title.' '.$p->post_name;
   $ship=preg_match('/dostav|isporuk|shipping|delivery|versand/i',$title);$ret=preg_match('/povrat|povrata|reklamac|zamjen|return|refund|widerruf/i',$title);$terms=preg_match('/uslov|uvjet|terms|pravil|policy|politika/i',$title)||in_array($p->ID,array((int)get_option('woocommerce_terms_page_id'),(int)get_option('wp_page_for_privacy_policy')),true);
   if(!$ship&&!$ret&&!$terms){$ship=preg_match('/(?:shipping|delivery|dostav|isporuk).{0,60}(?:\d|BAM|KM|EUR)/iu',$p->post_content);$ret=preg_match('/(?:return|refund|povrat|reklamac).{0,80}(?:\d|dan|day|rok)/iu',$p->post_content);}
   if(!$ship&&!$ret&&!$terms)continue;
   $text=DXPS_Feed_Product::text($p->post_content,8000);
   if($terms){$ship=$ship||preg_match('/dostav|isporuk|shipping|delivery|versand/i',$text);$ret=$ret||preg_match('/povrat|reklamac|return|refund|widerruf/i',$text);}
   $result['pages'][]=array('id'=>$p->ID,'title'=>$p->post_title,'url'=>get_permalink($p),'shipping'=>(bool)$ship,'returns'=>(bool)$ret,'text'=>$text);
  }
  if(class_exists('WC_Shipping_Zones')){
   $zones=WC_Shipping_Zones::get_zones();$zones[]=array('zone_id'=>0,'zone_name'=>'Locations not covered by other zones');
   foreach($zones as $z){$zone=new WC_Shipping_Zone($z['zone_id']);$locations=array();foreach($zone->get_zone_locations() as $loc)$locations[]=$loc->type.':'.$loc->code;
    foreach($zone->get_shipping_methods(true) as $method){$result['shipping'][]=array('zone'=>$z['zone_name'],'locations'=>$locations,'method'=>$method->get_method_title(),'title'=>$method->get_title(),'cost'=>$method->get_option('cost',''),'tax_status'=>$method->get_option('tax_status',''),'requires'=>$method->get_option('requires',''),'min_amount'=>$method->get_option('min_amount',''),'instance_id'=>$method->instance_id);
     $index=count($result['shipping'])-1;$cost=$method->get_option('cost','');$classes=false;
     foreach((array)$method->instance_settings as $key=>$value)if((strpos($key,'class_cost_')===0||$key==='no_class_cost')&&$value!==''&&$value!=='0')$classes=true;
     if('flat_rate'===$method->id&&is_numeric($cost)&&(float)$cost>=0&&!$classes&&(!wc_tax_enabled()||'none'===$method->get_option('tax_status',''))&&count($locations)===1&&strpos($locations[0],'country:')===0){$result['shipping'][$index]['import']=array('country'=>substr($locations[0],8),'price'=>(string)$cost,'service'=>$method->get_title());}
}
   }
  }
  set_transient('dxps_policy_discovery',$result,15*MINUTE_IN_SECONDS);return $result;
 }
 public static function issue($code){$fix=array('identifiers_missing_use_real_manufacturer_data'=>'Add the real GTIN, or brand + manufacturer MPN in the product DX Catalogue panel. If no identifiers were assigned, select the explicit no-identifiers policy and confirm it. SKU is not an MPN.','google_category_missing'=>'Choose a Google category below, or map each WooCommerce category. Hair extensions: 4057. Wigs: 181.','brand_missing'=>'Set the actual manufacturer brand on this product or a truthful fallback brand.','future_availability_date_required'=>'Set a future ISO 8601 availability date for this preorder/backorder.','identifiers_required'=>'This feed requires manufacturer identifiers. Supply a GTIN or brand + MPN.');return $fix[$code]??ucfirst(str_replace('_',' ',$code)).'. Open the product to correct its source data.';}
}
