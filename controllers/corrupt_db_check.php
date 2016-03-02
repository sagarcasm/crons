<?php
/*
============================== FILE COMMENTS ==============================
THis is a test cron for testing data live 
===========================================================================
*/

class Corrupt_db_check extends MX_Controller 
{
  //var $errors = "";
  var $page_name = "crons";
  var $page_h1 = "Crons";
  var $uri_val = '';
  var $insert_id = 0;
  var $testing = 0;
  var $connection_error = 0;
  
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
    $this->email_sync_details();
  }
  
  function email_sync_details()
  {
    $testing = 0;        //while testing cron make it 1 else 0
    $cron_id = 181 ;      //entry from dashboard cron table    21
    $total_counter  = 12;
    $email_data = array();
    $s_error = array();
    $db_error = array();
    
    $error_log      = array();
    $os_array       = "'LINUX'";
    $ex_array       = "'External'";
    $int_ext_array  = "'Exa Hosting', 'Ready Server', 'Redirector server','Dedicated - Exa Hosting','Testbed Server'";
    //domains
    $include_account_types = array('0','9','18','15','22'); //exasite,microsite,freebie
    $exclude_account_type = bring_exclude_values($include_account_types,'id', "account_type");
    $exclude = exclude_multi_values('account_type', $exclude_account_type);
    
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
                    ORDER BY s.server_name ASC"; 
                    
    
    $cron_data      = get_cron_limit($res_arr, $total_counter, $cron_id, $testing); 
    $insert_id      = array_key_exists('insert_id', $cron_data) ? $cron_data['insert_id'] : "";
    $result_s       = $this->db_interaction->run_query($cron_data['query']);
    
    foreach ($result_s as $server)
    {
      //ssh connection 
      $this->ssh->server_name   = $server['server_name'];
      $this->ssh->ip            = $server['ip_address'];
      $this->ssh->username      = aes_decrypt($server['server_username']);
      $this->ssh->password      = transform($server["server_password"],KEY);
      $connection = $this->ssh->connect();
      $pos = strpos($connection,"ERROR");
      
      if($pos === false) 
      {
        $result_arr = "SELECT d.domain,d.id,d.domain,d.account_type,pr.priority,acc_t.account_types
                    FROM ".TBL_DOMAINS." AS d 
                    LEFT JOIN ".TBL_IP_ADDRESS." AS ips ON d.ip_address_id = ".$server['ip_address_id']."
                    LEFT JOIN ".TBL_ACCOUNT_TYPES." AS acc_t ON d.account_type = acc_t.id
                    LEFT JOIN ".TBL_PRIORITY." AS pr ON d.priority = pr.id
                    WHERE ips.status = 'y'
                    AND d.launched = 1
                    AND ".$exclude."
                    GROUP BY d.id";
        $result_domain  = $this->db_interaction->run_query($result_arr);
        
        if(is_array($result_domain) && count($result_domain)>0)
        {
          foreach ($result_domain as $domains)
          {
            $db_details = "SELECT db_name,db_username,db_password,db_host FROM " . TBL_DBS . " WHERE domain_id='" . $domains['id'] . "' ORDER BY id DESC";
            $result_db  = $this->db_interaction->run_query($db_details);

            $db_array = array();
            $db_tbls = array();
            
            if(is_array($result_db) && count($result_db)>0)
            {
              foreach ($result_db as $db)
              {
                $email_data['domain_scanned']['domain'][$domains['id']] =  $domains['domain'];
                $rds = strpos($db['db_host'], "rds.amazonaws.com");
                if ($db['db_host'] == 'localhost')
                {
                  $shw_tables = "mysql -u root -e 'show tables from ".$db['db_name']."'";
                }
                else
                {
                  $shw_tables = "mysql -h ".$db['db_host']." -P 3306 -u ".$db['db_username']." -p".$db['db_password']." -e 'show tables from ".$db['db_name']."'";
                }
                $table_data = $this->ssh->execute_sec2($shw_tables);

                $error_tbl = strpos($table_data['error'], "ERROR");
                if ($error_tbl === false) //false
                {
                  $dbs = explode("\n", trim($table_data['output']));

                  $dbs = trim_array($dbs);
                  if (count($dbs) > 0) 
                  {
                    $db_tables = array_shift($dbs);
                    foreach ($dbs as $db_table)
                    {
                      $db_table = str_replace('\n',"", $db_table);
                      if ($db['db_host'] == 'localhost') 
                      {
                        $tabl_check = "mysql -u root -e 'use ".$db['db_name']."; select * from `".$db_table."` limit 1';";
                      }
                      else
                      {
                        $tabl_check = "mysql -h ".$db['db_host']." -P 3306 -u ".$db['db_username']." -p".$db['db_password']." -e 'use ".$db['db_name']."; select * from `".$db_table."` limit 1';";
                      }                  
                      $table_check = $this->ssh->execute_sec2($tabl_check);

                      $tbl_check = strpos($table_check['error'], "ERROR");
                      if($tbl_check !== false)//true
                      {
                        $db_tbls[] = $db_table;
                        $email_data['table_error'][$domains['id']]['website']['account_type'] = $domains['account_types'];
                        $email_data['table_error'][$domains['id']]['website']['domain'] = $domains['domain'];
                        $email_data['table_error'][$domains['id']]['website']['priority'] = $domains['priority'];
                        $email_data['table_error'][$domains['id']]['database'][$db['db_name']] = $db_tbls;
                      }
                    } 
                  }
                }
                else
                {
                  $email_data['db_not_connected'][$domains['id']]['domain_name'] = $domains['domain'];
                  $email_data['db_not_connected'][$domains['id']]['account_type'] = $domains['account_types'];
                  $email_data['db_not_connected'][$domains['id']]['priority'] = $domains['priority'];
                  $email_data['db_not_connected'][$domains['id']]['error'][$db['db_name']] = $table_data['error'];
                }
              }
            }// end of if(is_array($result_db) && count($result_db)>0)
          }
        }// end of if(is_array($result_domain) && count($result_domain)>0)
      }
      else
      {
        $email_data['server_error'][$server['id']]['server_name'] =  $server['server_name'];
        $email_data['server_error'][$server['id']]['ip'] =  $server['ip_address'];
      }
    }
    
    if (isset($email_data['domain_scanned']) AND !empty($email_data['domain_scanned']))
    {
      $subject="Domains Scanned for corrupt databases - Count(".count($email_data['domain_scanned']['domain']).")";
      $body  = $this->load->view('email_tpl_header', "", true);
      $body .= $this->load->view('corrupt_db_scanned',$email_data['domain_scanned'],true);
      $body .= $this->load->view('email_tpl_footer', "", true);
      // send_mail($subject, $body, array('Testec' => 'testec@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
      send_mail($subject, $body, array('Sagar Sawant' => 'sagar.sawant@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
    } 
    
    if (isset($email_data['table_error']) OR !empty($email_data['table_error']))
    {
      foreach ($email_data['table_error'] as $domains)
      {
        $subject="Corrupt table found for the domain - ".$domains['website']['domain']."";
        $body  = $this->load->view('email_tpl_header', "", true);
        $body .= $this->load->view('corrupt_db',$domains,true);
        $body .= $this->load->view('email_tpl_footer', "", true);
        // send_mail($subject, $body, array('TasksIT' => 'tasksit@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
        send_mail($subject, $body, array('Sagar Sawant' => 'sagar.sawant@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
      }
    } 
   
    if (isset($email_data['db_not_connected']) OR !empty($email_data['db_not_connected']))
    {
      foreach ($email_data['db_not_connected'] as $domain)
      {
        $subject="Corrupt database found for the domain - ".$domain['domain_name']."";
        $body  = $this->load->view('email_tpl_header', "", true);
        $body .= $this->load->view('corrupt_db_error',$domain,true);
        $body .= $this->load->view('email_tpl_footer', "", true);
        // send_mail($subject, $body, array('TasksIT' => 'tasksit@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
        send_mail($subject, $body, array('Sagar Sawant' => 'sagar.sawant@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
      }
    } 
    
    $cron_array 	= get_cron_data($cron_id, $email_data, $cron_data, $testing);
    $subject='Corrupt db Monthly Report'. ' - '.date('Y-m-d'); 
    $body  = $this->load->view('email_tpl_header', "", true);
    $body .= $this->load->view('corrupt_db_full_report',$cron_array,true);
    $body .= $this->load->view('email_tpl_footer', "", true);
    // $mail_status  =  send_mail($subject, $body, array('Testec' => 'testec@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1); 
    $mail_status  =  send_mail($subject, $body, array('Sagar Sawant' => 'sagar.sawant@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1); 
    cron_entry($mail_status, 2, $testing, $body, 'Corrupt db Monthly Report'. ' - '.date('Y-m-d'), $insert_id);
    
  }  
}
?>