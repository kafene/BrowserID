<?php

namespace kafene
{

/**
 * kafene\Persona
 * All-in-one BrowserID (aka Mozilla Persona) component.
 * @copyright 2013 kafene software <http://kafene.org/>
 * @license http://unlicense.org/ Unlicense
 * @link https://github.com/kafene/browserid
 * @package kafene\Persona
 * @version 2013-05-05
 *
 * https://developer.mozilla.org/en-US/docs/Persona/Remote_Verification_API
 *
 * @todo CSRF protection
 *
 * --------------------------------------------------------------------------- *
 *
 * USAGE:
 *
 * For basic usage, you can call kafene\Persona::getInstance()->server()
 * somewhere before your response is sent. If it detects that the current
 * request meets all of the criteria that make it an AJAX request from the
 * authentication handling javascript, it will handle the processing and
 * output a JSON response and halt further execution.
 *
 * Wherever you want the link to display, you can call
 * `echo kafene\Persona::getInstance()`
 * which will print the javascript, necessary to handle login and logout
 * requests, and a link showing the current auth status. Feel free, of course,
 * to modify the HTML and JS for your use case.
 *
 * For backwards compatibility I have also included a globally namespaced
 * function called `BrowserID_Handle`, which can be called and will start
 * the service with optionally provided audience, endpoint and processor
 * parameters, and return the HTML that the script generates, making it a
 * drop-in solution.
 *
 * @example:
 *     $persona_html = BrowserID_Handle();
 *     // ... later ...
 *     echo $persona_html;
 * --------------------------------------------------------------------------- *
 *
 * CHANGES:
 *
 * 2013-07-08
 *     * Fixed so it works in Opera, which is very buggy with Persona due to its
 *       implementation of the same-origin policy. Unfortunately this means rely
 *       on data from PHP instead of from Persona onLogin but it should be the
 *       same information, anyway.
 *     * Removed guessProcessor, just using SCRIPT_NAME instead as it should
 *       work better in most cases. If not you can always set it manually :)
 *     * Diversify Exception classes.
 *
 * 2013-05-05:
 *     new session/request keys:
 *         'BrowserIDAuth' => 'persona_auth';
 *         'BrowserIDAssertion' => 'persona_assertion';
 *         'BrowserIDDestroy' => 'persona_logout';
 *         'BrowserIDResponseJSON' => 'persona_json';
 *     refactored into a class.
 *     javascript compatible with new navigator.id.watch()
 *     super ajax adventure! doesn't need to reload to login or logout.
 *
 * --------------------------------------------------------------------------- *
 * --------------------------------------------------------------------------- *
 */
class Persona
{

    /**
     * Auth provider endpoint URL
     *
     * @var string
     */
    protected $endpoint = 'https://verifier.login.persona.org/verify';
    # public $endpoint = 'https://persona.org/verify';

    /**
     * Audience to send to verifier - scheme://host:port
     *
     * @var string
     */
    protected $audience = '';

    /**
     * JSON Response processor/server script URL.
     *
     * @var string
     */
    protected $processor = '';

    /**
     * Endpoint response JSON.
     *
     * @var string
     */
    protected $response = '';

    /**
     * Optional singleton/multiton pattern instances.
     *
     * @var array
     */
    protected static $instances = [];

    /**
     * Set up the object. If no processor is provided, the default
     * is a reasonable guess at the current URL, so if you don't have
     * a separate file calling server(), make sure you call
     * it before any output is done in your script. This is so the object
     * can remain a single self-hosting PHP file.
     *
     * @param string $audience Audience to pass to the verifier.
     * @param string $processor URL to receive AJAX POST requests.
     * @param string $endpoint Endpoint for auth provider.
     * @return \kafene\Persona $this Self
     */
    function __construct($audience = '', $processor = '', $endpoint = null)
    {
        $this->audience($audience);
        $this->processor($processor);
        $this->endpoint($endpoint);
        if (session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }
        return $this;
    }


    /**
     * Singleton? Oh no!
     *
     * @param string $id Instance ID.
     * @return \kafene\Persona Existing or new class instance.
     */
    static function getInstance($id = 'default')
    {
        return array_key_exists($id, static::$instances)
            ? static::$instances[$id]
            : static::$instances[$id] = new static;
    }

