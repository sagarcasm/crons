<?php
class server_load extends MX_Controller {
  
  function __construct() {
    parent::__construct();
    $this->load->model('db_interaction');
    $this->load->helper("phpmailer");
    $this->load->helper("common_function");
    $this->load->library("ssh");
    
    $this->load->helper('substituted_functions');
    $this->load->helper('cron_log');
    $this->load->helper('array_helper');
    $this->load->helper('db_encrypt');
    
    $this->load->helper('server_types');
    $this->load->helper('date');
    $this->load->helper('dashboard_cron');
  }

  function index() {
    $this->get_server_load();
  }

  function get_server_load() {
    $testing = 0;       //while testing cron make it 1 else 0
    $cron_id = 121;     //entry from dashboard cron table
    $insert_id = cron_entry($cron_id, 1, $testing);

    $current_date = date('Y-m-d');
    $last_month_date = strtotime($current_date.'-1 months');
    $last_month_date = date('Y-m-d', $last_month_date);
    
    $select_sql = "SELECT id, server_id, server_name, myob, myob_name, email_date 
                    FROM email_server_load WHERE email_date BETWEEN '".$last_month_date."' AND '".$current_date."' 
                    ORDER BY email_date DESC";
    $select_res = $this->db->query($select_sql)->result_array();
    $servers = array();
    if(count($select_res) > 0) {
      foreach ($select_res as $details) {
        $servers[date('W', strtotime($details['email_date']))][strtolower($details['server_name'])][] = $details['id'];
      }
    
      unset($select_res);
      $data = array();
      $year = date('Y');
      if(count($servers) > 0) {
        foreach ($servers as $week_no => $details) {
          foreach ($details as $server_name => $value) {
            $date_range = $this->getStartAndEndDate($week_no, $year);
            $data[$server_name][$date_range[0]." - ".$date_range[1]] = count($value);
          }
        }
      }
      unset($servers);
      $final_data['data'] = $data;
      $data['cron_id'] = $cron_id;
      unset($data);
      $body = $this->load->view("server_load_view", $final_data, true);
      $subject = "Server load warning(s)";
      $recepients = array('IT Tasks' => 'tasksit@');
      $mail_status = send_mail($subject, $body, $recepients, $cc = array(), '', '', "ExaCare", "allit@");
      cron_entry($mail_status, 2, $testing, $body, $subject, $insert_id);
    } else {
      cron_entry(2, 2, $testing, "No records found", "Successfull", $insert_id);
    }
  }

  function getStartAndEndDate($week, $year) {
    $time = strtotime("1 January ".$year, time());
    $day = date('w', $time);
    $time += ((7*$week)+1-$day)*24*3600;
    $return[0] = date('Y-m-d', $time);
    $time += 6*24*3600;
    $return[1] = date('Y-m-d', $time);
    return $return;
  }
}
?>