<?php

class Cron_add_db extends MX_Controller {
  var $uri_val = '';
  var $errors = array();
  
  function __construct() {
    parent::__construct();
    $this->load->model('db_interaction');
    $this->load->helper("phpmailer");
    $this->load->helper("common_function");
    $this->load->helper('custom_values_helper');
    $this->load->library("ssh");
    $this->load->helper('cookie');
    $this->load->library('Digg_Pagination');
    $this->load->helper('pagination');
    $this->load->helper('server_types');
    $this->load->helper('db_inet_helper');
    $this->load->helper('db_encrypt');
    $this->load->helper('stdlib');
    $this->load->helper('cron_log');
    $this->load->helper('dashboard_cron');
    $this->load->helper('array_helper');
  }

  function index($uri = '') {
    if ($uri != "") {
      $this->uri_val = $uri;
    }
    $this->_send_report();
  }
  
  function _send_report() {
    $uri_test = $this->uri_val;
    if ($uri_test == '') {
      $testing = 0;       //while testing cron make it 1 else 0
      $cron_id = 95;      //entry from dashboard cron table 95
    }
    $info = array();
    
    $res_arr = "SELECT d.id, d.domain, d.username, d.account_type, d.priority,
                ips.ip_address AS ser_ip, s.id as server_id, s.server_name AS ser_name, 
                s.server_username as ser_user, s.server_password as ser_pass
                FROM ".TBL_DOMAINS." AS d
                LEFT JOIN ".TBL_IP_ADDRESS." AS ips ON d.ip_address_id=ips.ip_address_id
                LEFT JOIN ".TBL_SERVERS." AS s ON ips.server_id=s.id  /* ORIGINAL */
                WHERE 1=1
                AND s.server_name !=''
                AND NOT (FIND_IN_SET('10', d.account_type) OR FIND_IN_SET('8', d.account_type) OR FIND_IN_SET('19', d.account_type))
                AND (FIND_IN_SET('0', d.account_type) OR FIND_IN_SET('18', d.account_type) OR FIND_IN_SET('22', d.account_type))
                AND d.launched = 1 
                AND s.id NOT IN (".fetch_inactive_server_list("INACTIVE/CANCELLED").")
                AND s.id IN (".fetch_serverid_str_by_os(LINUX).")
/* Testing 
                AND d.id IN (298, 5595, 593, 816, 5536, 
                            5454, 5453, 1007, 1631, 5038, 
                            1424, 2199, 2203, 306, 2206, 
                            5305, 2950, 3893, 5628, 5972, 
                            5448, 4856, 3941, 5439, 6028, 
                            4089, 267, 543, 237, 360)
Testing */
                GROUP BY d.id
                ORDER BY d.priority DESC, d.domain, s.server_name";
    
    $total_counter = 10; // 48 and 30
    $cron_data = get_cron_limit($res_arr, $total_counter, $cron_id, $testing, $uri_test);
    $insert_id = isset($cron_data['insert_id'])? $cron_data['insert_id']:"";
    $res       = $this->db_interaction->run_query($cron_data['query']);
    
    $info = $this->add_details($res);
    $data['info']   = $info;
    $data['errors'] = $this->errors;
    
    $data = get_cron_data($cron_id, $data, $cron_data, $testing);
    $data['total'] = $cron_data['total_data_count'];
    $data['cron_id'] = $cron_id;
    
    $subject = "Update DB details in EC, Domains scanned (".$data['total'].") issue with (".count($data['info']).")";
    $body = $this->load->view('add_db', $data, TRUE);
    
    if(count($data['info']) > 0 ) {
      $recepients = array('TeamDev TLs' => 'teamdevtls@',
                    'TeamDevDeputy' => 'teamdevdeputy@'
                    );
      $cc = array();
      $mail_status = send_mail($subject, $body, $recepients, $cc, '', '', "ExaCare", "allit@");
    } else {
      $recepients = array('Team EC' => 'testec@');
      $cc = array();
      $mail_status = send_mail($subject, $body, $recepients, $cc, '', '', "ExaCare", "allit@");
    }    
  }
    
