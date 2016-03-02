<?php
/*
made live on exa.lv

exa.lv
  Widget url : http://exa.lv/generate_password
  API url    : http://exa.lv/generate_password/get_passwords/

EC -->
  Widget url : http://ec/generate_password_api/
  API    url : http://ec/generate_password_api/get_passwords/

*/
class Generate_password_api extends MX_Controller
{
  function __construct()
  {
    parent::__construct();
  }

  function index()
  {
    $this->password_generator();
  }

  function password_generator()
  {
    $data['pagetitle']        = 'Password Generator';
    $data['health_check']     = array('testbed_login' => 0);
    $data['web_magnet']       = array(
                                      'wm_status' => 1,
                                      'wm_meta_tags' => 1,
                                      'ga_code' => '',
                                      'meta_key_google' => '',
                                      'meta_key_yahoo' => '',
                                      'meta_key_bing' => '',
                                      'wm_meta_tags' => '',
                                      'ga_code_status' => '',
                                      );

    $this->load->view('frontendheader', $data);
    $this->load->view('top_messages', $data);
    $this->load->view('password_generator_api_view');
    $this->load->view('footer.php');
  }

  function password_from_db()
  {
    $strength   = ($this->input->post('strength')) ? $this->input->post('strength') : 'easy';
    $length     = ($this->input->post('length')) ? $this->input->post('length') : 0;
    echo $this->generate_password_from_db(0, $strength, $length);
  }
  
  //fetching Single | Multiple password(s) from DB table - password dictionary
  //mode - display -0 or return as json -1
  function generate_password_from_db($mode=0, $strength='easy', $length=0)
  {
    $password   = '';
    $out_pwd    = '<tr><td>No Data to process</td></tr>';
    $rtn_pwd    = array();

    $sql_pass     = "SELECT length, word FROM password_dictionary WHERE ";
    switch($strength)
    {
      case 'medium' :
                      $sql_pass   .= " length BETWEEN 4 AND 5 AND word not like '%l%'";
                      $word_count = 2;
                      $rand_word  = rand(0,1);
                      break;

      case 'complex' :
                      $sql_pass   .= " length BETWEEN 5 AND 6 AND word REGEXP '^[a-z][^l]+[a-k m-z]$'";
                      $word_count = 3;
                      $rand_word  = rand(0,2);
                      break;
      default :
                      $sql_pass   .= " length BETWEEN 4 AND 5 AND word not like '%l%'";
                      $word_count = 1;
                      $rand_word  = 0;
    }
    $res_pass     = $this->db->query($sql_pass);
    if($res_pass->num_rows() > 0)
    {
      $out_pwd    = '';
      $res_pass   = $res_pass->result_array();
      shuffle($res_pass);
      if(!empty($length) && is_numeric($length))
      {
        for($ipwd=0; $ipwd < $length; $ipwd++)
        {
          $cont_ind = array_rand($res_pass, $word_count);
          if($word_count == 1)
          {
            $cont_ind = array($cont_ind);
          }
          $temp       = $this->return_password($cont_ind, $res_pass, $word_count, $strength, $rand_word);
          $rtn_pwd[]  = $temp;
          $out_pwd  .= '<tr><td>' . $temp . '</td></tr>';
          $res_pass = array_diff_key($res_pass, array_flip($cont_ind));
        }
      }
      else
      {
        $cont_ind = array_rand($res_pass, $word_count);
        if($word_count == 1)
        {
          $cont_ind = array($cont_ind);
        }
        $temp       = $this->return_password($cont_ind, $res_pass, $word_count, $strength, $rand_word);
        $rtn_pwd[]  = $temp;
        $out_pwd  .= '<tr><td>' . $temp . '</td></tr>';
      }
    }

    unset($res_pass);
    if($mode == 0)
    {
      $password   = '<table id="pass_out_table" cellpadding="3" cellspacing="1" class="table table-bordered table-striped" style="font-weight:bold;"><tr><th class="th_back" style="width:40%;text-align:center;">Your Password</th></tr>' . $out_pwd . '</table>';
      return $password;
    }
    else
    {
      echo json_encode($rtn_pwd);
//      return json_encode($rtn_pwd);
    }
  }

  function string_caps($string_pass, $method='easy', $index=0)
  {
    $str_len  = strlen($string_pass);
    switch($method)
    {
      case 'complex':
                      ($index == 0) ? ($string_pass = ucfirst($string_pass)) : $string_pass[$str_len-2] = strtoupper($string_pass[$str_len-2]);
                      break;
    }
    return $string_pass;
  }

  function return_password($cont_ind, $res_pass, $word_count, $strength, $rand_word)
  {
    $special    = array('.', '-');
    $content    = array();

    if($strength == 'easy')
    {
      foreach ($cont_ind as $index)
      {
        $content[]  = $res_pass[$index]['word'];
      }
    }
    else
    {
      foreach ($cont_ind as $index)
      {
        $content[]  = $res_pass[$index]['word'].$special[rand(0,1)];
      }
    }

    if($strength == 'complex')
    {//word capitalisation only for complex
      $content[$rand_word] = $this->string_caps($content[$rand_word], $strength, 0);
    }
    shuffle($content);
    $content             = rtrim(rtrim(implode('', $content), '.'), '-');
    $word_count          = strlen($content);

    if($strength == 'easy')
    {
      $min = 6-$word_count;     $max = 8-$word_count;
      if($min < 1) {
        $min = 1;
      }
    }
    elseif($strength == 'medium')
    {
      $min = 10-$word_count;     $max = 12-$word_count;
      if($min < 1) {
        $min = 1;
      }
    }
    elseif($strength == 'complex')
    {
      $min = 20-$word_count;     $max = 23-$word_count;
      if($min < 2) {
        $min = 2;
      }
    }
    if($max > 4)
      $max = 4;
    $number     = $this->get_random_num(rand($min, $max));
    $pass_array = array_merge(array($content), $number);
    shuffle($pass_array);
    $password   = implode('', $pass_array);
    $password   = str_replace(array('I', 'O'), array('i', 'o'), $password);   //Always Replace char O -> o, I -> i
    if($strength == 'complex')
      $password   = str_replace('l', 'L', $password); //Always Replace char l->L
    unset($cont_ind, $res_pass, $word_count, $strength, $rand_word, $pass_array);
    return $password;
  }

  function get_random_num($length)
  {
    $number = array();
    for($icnt=0; $icnt < $length; $icnt++)
    {
      $number[] = rand(2,9);
    }
    return $number;
  }

  function otp_check($func)
  {
    $key                = '3xaCar3-API';
    $timestamp          = strtotime('now');
    $cur_date           = date('Y-m-d', $timestamp);
    $addcheck_date_time = date('H', strtotime('+1 hour', $timestamp));
    $remcheck_date_time = date('H', strtotime('-1 hour', $timestamp));
    $time_append        = $cur_date . '-' . '(' . $remcheck_date_time . '' . '-' . $addcheck_date_time . ')';
    $signature          = sha1($key . $func . $time_append);
    return $signature;
  }

  function get_passwords($signature, $strength='easy', $length=0)
  {
    $ec_signature       = $this->otp_check('get_passwords');
    $data               = array('Signature not matched.');
    if($ec_signature == $signature)
    {
      $data             = $this->generate_password_from_db(1, $strength, $length);
    }
    return json_encode($data);
  }
}

?>