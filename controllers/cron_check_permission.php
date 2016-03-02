<?php
class Cron_check_permission extends MX_Controller
{
  function __construct()
  {
    parent::__construct();   
    $this->load->model('DB_Interaction', 'db_interaction');
    $this->load->helper(array('phpmailer', 'db_encrypt', 'stdlib', 'dashboard_cron', 'substituted_functions','777_permission','array'));
    $this->load->library('ssh');
  }
  
  function index()
  {
    $testing        = 0;      //while testing cron make it 1 else 0
    $cron_id        = 184;    //entry from dashboard cron table
    $total_counter  = 32;

    $include_account_types  = array('0','18','22'); //exasite, microsite and mobisite
    $exclude_account_type   = bring_exclude_values($include_account_types,'id', "account_type");
    $exclude                = exclude_multi_values('account_type', $exclude_account_type);
    $result_arr             = "SELECT d.username,d.domain,d.id,ips.ip_address_id,ips.ip_address,d.domain_type,ips.server_id,permission_777
                              FROM ".TBL_DOMAINS." AS d 
                              LEFT JOIN ".TBL_IP_ADDRESS." AS ips ON d.ip_address_id = ips.ip_address_id
                              WHERE ips.status = 'y'
                              AND ".$exclude."
                              AND d.launched = 1
                              AND d.id != '8826'
                              AND d.id != '1920'
                              AND d.id != '1065'
                              AND d.id != '1896'
                              ORDER BY ips.server_id ,ips.ip_address_id";
    $cron_data      = get_cron_limit($result_arr, $total_counter, $cron_id, $testing); 
    $insert_id      = array_key_exists('insert_id', $cron_data) ? $cron_data['insert_id'] : "";
    $result         = $this->db_interaction->run_query($cron_data['query']); 
    
    $ip = '';
    $server_id          = '';
    $connected          = false;
    $report             = array();
    $not_connected_ips  = array();
    
    if(is_array($result) && count($result) > 0)
    {
      foreach ($result as $d_value)
      {
        if($server_id != $d_value['server_id'])
        {
          $result     = "SELECT s.server_username,s.server_password,s.server_name, ips.ip_address ,ips.ip_address_id, s.id,s.tags
                        FROM ".TBL_SERVERS." AS s 
                        LEFT JOIN ".TBL_IP_ADDRESS." AS ips ON s.id = ips.server_id
                        WHERE s.id = '".$d_value['server_id']."'
                        AND ips.main_ip = 'y'
                        AND ips.status = 'y'";
          $server     = $this->db->query($result)->result_array();
          $server_id  = $d_value['server_id'];
        }
        
        $s_tags = explode(',', $server[0]['tags']);
        $s_tags = trim_array($s_tags);

        if((in_array('393', $s_tags) !== FALSE ) )
        {
          $domain_name   = $d_value['domain'];
        }
        else
        {
          $domain_name   = aes_decrypt($d_value['username']);
        } 

        if ($ip != $server[0]['ip_address_id'])
        {
          $ip                       = $server[0]['ip_address'];
          $server_name              = $server[0]['server_name'];
          $server_username          = aes_decrypt($server[0]['server_username']);
          $server_password          = transform($server[0]["server_password"],KEY);

          $this->ssh->server_name   = $server_name;
          $this->ssh->ip            = $ip;
          $this->ssh->username      = $server_username;
          $this->ssh->password      = $server_password;
          $stream                   = $this->ssh->connect();

          $pos    = strpos($stream,"ERROR");

          if($pos === false) 
          {
            $ip = $server[0]['ip_address_id'];
            $connected = true;
          }
          else 
          {
            $connected = false;
            /* save the ips in an array which is not connected */
            $not_connected_ips[$server[0]['id']]["server_name"] = $server[0]['server_name'];
            $not_connected_ips[$server[0]['id']]["ip_address"]  = $server[0]['ip_address'];
            $not_connected_ips[$server[0]['id']]["error"]       = $stream;
            $ip = '';
          }
        }
        else
        {
          $connected = true;
        }
		
        if($connected == true) 
        {
          $path = $d_value['permission_777'];
          
          $ex = "";
          $i  = 0;
          if(trim($path) != "")
          {
            $check  = explode("\n",trim($path));
            foreach($check as $val)
            {
              $ex .= " -ive ".rtrim($check[$i],"/");
              $i++;
            }
          }
          else
          {
            $ex = "";
          }
          
          $permission_check = $this->ssh->execute(permission_check($domain_name,$ex,$s_tags));
                 
          if(trim($permission_check) != "")
          {
            $check  = explode("\n",trim($permission_check));
            $check  = implode("\n\n",$check);

            $report[$d_value['id']]['domain_id']              = $d_value['id'];
            $report[$d_value['id']]['domain_name']            = $d_value['domain'];
            $report[$d_value['id']]['folder__777_permission'] = $check;
          }
        }	
      }
    }

    $cron_array = array(
                          'permission_report'    => $report
                        ); 
 
    $recepients   = array('EP Tasks' => 'tasksdev@');

    if(array_key_exists('permission_report', $cron_array) && is_array($cron_array['permission_report']) && count($cron_array['permission_report']) > 0)
    {
      foreach($cron_array['permission_report'] as $val)
      {
        $val['cron_id'] = $cron_id;
        $body    = $this->load->view('email_tpl_header', "", true);
        $body   .= $this->load->view('cron_check_permission_report',$val,true);
        $body   .= $this->load->view('email_tpl_footer', "", true);
        $subject = " 777 Permission Report for the domain - ".$val['domain_name']."";
        $mail_status  = send_mail($subject, $body, $recepients, array(), '', '', "ExaCare", "allit@");
      }
    }
    
    $data         = get_cron_data($cron_id, $cron_array, $cron_data, $testing);
    cron_entry($mail_status, 2, $testing, $body, $subject, $insert_id);
  }
}