    /**
     * Set or get processor URL.
     * In the absence of a processor URL, build a likely one.
     *
     * @param string $new New (or initial) processor URL.
     * @return string|object Existing processor URL, or \kafene\Persona $this Self
     * @throws \Exception If the URL is invalid.
     */
    function processor($new = null)
    {
        if (!$this->processor || $new) {
            $this->processor = $new ?: $this->audience().getenv('SCRIPT_NAME');
        }
        if (null === $new) {
            return $this->processor;
        }
        if (!filter_var($this->processor, FILTER_VALIDATE_URL)) {
            $errstr = 'Invalid processor "'.$this->processor.'".';
            throw new \UnexpectedValueException($errstr);
        }
        return $this;
    }

    /**
     * Set or get audience URL.
     *
     * @param string $new New (or initial) audience URL.
     * @return string|\kafene\Persona Existing audience URL, or $this Self
     * @throws \Exception If the URL is invalid.
     */
    function audience($new = null)
    {
        if (!$this->audience || $new) {
            $this->audience = $new ?: $this->guessAudience();
        }
        if (null === $new) {
            return $this->audience;
        }
        if (!filter_var($this->audience, FILTER_VALIDATE_URL)) {
            $errstr = 'Invalid audience "'.$this->audience.'".';
            throw new \UnexpectedValueException($errstr);
        }
        return $this;
    }

    /**
     * Guess an appropriate audience to send to the verifier.
     * Audience should be ~= scheme://host:port
     *
     * @return string guessed URL
     */
    protected function guessAudience()
    {
        $secure = filter_var(getenv('HTTPS'), FILTER_VALIDATE_BOOLEAN);
        $scheme = $secure ? 'https' : 'http';
        $host = getenv('HTTP_HOST') ?: getenv('SERVER_NAME');
        $p = intval(getenv('SERVER_PORT')) ?: 80;
        $port = (($secure && 443 == $p) || 80 == $p) ? '' : sprintf(':%d', $p);
        return sprintf('%s://%s%s', $scheme, $host, $port);
    }

    /**
     * Set or get Endpoint URL.
     *
     * @param string $new New (or initial) endpoint URL.
     * @return string|\kafene\Persona Existing endpoint, or $this Self
     * @throws \Exception If the URL is invalid.
     */
    function endpoint($new = null)
    {
        if (null === $new) {
            return $this->endpoint;
        }
        $this->endpoint = $new;
        if (!filter_var($this->endpoint, FILTER_VALIDATE_URL)) {
            $errstr = 'Invalid endpoint "'.$this->endpoint.'".';
            throw new \UnexpectedValueException($errstr);
        }
        return $this;
    }