  function add_details($res_arr) {
    error_reporting(E_ALL);
    ini_set("memory_limit", -1);
    $temp_name = '';
    $main_ips = $this->get_main_ip();
    $data = array();
    
    foreach($res_arr as $key => $details) {
      $domain_username = aes_decrypt($details['username']);
      $domain_id  = $details['id'];
      $priority   = $details['priority'];
      $domain_name = $details['domain']." (".$priority.")";
      $ser_name   = $details['ser_name'];
      $ser_id     = $details['server_id'];
      $ip         = $details['ser_ip'];
      $insert_status = 0;
      
      $username   = aes_decrypt($details['ser_user']);
      $password   = transform($details['ser_pass'], KEY);
      
      // SERVER CHANGED DO SSH CONNECTION
      if ($temp_name != $ser_name) {
        $temp_name  = $ser_name;
        if(array_key_exists($ser_id, $main_ips)) {
          $m_ip = $main_ips[$ser_id];
        } else {
          $m_ip = $ip;
        }
        $this->ssh->server_name = $temp_name;
        $this->ssh->ip          = $m_ip;
        $this->ssh->username    = $username;
        $this->ssh->password    = $password;
        
        $ee = $this->ssh->connect();
        $error_pos = strpos($ee, "ERROR");
      } else { // if ($temp_name != $ser_name) {
        // CONTINUE WITH OLD SERVER SSH CONNECTION
      }
      
      // AUTHENTICATION SUCCESSFUL
      if ($error_pos === false) {
        $run_database = "mysql -u root -e 'SHOW DATABASES' | grep '".$domain_username."_'";
        $database = trim($this->ssh->execute($run_database));
        $error_pos = strpos($database, "ERROR");
        if ($error_pos === false) {
          $dbs = array();
          $dbs = explode("\n", trim($database));
          $dbs = trim_array($dbs);
          
          if (count($dbs) > 0) {
            foreach ($dbs as $db_key => $db_val) {
              if(!empty($db_val)) {
                $sql = "SELECT db.*, d.domain, d.priority FROM ".TBL_DBS." AS db, ".TBL_DOMAINS." AS d 
                        WHERE 1 AND d.id = db.domain_id AND db_name = ".$this->db->escape($db_val);
                $res_sql = $this->db_interaction->run_query($sql);
                
                if(count($res_sql) > 0) {
                  foreach($res_sql as $res_sql_key => $res_sql_val) {
                    if(empty($res_sql_val['db_type'])) {
                      $data[$domain_name][$res_sql_val['db_name']][] = 'DB Type missing';
                    }
                    if(empty($res_sql_val['db_username'])) {
                      $data[$domain_name][$res_sql_val['db_name']][] = 'DB User Name missing';
                    }
                    if(empty($res_sql_val['db_password'])) {
                      $data[$domain_name][$res_sql_val['db_name']][] = 'DB Password missing';
                    }
                    if(empty($res_sql_val['db_host'])) {
                      $data[$domain_name][$res_sql_val['db_name']][] = 'DB Host missing';
                    }
                  }
                } else {
                  $insert_sql = "INSERT INTO dbs (domain_id, db_type, db_name, db_host) 
                                  VALUES (".$this->db->escape($domain_id).",'Mysql',".$this->db->escape($db_val).", 'localhost')";
                  $this->db->query($insert_sql);
                  $insert_status = 1;
                  $data[$domain_name][$db_val][] = 'New DB Added';
                  $data[$domain_name][$db_val][] = 'DB User Name missing';
                  $data[$domain_name][$db_val][] = 'DB Password missing';
                }
              } else { // if(!empty($db_val)) {
                $this->errors[] = 'No DB found for '.$domain_name.' on server '.$ser_name;
              }
            }
          } else {
            $this->errors[] = 'No DB found for '.$domain_name.' on server '.$ser_name;
          }
        } else {
          $this->errors[] = 'Error for '.$domain_name.' - '.$database;
        }
      } else { //if ($error_pos === false) {
        $this->errors[] = $ser_name . ' >> Server Auth Failed';
      }
      if($insert_status == 1) {
        $modified_by = $this->session->userdata['user_login_id'];
        $modified_on = date('Y-m-d H:i:s');
        update_all_history($domain_id,'domains','id',$modified_by,$modified_on);
      } else { // if($insert_status == 1) {
//         DO NOTHING
      }
    }
    return $data;
  }
  
  function get_main_ip() {
    $res = array();
    $return_array = array();
    $sql_server = "SELECT S.id,S.server_name,S.server_username,S.server_password,IP.ip_address
                    FROM " . TBL_SERVERS . " S
                    LEFT JOIN " . TBL_IP_ADDRESS . " IP ON (IP.server_id = S.id)
                    WHERE IP.main_ip='y' AND IP.status='y'
                    GROUP BY S.server_name";
    $res = $this->db_interaction->run_query($sql_server);
    if(count($res) > 0) {
      foreach ($res as $data) {
        $return_array[$data["id"]] = $data["ip_address"];
      } // foreach ($res as $data) {
    } // if(count($res) > 0) {
    return $return_array;
  } 
}
?>