<?php
require_once 'inc/vars.inc.php';
require_once 'inc/functions.inc.php';
$config = array(
     'useEASforOutlook' => 'yes',
     'autodiscoverType' => 'activesync',
     'imap' => array(
       'server' => $mailcow_hostname,
       'port' => '993',
       'ssl' => 'on',
     ),
     'smtp' => array(
       'server' => $mailcow_hostname,
       'port' => '465',
       'ssl' => 'on'
     ),
     'activesync' => array(
       'url' => 'https://'.$mailcow_hostname.'/Microsoft-Server-ActiveSync'
     )
);

if(file_exists('inc/vars.local.inc.php')) {
	include_once 'inc/vars.local.inc.php';
}

/* ---------- DO NOT MODIFY ANYTHING BEYOND THIS LINE. IGNORE AT YOUR OWN RISK. ---------- */

error_reporting(0);

$data = trim(file_get_contents("php://input"));

// Desktop client needs IMAP, unless it's Outlook 2013 or higher on Windows
if (strpos($data, 'autodiscover/outlook/responseschema')) { // desktop client
	$config['autodiscoverType'] = 'imap';
	if ($config['useEASforOutlook'] == 'yes' &&
	    strpos($_SERVER['HTTP_USER_AGENT'], 'Outlook') !== FALSE && // Outlook
	    strpos($_SERVER['HTTP_USER_AGENT'], 'Windows NT') !== FALSE && // Windows
	    preg_match('/Outlook (1[5-9]\.|[2-9]|1[0-9][0-9])/', $_SERVER['HTTP_USER_AGENT']) && // Outlook 2013 (version 15) or higher
	    strpos($_SERVER['HTTP_USER_AGENT'], 'MS Connectivity Analyzer') === FALSE // https://testconnectivity.microsoft.com doesn't support EAS for Outlook
	) {
			$config['autodiscoverType'] = 'activesync';
	}
}

$dsn = "$database_type:host=$database_host;dbname=$database_name";
$opt = [
		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES   => false,
];
$pdo = new PDO($dsn, $database_user, $database_pass, $opt);
$login_user = strtolower(trim($_SERVER['PHP_AUTH_USER']));
$as = check_login($login_user, $_SERVER['PHP_AUTH_PW']);

if (!isset($_SERVER['PHP_AUTH_USER']) OR $as !== "user") {
	header('WWW-Authenticate: Basic realm=""');
	header('HTTP/1.0 401 Unauthorized');
	exit;
} else {
	if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		if ($as === "user") {
      header("Content-Type: application/xml");
      echo '<?xml version="1.0" encoding="utf-8" ?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">';

      if(!$data) {
        list($usec, $sec) = explode(' ', microtime());
        echo '<Response>';
        echo '<Error Time="' . date('H:i:s', $sec) . substr($usec, 0, strlen($usec) - 2) . '" Id="2477272013">';
        echo '<ErrorCode>600</ErrorCode><Message>Invalid Request</Message><DebugData /></Error>';
        echo '</Response>';
        echo '</Autodiscover>';
        exit(0);
      }
      $discover = new SimpleXMLElement($data);
      $email = $discover->Request->EMailAddress;

      if ($config['autodiscoverType'] == 'imap') {
      ?>
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
      <User>
          <DisplayName><?php echo $displayname; ?></DisplayName>
      </User>
      <Account>
          <AccountType>email</AccountType>
          <Action>settings</Action>
          <Protocol>
              <Type>IMAP</Type>
              <Server><?php echo $config['imap']['server']; ?></Server>
              <Port><?php echo $config['imap']['port']; ?></Port>
              <DomainRequired>off</DomainRequired>
              <LoginName><?php echo $email; ?></LoginName>
              <SPA>off</SPA>
              <SSL><?php echo $config['imap']['ssl']; ?></SSL>
              <AuthRequired>on</AuthRequired>
          </Protocol>
          <Protocol>
              <Type>SMTP</Type>
              <Server><?php echo $config['smtp']['server']; ?></Server>
              <Port><?php echo $config['smtp']['port']; ?></Port>
              <DomainRequired>off</DomainRequired>
              <LoginName><?php echo $email; ?></LoginName>
              <SPA>off</SPA>
              <SSL><?php echo $config['smtp']['ssl']; ?></SSL>
              <AuthRequired>on</AuthRequired>
              <UsePOPAuth>on</UsePOPAuth>
              <SMTPLast>off</SMTPLast>
          </Protocol>
          <Protocol>
              <Type>CalDAV</Type>
              <Server>https://<?php echo $mailcow_hostname; ?>/SOGo/dav/<?php echo $email; ?>/Calendar</Server>
              <DomainRequired>off</DomainRequired>
              <LoginName><?php echo $email; ?></LoginName>
          </Protocol>
          <Protocol>
              <Type>CardDAV</Type>
              <Server>https://<?php echo $mailcow_hostname; ?>/SOGo/dav/<?php echo $email; ?>/Contacts</Server>
              <DomainRequired>off</DomainRequired>
              <LoginName><?php echo $email; ?></LoginName>
          </Protocol>
      </Account>
  </Response>
      <?php
      }
      else if ($config['autodiscoverType'] == 'activesync') {
        $username = trim($email);
        try {
          $stmt = $pdo->prepare("SELECT `name` FROM `mailbox` WHERE `username`= :username");
          $stmt->execute(array(':username' => $username));
          $MailboxData = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        catch(PDOException $e) {
          die("Failed to determine name from SQL");
        }
        if (!empty($MailboxData['name'])) {
          $displayname = utf8_encode($MailboxData['name']);
        }
        else {
          $displayname = $email;
        }
      ?>
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006">
      <Culture>en:en</Culture>
      <User>
          <DisplayName><?php echo $displayname; ?></DisplayName>
          <EMailAddress><?php echo $email; ?></EMailAddress>
      </User>
      <Action>
          <Settings>
              <Server>
                  <Type>MobileSync</Type>
                  <Url><?php echo $config['activesync']['url']; ?></Url>
                  <Name><?php echo $config['activesync']['url']; ?></Name>
              </Server>
          </Settings>
      </Action>
  </Response>
      <?php
      }
      ?>
</Autodiscover>
<?php
		}
	}
}
?>
