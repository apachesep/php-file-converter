<?php
/**
 * Converter
 *
 * 轉換
 *
 * @package		KALS
 * @category		Libraries
 * @author		Pudding Chen <puddingchen.35@gmail.com>
 * @copyright		Copyright (c) 2010, Pudding Chen
 * @license		http://opensource.org/licenses/gpl-license.php GNU Public License
 * @link		http://sites.google.com/site/puddingkals/
 * @version		1.0 2013/10/15 下午 09:23:36
 */
class Convert_handler extends KALS_object {
    
    /**
     * @var CI_DB_driver 
     */
    public $db;
    
    /**
     * @var Puli_log
     */
    private $puli_log;
    
    public function __construct() {
        parent::__construct();
        $this->db = $this->CI->db;
        
        $this->CI->load->library("object/Puli_log");
        $this->puli_log = $this->CI->puli_log;
        
        $this->CI->load->library("object/bitstream");
    }

    public function start() {
        $bitstream = $this->get_original_bitstream();
        
        if (is_null($bitstream)) {
            // 完成轉換，停止
            $this->_unlock();
            return $this;
        }
        
        // 檢查是否已經上鎖
        if ($this->_is_locked()) {
            // 如果已經上鎖，那就不做任何事情
            return $this->CI->lang->line("convert_handler_locked");
        }
        
        //先上鎖
        $this->_lock();
        
        // 來做檢查，看數量是否超過指定數目
        if ($this->_is_over_reserved_files()) {
            $this->_delete_over_reserved_files();
        }
        
        while (!is_null($bitstream)) {
        
            $result = $this->convert_start($bitstream);
            if ($result === FALSE) {
                return;
            }
            $this->convert_completed($bitstream);

            sleep($this->CI->config->item("wait_reload_interval"));
            
            $bitstream = $this->get_original_bitstream();
        }
        
        $this->_unlock();
        
        //$this->start();
    }
    
    private function _is_over_reserved_files() {
        
        $sql = "select bitstream_id "
            . "from bitstream "
            . "where original_id IS NOT NULL";
        $query = $this->db->query($sql);
        $number = $query->num_rows();
        
        return ($number > $this->CI->config->item("max_reserved_files"));
    }
    
    private function _delete_over_reserved_files() {
        
        $sql = "select bitstream_id "
            . "from bitstream "
            . "where original_id IS NOT NULL "
            . "order by bitstream_id desc";
        
        $query = $this->db->query($sql);
        
        $results = $query->result_array();
        $i = 0;
        foreach ($results AS $result) {
            if ($i < $this->CI->config->item("max_reserved_files")) {
                $i++;
                continue;
            }
            
            $bitstream_id = $result["bitstream_id"];
            
            $bitstream = new Bitstream($bitstream_id);
            //echo "delete: ".$bitstream_id."<br />";
            $bitstream->delete();
            $i++;
        }
    }


    /**
     * @return Bitstream
     */
    public function get_original_bitstream() {
        $sql = "select a.bitstream_id as original_id, count(b.bitstream_id) as converted_count "
            . "from bitstream as a left join bitstream as b on a.bitstream_id = b.original_id "
            . "group by a.bitstream_id "
            . "having converted_count = 0 "
            . "and a.deleted = 0 "
            . "and a.type = 'uploaded'"
            . "order by a.bitstream_id desc limit 0,1";
        $query = $this->db->query($sql);
        
        //echo $query->num_rows();
        if ($query->num_rows() == 0) {
            return NULL;
        }
        
        $result = $query->result_array();
        if (!isset($result[0])) {
            return NULL;
        }
        //print_r($result);
        $bitstream_id = $result[0]["original_id"];
        $bitstream = new Bitstream($bitstream_id);
        
        // 再檢查一下
        $path = $bitstream->get_path();
        if (is_file($path) === FALSE) {
            $this->puli_log->create_log($bitstream, "delete");
            $bitstream->delete();
            return $this->get_original_bitstream();
        }
        
        return $bitstream;
    }
    
