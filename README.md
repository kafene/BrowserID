# kafene\Persona

**All-in-one BrowserID (aka Mozilla Persona) component.**

* Copyright: 2013 kafene software <http://kafene.org/>
* License: <http://unlicense.org/> Unlicensed / Public Domain
* Version: 2013-05-05
* See: [Persona Remote Verification API](https://developer.mozilla.org/en-US/docs/Persona/Remote_Verification_API)

* @todo CSRF protection

## Usage

For basic usage, you can call `kafene\Persona::getInstance()->server()`
somewhere before your response is sent. If it detects that the current
request meets all of the criteria that make it an AJAX request from the
authentication handling javascript, it will handle the processing and
output a JSON response and halt further execution.

Wherever you want the link to display, you can call
`echo kafene\Persona::getInstance()`,
which will print the javascript, necessary to handle login and logout
requests, and a link showing the current auth status. Feel free, of course,
to modify the HTML and JS for your use case.

For backwards compatibility I have also included a globally namespaced
function called `BrowserID_Handle()`, which can be called and will start
the service with optionally provided audience, endpoint and processor
parameters, and return the HTML that the script generates, making it a
drop-in solution.

```php
    $persona_html = BrowserID_Handle();
    // ... later ...
    echo $persona_html;
```

## Changes

### 2013-06-07

* Fixed error situation in respond() function.
* Improved JS to work mostly without PHP.
* Improved error handling using an Exception handler.

### 2013-05-05:

* New session/request keys:
    * `BrowserIDAuth` => `persona_auth`;
    * `BrowserIDAssertion` => `persona_assertion`;
    * `BrowserIDDestroy` => `persona_logout`;
    * `BrowserIDResponseJSON` => `persona_json`;
* Refactored into a class.
* Javascript compatible with new navigator.id.watch()
* Super ajax adventure! doesn't need to reload to login or logout.

## Variables

Auth provider endpoint URL.

```php
protected $endpoint = 'https://verifier.login.persona.org/verify';
```

Audience to send to verifier - scheme://host:port

```php
protected $audience = '';
```

Audience to send to verifier - scheme://host:port

```php
protected $audience = '';
```

JSON Response processor/server script URL.

```php
protected $processor = '';
```

Endpoint response JSON.

```php
protected $response = '';
```

Optional singleton/multiton pattern instances.

```php
protected static $instances = [];
```

## Methods

### __construct()

Set up the object. If no processor is provided, the default
is a reasonable guess at the current URL, so if you don't have
a separate file calling server(), make sure you call
it before any output is done in your script. This is so the object
can remain a single self-hosting PHP file.

```php
/**
 * @param string $audience Audience to pass to the verifier.
 * @param string $processor URL to receive AJAX POST requests.
 * @param string $endpoint Endpoint for auth provider.
 * @return \kafene\Persona $this Self
 */
function __construct($audience = '', $processor = '', $endpoint = null) {
    $this->audience($audience);
    $this->processor($processor);
    $this->endpoint($endpoint);
    if(session_status() != PHP_SESSION_ACTIVE) session_start();
    return $this;
}
```

### getInstance()

Get an instance of the object, optionally with a named ID for handling several.
This is optional - object instantiation, cloning, etc., are still allowed.

```php
/**
 * @param string $id Instance ID.
 * @return \kafene\Persona Existing or new class instance.
 */
static function getInstance($id = 'default') {
    return array_key_exists($id, static::$instances)
        ? static::$instances[$id]
        : static::$instances[$id] = new static;
}
```

### processor()

Set or get processor URL.
In the absence of a processor URL, build a likely one.
@throws \Exception If the URL is invalid.

```php
/**
 * @param string $new New (or initial) processor URL.
 * @return string|object Existing processor URL, or \kafene\Persona $this Self
 */
function processor($new = null) {
    if($new) $this->processor = $new;
    if(!$this->processor) $this->processor = $this->guessProcessor();
    if(!$new) return $this->processor;
    if(!filter_var($this->processor, FILTER_VALIDATE_URL)) {
        $errstr = sprintf(_('Invalid processor "%s".'), $this->processor);
        throw new \Exception($errstr);
    }
    return $this;
}
```

### guessProcessor()

Build a likely processor URL (the current script URL).

```php
/**
 * @return string guessed URL
 */
protected function guessProcessor() {
    # $uri = getenv('REQUEST_URI') ?: '/';
    $root = preg_quote(strtr(getenv('DOCUMENT_ROOT'), '\\', '/'), '/');
    $script = strtr(getenv('SCRIPT_FILENAME'), '\\', '/');
    $uri = preg_replace("/^$root/i", '', $script);
    $base = $this->guessAudience();
    return sprintf('%s/%s', rtrim($base, '/'), ltrim($uri, '/'));
}
```

### audience()

Set or get audience URL.

```php
/**
 * @param string $new New (or initial) audience URL.
 * @return string|\kafene\Persona Existing audience URL, or $this Self
 * @throws \Exception If the URL is invalid.
 */
function audience($new = null) {
    if($new) $this->audience = $new;
    if(!$this->audience) $this->audience = $this->guessAudience();
    if(!$new) return $this->audience;
    if(!filter_var($this->audience, FILTER_VALIDATE_URL)) {
        $errstr = sprintf(_('Invalid audience "%s".'), $this->audience);
        throw new \Exception($errstr);
    }
    return $this;
}
```

### guessAudience()

Guess an appropriate audience to send to the verifier.
Audience should be ~= scheme://host:port

```php
/**
 * @return string guessed URL
 */
protected function guessAudience() {
    $ssl = filter_var(getenv('HTTPS'), FILTER_VALIDATE_BOOLEAN);
    $scheme = $ssl ? 'https' : 'http';
    $host = getenv('HTTP_HOST') ?: getenv('SERVER_NAME');
    $port = intval(getenv('SERVER_PORT')) ?: 80;
    if(($ssl && 443 == $port) || 80 == $port) $port = '';
    else $port = sprintf(':%d', $port);
    return sprintf('%s://%s%s', $scheme, $host, $port);
}
```

### endpoint()

Set or get Endpoint URL.

```php
/**
 * @param string $new New (or initial) endpoint URL.
 * @return string|\kafene\Persona Existing endpoint, or $this Self
 * @throws \Exception If the URL is invalid.
 */
function endpoint($new = null) {
    if(!$new) return $this->endpoint;
    $this->endpoint = $new;
    if(!filter_var($this->endpoint, FILTER_VALIDATE_URL)) {
        $errstr = sprintf(_('Invalid endpoint "%s".'), $this->endpoint);
        throw new \Exception($errstr);
    }
    return $this;
}
```

### getResponse()

Solicit a response from the endpoint and return the result.

```php
/**
 * @param string $assertion The assertion POST-ed by the client.
 * @return string|boolean $response The endpoint response.
 */
function getResponse($assertion) {
    $audience = $this->audience();
    $data = compact('assertion', 'audience');
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-type: application/x-www-form-urlencoded',
        'content' => http_build_query($data, '', '&')
    ]]);
    $response = file_get_contents($this->endpoint(), null, $ctx);
    return $response;
}
```

### checkResponse()

Validate needed parts of a given response, or determine the error.

```php
/**
 * @todo Verify expiry?
 * @param string|boolean $response Endpoint response or false.
 * @return string|boolean The error message, or true for no error.
 */
protected function checkResponse($response) {
    # Check that response was rec'd.
    if(empty($response) || !$response) {
        return _('No response was received from the verifier.');
    }
    # Check for ok JSON
    $json = $this->response = json_decode($response);
    if(json_last_error() !== JSON_ERROR_NONE) {
        return _('Parsing response JSON failed.');
    }
    # Check response has status
    if(empty($json->status)) {
        return _('No status received from endpoint.');
    }
    # Check failure status
    if(preg_match('/fail(ure|ed)?/i', $json->status)) {
        $reason = empty($json->reason)
            ? _('Unknown reason.')
            : $json->reason;
        return sprintf(_('Authentication failure: %s'), $reason);
    }
    # Check for 'okay' status
    if($json->status != 'okay') {
        return _('"Okay" response was not received from the endpoint.');
    }
    # Check for email
    if(empty($json->email)) {
        return _('Email was not received with response');
    }
    # Verify email
    $filter = FILTER_VALIDATE_EMAIL;
    if(!filter_var($json->email, $filter)) {
        return _('Invalid Email address.');
    }
    # All tests passed.
    return true;
}
```

### login()

Process a POST-ed login request.

```php
/**
 * @return array Response data
 */
protected function login() {
    $output = ['status' => 'failure', 'reason' => _('Unknown reason.')];
    $filter = FILTER_UNSAFE_RAW;
    $flags = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH;
    $assertion = filter_input(INPUT_POST, 'assertion', $filter, $flags);
    if(!$assertion) {
        $output['reason'] = _('Missing assertion.');
        return $output;
    }
    $this->response = $this->getResponse($assertion);
    # We'll either get `true` for success, or error strings for errors.
    $check = $this->checkResponse($this->response);
    if(true === $check) {
        $json = $this->response;
        $_SESSION['persona_auth'] = $json->email;
        $_SESSION['persona_info'] = (array) $json;
        $output['status'] = 'ok';
        $output['email'] = $json->email;
    } else {
        $output['reason'] = $check;
    }
    return $output;
}
```

### logout()

Process a POST-ed logout request.
Removes authentication information from $_SESSION.

```php
/**
 * @return array Response data
 */
protected function logout() {
    $output = ['status' => 'failure'];
    $_SESSION['persona_auth'] = false;
    unset($_SESSION['persona_auth']);
    $oops = isset($_SESSION['persona_auth']);
    $output['status'] = $oops ? 'failure' : 'ok';
    $output['reason'] = 'Logout failed.';
    return $output;
}
```

### expired()

Determine if a user's assertion is expired.
This is mostly of use to the endpoint.

```php
/**
 * @return boolean If the assertion has expired.
 */
function expired() {
    $user = $this->user();
    # @todo - maybe throw an exception - checking expired with no login?
    if(!$user) return true;
    if(!isset($_SESSION['persona_info']['expires'])) return true;
    $expires = (int) $_SESSION['persona_info']['expires'] / 1000; # ms -> s
    if($expires > time()) return true;
    return false;
}
```

### user()

Determine if the client is logged in, and if they are,
either return `true` or their user info.

```php
/**
 * @param boolean $bool Whether to return true, or user info for logged in users.
 * @return boolean|\ArrayObject False if the user is not logged in, or true/user info.
 */
function user($bool = false) {
    if(isset($_SESSION['persona_auth'], $_SESSION['persona_info'])) {
        if($this->expired()) {
            $this->logout();
            return false;
        }
        $default = [
            'status' => 'failure',
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
```

### shouldServe()

Used to determine whether the current request should be processed
as a Persona assertion verification request.

```php
/**
 * @return boolean If the request parameters all match.
 */
function shouldServe() {
    return ('XMLHttpRequest' == getenv('HTTP_X_REQUESTED_WITH'))
        && ('POST'=== strtoupper(getenv('REQUEST_METHOD')))
        && (!empty($_POST['persona_action']))
        && (in_array($_POST['persona_action'], ['login', 'logout']));
}
```

### server()

Acts as a server, intercepting any incoming requests
that meet the criteria of being a Persona assertion,
and sending the JSON response.
```php
/**
 * @param boolean $respond Whether to send the response once the output is ready.
 * @param boolean $exit Which $exit paramater will be sent to respond()
 * @return null|array $output The response for the client.
 */
function server($respond = true, $exit = true) {
    if(!$this->shouldServe()) return;
    $output = ('login' == $_POST['persona_action'])
        ? $this->login()
        : $this->logout();
    $email = isset($output['email']) ? $output['email'] : '';
    $output = json_encode($output, JSON_FORCE_OBJECT);
    if($respond) $this->respond($output, $exit);
    else return $output;
}
```

Serves a JSON response and exits the program if $exit is true.

```php
/**
 * @param array $output Response output.
 * @param boolean $exit Exit after sending response.
 * @return null|string $output The output JSON data.
 */
function respond($output, $exit = true) {
    http_response_code(200);
    header('Content-Type: application/json');
    # header(sprintf('Content-Length: %d', strlen($output)));
    header(sprintf('X-Persona-Audience: %s', $this->audience()));
    if($email) header(sprintf('X-Persona-User: %s', $email));
    if($exit) exit($output);
    return $output;
}
```

### render()

Returns the appropriate HTML to display a login form or logout link,
depending on whether the user is currently logged in or not.

```php
/**
 * @return string The HTML link and JavaScript
 */
function render() {
    $auth = isset($_SESSION['persona_auth']);
    $user = $auth ? $_SESSION['persona_auth'] : '';
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
```
```js
$(function() {
    var auth_field = $("#persona_auth a#persona_action");
    navigator.id.watch({
        loggedInUser: <?= $js_user ?>,
        onlogin: function(assertion) {
            $.post('<?= $processor ?>', {
                persona_action: 'login',
                assertion: assertion
            }, function(data) {
                if(data.status != 'ok') {
                    var reason = data.reason || '<?= _('Unknown reason.') ?>';
                    alert('<?= _('Error:') ?>\n%s'.replace('%s', reason));
                } else {
                    var email = data.email || 'Persona';
                    $(auth_field).text('<?= _('Log Out [%s]') ?>'.replace('%s', email));
                }
            }).fail(function() {
                alert('<?= _('Absent or malformed response to XHR.') ?>');
            });
        },
        onlogout: function() {
            $.post('<?= $processor ?>', {
                persona_action: 'logout'
            }, function(data) {
                if(data.status != 'ok') {
                    var reason = data.reason || '<?= _('Unknown reason.') ?>';
                    alert('<?= _('Error:') ?>\n%s'.replace('%s', reason));
                } else {
                    $(auth_field).text('<?= _('Log In [Persona]') ?>');
                }
            }).fail(function() {
                alert('<?= _('Absent or malformed response to XHR.') ?>');
            });
        }
    });
    $(auth_field).click(function(ev) {
        ev && ev.preventDefault && ev.preventDefault();
        <?php if($auth): ?>
            navigator.id.logout();
        <?php else: ?>
            navigator.id.request();
        <?php endif; ?>
    });
});
```
```php
</script>
<!-- <a href="" class="auth" id="persona_action">[<?= _('Loading &hellip;') ?>]</a> -->
<?php if($auth): ?>
<a href="" class="auth" id="persona_action"><?= _('Log Out') ?> [<?= $user ?>]</a>
<?php else: ?>
<a href="" class="auth" id="persona_action"><?= _('Log In') ?> [Persona]</a>
<?php endif; ?>
</div>
</div>
    <?php
    return trim(ob_get_clean());
}
```

### __toString()

Alias for render()

```php
/**
 * @see \kafene\Persona::render()
 * @return string \kafene\Persona::render()
 */
function __toString() {
    return $this->render();
}
```

### \BrowserID_Handle()

A wrapper to handle instantiating, configuring and loading
an instance of \kafene\Persona.

```php
/**
 * @global
 * @param string $audience Audience to pass to the verifier.
 * @param string $endpoint Endpoint for auth provider.
 * @param string $processor URL to receive AJAX POST requests.
 * @return string \kafene\Persona::render()
 * @example `echo BrowserID_Handle();`
 */
function BrowserID_Handle($audience = '', $endpoint = null, $processor = '') {
    $persona = \kafene\Persona::getInstance();
    if($audience) $persona->audience($audience);
    if($processor) $persona->processor($processor);
    if($endpoint) $persona->endpoint($endpoint);
    $persona->server();
    return (string) $persona;
}
```


