<?php
/**
* Product - class
* 
* Current class must call the constructor of the Base Class called Controller
*/
class Server_email_details extends MX_Controller
{    
  var $msg				= "";	
  var $errors 			= "";
  var $mode 				= "";
  var $page_name			= "crons";
  var $page_h1			= "Crons";
  var $uri_val = '';
  var $cron_body	=	'';
  var $entity_id = 14;//entity id 
  var $attribute_array = array(24,25,26);//attribute id
  
  function __construct()
  {
    parent::__construct();
    $this->load->model('db_interaction');
    $this->load->library("ssh");
    $this->load->helper('xmlapi');
    $this->load->helper("common_function");
    $this->load->helper('custom_values_helper');
    $this->load->helper('db_encrypt');
    $this->load->helper('stdlib');
    $this->load->helper('eav');
    $this->load->helper('server_types');
    $this->load->helper('dashboard_cron');
    $this->load->helper("phpmailer");
    $this->load->helper('db_inet_helper');
    $this->load->helper('cron_log');
    $this->load->helper('array_helper');
    $this->load->helper('substituted_functions');
  }
  
  function index($uri = '')
  {	
     if ($uri != "")
            $this->uri_val = $uri;
        $this->email_sync_details();
  }
  
  function email_sync_details()
  {	
    $emails_added = array();
    $emails_db = array();
    $subject  = 'Emails accounts updated for Domains on Servers';
    error_reporting(E_ALL);
    // log cron activity status in DB
    $uri_test = $this->uri_val;
    if ($uri_test == '') 
    {
      $testing = 1;        //while testing cron make it 1 else 0
      $cron_id = 161;      //entry from dashboard cron table    21
    }
    $entity_id = 14;       //entity id for the database insert
    $include_account_types = array('0','9','18'); //exasite,microsite,freebie
    $exclude_account_type = bring_exclude_values($include_account_types,'id', "account_type");
    $exclude = exclude_multi_values('account_type', $exclude_account_type);
    $result_arr = "SELECT d.username,d.domain,d.id,d.mx,ips.ip_address_id
                      FROM ".TBL_DOMAINS." AS d 
                      LEFT JOIN ".TBL_IP_ADDRESS." AS ips ON d.mx = ips.ip_address_id
                      WHERE ips.status = 'y'
                      AND ".$exclude." 
                      AND ips.main_ip = 'y'
                      GROUP BY ips.ip_address_id LIMIT 5";
    $total_counter = 50; // 48 and   30
    $res_arr = array();
    $cron_data = get_cron_limit($result_arr, $total_counter, $cron_id, $testing, $uri_test);
   
    $insert_id = array_key_exists('insert_id', $cron_data) ? $cron_data['insert_id'] : "";
    $result_domain  = $this->db_interaction->run_query($cron_data['query']); 
    
    
    $mx_id = array();
    $ip_add_check = '';
    foreach ($result_domain as $d_value)
    {
      if ($ip_add_check != $d_value['ip_address_id'])
      {
        $ip_add_check = $d_value['ip_address_id'];
        $result = "SELECT s.server_username,s.server_password,s.server_name, ips.ip_address ,ips.ip_address_id, s.id
                  FROM ".TBL_SERVERS." AS s 
                  LEFT JOIN ".TBL_IP_ADDRESS." AS ips ON s.id = ips.server_id
                  WHERE ips.ip_address_id = '".$d_value['ip_address_id']."'
                  GROUP BY s.id";
        $result_s = $this->db->query($result)->result_array();
        
        $host = $result_s[0]['ip_address'];
        $password = transform($result_s[0]["server_password"],KEY);
        $xmlapi = new xmlapi($host);
        $xmlapi->password_auth("root",$password);
        $xmlapi->set_output("array"); 
      }
      $mx_id = $d_value['mx'];
      $username = aes_decrypt($d_value["username"]);
      $email_details = $xmlapi->listemail($username);
      echo '<pre>';
      print_r($email_details);
      echo '</pre>';
      
      if (isset($email_details['data']['result']) AND $email_details['data']['result'] == 0)
      {
        echo 'Connection Failure.';echo '</br>';
      }
      else
      { 
        $get_domain_email = $this->get_email_db_data($d_value['id'], 1);// get the domain email details present in EC,1 for emails only
        if (count($get_domain_email) > 0 )
        {
          $get_full_domain_email = $this->get_email_db_data($d_value['id'], 2);
        }
        
        if (! isset($email_details['data']['email']))
        {
          $live_emails = array();
          foreach ($email_details['data'] as $acc_key => $acc_val)
          {
            $live_emails[] = $acc_val['email'];
            if (! in_array($acc_val['email'], $get_domain_email)) //insert data 
            {
              $emails_added[$d_value['ip_address_id']]['email'][] = $acc_val;
              $attri_values = array(
              '24'=>$acc_val['email'],//attribute id for email 
              '25'=>$acc_val['diskquota'],//attribute id for diskquota 
              '26'=>''//attribute id for password 
              );
              $this->insert_data($acc_val,$d_value['id'],$attri_values);
            }
            else //update data
            {
              foreach($get_domain_email as $row_id => $row_value)
              {
                if ($acc_val['email'] == $row_value)
                {
                  $set_row_val = $row_id;
                  break;
                } 
              }
              if ($get_full_domain_email[$set_row_val][25] != $acc_val['diskquota'])
              {
                $this->update_data($acc_val['diskquota'],$set_row_val);
              }
            }
          }
          //make the extra emails inactive in ec
          $this->delete_data($get_domain_email,$live_emails);
          unset($live_emails);
        }
        elseif(isset($email_details['data']['email']))
        {
          $live_emails = array();
          if (! in_array($acc_val['email'], $get_domain_email)) //insert data 
          {
            $emails_added[$d_value['ip_address_id']]['email'][] = $email_details['data'];
            $attri_values = array(
            '24'=>$email_details['data']['email'],//attribute id for email 
            '25'=>$email_details['data']['diskquota'],//attribute id for diskquota 
            '26'=>''//attribute id for password 
            );
            $this->insert_data($email_details['data'],$d_value['id'],$attri_values);
          }
          else
          {
            $live_emails[] =  $acc_val['email'];
            foreach($get_domain_email as $row_id => $row_value)
            {
              if ($acc_val['email'] == $row_value)
              {
                $set_row_val = $row_id;
                break;
              } 
            }
            if ($get_full_domain_email[$set_row_val][25] != $acc_val['diskquota'])
            {
              $this->update_data($acc_val['diskquota'],$set_row_val);
            }
            //make the extra emails inactive in ec
            $this->delete_data($get_domain_email,$live_emails);
            unset($live_emails);
          }
        }
      }
    } 
    
    if (isset($emails_added) AND !empty($emails_added))
    {
      $data['domain'] = $mx_id;
      $data['emails'] = $emails_added;
     
      $cron_array = array();
      $cron_array[] = $this->load->view("email_details_add", $data, true);
      
      if(($cron_data['total_counter'] == $cron_data['current_counter']) || ($cron_data['total_data_count'] <= ($cron_data['limit'] * $cron_data['current_counter'])))
      {
        $recepients = array(
                            'Team Leaders'       => 'teamleaders@exateam.com',
                            'Team Deputy'        => 'deputy@exateam.com'
                            );
        $cron_array = get_cron_data($cron_id, $cron_array, $cron_data, $testing);
        cron_entry(1, 2, $testing, implode('', $cron_array),  $subject. ' - '.date('Y-m-d'), $insert_id);
        $body  = $this->load->view('email_header', "", true);
        $body .= implode('', $cron_array);
        $body .= $this->load->view('email_footer', "", true);
        send_mail($subject, $body, $recepients, array(), '', '', 'ExaCare', 'allit@exateam.com', '', '', 1);
        
     }
     else
     {
        $recepients   = array(
                      'Sagar Sawant'  => 'sagar.sawant@exateam.com',
                      'Test EC'       => 'testec@exateam.com'
                      );
        get_cron_data($cron_id, $cron_array, $cron_data, $testing);
        cron_entry(2, 2, $testing, '', $subject. ' - '.date('Y-m-d'), $insert_id);
        $body  = $this->load->view('email_header', "", true);
        $body .= $this->load->view("email_details_add", $data, true);
        $body .= $this->load->view('email_footer', "", true);
        $mail_status    = send_mail($subject, $body, $recepients, $cc_mail, '', '', "ExaCare", "allit@exateam.com");
      }
    }
  }
  