    /**
     * Solicit a response from the endpoint and return the result.
     *
     * @param string $assertion The assertion POST-ed by the client.
     * @return string|boolean $response The endpoint response.
     */
    function getResponse($assertion)
    {
        $audience = $this->audience();
        $data = compact('assertion', 'audience');
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-type: application/x-www-form-urlencoded',
            'content' => http_build_query($data, '', '&')
        ]]);
        if ($response = file_get_contents($this->endpoint(), null, $ctx)) {
            return $response;
        } else {
            throw new \RuntimeException('Getting response from endpoint failed.');
        }
    }

    /**
     * Validate needed parts of a given response, or determine the error.
     *
     * @todo Verify expiry?
     * @param string|boolean $response Endpoint response or false.
     * @return string|boolean The error message, or true for no error.
     */
    protected function checkResponse($response)
    {
        # Check that response was rec'd.
        if (empty($response) || !$response) {
            throw new \DomainException('No response was received from the verifier.');
        }
        # Check for ok JSON
        $json = $this->response = json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \UnexpectedValueException('Parsing response JSON failed.');
        }
        # Check response has status
        if (empty($json->status)) {
            throw new \DomainException('No status received from endpoint.');
        }
        # Check failure status
        if (preg_match('/fail(ure|ed)?/i', $json->status)) {
            $reason = empty($json->reason) ? 'Unknown reason.' : $json->reason;
            throw new \RuntimeException("Authentication failure: $reason");
        }
        # Check for 'okay' status
        if ($json->status != 'okay') {
            throw new \UnexpectedValueException('"Okay" response was not received from the endpoint.');
        }
        # Check for email
        if (empty($json->email)) {
            throw new \DomainException('Email was not received with response');
        }
        # Verify email
        if (!filter_var($json->email, FILTER_VALIDATE_EMAIL)) {
            throw new \UnexpectedValueException('Invalid Email address.');
        }
        # All tests passed.
        return true;
    }

    /**
     * Process a POST-ed login request.
     *
     * @return array Response data
     */
    protected function login()
    {
        $output = [
            'error' => true,
            'message' => 'Unknown reason.',
        ];
        $filter = FILTER_UNSAFE_RAW;
        $flags = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH;
        $assertion = filter_input(INPUT_POST, 'assertion', $filter, $flags);
        if (!$assertion) {
            throw new \BadMethodCallException('Missing assertion.');
        }
        $this->response = $this->getResponse($assertion);
        # We'll either get `true` for success, or error strings for errors.
        if (true !== $this->checkResponse($this->response)) {
            throw new \RuntimeException('checkResponse() failed.');
        }
        $json = $this->response;
        $_SESSION['persona_auth'] = $json->email;
        $_SESSION['persona_info'] = (array) $json;
        $output['email'] = $json->email;
        return $output;
    }

    /**
     * Process a POST-ed logout request.
     * Removes authentication information from $_SESSION.
     *
     * @return array Response data
     */
    protected function logout()
    {
        $_SESSION['persona_auth'] = $_SESSION['persona_info'] = null;
        unset($_SESSION['persona_auth'], $_SESSION['persona_info']);
        if (!isset($_SESSION['persona_auth'], $_SESSION['persona_info'])) {
            return true;
        } else {
            throw new \UnexpectedValueException('Logout failed.');
        }
    }

    /**
     * Determine if a user's assertion is expired.
     * This is mostly of use to the endpoint.
     *
     * @return boolean If the assertion has expired.
     */
    function expired()
    {
        $user = $this->user();
        # @todo - maybe throw an exception - checking expired with no login?
        if (!$user || !isset($_SESSION['persona_info']['expires'])) {
            return true;
        }
        $expires = (int) $_SESSION['persona_info']['expires'] / 1000; # ms -> s
        return $expires > time();
    }

    /**
     * Determine if the client is logged in, and if they are,
     * either return `true` or their user info.
     *
     * @param boolean $bool Whether to return true, or user info for logged in users.
     * @param null $info Reference to info - useful if $bool is used.
     * @return boolean|\ArrayObject False if the user is not logged in, or true/user info.
     */
    function user($bool = false, &$info = null)
    {
        if (isset($_SESSION['persona_auth'], $_SESSION['persona_info'])) {
            if ($this->expired()) {
                $this->logout();
                return false;
            }
            $default = [
                'email' => '',
                'audience' => $this->audience(),
                'expires' => 0,
                'issuer' => '',
            ];
            $info = array_merge($default, $_SESSION['persona_info']);
            $info = new \ArrayObject($info, \ArrayObject::ARRAY_AS_PROPS);
            return $bool ? true : $info;
        }
    }

    /**
     * Handle error responses
     *
     * @param Exception $e
     */
    function ajaxError(\Exception $e) {
        $message = sprintf('%s [%s]', $e->getMessage(), $e->getLine());
        $this->respond(json_encode([
            'error' => true,
            'message' => $message,
            'line' => $e->getLine(),
            'file' => $e->getFile(),
            'exception' => $e,
        ], JSON_FORCE_OBJECT));
    }

    /**
     * Used to determine whether the current request should be processed
     * as a Persona assertion verification request.
     *
     * @return boolean If the request parameters all match.
     */
    function shouldServe()
    {
        return ('XMLHttpRequest' == getenv('HTTP_X_REQUESTED_WITH'))
            && ('POST'=== strtoupper(getenv('REQUEST_METHOD')))
            && (!empty($_POST['persona_action']));
    }

    /**
     * Acts as a server, intercepting any incoming requests
     * that meet the criteria of being a Persona assertion,
     * and sending the JSON response.
     *
     * @param boolean $respond Whether to send the response once the output is ready.
     * @param boolean $exit Which $exit paramater will be sent to respond()
     * @return null|array $output The response for the client.
     */
    function server($respond = true, $exit = true)
    {
        if (!$this->shouldServe()) {
            return;
        }
        set_exception_handler([$this, 'ajaxError']);
        set_error_handler(function($n, $s, $f, $l) {
            $this->ajaxError(new \ErrorException($s, $n, 0, $f, $l));
        });
        switch($_POST['persona_action']) {
            case 'login':
                $output = $this->login();
                break;
            case 'logout':
            default:
                $this->logout();
                break;
        }
        if ($respond) {
            $this->respond($output, $exit);
        }
        return $output;
    }

    /**
     * Serves a JSON response and exits the program if $exit is true.
     *
     * @param array $output Response output.
     * @param boolean $exit Exit after sending response.
     * @return null|string $output The output JSON data.
     */
    function respond(array $output, $exit = true)
    {
        http_response_code(200);
        header('Content-Type: application/json');
        # header(sprintf('Content-Length: %d', strlen($output)));
        header(sprintf('X-Persona-Audience: %s', $this->audience()));
        $output['error'] = false;
        $output = json_encode($output, JSON_FORCE_OBJECT);
        if (JSON_ERROR_NONE != json_last_error() || '' == trim($output)) {
            throw new \RuntimeException('Failed to encode JSON response.');
        }
        if (!empty($_SESSION['persona_auth'])) {
            $email = $_SESSION['persona_auth'];
            header(sprintf('X-Persona-User: %s', $email));
        }
        if ($exit) {
            exit($output);
        }
        return $output;
    }

    /**
     * Returns the appropriate HTML to display a login form or logout link,
     * depending on whether the user is currently logged in or not.
     *
     * @return string The HTML link and JavaScript
     */
    function render()
    {
        $auth = !empty($_SESSION['persona_auth']);
        $user = $auth ? $_SESSION['persona_auth'] : 'Persona';
        $js_user = $auth ? sprintf('"%s"', $user) : 'null';
        $processor = $this->processor();
        ob_start();
        ?>
<div id="persona_auth" class="persona auth">
<!-- Include the Persona JS and jQuery only if necessary. -->
<script>
window.navigator.id || document.write('<script src="https://login.persona.org/include.js">\x3C/script>');
window.jQuery || document.write('<script src="//ajax.googleapis.com/ajax/libs/jquery/2.0.0/jquery.min.js">\x3C/script>');
</script>
<script>
$(function() {
    var field = $("#persona_auth a#persona_action");
    var isloggedin = <?= $auth ? 'true' : 'false' ?>;
    var loggeduser = <?= $js_user ?>;
    var email;
    navigator.id.watch({
        loggedInUser: <?= $js_user ?>,
        onlogin: function(assertion) {
            $.post('<?= $processor ?>', {
                persona_action: 'login',
                assertion: assertion
            }, function(data) {
                try { console.debug(data); } catch(a) {}
                if (true == data.error) {
                    alert('Error: ' + (data.message || 'Unknown reason.'));
                } else {
                    email = data.email || 'Persona';
                    $('#persona_action_logout').text('Log Out ['+ email +']');
                    $('#persona_action_login').hide();
                    $('#persona_action_logout').show();
                }
            }).fail(function() {
                alert('Absent or malformed response to XHR [1].');
            });
        },
        onlogout: function() {
            $.post('<?= $processor ?>', {
                persona_action: 'logout'
            }, function(data) {
                try { console.debug(data); } catch(a) {}
                if (true == data.error) {
                    alert('Error: ' + (data.message || 'Unknown reason.'));
                } else {
                    $('#persona_action_logout').text('');
                    $('#persona_action_logout').hide();
                    $('#persona_action_login').show();
                }
            }).fail(function() {
                alert('Absent or malformed response to XHR [2].');
            });
        }
    });
    $('#persona_action_login').click(function(ev) {
        ev && ev.preventDefault && ev.preventDefault();
        navigator.id.request();
    });
    $('#persona_action_logout').click(function(ev) {
        ev && ev.preventDefault && ev.preventDefault();
        navigator.id.logout();
    });
    if (isloggedin && loggeduser) {
        $('#persona_action_logout').text('Log Out ['+ loggeduser +']');
        $('#persona_action_login').hide();
        $('#persona_action_logout').show();
    } else {
        $('#persona_action_logout').text('');
        $('#persona_action_logout').hide();
        $('#persona_action_login').show();
    }
});
</script>
<!-- <a href="" class="auth" id="persona_action">[Loading &hellip;]</a> -->
<a href="" class="auth" id="persona_action_logout"></a>
<a href="" class="auth" id="persona_action_login">Log In [Persona]</a>
</div>
</div>
        <?php
        return trim(ob_get_clean());
    }

    /**
     * Alias for $this->render()
     *
     * @see \kafene\Persona::render()
     * @return string \kafene\Persona::render()
     */
    function __toString()
    {
        return $this->render();
    }

} // End class `kafene\Persona`

} // End namespace `kafene`

namespace {

/**
 * A wrapper to handle instantiating, configuring and loading
 * an instance of \kafene\Persona.
 *
 * @global
 * @param string $audience Audience to pass to the verifier.
 * @param string $endpoint Endpoint for auth provider.
 * @param string $processor URL to receive AJAX POST requests.
 * @return string \kafene\Persona::render()
 */
function BrowserID_Handle($audience = '', $endpoint = null, $processor = '')
{
    $persona = \kafene\Persona::getInstance();
    if ($audience) $persona->audience($audience);
    if ($processor) $persona->processor($processor);
    if ($endpoint) $persona->endpoint($endpoint);
    $persona->server();
    return (string) $persona;
}

} // End global namespace
