<?php

/**
 * All-in-one BrowserID (aka Mozilla Persona) component.
 * Displays a login link, when clicked, handles the transaction
 * with persona verifier, sets $_SESSION['BrowserIDAuth'] to the user's email.
 * echo BrowserID_Handle('http://example.com/index.php');
 * @param $return to - URL for persona provider to redirect user back to,
    should contain a called instance of this function to continue processing
 * @param $endpoint - BrowserID Consumer to use (e.g. persona.org)
 */

// echo BrowserID_Handle();

function BrowserID_Handle($returnto = null, $ep = 'https://persona.org/verify')
{
  if(!$returnto) {
    $returnto = '//'.getenv('SERVER_NAME').getenv('REQUEST_URI');
  }
  if(session_status() != \PHP_SESSION_ACTIVE) {
    session_start();
  }
  // Handle request to log out
  if(isset($_REQUEST['BrowserIDDestroy']))
  {
    $_SESSION['BrowserIDAuth'] = false;
    unset($_SESSION['BrowserIDAuth']);
    exit(header("Location: $returnto"));
  }
  // Handle request to log in
  elseif(isset($_POST['BrowserIDAssertion']))
  {
    $assertion = $_POST['BrowserIDAssertion'];
    $audience  = getenv('HTTP_HOST');
    $stream    = stream_context_create(array('http' => array(
      'method' => 'POST'
    , 'content'=> "assertion=$assertion&audience=$audience"
    , 'header' => 'Content-type: application/x-www-form-urlencoded'
    )));
    if(false === $res = file_get_contents($ep, false, $stream)) {
      throw new \Exception('['.$ep.']: No Response');
    }
    elseif(false === ($json = json_decode($res))) {
      throw new \Exception('['.$ep.']: Parsing Response JSON Failed.');
    }
    elseif(!isset($json->status) || $json->status == 'failure') {
      $reason = empty($json->reason) ? 'Reason unavailable.' : $json->reason;
      throw new \Exception('['.$ep.']: '.$reason);
    }
    else {
      if($json->status == 'okay' && isset($json->email)) {
        $_SESSION['BrowserIDResponseJSON'] = $json;
        $_SESSION['BrowserIDAuth'] = $json->email;
      }
      else {
        $_SESSION['BrowserIDAuth'] = false;
        unset($_SESSION['BrowserIDAuth']);
      }
      // Redirect & continue processing
      exit(header('Location: '.$returnto));
    }
  }
  // return HTML forms.
  else
  {
    // Log-out form if logged in
    if(isset($_SESSION['BrowserIDAuth'])) {
      $u = $_SESSION['BrowserIDAuth'];
      return
        '<form method="post" id="BrowserIDLogout">'
      . '<input type="submit" name="BrowserIDDestroy" value="Log Out ['.$u.']">'
      . '</form>';
    }
    return
      '<form id="BrowserIDLogin" method="POST" action="'.$returnto.'">'
    . '<input id="BrowserIDAssertion" type="hidden" name="BrowserIDAssertion">'
    . '<script src="https://login.persona.org/include.js"></script>'
    . '<script>'
    . 'function BrowserIDVerify() { '
      . 'navigator.id.get(function(ass){ '
      . '  if(ass){ '
      . '    document.getElementById("BrowserIDAssertion").value = ass; '
      . '    document.getElementById("BrowserIDLogin").submit(); '
      . '  } else { alert("BrowserID Assertion Failed."); } '
      . '});'
    . '}'
    . '</script>'
    . '<a href="#" onclick="BrowserIDVerify();">Log In [Persona]</a></form>';
  }
}