    /**
     * 開始進行轉換
     * @param Bitstream $bitstream
     */
    public function convert_start($bitstream) {
        //$this->puli_log->create_log($bitstream, 'convert_start');
        
        // 開始進行轉換的手續
        
        $params["PATH"] = $bitstream->get_path();
        $params["DIR"] = $bitstream->get_dir();
        $params["FULLNAME"] = $bitstream->get_fullname();
        $params["EXT_NAME"] = $bitstream->get_ext_name();
        $params["FILE_NAME"] = $bitstream->get_file_name();
        $params["OUTPUT_DIR"] = $this->get_completed_dir();
        $params["ORI_NAME"] = $bitstream->get_original_file_name();
        
        $converter = $this->CI->config->item("converter");
        
        $converter_name = $converter["name"];
        
        $output_path = $converter["output_path"];
        $output_path = $this->_format_path($output_path, $params);
        $output_name = $converter["output_name"];
        $output_name = $this->_format_path($output_name, $params);
        $output_mime = $converter["mime"];
        if (is_null($output_mime)) {
            $output_mime = $bitstream->get_mime();
        }
        $scrtips = $converter["script"];
        
        if (isset($converter["params"])) {
            $params["PARAMS"] = $converter["params"];
            $params["BITSTREAM_PARAMS"] = $bitstream->get_parameters();
        }
        
        chdir($bitstream->get_dir());
        foreach ($scrtips as $step) {
            // 取代$step的資料
            $step = $this->_format_path($step, $params);
            //exec($step, $output);
            
            //echo system($step, $output);
            //passthru($step);
            //$output = array();
            exec($step);
            echo " - " .$step."<br />";
            /*
            if (is_array($output)) {
                foreach ($output AS $o) {
                    echo $o."<br />";
                }
            }
            echo " - " .$step."<br />";
             */
        }
        //return;
        
        //轉換完成，取得資料
        //echo $output_path;
        //return false;
        if (is_file($output_path)) {
            if ($bitstream->is_convert_completed()) {
                return true;
            }
            
            $converted_bitstream = new Bitstream();
            $internal_name = substr($output_path, strrpos($output_path, DIRECTORY_SEPARATOR)+1);
            $converted_bitstream->set_field("original_name", $output_name);
            $converted_bitstream->set_field("internal_name", $internal_name);
            $converted_bitstream->set_field("type", $converter_name);
            $converted_bitstream->set_field("original_id", $bitstream->get_id());
            $converted_bitstream->set_field("mime", $output_mime);
            
            $converted_bitstream->save();
            
            $bitstream->delete_file();

            $this->puli_log->create_log($converted_bitstream, $converter_name."_completed");
        }
        else {
            $this->puli_log->create_log($bitstream, $converter_name."_error");
            return false;
        }
        return true;
    }
    
    private function _format_path($path, $params) {
        $step = $path;

        $step = str_replace("[PATH]", $params["PATH"], $step);
        $step = str_replace("[DIR]", $params["DIR"], $step);
        $step = str_replace("[FULLNAME]", $params["FULLNAME"], $step);
        $step = str_replace("[EXT_NAME]", $params["EXT_NAME"], $step);
        $step = str_replace("[FILE_NAME]", $params["FILE_NAME"], $step);
        $step = str_replace("[OUTPUT_DIR]", $params["OUTPUT_DIR"], $step);
        $step = str_replace("[ORI_NAME]", $params["ORI_NAME"], $step);
        
        // 處理PARAMS
        if (isset($params["PARAMS"])) {
            $bitstream_params = $params["BITSTREAM_PARAMS"];
            
            foreach ($params["PARAMS"] as $key => $config) {
                $index = '[PARAMS_'.$key.']';
                $value = $config["default_value"];

                // 如何取得value？
                if (isset($bitstream_params[$key])) {
                    $value = $bitstream_params[$key];
                    
                    if (isset($config["input_type"])) {
                        if ($config["input_type"] == "int") {
                            $value = (int) $value;
                        }
                    }
                    
                    if (is_null($value) || $value == '') {
                        $value = $config["default_value"];
                    }
                }

                $step = str_replace($index, $value, $step);
            }
        }
        
        return $step;
    }
    
    /**
     * 取得完成下載的路徑
     * @return string
     */
    public function get_completed_dir() {
        $convert_files = $this->CI->config->item("convert_files");
        $dir_path = $convert_files["completed"];
        
        $dir_path = format_dir_separator($dir_path);
        
        if (substr($dir_path, -1) != DIRECTORY_SEPARATOR) {
            $dir_path = $dir_path . DIRECTORY_SEPARATOR;
        }
        
        $base_path = $this->get_base_path();
        
        $full_path = $base_path . $dir_path;
        
        return $full_path;
    }
    
    /**
     * 取得本應用程式所在的實體路徑
     * @return String 路徑
     */
    public function get_base_path() {
        $needle = 'application';
        $base_path = substr(__DIR__, 0, strpos(__DIR__, $needle));
        return $base_path;
    }

    /**
     * 轉換完成
     * @param Bitstream $bitstream
     */
    public function convert_completed($bitstream) {
        //$this->puli_log->create_log($bitstream, 'convert_completed');
        return $this;
    }
    
    /**
     * 上鎖
     */
    private function _lock() {
        if ($this->_is_locked()) {
            return;
        }
        $date = $this->_get_lock_content();
        file_put_contents($this->_get_lock_file_path(), $date);
    }
    
    /**
     * 解鎖
     */
    private function _unlock() {
        if ($this->_is_locked() === FALSE) {
            return;
        }
        unlink($this->_get_lock_file_path());
        //echo "unlock!!";
    }
    
    /**
     * 解鎖的公開版本
     */
    public function unlock() {
        $this->_unlock();
    }
    
    /**
     * 是否上鎖
     */
    private function _is_locked() {
        return is_file($this->_get_lock_file_path());
    }
    
    /**
     * 取得上鎖檔案路徑
     * @return {String}
     */
    private function _get_lock_file_path() {
        $rootpath = get_root_path();
        return $rootpath."converter-lock.txt";
    }
    
    /**
     * 設定上鎖檔案內容
     * @return {String}
     */
    private function _get_lock_content() {
        $date = date("Y/m/d G:i:s");
        return $date;
    }
}

/* End of file Converter.php */
/* Location: ./system/application/libraries/.../Converter.php */