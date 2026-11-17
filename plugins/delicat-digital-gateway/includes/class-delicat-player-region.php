<?php
// Shared by both Delicat integrations; no network calls or player data storage.
defined('ABSPATH') || exit;
if (!class_exists('Delicat_Player_Region_V2', false)) {
    final class Delicat_Player_Region_V2 {
        const MESSAGE = 'Désolé, ce compte est enregistré dans une autre région ou sur un autre serveur. Vérifiez le serveur de votre compte dans le jeu, puis effectuez votre transaction dans la rubrique correspondante.';
        public static function regions($text) {
            $text = strtolower(remove_accents(wp_strip_all_tags((string) $text)));
            $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            // Free Fire NA/US and LATAM labels refer to the Americas account
            // service used for this storefront. Brazil remains a distinct server.
            $map = array(
                'na'=>array('na','us','usa','latam','sac','america','americas','amerique','latinoamerica','latino','norteamerica','northamerica'),
                'eu'=>array('eu','europe','european','europeen'),
                'br'=>array('br','brazil','brasil'),
                'ind'=>array('ind','india','inde'),
                'sg'=>array('sg','singapore','singapour'),
                'id'=>array('id','indonesia','indonesie'),
                'th'=>array('th','thailand','thailande'),
                'vn'=>array('vn','vietnam'),
                'tw'=>array('tw','taiwan'),
                'me'=>array('me','mena','middleeast'),
                'cis'=>array('cis','ru','russia','russie'),
                'pk'=>array('pk','pakistan'),
                'bd'=>array('bd','bangladesh'),
            );
            $found = array();
            foreach ($map as $key=>$aliases) {
                if (array_intersect($words,$aliases)) { $found[]=$key; }
            }
            return $found;
        }
        public static function account_region(array $player) {
            $found=array();
            foreach (array('account_region','actual_region','player_region','actual_server','account_server','region','server_region','server') as $key) {
                if (!isset($player[$key]) || !is_scalar($player[$key])) { continue; }
                $regions=self::regions($player[$key]);
                foreach ($regions as $region) { $found[$region]=true; }
            }
            return count($found)===1 ? (string) key($found) : '';
        }
        public static function error_region(array $error) {
            // In an error payload a bare region/server can describe the requested
            // product. Use only fields explicitly identifying the actual account.
            $actual=array();
            foreach (array('account_region','actual_region','player_region','actual_server','account_server') as $key) {
                if (isset($error[$key]) && is_scalar($error[$key])) { $actual[$key]=$error[$key]; }
            }
            return self::account_region($actual);
        }
        public static function message(array $player=array()) {
            $region=self::account_region($player);
            $labels=array('na'=>'Amérique / LATAM (NA)','eu'=>'Europe (EU)','br'=>'Brésil (BR)',
                'ind'=>'Inde (IND)','sg'=>'Singapour (SG)','id'=>'Indonésie (ID)',
                'th'=>'Thaïlande (TH)','vn'=>'Vietnam (VN)','tw'=>'Taïwan (TW)',
                'me'=>'Moyen-Orient (ME)','cis'=>'CEI (CIS)','pk'=>'Pakistan (PK)','bd'=>'Bangladesh (BD)');
            if (!isset($labels[$region])) { return self::MESSAGE; }
            return sprintf('Désolé, ce compte est enregistré sur un autre serveur : %1$s. Veuillez effectuer votre transaction dans la rubrique Free Fire du serveur %1$s.', $labels[$region]);
        }
        public static function mismatch($context, array $fields, array $player) {
            if (!preg_match('/free[ _-]*fire/i', (string) $context)) { return false; }
            $expected = array();
            foreach (array('region','server','server_region') as $key) {
                if (isset($fields[$key]) && is_scalar($fields[$key])) {
                    $candidate=self::regions($fields[$key]);
                    if (count($candidate)===1) { $expected=$candidate; break; }
                }
            }
            if (!$expected) { $expected=self::regions($context); }
            // Ambiguous or unknown configuration is not proof of a mismatch.
            if (count($expected)!==1) { return false; }
            $actual=self::account_region($player);
            return $actual!=='' && !in_array($actual,$expected,true);
        }
        public static function is_region_error($code) {
            return in_array(strtolower((string) $code),array('region_mismatch','wrong_region','invalid_region','server_mismatch','wrong_server','invalid_server','account_region_mismatch','dfr_region_mismatch','dapi_uid_region_mismatch'),true);
        }
    }
}
