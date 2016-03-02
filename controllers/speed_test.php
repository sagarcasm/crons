<?php
/*
============================== FILE COMMENTS ==============================
THis is a test cron for testing data live 
===========================================================================
*/

class Speed_test extends MX_Controller 
{
  //var $errors = "";
  var $page_name = "crons";
  var $page_h1 = "Crons";
  var $uri_val = '';
  var $insert_id = 0;
  var $testing = 0;
  var $connection_error = 0;
  var $server_array = array(2169,2225,2938,2173,2629,3234);
  
  
  function __construct()
  {
    parent::__construct();
    $this->load->model('db_interaction');
    $this->load->helper("phpmailer");
    $this->load->helper("common_function");
    $this->load->helper('custom_values_helper');
    $this->load->library("ssh");
    $this->load->helper('server_types');
    $this->load->helper('db_inet_helper');
    $this->load->helper('db_encrypt');
    $this->load->helper('stdlib');
    $this->load->helper('dashboard_cron');
  }
  
  function index($uri = '')
  {	
    if ($uri != "")
    $this->uri_val = $uri;
    $this->speed_test();
  }
  
  function speed_test()
  {
    $failed_connection = array();
    
    error_reporting(E_ALL);
    // log cron activity status in DB
    $uri_test = $this->uri_val;
    if ($uri_test == '') 
    {
      // $testing = 0;        //while testing cron make it 1 else 0
      // $cron_id = 181 ;      //entry from dashboard cron table    21
      // $total_counter  = 2;
    }
    // $insert_id      = cron_entry($cron_id, 1, $testing);
    
    $error_log      = array();
    $os_array       = "'LINUX'";
    $ex_array       = "'External'";
    $int_ext_array  = "'Exa Hosting', 'Ready Server', 'Redirector server','Dedicated - Exa Hosting'";
    
    $res_arr = "SELECT s.id,s.server_username,s.server_password,s.server_name, ips.ip_address,ips.ip_address_id, s.internal_ip_address,s.id
                    FROM ".TBL_SERVERS." AS s, ".TBL_IP_ADDRESS." AS ips
                    WHERE s.id = ips.server_id
                    AND s.id IN (".fetch_server($int_ext_array).")
                    AND s.id IN (".fetch_server($os_array).")
                    AND s.id IN (".fetch_server($ex_array).")
                    AND s.id not IN (".fetch_inactive_server_list('INACTIVE/CANCELLED').")
                    AND s.id NOT IN (".EX_SERVER_ID.")
                    and ips.status = 'y'
                    Group By s.server_name
                    ORDER BY s.server_name ASC LIMIT 5"; 
    $result_s  = $this->db_interaction->run_query($res_arr);
    echo $query = $this->db->last_query();
    echo '<pre>';
    print_r( $result_s);
    echo '</pre>';exit;
    
    foreach ($result_s as $server)
    {
      $ip                       = $server['ip_address'];
      $server_name              = $server['server_name'];
      $server_username          = aes_decrypt($server['server_username']);
      $server_password          = transform($server["server_password"],KEY);
      
      //ssh connection 
      $this->ssh->server_name   = $server_name;
      $this->ssh->ip            = $ip;
      $this->ssh->username      = $server_username;
      $this->ssh->password      = $server_password;
      $connection = $this->ssh->connect();
      $pos = strpos($connection,"ERROR");
      
      if($pos === false) 
      {
        foreach ($this->server_array as $value)
        {
          echo '-----From server id $value-------';
          $tabl_check = '(/usr/local/bin/speedtest --bytes --server '.$value.'| egrep "load:|Hosted by|results")';
          $table_check = $this->ssh->execute_sec2($tabl_check);
          echo '<pre>';
          print_r($table_check);
          echo '</pre>';
        }
      }
    }
  }
}
?>