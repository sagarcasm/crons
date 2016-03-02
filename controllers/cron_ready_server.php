<?php
class Cron_ready_server extends MX_Controller{

	var $msg       = "";
	var $errors 	 = "";
  var $uri_val   = '';
  var $date_l    = '';
  var $insert_id = '';
  var $testing   = '';
  var $cpu_list = array();

  function __construct()
	{
		parent::__construct();
    $this->load->model('db_interaction');
    $this->load->helper("phpmailer");
    $this->load->helper('dashboard_cron');
    $this->load->helper("common_function");
	}

  function index()
	{
    $this->more_domains();
	}

	function _backup()
	{
    $testing = 0; //while testing cron make it 1 else 0
    $cron_id = 65;     //entry from dashboard cron table
    //cron_entry($val, $pos, $test, $body = '', $subject = '', $insert_id = '')
    $insert_id = cron_entry($cron_id, 1, $testing);
    
    $fetch_query1 = "SELECT serv.id, serv.server_name, serv.country, serv.ftptype_ftpenbl,serv.tags,
                          CAST(GROUP_CONCAT(DISTINCT custom.id ) AS BINARY) AS type_id, serv.FTP_Enabled,
                          serv.server_username, ser_typ.server_id,                        
                          CAST( GROUP_CONCAT(DISTINCT ips.ip_address ORDER BY ips.ip_address ASC SEPARATOR ', ') AS BINARY ) AS ip_add                          
                        FROM servers AS serv
                        LEFT OUTER JOIN server_types AS ser_typ ON serv.id = ser_typ.server_id
                        LEFT OUTER JOIN ip_addresses AS ips    ON serv.id = ips.server_id                                            
                        LEFT OUTER JOIN customvalues AS custom ON ser_typ.type_id = custom.id
                        WHERE NOT FIND_IN_SET( 266, serv.tags ) AND NOT FIND_IN_SET( 192, serv.tags )
                        GROUP BY serv.id
                        HAVING ((type_id LIKE '%276%' OR type_id LIKE '%246%' OR type_id LIKE '%174%' OR type_id LIKE '%178%' OR type_id LIKE '%183%') 
                        AND type_id LIKE  '%166%' 
                        AND type_id LIKE  '%182%' 
                        AND type_id NOT LIKE '%173%')
                        ORDER BY country, server_name ASC";

    $result_query = $this->db_interaction->run_query($fetch_query1);

    $count_server = count($result_query);
    $found_au = 0; $redirectn_server = 0; $testbed_server = 0; $ready_server = 0; $retail_hosting = 0; $exa_hosting = 0; $exa_host_au = 0;
    $au_ready = array(); $ready = array(); $exa_host = array(); $exa_host_au = array();
    if($count_server > 0)
    {
      foreach($result_query as $val)
      {
        $type_id = explode(",",$val['type_id']);           
        if(in_array('276', $type_id))
          $redirectn_server++;
        else if(in_array('246', $type_id))
          $testbed_server++;
        else if(in_array('174', $type_id))
        {
          $ready_server++;
          if($val['country'] == 'au')
          {
            $found_au = 1;
            $au_ready[] = $val['id'];
          }
          else 
          {
            $ready[] = $val['id'];
          }
        }
        else if(in_array('178', $type_id))
          $retail_hosting++;
        else if(in_array('183', $type_id) && $val['FTP_Enabled'] == 'Yes')
        {
          $exa_hosting++;
          if($val['country'] == 'au')
          {
            $exa_host_au = 1;
            $exa_host_au[] = $val['id'];
          }
          else 
          {
            $exa_host[] = $val['id'];
          }
        }
      }
    }
    unset($result_query);
    $ready_with_ssl = 0; $readyau_with_ssl = 0; $exahosting_with_ssl = 0; $exahostau_with_ssl = 0;
    if(count($ready) > 0)
    {
      $ready_with_ssl = $this->check_ip($ready);
    }
    if(count($au_ready) > 0)
    {
      $readyau_with_ssl = $this->check_ip($au_ready);
    }
    if(count($exa_host) > 0)
    {
      $exahosting_with_ssl = $this->check_ip($exa_host);
    }
    if(count($exa_host_au) > 0)
    {
      $exahostau_with_ssl = $this->check_ip($exa_host_au);
    }
    
    $error = array();
    $data = array();
    if($ready_server < 5 || $found_au == 0 || $redirectn_server == 0 || $testbed_server == 0 || $retail_hosting == 0 || $exa_hosting == 0 || $ready_with_ssl == 0
       || $readyau_with_ssl == 0 || $exahosting_with_ssl == 0 || $exahostau_with_ssl == 0 || $exa_host_au == 0)
    {
      if($ready_server < 5)
        $error[] = 'Ready Server in EC are less than 5 in number(Count:'.$ready_server.')';
      
      if($found_au == 0)
        $error[] = 'There is no Ready Server in EC marked as AU';

      if($redirectn_server == 0)
        $error[] = 'No Redirector Server in EC';

      if($retail_hosting == 0)
        $error[] = 'No Retail Hosting Only Server in EC';

      if($testbed_server == 0)
        $error[] = 'No Testbed Server in EC';
      
      if($exa_hosting == 0)
        $error[] = 'No FTP Enabled Exa Hosting Server in EC';
      
      if($exa_host_au == 0)
        $error[] = 'No FTP Enabled Exa Hosting Server in EC marked as AU';
      
      if($ready_with_ssl == 0)
        $error[] = 'No Free IP on any Ready Server with country other than AU';
      
      if($readyau_with_ssl == 0)
        $error[] = 'No Free IP on any Ready Server with country as AU';
      
      if($exahosting_with_ssl == 0)
        $error[] = 'No Free IP on any Exa Hosting Server with country other than AU';
      
      if($exahostau_with_ssl == 0)
        $error[] = 'No Free IP on any Exa Hosting Server with country as AU';
      $data['error'] = $error;
      $body = $this->load->view('ready_server', $data, TRUE);
      $subject = "Ready Server, Redirector Server and Retail Hosting Only Check";

//       $recepients = array(
//                    'vidya'	=> 'vidyashree.samani@'
//            );
      $recepients = array(
                   //'Rupesh'	=> 'rupeshnichani@gmail.com'
           );

      $recepients = array('Team EC' => 'testec@');
      $m_cc = array();
      $mail_status = send_mail($subject, $body, $recepients, $m_cc, '', '', "ExaCare", "allit@");
      //cron_entry($val, $pos, $test, $body = '', $subject = '', $insert_id = '')
      cron_entry($mail_status, 2, $testing, $body, $subject, $insert_id);
    }
    else
    {
      $subject = "Ready Server, Redirector Server and Retail Hosting Only Check";
      //cron_entry($val, $pos, $test, $body = '', $subject = '', $insert_id = '')
      cron_entry(2, 2, $testing, '', $subject, $insert_id);
    }
	}
  
