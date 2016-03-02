<?php
class Cron_ssl_check extends MX_Controller 
{
  var $check = array('+A','-A','A','+B','B');
  
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
    $this->ssl_check();
  }
  
  function ssl_check()
  {
    $testing = 0;        //while testing cron make it 1 else 0
    $cron_id = 203 ;      //entry from dashboard cron table    21
    $total_counter  = 10;
    $ssl_check = array();
    
    $include_account_types = array('0'); //exasite,microsite,freebie ,testbed - '15',
    $exclude_account_type = bring_exclude_values($include_account_types,'id', "account_type");
    $exclude = exclude_multi_values('account_type', $exclude_account_type);
    
    $res_server = "SELECT s.id,s.server_username,s.server_password,s.server_name, ips.ip_address,ips.ip_address_id, s.internal_ip_address,s.id
                    FROM ".TBL_SERVERS." AS s, ".TBL_IP_ADDRESS." AS ips
                    WHERE s.id = ips.server_id
                    AND s.id IN (298)
                    and ips.status = 'y'
                    AND ips.main_ip = 'y'
                    Group By s.server_name
                    ORDER BY s.server_name ASC"; 
    $server     = $this->db_interaction->run_query($res_server);
       
    $this->ssh->server_name   = $server[0]['server_name'];
    $this->ssh->ip            = $server[0]['ip_address'];
    $this->ssh->username      = aes_decrypt($server[0]['server_username']);
    $this->ssh->password      = transform($server[0]["server_password"],KEY);
    $connection = $this->ssh->connect();
    
    $pos = strpos($connection,"ERROR");
    if($pos === false) 
    {
      $result_arr = "SELECT d.has_ssl,d.domain,d.id,d.domain,d.account_type,pr.priority,acc_t.account_types
                    FROM ".TBL_DOMAINS." AS d 
                    LEFT JOIN ".TBL_ACCOUNT_TYPES." AS acc_t ON d.account_type = acc_t.id
                    LEFT JOIN ".TBL_PRIORITY." AS pr ON d.priority = pr.id
                    WHERE d.has_ssl = 1
                    AND d.launched = 1
                    AND ".$exclude." 
                    GROUP BY d.id";
                    
      $cron_data      = get_cron_limit($result_arr, $total_counter, $cron_id, $testing); 
      $insert_id      = array_key_exists('insert_id', $cron_data) ? $cron_data['insert_id'] : "";
      $result_d       = $this->db_interaction->run_query($cron_data['query']);
                        
      foreach($result_d as $domains)
      {
        $cmd =  "grade=`go run /root/ssllabs-scan/ssllabs-scan.go --quiet --grade ".$domains['domain']." | awk '{print $2}' | sed 's/^".'"'."\(.*\)".'"'."$/"."\\1"."/'`;echo ".'$grade;';
        $grade = $this->ssh->execute_sec2($cmd);
        $grade_char = trim(str_replace('\n',"", $grade['output']));
        $error_check = strpos($table_data['error'], "ERROR");
        
        if ($error_check === false)
        {
          if (empty($grade_char))
          {
            $domains['grade'] = 'N/A';
            $this->update_ssl_status($domains);
            $ssl_check['data'][$domains['id']] = $domains;
          }
          else
          {
            if (!in_array($grade_char,$this->check))
            {
              $domains['grade'] = $grade_char;
              $this->email_it($domains);
              $ssl_check['data'][$domains['id']] = $domains;
            }
            else
            {
              $domains['grade'] = $grade_char;
              $ssl_check['data'][$domains['id']] = $domains;
            }
          }
        }//if ($error_check === false)
      }//foreach($result_d as $domains)
      
      $cron_array 	= get_cron_data($cron_id, $ssl_check, $cron_data, $testing);
      $subject = "SSL check summary ".' - '.date('Y-m-d');
      $body  = $this->load->view('email_tpl_header', "", true);
      $body .= $this->load->view('ssl_check_report', $cron_array, true);
      $body .= $this->load->view('email_tpl_footer', "", true);
      send_mail($subject, $body, array('Sagar Sawant' => 'sagar.sawant@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
      cron_entry($mail_status, 2, $testing, $body, $subject, $insert_id);
    }//if($pos === false)
  }//function ssl_check() 
  
  function update_ssl_status($domains)
  {
    $domains['update_status'] = '1';
    $subject = "SSL grade not found for the domain ".$domains['domain'];
    /* //$update = "UPDATE ".TBL_DOMAINS." SET has_ssl = '0' WHERE id =".$domains['id'];
    //$this->db->query($update); */
    $body  = $this->load->view('email_tpl_header', "", true);
    $body .= $this->load->view('ssl_check_view', $domains, true);
    $body .= $this->load->view('email_tpl_footer', "", true);
    send_mail($subject, $body, array('Sagar Sawant' => 'sagar.sawant@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
  }//function update_ssl_status($domains)
  
  function email_it($domains)
  {
    $domains['update_status'] = '0';
    $subject = "SSL grade found below 'B' for the domain ".$domains['domain']."(".$domains['grade'].")";
    $body  = $this->load->view('email_tpl_header', "", true);
    $body .= $this->load->view('ssl_check_view', $domains, true);
    $body .= $this->load->view('email_tpl_footer', "", true);
    send_mail($subject, $body, array('Sagar Sawant' => 'sagar.sawant@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
  }//function email_it($domains)
}//class Cron_ssl_check extends MX_Controller 
?>