  function insert_data($data = array(),$id='',$attri_values = array())
  {
    $parent_id = get_parent_id('domains',$id);
    $modified_by = 531;//exa shared 
    $modified_on = date('Y-m-d H:i:s');
    $row_token = get_last_row_id();
    $row_token = $row_token + 1;
    
    foreach($attri_values as $e_id => $e_val) 
    {
      $t_data['row_id'] = $row_token;
      $t_data['entity_id'] = $this->entity_id;
      $t_data['attribute_id'] = $e_id;
      $t_data['value'] = $e_val;
      $t_data['domain_id'] = $id;
      $flag = $this->db->insert(TBL_VALUES,$t_data);
      if($flag) 
      {
        // history_update($row_token,TBL_VALUES , 'row_id', $parent_id,$modified_by, $modified_on,1);
      }
    }
  }
  
  function update_data($disk_quota,$row_val)
  {
    $update_sql = "UPDATE ".TBL_VALUES." SET `value` = ".$disk_quota." WHERE `row_id` = ".$row_val." AND `attribute_id` = 25";
    $this->db->query($update_sql);
  }
  
  function delete_data($get_domain_email = array(),$live_emails = array())
  {
    $extra_emails = array_diff($get_domain_email, $live_emails);
    foreach($extra_emails as $db_key => $db_mails)
    {
      $update_sql = "UPDATE ".TBL_VALUES." SET `status` = 0 WHERE `row_id` = ".$db_key."";
      $this->db->query($update_sql);
    }
  }
  
  function get_email_db_data($id = '', $check)
  {
    $data_entities = array();
    $data_entities = get_attributes(array('14'),'domains',$id);
    if ($check == 1)
    {
      $db_emails_values  = array();
      if (isset($data_entities['domain_emails']['types'][$id][$this->entity_id]))
      {
        $db_check = $data_entities['domain_emails']['types'][$id][$this->entity_id];
        foreach ($db_check as $row_id => $value)
        {
          $db_emails_values[$row_id] = $value[24]['value'];
        }
      }
      return $db_emails_values;
    }
    elseif ($check == 2)
    {
      if (isset($data_entities['domain_emails']['types'][$id][$this->entity_id]))
      {
        return $data_entities['domain_emails']['types'][$id][$this->entity_id];
      }
      else
      {
        return $data_entities;
      }
    }
  }
}
// END OF FILE		
?>