  function check_ip($ready)
  {
    $ssl_ip = 0;
    foreach($ready as $ser_ip)
    {
      $sql_ip  = "SELECT *
                    FROM ".TBL_IP_ADDRESS."
                    WHERE server_id IN (".$ser_ip.") AND status = 'y' "; // Pickup the First Ready Server From List
      $res_ip = $this->db->query($sql_ip)->result_array();
      foreach($res_ip as $val_ip)
      {
        $sql_domain = "SELECT d.id, d.domain
                        FROM domains as d
                        WHERE d.ip_address_id = '".$val_ip['ip_address_id']."'";
        $res_domain = $this->db->query($sql_domain)->result_array();
        if(is_array($res_domain) && count($res_domain) > 0)
        {
          
        }
        else
        {
          $ssl_ip = 1;
          break;
        }
      }      
    }
    return $ssl_ip;
  }

  function more_domains()
  {
    $testing = 0; //while testing cron make it 1 else 0
    $cron_id = 70;     //entry from dashboard cron table
    $insert_id = cron_entry($cron_id, 1, $testing);
    $result_query = array();
    $result_vps = array();
    $data = array();
    $exceed_limit = array();

    $fetch_query1 = "SELECT serv.id,  p.provider_name,  serv.server_name, serv.country, serv.ftptype_ftpenbl, serv.tags, CAST( GROUP_CONCAT( DISTINCT custom.id ) AS 
                      BINARY ) AS type_id, serv.server_username, CAST( GROUP_CONCAT( DISTINCT ips.ip_address
                      ORDER BY ips.ip_address ASC 
                      SEPARATOR  ', ' ) AS 
                      BINARY ) AS ip_add, ser_typ.server_id, serv.CPU, serv.CPU2
                      FROM servers AS serv
                      LEFT OUTER JOIN server_types AS ser_typ ON serv.id = ser_typ.server_id
                      LEFT OUTER JOIN ip_addresses AS ips ON serv.id = ips.server_id
                      LEFT OUTER JOIN customvalues AS custom ON ser_typ.type_id = custom.id
                      LEFT OUTER JOIN providers AS p ON serv.provider_id = p.id 
                      WHERE serv.id != 28 
                      AND NOT FIND_IN_SET('266', serv.tags)
                      GROUP BY serv.id
                      HAVING (
                      (
                      type_id LIKE  '%174%'
                      OR type_id LIKE  '%183%'
                      OR type_id LIKE  '%276%'
                      OR type_id LIKE  '%306%'
                      OR type_id LIKE  '%178%'
                      OR type_id LIKE  '%246%'
                      )
                      AND type_id NOT LIKE  '%173%'
                      AND type_id LIKE  '%166%' 
                      AND type_id LIKE  '%182%' 
                      )
                      ORDER BY country, server_name ASC";

