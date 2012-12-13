<?php
header("Content-type: text/xml");


$response_tmpl =
<<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<response>
<osmp_txn_id>[OSMP_TXN_ID]</osmp_txn_id>
<prv_txn>[PRV_TXN]</prv_txn>
<sum>[SUM]</sum>
<result>[RESULT]</result>
<comment>[COMMENT]</comment>
</response>
EOF;

require_once('conf.php'); global $conf;

// logging function
function log_msg($msg) {
  global $conf;
  if ($log_file=@fopen($conf['logpath'].'osmp.log', 'a')) {
    fputs($log_file,date('Y:m:d G:i:s')." ".$msg."\n");
    fclose($log_file);
    return true;
  } else return false;
}

// request transaction ID
$txn_id='';

// previous transaction ID
$prv_txn='';

// sum of payment
$sum='';

// code of operation result
$result='';

// transaction comment
$comment='';

// request command (payment or checking)
$command=$_GET["command"]; 

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// check request
if ($command == "check") {

  // read parameters

  // transaction id
  $txn_id = $_GET["txn_id"];

  // add_param id
  $account = $_GET["account"];

  // sum of payment
  $sum = $_GET["sum"];

  // parse parameters

  // transaction id - numbers, length 1-20
  if (preg_match("/(^[0-9]{1,20}$)/", $txn_id)) {

      // add_param - numbers, length 5-10
      if (preg_match("/(^[0-9]{5,10}$)/", $account)) {

      // sum of payment - float (1-6.1-6)
      if (preg_match("/(^[0-9]{1,6})([.])([0-9]{1,6}$)/", $sum)) {

        // connect to DB
        $bd = mysql_connect($conf['mysql_hostname'], $conf['mysql_user'], $conf['mysql_passwd']);
        if ($bd) {

          // get basic account by received additional parameter
          $sql_select_acc=mysql_query("SELECT users.basic_account FROM UTM5.users WHERE users.id IN (SELECT userid FROM UTM5.user_additional_params WHERE value=$account AND paramid=2) AND users.is_deleted!=1;");
          $data = mysql_fetch_array($sql_select_acc, MYSQL_ASSOC);
          $basic_account = $data["basic_account"];

          // if basic account not found
          if ($basic_account == "") {
            $result = "5";
            $comment = "Personal account $account not found";
            log_msg($comment);
          }

          // if basic account found
          if ($basic_account != "") {
            $result = "0";
            $comment = "Personal account $account is found";
            log_msg($comment);
          }
          mysql_close($bd);

        // if connecton to DB failure
        } else {
          $result = "1";
          $comment = "Database not accessed";
          log_msg($comment);
        }

      // if sums format is incorrect
      } else {
        $result = "300";
        $comment = "Incorrent format of sum";
        log_msg($comment);
      }

    // if add_params format is incorrect
    } else {
      $result = "4";
      $comment = "Incorrent format of account";
      log_msg($comment);
    }

  // if transactions id format is incorrect
  } else {
    $result = "300";
    $comment = "Incorrent format of transaction id";
    log_msg($comment);
  }
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// payment request
if ($command == "pay") {

  // transaction id
  $txn_id = $_GET["txn_id"];

  // transaction date
  $txn_date = $_GET["txn_date"];

  // add_param id
  $account = $_GET["account"];

  // sum of payment
  $sum = $_GET["sum"];

  // parse parameters

  // transaction id - numbers, length 1-20
  if (preg_match("/(^[0-9]{1,20}$)/", $txn_id)) {

    // add_param - numbers, length 5-10
    if (preg_match("/(^[0-9]{5,10}$)/", $account)) {

      // sum of payment - float (1-6.1-6)
      if (preg_match("/(^[0-9]{1,6})([.])([0-9]{1,6}$)/", $sum)) {

        // transaction date - format YYYYMMDDhhmmss
        if (preg_match("/^([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})$/", $txn_date, $regs)) {

          // connect to DB
          $bd = mysql_connect($conf['mysql_hostname'], $conf['mysql_user'], $conf['mysql_passwd']);
          if ($bd) {

            // parse date
            $year = $regs[1]; $month = $regs[2]; $day = $regs[3];
            $hour = $regs[4]; $min = $regs[5]; $sec = $regs[6];
            $bdate = mktime($hour, $min, $sec, $month, $day, $year);

            // get basic account by received additional parameter
            $sql_select_acc=mysql_query("SELECT users.basic_account FROM UTM5.users WHERE users.id IN (SELECT userid FROM UTM5.user_additional_params WHERE value=$account AND paramid=2) AND users.is_deleted!=1;");
            $data = mysql_fetch_array($sql_select_acc, MYSQL_ASSOC);
            $basic_account = $data["basic_account"];

            // get previous transaction id
            $sqlchk = mysql_query("SELECT temp.txn_id, temp.check FROM osmp.temp WHERE temp.txn_id = ".$txn_id." and temp.check != '1' LIMIT 1");
            $datachk = mysql_fetch_array($sqlchk, MYSQL_ASSOC);
            $payment_ext_number = $datachk["txn_id"];

            // repeat transaction check
            if ($payment_ext_number != $txn_id) {

              // transaction external number for UTM5
              $eedata = "OSMP".$txn_id;

              // run Netup payment tool with received parameters
              exec($conf['payment_tool_path']
                  . " -b " . $sum
                  . " -a " . $basic_account
                  . " -L 'Recieved from OSMP $sum' -k 'Recieved from OSMP $sum' -e " . $eedata
                  . " -t " . $bdate);

              // payment transaction verifying by ext_number
              $sqlchk2 = mysql_query("SELECT payment_ext_number FROM UTM5.payment_transactions WHERE payment_ext_number = '".$eedata."' and is_canceled != '1' LIMIT 1");
              $datachk2 = mysql_fetch_array($sqlchk2, MYSQL_ASSOC);
              $payment_ext_number2 = $datachk2["payment_ext_number"];

              // if payment transaction success
              if ($payment_ext_number2 == $eedata) {
                mysql_query("INSERT INTO osmp.temp VALUES (" . $txn_id . ", " . $basic_account . ", " . $sum . ", 0)");
                $prv_txn = mysql_insert_id();
                $result = "0";
                $comment = "Payment for $account recieved $sum roubles";
                log_msg($comment);

              // if payment transaction failure
              } elseif ($payment_ext_number2 != $txn_id) {
                $result = "5";
                $comment = "Payment account $account not found";
                log_msg($comment);
              }

            // if transaction alredy exist
            } elseif ($payment_ext_number == $txn_id) {
              $sql_select_from_temp=mysql_query("SELECT temp.account, temp.sum FROM osmp.temp WHERE temp.txn_id = ".$txn_id." and temp.check != 1 LIMIT 1");
              $data_from_temp=mysql_fetch_array($sql_select_from_temp, MYSQL_ASSOC);
              $account=$data_from_temp["account"];
              $sum=$data_from_temp["sum"];
              $prv_txn = "0";
              $result = "0";
              $comment = "Payment with this transaction id alredy recieved";
              log_msg($comment);
            }
            mysql_close($bd);

          // if connecton to DB failure
          } elseif (!$bd) {
            $result = "1";
            $comment = "Database not accessed";
            log_msg($comment);
          }

        // if date format is incorrect
        } else {
          $result = "300";
          $comment = "Incorrent format of txn_date";
          log_msg($comment);
        }

      // if sums format is incorrect
      } else {
        $result = "300";
        $comment = "Incorrent format of sum";
        log_msg($comment);
      }

    // if add_params format is incorrect
    } else {
      $result = "4";
      $comment = "Incorrent format of account";
      log_msg($comment);
    }

  // if transactions id format is incorrect
  } else {
    $result = "300";
    $comment = "Incorrent format of txn_id";
    log_msg($comment);
  }
}

// received invalid command
if ($command != "check" && $command != "pay") {
  $result = "300";
  $comment = "Incorrect command request";
  log_msg($comment);
}

// out response
$replace = array("[RESULT]" => $result, "[OSMP_TXN_ID]" => $txn_id, "[COMMENT]" => $comment, "[PRV_TXN]" => $prv_txn, "[SUM]" => $sum);
  echo strtr($response_tmpl, $replace);