    $result_query = $this->db_interaction->run_query($fetch_query1);
      
    $fetch_query2 = "SELECT serv.id, serv.server_name, serv.country, serv.ftptype_ftpenbl, serv.tags, CAST( GROUP_CONCAT( DISTINCT custom.id ) AS 
                      BINARY ) AS type_id, serv.server_username, CAST( GROUP_CONCAT( DISTINCT ips.ip_address
                      ORDER BY ips.ip_address ASC 
                      SEPARATOR  ', ' ) AS 
                      BINARY ) AS ip_add, ser_typ.server_id
                      FROM servers AS serv
                      LEFT OUTER JOIN server_types AS ser_typ ON serv.id = ser_typ.server_id
                      LEFT OUTER JOIN ip_addresses AS ips ON serv.id = ips.server_id
                      LEFT OUTER JOIN customvalues AS custom ON ser_typ.type_id = custom.id
                      WHERE serv.id != 28
                      GROUP BY serv.id
                      HAVING (
                      (
                      type_id LIKE  '%181%'                                             
                      )
                      AND 
                      ( type_id NOT LIKE  '%173%' 
                        AND type_id NOT LIKE  '%174%'
                        AND type_id NOT LIKE  '%183%'
                        AND type_id NOT LIKE  '%276%'
                        AND type_id NOT LIKE  '%306%'
                        AND type_id NOT LIKE  '%178%'
                      )
                      )
                      ORDER BY country, server_name ASC";

    $result_vps = $this->db_interaction->run_query($fetch_query2);
   
    $cpu = "SELECT id,value FROM  `customvalues` WHERE  `name`='CPU'";
    $result_cpu = $this->db_interaction->run_query($cpu);
    foreach ($result_cpu as $cpu)
    {
      $this->cpu_list[$cpu['id']] = $cpu['value'];
    }
      
    if(count($result_query) > 0 && count($result_vps) > 0)
    {
      if(count($result_query) > 0)
      {
        foreach($result_query as $val_d)
        {
          $modified_by = '531';
          $modified_on = date('Y-m-d H:i:s');
          $active_domains = array();
          $active_query = "SELECT count(*) as active_domains
                                        FROM ".TBL_SERVERS." AS s, ".TBL_IP_ADDRESS." AS ips, ".TBL_DOMAINS." AS d
                                        WHERE NOT (FIND_IN_SET('10', d.account_type)) AND NOT (FIND_IN_SET('19', d.account_type))
                                        AND d.ip_address_id = ips.ip_address_id
                                        AND ips.server_id = s.id
                                        AND s.id=".$val_d["id"];
          $active_domains = $this->db_interaction->run_query($active_query);
          $type_id = explode(",", $val_d["type_id"]);
          
          $tags = explode(",", $val_d["tags"]);
          if(in_array("174", $type_id))
          { 
            //Ready Server
            $domains_cnt = $this->_cal_domaincnt($val_d['CPU'],174);//default 30
            if(count($active_domains) > 0 AND $active_domains[0]['active_domains'] > $domains_cnt)
            {
              $role_result = array();
              $role_query = "SELECT s.* , c2.value, c1.pid
                                FROM server_types AS s
                                LEFT JOIN customvalues AS c1 ON s.type_id = c1.id
                                LEFT JOIN customvalues AS c2 ON c2.id = c1.pid
                                WHERE s.server_id =".$val_d["id"]."
                                AND c2.id =162
                                AND c1.id =174";
              $role_result = $this->db_interaction->run_query($role_query);
              $data["server"][$val_d["server_name"]]["domain_cnt"] = $active_domains[0]['active_domains'];
              if(count($role_result) > 0)
              {
                $data["server"][$val_d["server_name"]]["update"] = 1;
                $update_query = "UPDATE server_types
                                    SET type_id = 183
                                    WHERE id = ".$role_result[0]["id"];
                $this->db->query($update_query);
                update_all_history($val_d["id"],'servers','id',$modified_by,$modified_on);
              }
              else
              {
                $data["server"][$val_d["server_name"]]["update"] = 0;
              }
              $amazon_check = strpos($val_d['provider_name'], "AMAZON");
              if ($amazon_check !== false)
              {
                $this->email_it($val_d,$domains_cnt);
              }
            }
            elseif ($active_domains[0]['active_domains'] < $domains_cnt && in_array("192", $tags))
            {
              //remove the tag  192
              $tags = array_unique($tags);
              unset($tags[array_search("192",$tags)]);
              $update_query = "UPDATE servers
                              SET tags = '".implode(",", $tags)."'
                              WHERE id = ".$val_d["id"];
              $data["tag_deleted"][$val_d["id"]]['s_name'] = $val_d["server_name"];
              $data["tag_deleted"][$val_d["id"]]['threshold'] = $domains_cnt;
              $data["tag_deleted"][$val_d["id"]]['active_domains'] = $active_domains[0]['active_domains'];
              $this->db->query($update_query);
              update_all_history($val_d["id"],'servers','id',$modified_by,$modified_on);
            }
          }
          else if(in_array("306", $type_id))
          { 
            //Dedicated - Exa Hosting
            $domains_cnt = $this->_cal_domaincnt($val_d['CPU'],306);//default 50
            if(count($active_domains) > 0 && $active_domains[0]['active_domains'] > $domains_cnt)
            {
              $exceed_limit[$val_d["server_name"]]["domain_cnt"] = $active_domains[0]['active_domains'];
              $exceed_limit[$val_d["server_name"]]["type"] = "Role Type Dedicated - Exa Hosting";
              $amazon_check = strpos($val_d['provider_name'], "AMAZON");
              if ($amazon_check !== false)
              {
                $this->email_it($val_d,$domains_cnt,$active_domains[0]['active_domains']);
              }
            }
            elseif($active_domains[0]['active_domains'] < $domains_cnt && in_array("192", $tags))
            {
              $tags = array_unique($tags);
              unset($tags[array_search("192",$tags)]);
              $update_query = "UPDATE servers
                              SET tags = '".implode(",", $tags)."'
                              WHERE id = ".$val_d["id"];
              $data["tag_deleted"][$val_d["id"]]['s_name'] = $val_d["server_name"];
              $data["tag_deleted"][$val_d["id"]]['threshold'] = $domains_cnt;
              $data["tag_deleted"][$val_d["id"]]['active_domains'] = $active_domains[0]['active_domains'];
              $this->db->query($update_query);
              update_all_history($val_d["id"],'servers','id',$modified_by,$modified_on);
            }
          }
          else
          {
            if(in_array("183", $type_id))
            { //Exa Hosting
              $count_dom = $this->_cal_domaincnt($val_d['CPU'],183);// $count_dom = 35;
            }
            elseif(in_array("178", $type_id))
            { 
              //Retail Hosting Only
              $count_dom = $this->_cal_domaincnt($val_d['CPU'],178);// $count_dom = 50;
            }
            elseif(in_array("276", $type_id))
            { //Redirector server
              $count_dom = 400;
            }
            else 
            { //Testbed server
              $count_dom = $this->_cal_domaincnt($val_d['CPU'],246);// $count_dom = 200;
            }
            if(count($active_domains) > 0)
            {
              if($active_domains[0]['active_domains'] >= $count_dom && !in_array("192", $tags))
              { // add tag if count of active domains exceed or equal to limit
                $update_query = "UPDATE servers
                                  SET tags = IF(tags='', '192', CONCAT(tags,',192'))
                                  WHERE id = ".$val_d["id"];
                $data["tag_added"][$val_d["id"]] = $val_d["server_name"]."(".$active_domains[0]['active_domains'].")"; 
                $this->db->query($update_query);
                update_all_history($val_d["id"],'servers','id',$modified_by,$modified_on);
                $amazon_check = strpos($val_d['provider_name'], "AMAZON");
                if ($amazon_check !== false)
                {
                  $this->email_it($val_d,$count_dom,$active_domains[0]['active_domains']);
                }
              }
              elseif($active_domains[0]['active_domains'] < $count_dom && in_array("192", $tags))
              { //remove tag if count of active domains is less than the limit
                unset($tags[array_search("192",$tags)]);
                $update_query = "UPDATE servers
                                  SET tags = '".implode(",", $tags)."'
                                  WHERE id = ".$val_d["id"];
                $data["tag_deleted"][$val_d["id"]]['s_name'] = $val_d["server_name"];
                $data["tag_deleted"][$val_d["id"]]['threshold'] = $count_dom;
                $data["tag_deleted"][$val_d["id"]]['active_domains'] = $active_domains[0]['active_domains'];
                $this->db->query($update_query);
                update_all_history($val_d["id"],'servers','id',$modified_by,$modified_on);
              }
            }
          }
        }
      }
      unset($result_query);
      if(count($result_vps) > 0)
      {
        foreach($result_vps as $val_vps)
        {
          $active_domains = array();
          $active_query = "SELECT count(*) as active_domains
                                        FROM ".TBL_SERVERS." AS s, ".TBL_IP_ADDRESS." AS ips, ".TBL_DOMAINS." AS d
                                        WHERE NOT (FIND_IN_SET('10', d.account_type)) 
                                        AND d.ip_address_id = ips.ip_address_id
                                        AND ips.server_id = s.id
                                        AND s.id=".$val_vps["id"];
          $active_domains = $this->db_interaction->run_query($active_query);
          $type_id = explode(",", $val_vps["type_id"]);
          if(count($active_domains) > 0 && $active_domains[0]['active_domains'] > 30)
          {
            $exceed_limit[$val_vps["server_name"]]["domain_cnt"] = $active_domains[0]['active_domains'];
            $exceed_limit[$val_vps["server_name"]]["type"] = "Category VPS";
          }
        }
      }
      
      if(count($exceed_limit) > 0)
      {
        $data["exceed"] = $exceed_limit;
      }

      if(count($data) > 0)
      {
        $body  = $this->load->view('email_tpl_header', "", true);
        $body .= $this->load->view('ready_domain', $data, TRUE);
        $body .= $this->load->view('email_tpl_footer', "", true);
        $subject = "Server having active domains more than threshold";
        $recepients = array(EC_MAIL_REPO	=> EC_MAIL_REPO_ID);
        $m_cc = array();
        $mail_status = send_mail($subject, $body, $recepients, $m_cc, '', '', "ExaCare", "allit@");
        cron_entry($mail_status, 2, $testing, $body, $subject, $insert_id);
      }
      else
      {
        $subject = "Server having active domains more than threshold";
        cron_entry(2, 2, $testing, '', $subject, $insert_id);
      }
    }
    else
    {
      $data["error"] = "1";
      $body  = $this->load->view('email_tpl_header', "", true);
      $body .= $this->load->view('ready_domain', $data, TRUE);
      $body .= $this->load->view('email_tpl_footer', "", true);
      $subject = "Server having active domains more than threshold";
      $recepients = array(EC_MAIL_REPO	=> EC_MAIL_REPO_ID);
      $m_cc = array();
      $mail_status = send_mail($subject, $body, $recepients, $m_cc, '', '', "ExaCare", "allit@");
      cron_entry($mail_status, 2, $testing, $body, $subject, $insert_id);
    }
    // Trigger cron to check Ready Server and Redirector Server
    $this->_backup();
  }
  
  function email_it($server_details,$threshold,$active_count)
  {
    if (array_key_exists($server_details['CPU'],$this->cpu_list))
    {
     $server_details['CPU'] = $this->cpu_list[$server_details['CPU']];
    }
    $server_details['threshold'] = $threshold;
    $server_details['active_count'] = $active_count;
    $subject="Active domains exceeds the threhold value for server -".$server_details['server_name'];
    $body  = $this->load->view('email_tpl_header', "", true);
    $body .= $this->load->view('ready_server_view',$server_details,true);
    $body .= $this->load->view('email_tpl_footer', "", true);
    send_mail($subject, $body, array('Tasksit' => 'tasksit@'), array(), '', '', 'ExaCare', 'allit@', '', '', 1);
    return;
  }
  
  function _cal_domaincnt($cpu_size, $role)
  {
    switch($cpu_size)
    {
      /* case 461:  //m3.2xlarge
        $threshold = 120; */
        
      case 438:  //m1.xlarge
      case 484:  //m3.xlarge
      case 446:  //m3.xlarge 13ecus
        $threshold = 150;
        break;
        
      case 445:  //m1.large
      case 479:  //m3.large
        $threshold = 75;
        break;
      case 428:  //m1.medium
      case 459:  //m3.medium
        $threshold = 45;
        break;
      
      /* case 425:  //m1.small
        $threshold = 15; */
      default:
        $threshold = 0;
    }
    
    if ($threshold != 0)
    {
      switch($role)
      { 
        case 174:  //Ready Server
          return $threshold;
          break;
        case 306:  //Dedicated hosting
        case 183:  //Exa Hosting
        case 178:  //Retail Hosting
          return $threshold+30;
          break;
        case 246:  //Testbed Server
          return $threshold+40;
          break;
        // default:
          // return 0;
      }
    }
    else //Defaults
    {
      switch($role)
      { 
        case 174:  //Ready Server
          return 30;
          break;
        case 306:  //Dedicated hosting
          return 50;
          break;
        case 183:  //Exa Hosting
          return 35;
          break;
        case 178:  //Retail Hosting
          return 50;
          break;
        case 246:  //Testbed Server
          return 200;
          break;
        // default:
          // return 0;
      }
    }
  } 
}//class over
?>