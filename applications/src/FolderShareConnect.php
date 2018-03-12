<?php

namespace FolderShare;

/**
 * Manages communications with a web site using the FolderShare Drupal module.
 *
 * This class handles client-server communications for client applications
 * communicating with a remote server running the Drupal content management
 * system and the FolderShare module. Drupal manages the content and provides
 * a general-purpose web services (i.e. "REST") interface. FolderShare
 * supports that interface in its management of a hierarchy of files and
 * folders stored on the server.
 *
 * This class's methods configure the client-server communications path,
 * then use that path to send requests to the server and handle responses
 * back from the server. The requests supported here include:
 *
 * - Create folders.
 * - Upload files and folders.
 * - Download files and folders.
 * - Get lists of files and folders.
 * - Search files and folders.
 * - Get file and folder names and other descriptive values.
 * - Rename and edit file and folder description values.
 * - Copy and move files and folders.
 * - Delete files and folders.
 * - Get FolderShare settings.
 * - Get web site settings related to these services.
 *
 * <B>Web server setup</B><BR>
 * In order for this class to communicate with a web server, the following
 * must have been configured by the site administrator on that server:
 *
 * - The site must be running the Drupal content management system.
 *
 * - The FolderShare module for Drupal must be installed.
 *
 * - The Drupal REST services module must be installed.
 *
 * - The FolderShare resource for REST must be enabled and configured to
 *   enable:
 *   - GET, POST, PATCH, and DELETE methods.
 *   - The JSON serialization format.
 *   - An authentication service (such as basic authentication).
 *
 * Applications may use this class to open a connection to a web server,
 * authenticate the user across that connection, then issue
 * one or more requests on the connection. The connection is automatically
 * closed when the connection object is deleted.
 *
 * <B>Return formats</B><BR>
 * Many requests can return information to the client in one of these formats:
 *
 * - 'full' returns a complex serialized entity or list of entities as an
 *   associative array of fields that each containing an array of values,
 *   which may in turn include on or more named values that may be scalars
 *   or further arrays. This structure matches the layout of data on the
 *   server and it is suitable for re-use in a future call to create or
 *   update a file or folder. However, it is not very user-friendly.
 *
 * - 'keyvalue' returns a simply serialized entity as an associative array
 *   of field names that each contain either a scalar value or an array of
 *   scalar values. The key-value array is easier to process by client
 *   code and it can be converted to a user-friendly output.
 */
class FolderShareConnect {

  /*--------------------------------------------------------------------
   *
   * Version.
   *
   *--------------------------------------------------------------------*/

  /**
   * The version number of this class.
   */
  const VERSION = '0.4.0 (January 2018)';

  /*--------------------------------------------------------------------
   *
   * Authentication constants.
   *
   *--------------------------------------------------------------------*/

  /**
   * A connection authentication type that does no authentication.
   *
   * This value is a default until a specific authentication style has
   * been set up.  However, most web sites will not allow connections
   * without authentication.
   *
   * @see ::getAuthenticationType()
   * @see ::getAuthenticationTypeName()
   * @see ::login()
   */
  const AUTHENTICATION_NONE = 0;

  /**
   * A basic authentication type that uses a user name and password.
   *
   * Drupal core supports basic authentication, and it is this style that
   * is mostly widely used for web services. With basic authentication,
   * the caller of this class provides a user name and password that are
   * included on every request to the server.
   *
   * Basic authentication can fail if the user name and password
   * are invalid.
   *
   * When a connection uses SSL encryption, the user name and password
   * are included in the encrypted content so they cannot be seen by
   * intermediate parties.
   *
   * @see ::getAuthenticationType()
   * @see ::getAuthenticationTypeName()
   * @see ::getPassword()
   * @see ::getUserName()
   * @see ::login()
   */
  const AUTHENTICATION_BASIC = 1;

  /**
   * A cookie authentication type that uses a session cookie.
   *
   * Drupal core supports cookie authentication, and it is this style
   * that is used by web browsers. With cookie authentication, the
   * caller of this class provides a user name and password that are
   * used for an initial connection to the server. The server returns
   * a session cookie and that cookie is used for further requests.
   *
   * Cookie authentication can fail if the session cookie cannot be
   * obtained. This can happen if the server has been configured to use a
   * non-standard user login form, such as one that requires a CAPTCHA.
   *
   * Cookie authentication can also fail if the user name and password
   * are invalid.
   *
   * When a connection uses SSL encryption, the user name and password
   * are included in the encrypted content so they cannot be seen by
   * intermediate parties.
   *
   * @see ::getAuthenticationType()
   * @see ::getAuthenticationTypeName()
   * @see ::getPassword()
   * @see ::getUserName()
   * @see ::login()
   */
  const AUTHENTICATION_COOKIE = 2;

  /**
   * A cookie or basic authentication type.
   *
   * This authentication type encompasses both AUTHENTICATION_COOKIE and
   * AUTHENTICATION_BASIC types. When a connection is first established,
   * this class tries to use cookie authentication to log in the user.
   * If that works, subsequent requests use the session cookie returned by
   * that authentication process. But if cookie authentication fails,
   * this class fails-over to basic authentication and tries sending the
   * user name and password on all subsequent requests.
   *
   * When a connection uses SSL encryption, the user name and password
   * are included in the encrypted content so they cannot be seen by
   * intermediate parties.
   *
   * @see ::getAuthenticationType()
   * @see ::getAuthenticationTypeName()
   * @see ::getPassword()
   * @see ::getUserName()
   * @see ::login()
   */
  const AUTHENTICATION_AUTO = 3;

  /**
   * The order of authentication types tried for AUTHENTICATION_AUTO.
   *
   * When the requested authentication type is AUTHENTICATION_AUTO, the
   * class automatically cycles through authentication types. Each type
   * is checked, in the order here, to find the first one that works.
   *
   * @see ::getAuthenticationType()
   * @see ::getAuthenticationTypeName()
   * @see ::getPassword()
   * @see ::getUserName()
   * @see ::login()
   */
  const AUTHENTICATION_AUTO_ORDER = [
    self::AUTHENTICATION_COOKIE,
    self::AUTHENTICATION_BASIC,
  ];

  /*--------------------------------------------------------------------
   *
   * Login state constants.
   *
   *--------------------------------------------------------------------*/

  /**
   * An authentication status indicating the user is not logged in.
   *
   * This is the state for the connection before a login is performed.
   *
   * @see ::login()
   */
  const STATUS_NOT_LOGGED_IN = 0;

  /**
   * An authentication status indicating user login is pending.
   *
   * The pending state is used when login credentials have been set, but
   * no request using them has been made yet. Once a request has successfully
   * used them, the login status changes to STATUS_LOGGED_IN.
   *
   * This state is only used by AUTHENTICATION_BASIC.
   *
   * @see ::login()
   */
  const STATUS_PENDING = 1;

  /**
   * An authentication status indicating user login failed.
   *
   * This state indicates that login credentials were set and tried, but
   * failed.
   *
   * @see ::login()
   */
  const STATUS_FAILED = 2;

  /**
   * An authentication status indicating the user is logged in.
   *
   * This state indicates that authentication was performed successfully.
   *
   * @see ::login()
   */
  const STATUS_LOGGED_IN = 3;

  /*--------------------------------------------------------------------
   *
   * URL constants.
   *
   *--------------------------------------------------------------------*/

  /**
   * The URL path for requesting a CSRF token.
   *
   * The path only contains the string following the scheme, domain, and
   * optional port on a URL (e.g. for "http://example:80/stuff", only
   * include "stuff").
   *
   * This path is defined within Drupal core's REST services module and
   * cannot be changed.
   */
  const URL_CSRF_TOKEN = '/session/token';

  /**
   * The URL path for logging in using cookie authentication.
   *
   * This path is defined within Drupal core's User module and cannot
   * be changed.
   */
  const URL_USER_LOGIN = '/user/login';

  /**
   * The URL path for logging out using cookie authentication.
   *
   * This path is defined within Drupal core's User module and cannot
   * be changed.
   */
  const URL_USER_LOGOUT = '/user/logout';

  /**
   * The URL path for an HTTP GET request.
   *
   * The path only contains the string following the scheme, domain, and
   * optional port on a URL (e.g. for "http://example:80/stuff", only
   * include "stuff"). An entity ID and format query will be added.
   *
   * This path is defined within the FolderShare resource for REST and
   * cannot be changed.
   */
  const URL_GET = '/foldershare';

  /**
   * The URL path for an HTTP DELETE request.
   *
   * The path only contains the string following the scheme, domain, and
   * optional port on a URL (e.g. for "http://example:80/stuff", only
   * include "stuff"). An entity ID will be added.
   *
   * This path is defined within the FolderShare resource for REST and
   * cannot be changed.
   */
  const URL_DELETE = '/foldershare';

  /**
   * The URL path for an HTTP PATCH request.
   *
   * The path only contains the string following the scheme, domain, and
   * optional port on a URL (e.g. for "http://example:80/stuff", only
   * include "stuff"). An entity ID and format query will be added.
   *
   * This path is defined within the FolderShare resource for REST and
   * cannot be changed.
   */
  const URL_PATCH = '/foldershare';

  /**
   * The URL path for an HTTP POST request.
   *
   * The path only contains the string following the scheme, domain, and
   * optional port on a URL (e.g. for "http://example:80/stuff", only
   * include "stuff").
   *
   * This path is defined within the FolderShare resource for REST and
   * cannot be changed.
   */
  const URL_POST = '/entity/foldershare';

  /**
   * The URL path for an HTTP POST request to upload a file or folder.
   *
   * The path leads to an upload form instead of to the REST resource
   * because the REST module currently does not support base class
   * features needed for file upload.
   *
   * This path is defined within the FolderShare module and cannot be
   * changed.
   */
  const URL_UPLOAD = '/foldershare/upload';

  /*--------------------------------------------------------------------
   *
   * Fields
   *
   *--------------------------------------------------------------------*/

  /**
   * When TRUE, this class writes status messages to STDERR.
   *
   * @var string
   * @see ::isVerbose()
   * @see ::setVerbose()
   */
  protected $verbose;

  /**
   * The host name (and optional port) for the web site.
   *
   * During use, the URL will have Drupal routes added that lead to
   * REST resources.
   *
   * @var string
   * @see ::getHostName()
   * @see ::setHostName()
   */
  protected $hostName;

  /**
   * The authentication type.
   *
   * This is one of AUTHENTICATION_NONE, AUTHENTICATION_BASIC, or
   * AUTHENTICATION_COOKIE.
   *
   * @var int
   * @see ::getUserName()
   * @see ::getPassword()
   * @see ::getAuthenticationType()
   * @see ::setUserNameAndPassword()
   */
  protected $authenticationType;

  /**
   * The user name if using basic or cookie authentication.
   *
   * When using basic authentication, the user name and password are
   * sent on every request to the server. When using cookie authentication,
   * the user name and password are only sent on an initial login request,
   * which returns a session cookie used for all further requests.
   *
   * @var string
   * @see ::getUserName()
   * @see ::setUserNameAndPassword()
   */
  protected $userName;

  /**
   * The user password if using basic or cookie authentication.
   *
   * When using basic authentication, the user name and password are
   * sent on every request to the server. When using cookie authentication,
   * the user name and password are only sent on an initial login request,
   * which returns a session cookie used for all further requests.
   *
   * @var string
   * @see ::getPassword()
   * @see ::setUserNameAndPassword()
   */
  protected $password;

  /**
   * The CSRF token, or NULL if empty.
   *
   * The "Cross-Site-Request-Forgery" (CSRF) token is retrieved from
   * the server on the first request to the server. The token is then
   * attached to all further non-safe requests to the same server
   * (e.g. for posts, patches, and deletes, but not gets).
   *
   * @var string
   * @see ::clearCSRFToken()
   * @see ::getCSRFToken()
   */
  protected $csrfToken;

  /**
   * The logout token when using cookies.
   *
   * @var string
   */
  protected $cookieLogoutToken;

  /**
   * Whether the user's credentials have been authenticated (logged in).
   *
   * @var int
   * @see ::STATUS_NOT_LOGGED_IN
   * @see ::STATUS_PENDING
   * @see ::STATUS_FAILED
   * @see ::STATUS_LOGGED_IN
   */
  protected $authenticationStatus;

  /**
   * The CURL session handle after it has been initialized.
   *
   * The session handle is created when an object of this class is
   * constructed, and released when the object is destroyed.
   *
   * @var resource
   */
  protected $curlSession;

  /**
   * A flag indicating that server communications has been validated.
   *
   * This flag is initialized to FALSE when an object of this class is
   * constructed, and it is reset to FALSE any time the host name is
   * changed. The flag is set to TRUE by verifyServer() prior, which
   * issues intial communications to the server before the caller of
   * this class makes their first request.
   *
   * @var bool
   * @see ::verifyServer()
   */
  protected $serverVerified;

  /**
   * The list of HTTP verbs supported by the remote site.
   *
   * The string array is changed from empty to a list of the names of
   * supported HTTP verbs when the server connection is verified on the
   * first request. HTTP verbs are always in upper case and one of the
   * following values:
   * - GET.
   * - DELETE.
   * - PATCH.
   * - POST.
   *
   * This list of supported HTTP verbs is checked on each call to this
   * class before trying to send the request. If the verb is not supported,
   * the request is aborted.
   *
   * Typically, servers support all of the above HTTP verbs.
   *
   * @var string[]
   * @see ::verifyServer()
   * @see ::getHttpVerbs()
   */
  protected $httpVerbs;

  /**
   * The current preferred format for content returned by the server.
   *
   * Some requests to the server return content, such as the list of fields
   * in a FolderShare entity (a file or folder). The format of that
   * returned content can vary depending upon the selected return format:
   * - 'full' = returns a full detailed entity or list of entities.
   * - 'keyvalue' = returns a simplified entity or list of entities.
   *
   * @var string
   * @see ::setReturnFormat()
   * @see ::getReturnFormat()
   */
  protected $format;

  /**
   * The most recent download file name.
   *
   * During a file download from the server, an HTTP header callback invoked
   * by CURL looks for the "Content-Disposition" header line that names the
   * file being downloaded. If found, the name is saved to this field where
   * it is used at the end of the download to rename the file saved to local
   * storage.
   *
   * @var string
   * @see ::download()
   * @see ::downloadHeaderCallback()
   */
  private $downloadFilename;

  /*--------------------------------------------------------------------
   *
   * Construction & Destruction.
   *
   *--------------------------------------------------------------------*/

  /**
   * Creates a new connection ready to communicate to a remote host.
   *
   * Constructing an object of this class initializes internal state, but
   * does not open a connection to a server yet. The caller must set
   * the host name and, if needed, the authentication credentials to use.
   * Thereafter, methods that request data from the server open the
   * connection and send and receive the appropriate data.
   *
   * @see ::setHostName()
   * @see ::setUserNameAndPassword()
   */
  public function __construct() {
    if (function_exists('curl_init') === FALSE) {
      throw new \RuntimeException(
        "The PHP CURL package is required, but not installed.\nThis application cannot continue without it.");
    }

    $this->authenticationType = self::AUTHENTICATION_NONE;
    $this->authenticationStatus = self::STATUS_NOT_LOGGED_IN;
    $this->userName = '';
    $this->password = '';
    $this->format = 'full';
    $this->verbose = FALSE;
    $this->clearCSRFToken();
    $this->unverifyServer();

    $this->curlSession = curl_init();
  }

  /**
   * Closes the connection to the remote server.
   *
   * If the user is logged in, they are logged out first.
   */
  public function __destruct() {
    $this->logout();
    curl_close($this->curlSession);
  }

  /*--------------------------------------------------------------------
   *
   * Verbosity.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns TRUE if the connection has been set to be verbose.
   *
   * When the connection is verbose, status messages are written to STDERR
   * for each operation. This is useful when debugging, but probably not
   * a feature users will enjoy.
   *
   * @return bool
   *   Returns TRUE if the connection is verbose.
   *
   * @see ::setVerbose()
   */
  public function isVerbose() {
    return $this->verbose;
  }

  /**
   * Sets whether the connection is verbose.
   *
   * When the connection is verbose, status messages are written to STDERR
   * for each operation. This is useful when debugging, but probably not
   * a feature users will enjoy.
   *
   * @param bool $verbose
   *   Sets the connection's verbosity to TRUE (verbose) or FALSE (not).
   *
   * @see ::isVerbose()
   */
  public function setVerbose(bool $verbose) {
    $this->verbose = $verbose;
  }

  /**
   * Prints a message to STDERR if verbosity is enabled.
   *
   * @param string $message
   *   The message to print if verbosity is enabled.
   */
  protected function printVerbose(string $message) {
    if ($this->verbose === TRUE && empty($message) === FALSE) {
      fwrite(STDERR, "FolderShareConnect: $message\n");
    }
  }

  /*--------------------------------------------------------------------
   *
   * Host.
   *
   *--------------------------------------------------------------------*/

  /**
   * Gets the name of the host and an optional port for the connection.
   *
   * The host name selects the site with which this connection communicates.
   * Host names are typically a simple string like "example.com", but they
   * optionally may include a port number for the web server, such as
   * "example.com:80".
   *
   * @return string
   *   The host name and optional port for the web site.
   *
   * @see ::setHostName()
   */
  public function getHostName() {
    return $this->hostName;
  }

  /**
   * Sets the host name and an optional port for the connection.
   *
   * The host name selects the site with which this connection communicates.
   * Host names are typically a simple string like "example.com", but they
   * optionally may include a port number for the web server, such as
   * "example.com:80".
   *
   * Setting the host name is normally done immediately after constructing
   * the connection and before issuing any requests. If the host name is
   * changed later, the user is logged out and the connection reset.
   *
   * @param string $hostName
   *   The host name and optional for the web site.
   *
   * @see ::getHostName()
   * @see ::logout()
   */
  public function setHostName(string $hostName) {
    $this->logout();
    $this->hostName = $hostName;
    $this->clearCSRFToken();
    $this->unverifyServer();
  }

  /*--------------------------------------------------------------------
   *
   * Authentication.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns the authentication type in use.
   *
   * The authentication type is set at login as one of:
   * - ATHENTICATION_NONE = there is no user authentication.
   * - ATHENTICATION_BASIC = authentication uses a user name and password.
   * - ATHENTICATION_COOKIE = authentication uses a session cookie along
   *   with a user name and password.
   *
   * The special AUTHENTICATION_AUTO selects automatic negotiation among
   * supported authentication methods. After authentication, the
   * authentication type will be set to the type used.
   *
   * @return int
   *   Returns the authentication type.
   *
   * @see ::AUTHENTICATION_NONE
   * @see ::AUTHENTICATION_BASIC
   * @see ::AUTHENTICATION_COOKIE
   * @see ::AUTHENTICATION_AUTO
   */
  public function getAuthenticationType() {
    return (int) $this->authenticationType;
  }

  /**
   * Returns a human-friendly name string for the authentication type.
   *
   * @param int $authenticationType
   *   The authentication type to translate into a name string.
   *
   * @return string
   *   Returns a human-friendly name for the authentication type.
   */
  public function getAuthenticationTypeName(int $authenticationType) {
    switch ($authenticationType) {
      case self::AUTHENTICATION_NONE:
        return "No Authentication";

      case self::AUTHENTICATION_BASIC:
        return "Basic Authentication";

      case self::AUTHENTICATION_COOKIE:
        return "Cookie Authentication";

      case self::AUTHENTICATION_AUTO:
        return "Automatic Authentication";

      default:
        return "Unknown Authentication $authenticationType";
    }
  }

  /**
   * Gets the user name, if set, when using basic and cookie authentication.
   *
   * The user name is set at login.
   *
   * @return string
   *   Returns the current user name.
   *
   * @see ::getPassword()
   * @see ::login()
   * @see ::logout()
   */
  public function getUserName() {
    return $this->userName;
  }

  /**
   * Gets the password, if set, when using basic and cookie authentication.
   *
   * The password is set at login.
   *
   * @return string
   *   Returns the current password.
   *
   * @see ::getUserName()
   * @see ::login()
   * @see ::logout()
   */
  public function getPassword() {
    return $this->password;
  }

  /**
   * Returns TRUE if the user is logged in.
   *
   * A connection is logged in if credentials were supplied to login()
   * and those credentials have been confirmed by the server. Confirmation
   * is performed differently for different authentication types:
   * - AUTHENTICATION_NONE: credentials are never confirmed and this
   *   function always returns FALSE.
   * - AUTHENTICATION_COOKIE: credentials are confirmed at login().
   * - AUTHENTICATION_BASIC: credentials are confirmed on the first request
   *   after login().
   *
   * @return bool
   *   Returns TRUE if the connection has been logged in, and FALSE
   *   otherwise.
   */
  public function isLoggedIn() {
    switch ($this->authenticationStatus) {
      default:
      case self::STATUS_NOT_LOGGED_IN:
      case self::STATUS_PENDING:
      case self::STATUS_FAILED:
        return FALSE;

      case self::STATUS_LOGGED_IN:
        return TRUE;
    }
  }

  /**
   * Returns a human-friendly name string for the authentication status.
   *
   * @param int $authenticationStatus
   *   The authentication status to translate into a name string.
   *
   * @return string
   *   Returns a human-friendly name for the authentication status.
   */
  protected function getAuthenticationStatusName(int $authenticationStatus) {
    switch ($authenticationStatus) {
      case self::STATUS_NOT_LOGGED_IN:
        return "Not logged in";

      case self::STATUS_PENDING:
        return "Login pending";

      case self::STATUS_FAILED:
        return "Login failed";

      case self::STATUS_LOGGED_IN:
        return "Logged in";

      default:
        return "Unknown authentication status $authenticationStatus";
    }
  }

  /*--------------------------------------------------------------------
   *
   * Configuration.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns the currently selected format for returned data.
   *
   * For requests that return data, that data is returned as an associative
   * array in one of two formats:
   * - 'full' = returns a full detailed entity or list of entities.
   * - 'keyvalue' = returns a simplified entity or list of entities.
   *
   * @return string
   *   The name of a server-known format for returned data.
   *
   * @see ::setReturnFormat()
   */
  public function getReturnFormat() {
    return $this->format;
  }

  /**
   * Sets the currently selected format for returned data.
   *
   * For requests that return data, that data is returned as an associative
   * array in one of two formats:
   * - 'full' = returns a full detailed entity or list of entities.
   * - 'keyvalue' = returns a simplified entity or list of entities.
   *
   * @param string $format
   *   The name of a server-known format for returned data.
   *
   * @see ::getReturnFormat()
   */
  public function setReturnFormat(string $format) {
    $this->format = $format;
  }

  /*--------------------------------------------------------------------
   *
   * Error messages.
   *
   *--------------------------------------------------------------------*/

  /**
   * Returns a nicer CURL error message.
   *
   * CURL errors indicate a communications problem. For all errors, CURL can
   * provide a brief error message, but these messages are rarely sufficient
   * to explain to a user what is going on and what they can do about it.
   *
   * This method maps selected error numbers to friendlier error messages.
   * If the error number is not recognized, the default CURL error message
   * is returned.
   *
   * @param int $errno
   *   The CURL error number.
   *
   * @return string
   *   Returns a friendly error message.
   */
  protected function getNiceCurlErrorMessage(int $errno) {
    switch ($errno) {
      case CURLE_URL_MALFORMAT:
        return
"The host name is not in the proper format.
Please check that the name does not include a '/', space, or other special
characters.";

      case CURLE_URL_MALFORMAT_USER:
      case CURLE_MALFORMAT_USER:
        return
"The user name is not in the proper format.
Please check that the name does not include a ':', space, or other special
characters.";

      case CURLE_COULDNT_RESOLVE_HOST:
        return
"The host could not be found.
Please check your host name for typos.";

      case CURLE_HTTP_PORT_FAILED:
        $colonIndex = mb_strpos($this->hostName, ':');
        if ($colonIndex === FALSE) {
          return
"The host refused a connection on the default port 80.
The host may not support web service access or it may require a different port.
You may specify a port by adding ':' and a port number to the end of the host
name (e.g. 'example.com:1234').";
        }

        $port = mb_substr($this->hostName, ($colonIndex + 1));
        return
"The host refused a connection on the specified port $port.
Please check that the port number is correct.";

      case CURLE_COULDNT_CONNECT:
      case CURLE_OPERATION_TIMEOUTED:
        return
"The host is not responding.
The host may be down, there could be a network problem, or the host may
not support the network connections required by this application.";

      case CURLE_BAD_PASSWORD_ENTERED:
        return
"The host denied access because the user name or password is incorrect.
Please check your user name and password for typos.";

      case CURLE_REMOTE_ACCESS_DENIED:
        return
"The host denied access.
The host may not support web service access, or the user name and password
may not grant sufficient permission to respond to your request.";

      case CURLE_TOO_MANY_REDIRECTS:
        return
"There is a communications problem with the host.
The host is repeatedly redirecting requests to another host, or to the
same host in a never-ending cycle. Please report this to the host's
administrator.";

      case CURLE_SEND_ERROR:
        return
"The host unexpectedly stopped receiving requests.
The host may have gone down or there could be a network problem.";

      case CURLE_RECV_ERROR:
        return
"The host unexpectedly stopped sending information.
The host may have gone down or there could be a network problem.";

      case CURLE_FILESIZE_EXCEEDED:
        return
"The host reported that the file size was too large.
The file may be too large to transfer.";

      case CURLE_PARTIAL_FILE:
        return
"The host failed to send the entire file.
The host may be busy, there may be a connection problem, or the file may
be too large to transfer reliably.";

      default:
        return curl_error($this->curlSession);
    }
  }

  /**
   * Returns a nicer HTTP error message.
   *
   * Web servers that respond with an error HTTP code may or may not include
   * a message with that code. If they do not, this method returns a nicer
   * message for a variety of common HTTP codes.
   *
   * @param int $httpCode
   *   The HTTP code.
   *
   * @return string
   *   Returns friendlier error message.
   */
  protected function getNiceHttpErrorMessage(int $httpCode) {
    //
    // The list below is intentionally incomplete. HTTP codes that are not
    // likely for requests issued by this class are not included. For
    // instance, codes associated with WebDAV, IM, and proxies are not
    // included.
    //
    switch ($httpCode) {
      case 200:
        // OK.
        return
"The request succeeded.";

      case 201:
        // Created.
        return
"The request to create content succeeded.";

      case 202:
        // Accepted.
        return
"The request was accepted and processing is in progress.";

      case 204:
        // No content.
        return
"The request succeeded and, as expected, no content was returned.";

      case 400:
        // Bad request.
        return
"There is a problem with the request.
The host rejected the request because it is too large or it is improperly
formatted. This is probably a programming error. Please contact the
developers of this software.";

      case 401:
        // Unauthorized. This really should be handled outside this method
        // so that the server's response header can be considered.
        return
"The host has denied access.
The host may require additional authentication or the host may be blocking
access from your local host or network.";

      case 403:
        // Forbidden.
        return
"The host denied access. You do not have permission for this request.";

      case 404:
        // Not found.
        return
"The requested information could not be found.";

      case 405:
        // Method not allowed.
        return
"The host does not support this type of information request.";

      case 406:
        // Not acceptable.
        return
"The host does not support information interchange in a format recognized
by this application.";

      case 408:
        // Request timeout.
        return
"The host is unexpectedly not responding.
The host may have gone down, there could be a network problem, or the
host may have had a problem with the request.";

      case 410:
        // Gone.
        return
"The requested information is no longer available.";

      case 429:
        // Too many requests.
        return
"The host is declining connections because of too many requests.
The host is rate limiting its communications and the number of requests has
exceeded that limit.";

      case 500:
        // Internal server error.
        return
"The host encountered an unknown internal error.
If this continues, please report this to the host's administrator.";

      case 503:
        // Service unavailable.
        return
"The host's services are temporarily unavailable.
Please check back later.";

      default:
        // All other error codes are obscure. Return a generic message.
        return
"The host responded with an unexpected HTTP error code: $httpCode.\n";
    }
  }

  /*--------------------------------------------------------------------
   *
   * Utilities.
   *
   * The miscellaneous functions here provide mixed functionality to
   * later code.
   *
   *--------------------------------------------------------------------*/

  /**
   * Creates an empty dummy entity.
   *
   * During POST and PATCH operations, a new entity and fields are required
   * in the request. If the operation does not need a detailed entity, or if
   * the caller does not provide one, this dummy entity is used.
   *
   * @return array
   *   Returns a dummy entity array with well-known fields and no values.
   */
  protected function createDummyEntity() {
    return [
      'name' => [],
      /*
      'uid' => [],
      'created' => [],
      'changed' => [],
      'description' => [],
      'kind' => [],
      'mime' => [],
      'parentid' => [],
      'rootid' => [],
      'size' => [],
      'file' => [],
      'image' => [],
      'media' => [],
      'grantauthoruids' => [],
      'grantviewuids' => [],
       */
    ];
  }

  /**
   * Returns CURL options common to most HTTP requests made here.
   *
   * These options do the following:
   * - Set the user agent.
   * - Request that content be returned.
   * - Disable returning the header.
   * - Enable redirect following.
   * - Set timeouts.
   *
   * If $includeAuthentication is TRUE, then HTTP basic user name and
   * password credentials are added to the header, if they have been set.
   *
   * @param bool $includeAuthentication
   *   (optional, default = TRUE) When TRUE, authentication credentials
   *   are included.
   *
   * @return array
   *   Returns an array of common Curl options.
   *
   * @see ::login()
   */
  protected function getCommonCurlOptions(bool $includeAuthentication = TRUE) {
    $options = [
      // Requests come from this class.
      CURLOPT_USERAGENT      => 'FolderShareConnect',

      // Return the request's results.
      CURLOPT_RETURNTRANSFER => TRUE,

      // Don't include the HTTP header in the results. This corrupts
      // those results. Instead, if the header is needed, a header callback
      // is used.
      CURLOPT_HEADER         => FALSE,

      // Don't encode.
      CURLOPT_ENCODING       => '',

      // Follow redirects.
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_AUTOREFERER    => TRUE,
      CURLOPT_MAXREDIRS      => 10,

      // Time-out if things stall.
      CURLOPT_CONNECTTIMEOUT => 120,
      CURLOPT_TIMEOUT        => 120,
    ];

    // If there is a need to include authentication credentials, add the
    // right options.
    if ($includeAuthentication === TRUE) {
      $this->printVerbose("  Using " .
        $this->getAuthenticationTypeName($this->authenticationType));

      switch ($this->authenticationType) {
        case self::AUTHENTICATION_BASIC:
          // Add credentials for basic authentication.
          $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
          $options[CURLOPT_USERPWD] = $this->userName . ':' . $this->password;
          break;

        case self::AUTHENTICATION_COOKIE:
          // Add credentials for cookie authentication.
          $options[CURLOPT_COOKIEJAR] = "";
          $options[CURLOPT_COOKIEFILE] = "";
          break;
      }
    }

    return $options;
  }

  /*--------------------------------------------------------------------
   *
   * Server verification.
   *
   * These methods verify a connection to a server and request the
   * features it supports.
   *
   *--------------------------------------------------------------------*/

  /**
   * Resets verification of server communications.
   *
   * Server verification is needed prior to the first server request to
   * insure that the server exists and can respond to requests, and to
   * get the list of HTTP verbs supported by the sever.
   *
   * Server verification should be reset each time key communications
   * parameters are changed, such as the host name. It is not necessary to
   * reset on changes to authentication credentials since the server would
   * still exist and still support the same range of HTTP requests.
   *
   * @see ::verifyServer()
   */
  protected function unverifyServer() {
    // Log the user out if they are logged in.
    $this->logout();

    $this->serverVerified = FALSE;
    $this->httpVerbs = [];
  }

  /**
   * Verifies that the server exists and can respond to requests.
   *
   * This method is called the first time a request is made of the server.
   * It serves two purposes:
   * - Validates that the server exists and can respond.
   * - Gets the HTTP verbs that it responds to.
   *
   * An exception is thrown if the server does not exist or cannot respond.
   * An exception is also thrown if the server does not respond with an
   * 'Allow' header that lists supported HTTP verbs.
   *
   * Otherwise the 'Allow' header is used to initialize the $httpVerbs
   * field that lists supported HTTP verbs, such as 'GET', 'POST',
   * 'PATCH', and 'DELETE'.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such as with a bad host name.
   *   - The host refuses contact.
   *   - The host does not support the operation.
   *
   * @see ::unverifyServer()
   */
  protected function verifyServer() {
    //
    // Validate
    // --------
    // If the server has already been verified, no further action is needed.
    if ($this->serverVerified === TRUE) {
      return;
    }

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    // Authentication credentials are not required.
    //
    // Use an OPTIONS request. The request should not return a body
    // and we need the header.
    //
    // Setting COOKIELIST to "ALL" clears all prior cookies.
    $url = $this->hostName . self::URL_GET . '/0?_format=json';

    $options                         = $this->getCommonCurlOptions(FALSE);
    $options[CURLOPT_URL]            = $url;
    $options[CURLOPT_HTTPGET]        = FALSE;
    $options[CURLOPT_CUSTOMREQUEST]  = 'OPTIONS';
    $options[CURLOPT_NOBODY]         = TRUE;
    $options[CURLOPT_HEADERFUNCTION] = [$this, 'verifyHeaderCallback'];
    $options[CURLOPT_COOKIELIST]     = "ALL";

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue request
    // -------------
    // There are two types of errors possible:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content will be the HTTP header.
    $this->printVerbose("Verifying server connection");

    $this->httpVerbs = [];

    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with CURL or with
      // its communications with the server. This is fatal.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Decode JSON content.
    if (empty($content) === FALSE) {
      $content = json_decode($content, TRUE);
    }

    if ($httpCode !== 200) {
      // Server error. The server has rejected the request for any number
      // of reasons.
      if (isset($content['message']) === TRUE) {
        $message = $content['message'];
      }
      else {
        // Unfortunately, Drupal has not provided a meaningful HTTP code or
        // message on some failures. For instance, if the route we tried is not
        // recognized because the FolderShare module is not enabled, Drupal
        // returns a generic "404" error, indicating "The requested information
        // could not be found." This is no help to the user.
        if ($httpCode === 404) {
          $message = "The host has declined the connection.
This can occur if the host does not have the FolderShare module enabled,
or if web services for the module have not been enabled. Please contact the
host's administrator.";
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }
      }

      $this->printVerbose("  Server failed with HTTP code $httpCode");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    $this->printVerbose("  Server connection verified");
    $this->serverVerified = TRUE;
  }

  /**
   * Responds to an HTTP header receipt during a server verify.
   *
   * This method is called by CURL while verifying a connection. Each call
   * passes a line from the incoming HTTP header. The line is parsed
   * to look for "Allow", which includes the names of HTTP verbs supported.
   * This is saved for later use.
   *
   * @param resource $curlSession
   *   The resource for the current CURL session.
   * @param string $line
   *   The HTTP header line to parse.
   *
   * @return int
   *   Returns the number of bytes in the incoming HTTP header line.
   */
  private function verifyHeaderCallback($curlSession, $line) {
    // Get the length (in bytes) of the incoming line. This must be
    // returned when the function is done.
    $lineBytes = strlen($line);

    // Split the line to get the header key and value.
    $parts = mb_split(':', $line, 2);
    if (count($parts) !== 2) {
      return $lineBytes;
    }

    // We only want the allow header line.
    if ($parts[0] !== 'Allow') {
      return $lineBytes;
    }

    // Extract the HTTP verbs from the line.
    $verbs = explode(",", $parts[1]);
    foreach ($verbs as $verb) {
      $this->httpVerbs[] = strtoupper(trim($verb));
    }

    return $lineBytes;
  }

  /**
   * Returns TRUE if HTTP DELETE requests are supported on the server.
   *
   * If no requests have been made to the server yet, this method makes
   * a server request to get the list of HTTP requests supported.
   *
   * @return bool
   *   Returns TRUE if HTTP DELETE requests are supported.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact.
   *   - The host does not support the operation.
   */
  public function isHttpDeleteSupported() {
    $this->verifyServer();
    return in_array('DELETE', $this->httpVerbs);
  }

  /**
   * Returns TRUE if HTTP GET requests are supported on the server.
   *
   * If no requests have been made to the server yet, this method makes
   * a server request to get the list of HTTP requests supported.
   *
   * @return bool
   *   Returns TRUE if HTTP GET requests are supported.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact.
   *   - The host does not support the operation.
   */
  public function isHttpGetSupported() {
    $this->verifyServer();
    return in_array('GET', $this->httpVerbs);
  }

  /**
   * Returns TRUE if HTTP PATCH requests are supported on the server.
   *
   * If no requests have been made to the server yet, this method makes
   * a server request to get the list of HTTP requests supported.
   *
   * @return bool
   *   Returns TRUE if HTTP PATCH requests are supported.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact.
   *   - The host does not support the operation.
   */
  public function isHttpPatchSupported() {
    $this->verifyServer();
    return in_array('PATCH', $this->httpVerbs);
  }

  /**
   * Returns TRUE if HTTP POST requests are supported on the server.
   *
   * If no requests have been made to the server yet, this method makes
   * a server request to get the list of HTTP requests supported.
   *
   * @return bool
   *   Returns TRUE if HTTP POST requests are supported.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact.
   *   - The host does not support the operation.
   */
  public function isHttpPostSupported() {
    $this->verifyServer();
    return in_array('POST', $this->httpVerbs);
  }

  /**
   * Gets a list of HTTP operations supported on the server.
   *
   * If no requests have been made to the server yet, this method makes
   * a server request to get the list of HTTP requests supported. Typically
   * these include:
   * - GET = get items or configuration information.
   * - POST = create or upload new items.
   * - PATCH = change existing items.
   * - DELETE = delete existing items.
   *
   * A host usually has all of these enabled, but for security reasons it
   * is possible for a host to disable some or all of them. For instance,
   * a host that publishes public content may have GET enabled, but it
   * may disable POST, PATCH, and DELETE so that that content cannot be
   * modified.
   *
   * @return string[]
   *   Returns an array listing the HTTP verbs supported by the server.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact.
   *   - The host does not support the operation.
   *
   * @see ::getConfiguration()
   * @see ::isHttpDeleteSupported()
   * @see ::isHttpGetSupported()
   * @see ::isHttpPatchSupported()
   * @see ::isHttpPostSupported()
   */
  public function getHttpVerbs() {
    $this->verifyServer();
    return $this->httpVerbs;
  }

  /*--------------------------------------------------------------------
   *
   * CSRF token handling.
   *
   * A CSRF (Cross-Site Request Forgery) token must be requested, returned,
   * and used for all operations that might change remote content (e.g.
   * for POST, PATCH, and DELETE requests).
   *
   *--------------------------------------------------------------------*/

  /**
   * Clears the CSRF token saved from a prior request.
   *
   * The connection's CSRF token is initially cleared and it must be
   * cleared again (to trigger a future request for a new CSRF token)
   * any time primary communications parameters are changed, such as
   * the host name. Logging out also clears the CSRF token.
   *
   * @see ::getCSRFToken()
   * @see ::logout()
   */
  protected function clearCSRFToken() {
    $this->csrfToken = NULL;
  }

  /**
   * Returns the current CSRF token.
   *
   * A "Cross-Site Request Forgery" (CSRF) is a web site or user attack
   * that sends a message to a server, without the user's knowledge, to
   * change the user's data on the server. The message reuses the current
   * authentication credentials of a logged in user.
   *
   * A CSRF token is a randomly generated string created by the server and
   * issued to the client for use throughout a session. Drupal requires
   * that all "non-safe" HTTP requests (i.e. anything except OPTIONS, GET,
   * and HEAD) send a previously acquired CSRF token as part of its
   * validation mechanism. Requests without the CSRF token, or with a wrong
   * token, are rejected.
   *
   * The communications methods in this class call this method to get the
   * current CSRF token each time they need to make a "non-safe" operation.
   * If no token has been requested from the site yet, a request is made
   * immediately to get a new CSRF token. That token is saved and re-used
   * on future calls.
   *
   * Cookie-based authentication automatically retreives the CSRF token
   * during login. Other authentication types may require an explicit
   * request to get a CSRF token.
   *
   * @return string
   *   Returns the current CSRF token, or NULL if a token could not
   *   be retrieved because the site is down or it does not respond to
   *   a token request.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *
   * @see ::clearCSRFToken()
   * @see ::login()
   */
  protected function getCSRFToken() {
    //
    // Return current token (if any)
    // -----------------------------
    // If a previous request for a CSRF token has already returned one
    // for this session, return it immediately.
    if ($this->csrfToken !== NULL) {
      return $this->csrfToken;
    }

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    //
    // Authentication credentials are not required. In fact, if they are
    // included, the request will fail.
    $options = $this->getCommonCurlOptions(FALSE);
    $options[CURLOPT_HTTPGET] = TRUE;
    $options[CURLOPT_URL] = $this->hostName . self::URL_CSRF_TOKEN;

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue GET
    // ---------
    // Issue an HTTP GET to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content is the CSRF token string.
    $this->printVerbose("Getting CSRF token");
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    if ($httpCode !== 200) {
      // A CSRF token was not returned! Operations that require the token
      // cannot be done.
      if (isset($content['message']) === TRUE) {
        $message = $content['message'];
      }
      else {
        $message = $this->getNiceHttpErrorMessage($httpCode);
      }

      $this->printVerbose("  Server failed with HTTP code $httpCode");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    $this->printVerbose("  CSRF token received");
    $this->csrfToken = $content;
    return $this->csrfToken;
  }

  /*--------------------------------------------------------------------
   *
   * Login and logout.
   *
   * Login uses the authentication type and credentials to establish an
   * authenticated connection with the server. Logout reverses that.
   *
   *--------------------------------------------------------------------*/

  /**
   * Logs the user in to the server using the given authentication credentials.
   *
   * Authentication credentials include a user name and password for
   * the remote server. Setting these credentials and logging in must be
   * done before any requests may be sent to the server.
   *
   * The way authentication credentials are used depends upon the
   * authentication type:
   * - AUTHENTICATION_NONE does not use the credentials at all. Requests
   *   to the server are not authenticated, which usually means the caller
   *   only has access to public content that is available to anonymous
   *   visitors to the site.
   * - AUTHENTICATION_BASIC sends the credentials along on every request.
   *   The server checks the credentials before responding.
   * - AUTHENTICATION_COOKIE sends the credentials along on an initial
   *   "login" request during which the server checks the credentials before
   *   responding with a session cookie. The session cookie is then sent
   *   along on all subsequent requests.
   *
   * AUTHENTICATION_NONE is the initial state for a newly opened connection
   * object, but it is rarely suitable for use. Most activities require an
   * authenticated connection.
   *
   * AUTHENTICATION_COOKIE is the style used by web browsers where the user
   * logs into a site, then makes requests for site pages. The browser saves
   * the session cookie and automatically re-uses it for each request.
   *
   * AUTHENTICATION_BASIC is the style used by many web services. The
   * credentials are passed along with every request and there are no cookies
   * involved. This works because web services are often isolated requests
   * rather than a user asking for page after page after page.
   *
   * The AUTHENTICATION_COOKIE type is the most efficient when multiple
   * requests are made through the same open connection. This type only does
   * full authentication on the server at the start of a series of requests.
   * However, if the server has a non-standard login process, then this
   * class cannot reliably get the session cookie and authentication will
   * fail.
   *
   * The AUTHENTICATION_BASIC type is the most reliable since it can work
   * regardless of the login process.
   *
   * The special AUTHENTICATION_AUTO type indicates that automatic type
   * finding should progress through several authentication types and stop
   * on the first one that succeeds.
   *
   * Authentication failure can occur for several reasons:
   * - The server may not support web services at all.
   * - The server may have a non-standard user login form that requires a
   *   CAPTCHA or other required input that this software cannot provide.
   * - The server may not have the authentication type enabled.
   * - The server may reject the user name and password as invalid.
   *
   * If the user name is empty, no authentication can be done and the
   * authentication type is automatically reset to AUTHENTICATION_NONE.
   *
   * @param string $userName
   *   The user name. If the name is empty, authentication is disabled and
   *   the authentication type is set to AUTHENTICATION_NONE.
   * @param string $password
   *   The password. An empty password is allowed, but discouraged.
   * @param int $authenticationType
   *   (optional, default = AUTHENTICATION_AUTO) The type of
   *   authentication to use with the credentials.
   *
   * @return bool
   *   Returns TRUE if the login is successful, and FALSE otherwise.
   *   Failed authentication often throws an exception.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such as with a bad host name.
   *   - The host refuses contact.
   *   - The authentication type is not accepted.
   *   - The user name or password are not accepted.
   *
   * @see ::getUserName()
   * @see ::getPassword()
   * @see ::getAuthenticationType()
   * @see ::isLoggedIn()
   * @see ::logout()
   */
  public function login(
    string $userName,
    string $password,
    int $authenticationType = self::AUTHENTICATION_AUTO) {

    //
    // Verify server
    // -------------
    // If the server connection has not yet been verified, do so now.
    // We cannot log in without being sure that the host and port are
    // right and that a server is responding.
    //
    // This call returns immediately if the server is already verified.
    //
    // This call throws an exception if the host name is bad or there
    // is some other problem communicating with the server.
    $this->verifyServer();

    //
    // Log out
    // -------
    // If the user is already logged in, then log out first. After this,
    // the authentication status is guaranteed to be STATUS_NOT_LOGGED_IN.
    //
    // This call returns immediately if the user is not logged in.
    $this->logout();

    //
    // Save values
    // -----------
    // If the user name is empty, then there is no authentication type.
    // Otherwise, select the indicated authentication type and save the
    // user name and password.
    if (empty($userName) === TRUE) {
      $authenticationType = self::AUTHENTICATION_NONE;
      $this->userName = '';
      $this->password = '';
    }
    else {
      $this->userName = $userName;
      $this->password = $password;
    }

    //
    // Log in
    // ------
    // Use the given authentication type. If it is for automatic type
    // finding, then this will loop through several methods to find the
    // first one that works.
    return $this->loginInternal($authenticationType);
  }

  /**
   * Logs the user in, recursing through more than one method.
   *
   * The requested authentication type is used to log the user in and
   * confirm that it worked. If the AUTHENTICATION_AUTO type is used,
   * and the initial login or validation fails, then the next type is
   * tried by recursing.
   *
   * @param int $authenticationType
   *   The type of authentication to use with current credentials.
   * @param int $autoIndex
   *   (optional, default = 0) If the authentication type is
   *   AUTHENTICATION_AUTO, the $autoIndex indicates the next authentication
   *   type to try. For other authentication types, the index is not used.
   *
   * @return bool
   *   Returns TRUE if the login is successful, and FALSE on failure.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such as with a bad host name.
   *   - The host refuses contact.
   *   - The user name or password are not accepted.
   *
   * @see ::login()
   */
  private function loginInternal(int $authenticationType, int $autoIndex = 0) {

    //
    // Select authentication type
    // --------------------------
    // If an explicit authentication type has been requested, use it.
    // Otherwise for automatic type finding, try the next one.
    if ($authenticationType === self::AUTHENTICATION_AUTO) {
      if ($autoIndex < 0 ||
          $autoIndex >= count(self::AUTHENTICATION_AUTO_ORDER)) {
        // The auto index is out of bounds. We've reached the end of
        // automatic authentication type handling.
        $this->authenticationType = self::AUTHENTICATION_NONE;
        throw new \RuntimeException(
"The login to the host failed.
The user name and/or password may be incorrect. Please check them for typos.
If they appear correct, it is also possible that the host's web services are
not configured to support the type of authentication used by this software.
Please contact the host's administrator.");
      }
      else {
        // Try the next authentication type.
        $this->authenticationType = self::AUTHENTICATION_AUTO_ORDER[$autoIndex];
        $auto = TRUE;
      }
    }
    else {
      // Use the explicit authentication type requested.
      $this->authenticationType = $authenticationType;
      $auto = FALSE;
    }

    //
    // Log in
    // ------
    // Based upon the authentication type, contact the server.
    $this->printVerbose("  Logging in with " .
      $this->getAuthenticationTypeName($this->authenticationType));

    $savedException = NULL;

    switch ($this->authenticationType) {
      default:
      case self::AUTHENTICATION_NONE:
        // No authentication.
        //
        // When there is no authentication in use, the user is always
        // logged out and only has access to public content available for
        // non-authenticated users.
        $this->authenticationStatus = self::STATUS_NOT_LOGGED_IN;
        return FALSE;

      case self::AUTHENTICATION_BASIC:
        // Authentication is on every request.
        //
        // When using basic authentication, the user is logged in separately
        // on every request. There is no explicit login process. The status
        // is set to pending until the first request using the credentials
        // confirms that the credentials work.
        $this->authenticationStatus = self::STATUS_PENDING;
        break;

      case self::AUTHENTICATION_COOKIE:
        // Authentication uses a session cookie.
        //
        // When using cookie authentication, the user is logged in by sending
        // credentials now. On success, the server returns a session cookie
        // that is used for further requests.
        try {
          $this->loginForCookie();
          $this->authenticationStatus = self::STATUS_PENDING;
          $this->printVerbose("  Authentication succeeded");
        }
        catch (\Exception $e) {
          $this->authenticationStatus = self::STATUS_FAILED;
          $this->printVerbose("  Authentication failed");
          $savedException = $e;
        }
        break;
    }

    // If authentication failed, either stop or advance to the next
    // automatic authentication type check.
    if ($this->authenticationStatus === self::STATUS_FAILED) {
      if ($auto === FALSE) {
        if ($savedException !== NULL) {
          throw $savedException;
        }
        return FALSE;
      }

      return $this->loginInternal(self::AUTHENTICATION_AUTO, ($autoIndex + 1));
    }

    //
    // Test login
    // ----------
    // The above login may have succeeded, but the mechanism for loggin in
    // *DOES NOT* guarantee further access to content. The Drupal REST
    // module can be configured by a site to only support a specific
    // authentication type (e.g. cookies or basic). This means it is
    // possible to log in with cookies, then fail when a REST request is made.
    //
    // We'd like to detect this problem and switch authentication methods
    // if needed. To do so, we need to issue a request and see if it fails.
    try {
      // Make a gratuitous request for the site's configuration. The server
      // always returns success on this request IF the site's REST configuration
      // supports the current authentication type.
      $this->printVerbose("  Testing authentication");
      $this->getConfiguration();
      $this->printVerbose("  Testing succeeded");
    }
    catch (\Exception $e) {
      // The request failed!
      $this->authenticationStatus = self::STATUS_FAILED;
      $this->printVerbose("  Testing failed");

      // If the test failed with any error except 403, then the site
      // configuration is not compatible with this class. We have to fail,
      // even when using automatic authentication type finding.
      $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
      if ($httpCode !== 403) {
        // It wasn't a 403 error.
        //
        // Log out in order to clean up state (this may try to send another
        // request, but it won't throw an exception).
        //
        // Error codes and messages from testing are not useful to return
        // to the caller. For example, if the web service is not enabled,
        // testing will return a 404 error and a complaint that the route
        // is bad. That is not a useful message for the user.
        //
        // Instead, re-throw an earlier exception, or throw a new exception
        // with a useful message.
        $this->logout();
        if ($savedException !== NULL) {
          throw $savedException;
        }
        throw new \RuntimeException(
"The login to the host failed.
The host is not responding properly to communications requests. This can
occur if the host's web services are not enabled. Please contact the host's
administrator.");
      }

      // When the error code is 403, the server is telling us that it is
      // not configured to use the authentication method or serialization
      // format we tried, even though it succeeded with the login. This can
      // happen if we've used cookie authentication to log in, but the REST
      // services we need to use have been set for basic authentication,
      // or something else. This can also happen if we've sent JSON format
      // data, but the server is not configured to accept it.
      //
      // If we're not using automatic authentication type finding, then
      // we're done and have to fail.
      if ($auto === FALSE) {
        // Log out in order to clean up state (this may try to send another
        // request, but it won't throw an exception).
        $this->logout();
        throw new \RuntimeException(
"The login to the host failed.
The host is not configured to accept the type of communications done by
this software. This may be due to a configuration problem with the host's
web services. Please contact the host's administrator.");
      }

      // Try the next authentication type.
      return $this->loginInternal(self::AUTHENTICATION_AUTO, ($autoIndex + 1));
    }

    // The login test succeeded! We're logged in with an authentication
    // method that the site supports.
    $this->authenticationStatus = self::STATUS_LOGGED_IN;
    return TRUE;
  }

  /**
   * Logs in using cookie authentication.
   *
   * The current credentials are used to log in and request a session
   * cookie, which is automatically saved for further use within CURL.
   * A successful login also returns and saves the CSRF token and a
   * logout token.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such as with a bad host name.
   *   - The host does not support the operation.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The arguments are malformed.
   *   - The arguments are invalid, such as for non-existent items.
   *
   * @see ::login()
   */
  private function loginForCookie() {
    //
    // Create headers
    // --------------
    // The headers specify that post fields are in JSON format.
    $headers = [];
    $headers[] = 'Content-Type: application/json';

    $postFields = [
      'name' => $this->userName,
      'pass' => $this->password,
    ];

    $postText = json_encode($postFields);

    //
    // Create URL
    // ----------
    // The POST URL includes:
    // - The host name.
    // - The post web services path.
    // - The return syntax.
    $url = $this->hostName . self::URL_USER_LOGIN . '?_format=json';

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    //
    // Setting COOKIELIST to "ALL" clears all prior cookies.
    //
    // Setting COOKIEFILE to "" causes CURL to maintain cookies in memory
    // instead of in a file.
    $options                     = $this->getCommonCurlOptions(FALSE);
    $options[CURLOPT_URL]        = $url;
    $options[CURLOPT_POST]       = 1;
    $options[CURLOPT_POSTFIELDS] = $postText;
    $options[CURLOPT_COOKIELIST] = "ALL";
    $options[CURLOPT_COOKIEFILE] = "";
    $options[CURLOPT_HTTPHEADER] = $headers;

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue POST
    // ----------
    // Issue an HTTP POST to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Decode JSON content.
    if (empty($content) === FALSE) {
      $content = json_decode($content, TRUE);
    }

    switch ($httpCode) {
      case 200:
        // OK. The request succeeded. Continue.
        break;

      case 400:
        // Drupal's UserAuthenticationController checks the incoming
        // credentials and responds with any of several possible errors.
        // Unfortunately, they all come back as a generic HTTP 400 error
        // for a "Bad request":
        // - Missing credentials.
        // - Missing credentials.name.
        // - Missing credentials.pass.
        // - User account has not been activated or is blocked.
        // - Sorry, unrecognized username or password.
        //
        // The first three are not possible because we created and passed
        // the POST fields above.
        //
        // The latter two errors are important and should have been 401 errors.
        if (isset($content['message']) === TRUE) {
          // Use the message returned by Drupal, as bad as it is.
          $message = $content['message'];
          if (mb_ereg_search('blocked', $message) === TRUE) {
            // Replace the message with something friendlier.
            $message = "The login to the host has failed.
The account may have been blocked. If the account is new, it may not yet have
been activated. Please contact the host's administrator.";
          }
          elseif (mb_ereg_search('unrecognized', $message) === TRUE) {
            $message = "The login to the host has failed.
The user name and/or password may be incorrect. Please check them for typos.";
          }
        }
        else {
          $message = "The login to the host has failed.
The user name and/or password may be incorrect. Please check them for typos.
If they appear correct, it is also possible that the account has been blocked.
If the account is new, it may not yet have been activated. Please contact the
host's administrator.";
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);

      case 429:
        // Drupal's UserAuthenticationController checks for rapid failed
        // login requests and, after a limit, blocks the account for the
        // requestor's IP address.
        //
        // Fall thru to the 'default' HTTP code handling.
        //
      default:
        if (isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }

    //
    // Parse content
    // -------------
    // The returned content is an associative array of the form:
    // @code
    // [
    //   "current_user" => [
    //     "uid" => 1234,
    //     "roles" => [
    //       "authenticated",
    //       "administrator",
    //       ...
    //     ],
    //     "name" => "myname",
    //   ],
    //   "csrf_token" => "token",
    //   "logout_token" => "token",
    // ]
    // @endcode
    //
    // The "name" we already know since we provided it in the first place.
    // The user ID and roles are irrelevant here.
    //
    // Get and save the CSRF and logout tokens. The CSRF token must be
    // sent to the server on all requests that change content (i.e.
    // POST, PATCH, and DELETE). The logout token must sent to the server
    // to formally log out.
    if (isset($content['csrf_token']) === TRUE) {
      $this->csrfToken = $content['csrf_token'];
    }

    if (isset($content['logout_token']) === TRUE) {
      $this->logoutToken = $content['logout_token'];
    }
  }

  /**
   * Logs the user out of the server.
   *
   * If the user is not logged in, this method has no effect.
   *
   * If the user is logged in, they are logged out and their authentication
   * credentials cleared. Further requests to the server will not provide
   * authentication credentials. These requests may succeed if the server
   * allows unauthenticated access, but they will fail if the user needs
   * to be logged in.
   *
   * @see ::login()
   * @see ::isLoggedIn()
   */
  public function logout() {
    //
    // Logout
    // ------
    // If the server hasn't been verified yet, then we're already logged out.
    // If the user is logged in, log them out.
    if ($this->serverVerified === FALSE) {
      return;
    }

    if ($this->authenticationStatus === self::STATUS_LOGGED_IN ||
        $this->authenticationStatus === self::STATUS_PENDING) {
      switch ($this->authenticationType) {
        default:
        case self::AUTHENTICATION_NONE:
        case self::AUTHENTICATION_BASIC:
          // No logout required.
          break;

        case self::AUTHENTICATION_COOKIE:
          // Logout with the logout token.
          $this->printVerbose("Logging out");
          try {
            $this->logoutForCookie();
          }
          catch (\Exception $e) {
            // Ignore exceptions.
          }
          $this->printVerbose("  Logged out");
          break;
      }
    }

    //
    // Reset
    // -----
    // Clear the authentication status, authentication type, user name,
    // and password.
    $this->authenticationStatus = self::STATUS_NOT_LOGGED_IN;
    $this->authenticationType = self::AUTHENTICATION_NONE;
    $this->userName = '';
    $this->password = '';
    $this->logoutToken = '';
    $this->clearCSRFToken();
  }

  /**
   * Logs out using cookie authentication.
   *
   * The logout token, if any, is used to log out the user.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such as with a bad host name.
   *   - The host does not support the operation.
   *   - The host refuses contact, such as with bad authentication credentials.
   *
   * @see ::logout()
   */
  private function logoutForCookie() {
    //
    // Validate
    // --------
    // If there is no saved logout token from a prior login, then there
    // is no need to logout.
    if (empty($this->logoutToken) === TRUE) {
      return;
    }

    //
    // Create URL
    // ----------
    // The POST URL includes:
    // - The host name.
    // - The post logout path.
    // - The return syntax.
    // - The logout token.
    $url = $this->hostName . self::URL_USER_LOGOUT . '?_format=json';
    $url .= '&token=' . $this->logoutToken;

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    $options                     = $this->getCommonCurlOptions(FALSE);
    $options[CURLOPT_URL]        = $url;
    $options[CURLOPT_POST]       = 1;
    $options[CURLOPT_POSTFIELDS] = '';

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue POST
    // ----------
    // Issue an HTTP POST to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Decode JSON content.
    if (empty($content) === FALSE) {
      $content = json_decode($content, TRUE);
    }

    // Check for error codes. The following are standard HTTP success codes:
    // - 200 = OK.
    // - 201 = Created.
    // - 202 = Accepted.
    // - 203 = Non-authoritative information.
    // - 204 = No content.
    // - 205 = Reset content.
    // - 206 = Partial content.
    // - 207 = Multi-status.
    // - 208 = Already reported.
    //
    // A POST to log out should always succeed and not return anything.
    //
    // All other standard success codes are unacceptable.
    switch ($httpCode) {
      case 200:
      case 204:
        return;

      default:
        if (isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }
  }

  /*--------------------------------------------------------------------
   *
   * Issue requests.
   *
   * These methods issue HTTP requests for raw data or to trigger operations.
   * Other methods parse that data and return it.
   *
   *--------------------------------------------------------------------*/

  /**
   * Issues a GET request.
   *
   * GET requests are used to retrieve information from the server.  The
   * type of information varies with the operation choice:
   * - Single entity requests:
   *   - 'get-entity' returns an entity.
   *   - 'get-parent' returns an entity's parent entity.
   *   - 'get-root' returns an entity's root entity.
   * - Special entity information requests:
   *   - 'get-sharing' returns an entity's sharing settings.
   * - Entity list requests:
   *   - 'get-ancestors' returns a list of an entity's ancestor entities.
   *   - 'get-descendants' returns a list of an entity's descendant entities.
   *   - 'get-search' returns a search through an entity's subtree.
   * - Configuration and misc data requests:
   *   - 'get-configuration' returns site and module settings.
   *   - 'get-usage' returns module usage stats for the user.
   *   - 'get-version' returns version numbers for site software.
   *
   * GET requests focused on an entity (get the entity, parent, root,
   * ancestors, or descendants) require a full folder path or a numeric
   * entity ID to indicate the entity.
   *
   * @param string $operation
   *   The name of the requested operation (e.g. "get-entity").
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file or folder.
   *   Some operations do not use this value.
   *
   * @return mixed
   *   Returns a variety of content types depending upon the operation.
   *   In most cases, the content returned is an array.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The arguments are malformed.
   *   - The arguments are invalid, such as for non-existent items.
   */
  protected function httpGet(string $operation, $entityIdOrPath) {
    //
    // Initialize
    // ----------
    // Check if HTTP GET is supported. This may issue a connection to the
    // server to find out. If that fails, an exception is thrown.
    if ($this->isHttpGetSupported() === FALSE) {
      throw new \RuntimeException(
        "The operation cannot be completed.\nThe host does not support requests to get content.");
    }

    //
    // Create headers
    // --------------
    // The headers specify the GET operation and the source path, if any.
    $headers = [];

    // Set the operation.
    $headers[] = 'X-FolderShare-Get-Operation: ' . $operation;

    // Request the returned data in a specific format.
    $headers[] = 'X-FolderShare-Return-Format: ' . $this->format;

    // Set the source path if an entity ID is not used.
    if (is_numeric($entityIdOrPath) === TRUE) {
      $entityId = intval($entityIdOrPath);
    }
    else {
      // Make sure the path is safe. HTTP headers primarily support ASCII,
      // while paths may include multi-byte characters. Use URL encoding
      // to add them to the header.
      $entityId = 0;
      $headers[] = 'X-FolderShare-Source-Path: ' . rawurlencode($entityIdOrPath);
    }

    //
    // Create URL
    // ----------
    // The GET URL includes:
    // - The host name.
    // - The canonical web services path.
    // - The entity ID (even if one is not used).
    // - The return syntax.
    $url = $this->hostName . self::URL_GET . '/' . $entityId . '?_format=json';

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    //
    // Authentication settings should be included for most operations.
    //
    // A CSRF token is not required.
    $this->printVerbose("Issuing GET for $operation");
    $options                     = $this->getCommonCurlOptions(TRUE);
    $options[CURLOPT_URL]        = $url;
    $options[CURLOPT_HTTPGET]    = TRUE;
    $options[CURLOPT_HTTPHEADER] = $headers;

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue GET
    // ---------
    // Issue an HTTP GET to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Decode JSON content.
    if (empty($content) === FALSE) {
      $content = json_decode($content, TRUE);
    }

    // Check for error codes. The following are standard HTTP success codes:
    // - 200 = OK.
    // - 201 = Created.
    // - 202 = Accepted.
    // - 203 = Non-authoritative information.
    // - 204 = No content.
    // - 205 = Reset content.
    // - 206 = Partial content.
    // - 207 = Multi-status.
    // - 208 = Already reported.
    // - 226 = IM used.
    //
    // A 200 is returned when there is content, and 204 when there isn't.
    // Both are OK.
    //
    // All other standard success codes are unacceptable.
    switch ($httpCode) {
      case 200:
        $this->printVerbose("  Request complete");
        return $content;

      case 204:
        $this->printVerbose("  Request complete");
        if (empty($content) === TRUE) {
          return NULL;
        }
        return $content;

      default:
        if (isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }
  }

  /**
   * Deletes content on a web server.
   *
   * DELETE requests are used to delete a single entity, or a subtree,
   * based upon the operation choice:
   * - 'delete-file' deletes a single file.
   * - 'delete-folder' deletes a single empty folder.
   * - 'delete-folder-tree' deletes a single folder recursively.
   * - 'delete-file-or-folder' deletes a single file or empty folder.
   * - 'delete-file-or-folder-tree' deletes anything recursively.
   *
   * DELETE requests require a full folder path or a numeric entity ID
   * to indicate the entity to delete.
   *
   * DELETE requests do not return content. On an error, an exception
   * is thrown.
   *
   * @param string $operation
   *   The name of the requested operation (e.g. "delete-file").
   * @param int|string $entityIdOrPath
   *   Some operations do not use this value.
   *   The numeric entity ID or string path for a remote file or folder.
   *
   * @return mixed
   *   Always empty.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The arguments are malformed.
   *   - The arguments are invalid, such as for non-existent items.
   */
  protected function httpDelete(string $operation, $entityIdOrPath) {
    //
    // Initialize
    // ----------
    // Check if HTTP DELETE is supported. This may issue a connection to the
    // server to find out. If that fails, an exception is thrown.
    if ($this->isHttpDeleteSupported() === FALSE) {
      throw new \RuntimeException(
        "The operation cannot be completed.\nThe host does not support requests to delete content.");
    }

    //
    // Create headers
    // --------------
    // The headers specify the DELETE operation and the source path.
    // There is no return format header because DELETE never returns
    // anything except success or error codes.
    $headers = [];

    // Set the operation.
    $headers[] = 'X-FolderShare-Delete-Operation: ' . $operation;

    // Get the CSRF token. This may trigger a GET request to the server.
    // If that fails, an exception is thrown.
    $headers[] = 'X-CSRF-Token: ' . $this->getCSRFToken();

    // Set the source path if an entity ID is not used.
    if (is_numeric($entityIdOrPath) === TRUE) {
      $entityId = intval($entityIdOrPath);
    }
    else {
      // Make sure the path is safe. HTTP headers primarily support ASCII,
      // while paths may include multi-byte characters. Use URL encoding
      // to add them to the header.
      $entityId = 0;
      $headers[] = 'X-FolderShare-Source-Path: ' . rawurlencode($entityIdOrPath);
    }

    //
    // Create URL
    // ----------
    // The DELETE URL includes:
    // - The host name.
    // - The canonical web services path.
    // - The entity ID (even if one is not used).
    // - The return syntax.
    $url = $this->hostName . self::URL_DELETE . '/' . $entityId . '?_format=json';

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    //
    // Authentication settings should be included for most operations.
    //
    // A CSRF token is required.
    $this->printVerbose("Issuing DELETE for $operation");
    $options                        = $this->getCommonCurlOptions(TRUE);
    $options[CURLOPT_URL]           = $url;
    $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    $options[CURLOPT_HTTPHEADER]    = $headers;

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue DELETE
    // ------------
    // Issue an HTTP DELETE to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Decode JSON content.
    if (empty($content) === FALSE) {
      $content = json_decode($content, TRUE);
    }

    // Check for error codes. The following are standard HTTP success codes:
    // - 200 = OK.
    // - 201 = Created.
    // - 202 = Accepted.
    // - 203 = Non-authoritative information.
    // - 204 = No content.
    // - 205 = Reset content.
    // - 206 = Partial content.
    // - 207 = Multi-status.
    // - 208 = Already reported.
    // - 226 = IM used.
    //
    // Since a DELETE never returns anything, only a 204 is valid.
    //
    // All other standard success codes are unacceptable.
    switch ($httpCode) {
      case 204:
        $this->printVerbose("  Request complete");
        if (empty($content) === TRUE) {
          return NULL;
        }
        return $content;

      default:
        if (isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }
  }

  /**
   * Posts new content to the web server.
   *
   * POST requests are used to create a single entity based upon the
   * operation choice:
   * - 'new-rootfolder' creates a new root folder.
   * - 'new-folder' creates a new subfolder.
   * - 'new-file' creates a new file.
   * - 'new-media' creates a new media item.
   *
   * POST requests never support a numeric entity ID since the operation
   * is creating an entity. The $path argument selects the location in
   * which to create the new entity.
   *
   * @param string $operation
   *   The name of the requested operation.
   * @param string $path
   *   The path to a remote parent folder into which to place a new entity.
   * @param array $postFields
   *   (optional, default = []) The post fields in 'full' entity format
   *   for operations that need additional values.
   *
   * @return mixed
   *   Returns a variety of content types depending upon the operation.
   *   In most cases, the content returned is an array.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The arguments are malformed.
   *   - The arguments are invalid, such as for non-existent items.
   */
  protected function httpPost(
    string $operation,
    string $path,
    array $postFields = []) {

    //
    // Initialize
    // ----------
    // Check if HTTP POST is supported. This may issue a connection to the
    // server to find out. If that fails, an exception is thrown.
    if ($this->isHttpPostSupported() === FALSE) {
      throw new \RuntimeException(
        "The operation cannot be completed.\nThe host does not support requests to create or upload new content.");
    }

    //
    // Create headers
    // --------------
    // The headers specify the POST operation and the source path, if any.
    $headers = [];

    // Set the operation.
    $headers[] = 'X-FolderShare-Post-Operation: ' . $operation;

    // Get the CSRF token. This may trigger a GET request to the server.
    // If that fails, an exception is thrown.
    $headers[] = 'X-CSRF-Token: ' . $this->getCSRFToken();

    // Make sure the path is safe. HTTP headers primarily support ASCII,
    // while paths may include multi-byte characters. Use URL encoding
    // to add them to the header.
    $headers[] = 'X-FolderShare-Destination-Path: ' . rawurlencode($path);

    // POST fields are serialized as JSON.
    $headers[] = 'Content-Type: application/json';

    // Request the returned data in a specific format.
    $headers[] = 'X-FolderShare-Return-Format: ' . $this->format;

    // Process post fields, if provided.
    if (empty($postFields) === TRUE) {
      $postFields = $this->createDummyEntity();
    }

    $postText = json_encode($postFields);

    //
    // Create URL
    // ----------
    // The POST URL includes:
    // - The host name.
    // - The post web services path.
    // - The return syntax.
    $url = $this->hostName . self::URL_POST . '?_format=json';

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    //
    // Authentication settings should be included for most operations.
    //
    // A CSRF token is required.
    $this->printVerbose("Issuing POST for $operation");
    $options                      = $this->getCommonCurlOptions(TRUE);
    $options[CURLOPT_URL]         = $url;
    $options[CURLOPT_POST]        = 1;
    $options[CURLOPT_SAFE_UPLOAD] = TRUE;
    $options[CURLOPT_POSTFIELDS]  = $postText;
    $options[CURLOPT_HTTPHEADER]  = $headers;

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue POST
    // ----------
    // Issue an HTTP POST to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Decode JSON content.
    if (empty($content) === FALSE) {
      $content = json_decode($content, TRUE);
    }

    // Check for error codes. The following are standard HTTP success codes:
    // - 200 = OK.
    // - 201 = Created.
    // - 202 = Accepted.
    // - 203 = Non-authoritative information.
    // - 204 = No content.
    // - 205 = Reset content.
    // - 206 = Partial content.
    // - 207 = Multi-status.
    // - 208 = Already reported.
    // - 226 = IM used.
    //
    // A POST always creates content, so all successful requests return 201.
    //
    // All other standard success codes are unacceptable.
    switch ($httpCode) {
      case 201:
        $this->printVerbose("  Request complete");
        if (empty($content) === TRUE) {
          return NULL;
        }
        return $content;

      default:
        if (isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }
  }

  /**
   * Updates content on the web server.
   *
   * PATCH requests are used to update a single entity based upon the
   * operation choice:
   * - 'update-entity' changes one or more entity fields.
   * - 'update-sharing' changes an entity's sharing settings.
   * - 'move-overwrite' moves/renames an entity, optionally overwriting.
   * - 'move-no-overwrite' moves/renames an entity without overwriting.
   *
   * PATCH requests require a full folder path or a numeric entity ID
   * to indicate the entity to delete.
   *
   * @param string $operation
   *   The name of the requested operation.
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file or folder.
   * @param string $destinationPath
   *   (optional, default = "") The string path for the remote destination
   *   on move and copy operations. Other operations do not use this value.
   * @param array $postFields
   *   (optional, default = []) The post fields in 'full' entity format
   *   for operations that need additional values.
   *
   * @return mixed
   *   Returns a variety of content types depending upon the operation.
   *   In most cases, the content returned is an array.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The arguments are malformed.
   *   - The arguments are invalid, such as for non-existent items.
   */
  protected function httpPatch(
    string $operation,
    string $entityIdOrPath,
    string $destinationPath,
    array $postFields = []) {

    //
    // Initialize
    // ----------
    // Check if HTTP POST is supported. This may issue a connection to the
    // server to find out. If that fails, an exception is thrown.
    if ($this->isHttpPostSupported() === FALSE) {
      throw new \RuntimeException(
        "The operation cannot be completed.\nThe host does not support requests to create or upload new content.");
    }

    //
    // Create headers
    // --------------
    // The headers specify the POST operation and the source path, if any.
    $headers = [];

    // Set the operation.
    $headers[] = 'X-FolderShare-Patch-Operation: ' . $operation;

    // Get the CSRF token. This may trigger a GET request to the server.
    // If that fails, an exception is thrown.
    $headers[] = 'X-CSRF-Token: ' . $this->getCSRFToken();

    // Set the source path if an entity ID is not used.
    if (is_numeric($entityIdOrPath) === TRUE) {
      $entityId = intval($entityIdOrPath);
    }
    else {
      // Make sure the path is safe. HTTP headers primarily support ASCII,
      // while paths may include multi-byte characters. Use URL encoding
      // to add them to the header.
      $entityId = 0;
      $headers[] = 'X-FolderShare-Source-Path: ' . rawurlencode($entityIdOrPath);
    }

    // Set the destination path, if any.
    if (empty($destinationPath) === FALSE) {
      $headers[] = 'X-FolderShare-Destination-Path: ' . rawurlencode($destinationPath);
    }

    // POST fields are serialized as JSON.
    $headers[] = 'Content-Type: application/json';

    // Request the returned data in a specific format.
    $headers[] = 'X-FolderShare-Return-Format: ' . $this->format;

    // Process post fields, if provided.
    if (empty($postFields) === TRUE) {
      $postFields = $this->createDummyEntity();
    }

    $postText = json_encode($postFields);

    //
    // Create URL
    // ----------
    // The PATCH URL includes:
    // - The host name.
    // - The canonical web services path.
    // - The entity ID (even if one is not used).
    // - The return syntax.
    $url = $this->hostName . self::URL_PATCH . '/' . $entityId . '?_format=json';

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    //
    // Authentication settings should be included for most operations.
    //
    // A CSRF token is required.
    $this->printVerbose("Issuing PATCH for $operation");
    $options                        = $this->getCommonCurlOptions(TRUE);
    $options[CURLOPT_URL]           = $url;
    $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
    $options[CURLOPT_SAFE_UPLOAD]   = TRUE;
    $options[CURLOPT_POSTFIELDS]    = $postText;
    $options[CURLOPT_HTTPHEADER]    = $headers;

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue PATCH
    // -----------
    // Issue an HTTP PATCH to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Decode JSON content.
    if (empty($content) === FALSE) {
      $content = json_decode($content, TRUE);
    }

    // Check for error codes. The following are standard HTTP success codes:
    // - 200 = OK.
    // - 201 = Created.
    // - 202 = Accepted.
    // - 203 = Non-authoritative information.
    // - 204 = No content.
    // - 205 = Reset content.
    // - 206 = Partial content.
    // - 207 = Multi-status.
    // - 208 = Already reported.
    // - 226 = IM used.
    //
    // A PATCH may modify or create content, so 200 and 201 are acceptable.
    //
    // All other standard success codes are unacceptable.
    switch ($httpCode) {
      case 200:
        $this->printVerbose("  Request complete");
        return $content;

      case 201:
        $this->printVerbose("  Request complete");
        if (empty($content) === TRUE) {
          return NULL;
        }
        return $content;

      default:
        if (isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }
  }

  /*--------------------------------------------------------------------
   *
   * GET requests.
   *
   * These methods issue low-level HTTP GET requests and return
   * appropriate structured content.
   *
   *--------------------------------------------------------------------*/

  /**
   * Gets information about a file or folder.
   *
   * An array is returned that describes the item.
   *
   * The format of the returned content depends upon the currently selected
   * return format. The content includes mandatory fields for each item,
   * including its name, creation and modified dates, MIME type, kind,
   * entity ID, and parent and root folder IDs. The returned content may
   * include additional fields if they are not empty, including the item's
   * description, comments, etc.
   *
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file or folder.
   *
   * @return array
   *   Returns an array of information describing the file or folder.
   *   The format of the information depends upon the current return format.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The path is malformed.
   *   - The item does not exist.
   *   - The user does not have necessary permissions.
   *
   * @see ::download()
   */
  public function getFileOrFolder($entityIdOrPath) {
    //
    // Validate
    // --------
    // If the path is a string, it cannot be empty. Further checking of
    // the path, or of integer entity IDs, must be done on the server.
    if (is_string($entityIdOrPath) === TRUE &&
        empty($entityIdOrPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    return $this->httpGet("get-entity", $entityIdOrPath);
  }

  /**
   * Gets information about a file or folder's parent folder.
   *
   * An array is returned that describes the item's parent folder.
   *
   * The format of the returned content depends upon the currently selected
   * return format. The content includes mandatory fields for each item,
   * including its name, creation and modified dates, MIME type, kind,
   * entity ID, and parent and root folder IDs. The returned content may
   * include additional fields if they are not empty, including the item's
   * description, comments, etc.
   *
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file or folder
   *   for whome parent folder information is returned.
   *
   * @return array
   *   Returns an array of information describing the file or folder's
   *   parent folder. The format of the information depends upon the
   *   current return format.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The path is malformed.
   *   - The item does not exist.
   *   - The user does not have necessary permissions.
   *
   * @see ::download()
   * @see ::getRootFolder()
   */
  public function getParentFolder($entityIdOrPath) {
    //
    // Validate
    // --------
    // If the path is a string, it cannot be empty. Further checking of
    // the path, or of integer entity IDs, must be done on the server.
    if (is_string($entityIdOrPath) === TRUE &&
        empty($entityIdOrPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    return $this->httpGet("get-parent", $entityIdOrPath);
  }

  /**
   * Gets information about a file or folder's top-level (root) folder.
   *
   * An array is returned that describes the item's top-level (root) folder.
   *
   * The format of the returned content depends upon the currently selected
   * return format. The content includes mandatory fields for each item,
   * including its name, creation and modified dates, MIME type, kind,
   * entity ID, and parent and root folder IDs. The returned content may
   * include additional fields if they are not empty, including the item's
   * description, comments, etc.
   *
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file or folder
   *   for whome root folder information is returned.
   *
   * @return array
   *   Returns an array of information describing the file or folder's
   *   root folder. The format of the information depends upon the
   *   current return format.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The path is malformed.
   *   - The item does not exist.
   *   - The user does not have necessary permissions.
   *
   * @see ::download()
   * @see ::getParentFolder()
   * @see ::getRootFolders()
   */
  public function getRootFolder($entityIdOrPath) {
    //
    // Validate
    // --------
    // If the path is a string, it cannot be empty. Further checking of
    // the path, or of integer entity IDs, must be done on the server.
    if (is_string($entityIdOrPath) === TRUE &&
        empty($entityIdOrPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    return $this->httpGet("get-root", $entityIdOrPath);
  }

  /**
   * Gets a list of ancestor folders for a file or folder.
   *
   * An array is returned that contains a list of ancestors, ordered from
   * the top-level folder down to (but not including) the indicated item.
   *
   * The format of the returned content depends upon the currently selected
   * return format. The content includes mandatory fields for each item,
   * including its name, creation and modified dates, MIME type, kind,
   * entity ID, and parent and root folder IDs. The returned content may
   * include additional fields if they are not empty, including the item's
   * description, comments, etc.
   *
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file or folder
   *   for whome ancestor information is returned.
   *
   * @return array
   *   Returns an array of information describing a list of the file or
   *   folder's ancestors. The format of the information depends upon
   *   the current return format.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The path is malformed.
   *   - The item does not exist.
   *   - The user does not have necessary permissions.
   *
   * @see ::download()
   */
  public function getAncestors($entityIdOrPath) {
    //
    // Validate
    // --------
    // If the path is a string, it cannot be empty. Further checking of
    // the path, or of integer entity IDs, must be done on the server.
    if (is_string($entityIdOrPath) === TRUE &&
        empty($entityIdOrPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    return $this->httpGet("get-ancestors", $entityIdOrPath);
  }

  /**
   * Gets a list of descendant (child) files and folders for a folder.
   *
   * An array is returned that contains a list of descendants, if any.
   * If the item is a file, there are no descendants.
   *
   * The format of the returned content depends upon the currently selected
   * return format. The content includes mandatory fields for each item,
   * including its name, creation and modified dates, MIME type, kind,
   * entity ID, and parent and root folder IDs. The returned content may
   * include additional fields if they are not empty, including the item's
   * description, comments, etc.
   *
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file or folder
   *   for whome descendant (child) folder information is returned.
   *
   * @return array
   *   Returns an array of information describing a list of the item's
   *   descendants. The format of the information depends upon the current
   *   return format.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The path is malformed.
   *   - The item does not exist.
   *   - The user does not have necessary permissions.
   *
   * @see ::download()
   */
  public function getDescendants($entityIdOrPath) {
    //
    // Validate
    // --------
    // If the path is a string, it cannot be empty. Further checking of
    // the path, or of integer entity IDs, must be done on the server.
    if (is_string($entityIdOrPath) === TRUE &&
        empty($entityIdOrPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    return $this->httpGet("get-descendants", $entityIdOrPath);
  }

  /**
   * Gets a list of top-level folders.
   *
   * An array is returned that contains a list of top-level folders in
   * the selected "scheme". Recognized schemes include:
   *   - 'private' returns the user's own top-level folders.
   *   - 'public' returns all publically accessible top-level folders.
   *   - 'shared' returns top-level folders shared explicitly with the user.
   *
   * The format of the returned content depends upon the currently selected
   * return format. The content includes mandatory fields for each item,
   * including its name, creation and modified dates, MIME type, kind,
   * entity ID, and parent and root folder IDs. The returned content may
   * include additional fields if they are not empty, including the item's
   * description, comments, etc.
   *
   * @param string $scheme
   *   (optional, default = 'private') The name of a well-known group of
   *   top-level folders.
   *
   * @return array
   *   Returns an array of information describing a list of root folders.
   *   The format of the information depends upon the current return format.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the scheme is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The user does not have necessary permissions.
   *   - The root folder scheme is malformed.
   *   - The user's root folder list is locked by another process.
   *
   * @see ::download()
   * @see ::getRootFolder()
   */
  public function getRootFolders(string $scheme = 'private') {
    //
    // Validate
    // --------
    // The scheme cannot be empty. Further scheme checking is left to
    // the server.
    if (empty($scheme) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote top-level folder scheme.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    return $this->httpGet("get-descendants", $scheme . ':/');
  }

  /**
   * Get a report on the user's FolderShare usage.
   *
   * An associative array is returned where keys and values indicate the
   * current user's level of usage of the FolderShare virtual file system
   * on the server. The following keys are expected:
   * - 'nRootFolders' = the number of root folders.
   * - 'nFolders' = the number of subfolders.
   * - 'nFiles' = the number of files.
   * - 'nBytes' = the amount of storage space used, in bytes.
   *
   * The returned array also includes the following keys:
   * - 'host' = the host name.
   * - 'user-id' = the numeric user ID of the authenticated user.
   * - 'user-account-name' = the text account name of the authenticated user.
   * - 'user-display-name' = the text display name of the authenticated user.
   *
   * There may be additional values, depending upon the site and the
   * software installed at the site.
   *
   * @return array
   *   Returns a key-value array describing the user's usage of the module.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The user does not have necessary permissions.
   */
  public function getUsage() {
    //
    // Execute
    // -------
    // Issue the request.
    return $this->httpGet("get-usage", 0);
  }

  /**
   * Get a report on the server's software configuraiton.
   *
   * An associative array is returned where keys and values are for
   * server software parameters. The following keys are expected:
   *
   * - 'file-restrict-extensions' = TRUE or FALSE indicating if file name
   *   extension restrictions are enabled.
   *
   * - 'file-allowed-extensions' = a space-separated list of file name
   *   extensions checked, if extension restrictions are enabled.
   *
   * - 'file-maximum-upload-size' = the maximum size, in bytes, for each
   *   uploaded file.
   *
   * - 'file-maximum-upload-number' = the maximum number of files that can
   *   be uploaded in a single request.
   *
   * - 'sharing-allowed' = TRUE or FALSE indicating if folder sharing
   *   is enabled on the site.
   *
   * - 'sharing-allowed-with-anonymous' = TRUE or FALSE indicating if folder
   *   sharing with the "anonymous" user (the unauthenticated public) is
   *   enabled on the site.
   *
   * - 'serializer-formats' = a list of serialization formats configured
   *   for the method.
   *
   * - 'authentication-providers' = a list of authentication providers
   *   configured for the method.
   *
   * The returned array also includes the following keys:
   * - 'host' = the host name.
   *
   * There may be additional values, depending upon the site and the
   * software installed at the site.
   *
   * @return array
   *   Returns a key-value array of values describing the site and module
   *   configuration.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The user does not have necessary permissions.
   *
   * @see ::getHttpVerbs()
   * @see ::getVersion()
   */
  public function getConfiguration() {
    //
    // Execute
    // -------
    // Issue the request.
    return $this->httpGet("get-configuration", 0);
  }

  /**
   * Get a report on software version numbers.
   *
   * An associative array is returned where keys are software items and
   * values are associative arrays that each have two keys and values:
   * - 'name' = the human-readable name of the item.
   * - 'version' = the version number of the item.
   *
   * If $doConnect is FALSE, the returned array only describes the client
   * side software. Otherwise a connection to the server is made and the
   * returned array describes both the client side and server side software.
   *
   * When a connection is made, the returned array also includes the
   * following keys:
   * - 'host' = the host name.
   *
   * @param bool $doConnect
   *   (optional, default = TRUE) When TRUE, the returned array includes
   *   client and server information. When FALSE, the array only includes
   *   client information and no communications with the server is done.
   *
   * @return array
   *   Returns a key-value array of software items and their names and
   *   version numbers.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The user does not have necessary permissions.
   *
   * @see ::getConfiguration()
   */
  public function getVersion(bool $doConnect = TRUE) {
    //
    // Create API versions
    // -------------------
    // For non-connecting requests, the API's information is all that is
    // returned. For connecting requests, the API's information is prepended
    // to whatever the server returns.
    $curlVersion = curl_version();

    $clientVersions = [
      'client' => [
        'foldershareconnect' => [
          'name'    => 'FolderShareConnect client API',
          'version' => self::VERSION,
        ],
        'clientphp' => [
          'name'    => 'PHP',
          'version' => phpversion(),
        ],
        'curl'      => [
          'name'    => 'cURL library',
          'version' => $curlVersion['version'],
        ],
        'ssl'       => [
          'name'    => 'OpenSSL library',
          'version' => $curlVersion['ssl_version'],
        ],
        'libz'       => [
          'name'    => 'Z compression library',
          'version' => $curlVersion['libz_version'],
        ],
      ],
    ];

    //
    // Execute
    // -------
    // Issue the request.
    if ($doConnect === FALSE) {
      return $clientVersions;
    }

    return array_merge($clientVersions, $this->httpGet("get-version", 0));
  }

  /*--------------------------------------------------------------------
   *
   * DELETE requests.
   *
   *--------------------------------------------------------------------*/

  /**
   * Deletes a file.
   *
   * This request will delete any type of file, including data files,
   * image files, or media items.
   *
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The user does not have necessary permissions.
   *   - The item does not exist.
   *   - The item is not a file.
   *   - The item is locked by another process.
   *
   * @see ::deleteFileOrFolder()
   * @see ::deleteFolder()
   */
  public function deleteFile($entityIdOrPath) {
    //
    // Validate
    // --------
    // If the path is a string, it cannot be empty. Further checking of
    // the path, or of integer entity IDs, must be done on the server.
    if (is_string($entityIdOrPath) === TRUE &&
        empty($entityIdOrPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    $this->httpDelete("delete-file", $entityIdOrPath);
  }

  /**
   * Deletes a file or folder, optionally recursively.
   *
   * If the item indicated by the integer ID or path is a folder, and
   * $recurse is FALSE, the folder must be empty. If $recurse is TRUE,
   * non-empty folders will have their content deleted first, recursing
   * as necessary through subfolders.
   *
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote file or folder.
   * @param bool $recurse
   *   (optional, default = FALSE) When TRUE, non-empty folders are deleted
   *   recursively, including any subfolders and their content. When FALSE,
   *   the operation only deletes files or empty folders.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The user does not have necessary permissions.
   *   - The item does not exist.
   *   - The item is locked by another process.
   *
   * @see ::deleteFile()
   * @see ::deleteFolder()
   */
  public function deleteFileOrFolder($entityIdOrPath, bool $recurse = FALSE) {
    //
    // Validate
    // --------
    // If the path is a string, it cannot be empty. Further checking of
    // the path, or of integer entity IDs, must be done on the server.
    if (is_string($entityIdOrPath) === TRUE &&
        empty($entityIdOrPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    if ($recurse === TRUE) {
      $this->httpDelete("delete-file-or-folder-tree", $entityIdOrPath);
    }
    else {
      $this->httpDelete("delete-file-or-folder", $entityIdOrPath);
    }
  }

  /**
   * Deletes a folder, optionally recursively.
   *
   * The item indicated by an integer ID or a path must be a folder.
   * If $recurse is FALSE, the folder must be empty. If $recurse is TRUE,
   * non-empty folders will have their content deleted first, recursing
   * as necessary through subfolders.
   *
   * @param int|string $entityIdOrPath
   *   The numeric entity ID or string path for a remote folder.
   * @param bool $recurse
   *   (optional, default = FALSE) When TRUE, non-empty folders are deleted
   *   recursively, including any subfolders and their content. When FALSE,
   *   the operation only deletes empty folders.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path is an empty string.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The user does not have necessary permissions.
   *   - The item does not exist.
   *   - The item is not a folder.
   *   - The item is locked by another process.
   *
   * @see ::deleteFile()
   * @see ::deleteFileOrFolder()
   */
  public function deleteFolder($entityIdOrPath, bool $recurse = FALSE) {
    //
    // Validate
    // --------
    // If the path is a string, it cannot be empty. Further checking of
    // the path, or of integer entity IDs, must be done on the server.
    if (is_string($entityIdOrPath) === TRUE &&
        empty($entityIdOrPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    if ($recurse === TRUE) {
      $this->httpDelete("delete-folder-tree", $entityIdOrPath);
    }
    else {
      $this->httpDelete("delete-folder", $entityIdOrPath);
    }
  }

  /*--------------------------------------------------------------------
   *
   * POST requests.
   *
   *--------------------------------------------------------------------*/

  /**
   * Creates a new top-level folder.
   *
   * A new top-level folder is created with the selected name in the
   * user's top-level folder list. An exception is thrown if the name
   * is already in use.
   *
   * @param string $name
   *   The name of the new top-level folder.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the name is empty.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The user does not have necessary permissions.
   *   - The top level folder list is locked by another process.
   *   - The name is malformed.
   *   - The new name would cause a collision.
   *
   * @see ::newFolder()
   */
  public function newRootFolder(string $name) {
    //
    // Validate
    // --------
    // The name cannot be empty. Checking that the name is well formed
    // must be done on the server.
    if (empty($name) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty new remote folder name.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    $this->httpPost(
      "new-rootfolder",
      "/",
      [
        'name' => [
          0 => [
            'value' => $name,
          ],
        ],
      ]);
  }

  /**
   * Creates a new folder in a parent folder.
   *
   * A new folder with the selected name is created in the parent folder.
   * An exception is thrown if the name is already in use.
   *
   * @param string $path
   *   The string path for a remote parent folder.
   * @param string $name
   *   The name of the new folder.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path or name are empty.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The path is malformed.
   *   - The parent folder does not exist.
   *   - The user does not have necessary permissions.
   *   - The parent folder is locked by another process.
   *   - The name is malformed.
   *   - The new name would cause a collision.
   *
   * @see ::newRootFolder()
   */
  public function newFolder(string $path, string $name) {
    //
    // Validate
    // --------
    // The path and name cannot be empty. Checking that the path and name
    // are well formed, and that the path refers to an existing folder must be
    // done on the server.
    if (empty($path) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote parent folder path.");
    }

    if (empty($name) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty new remote folder name.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    $this->httpPost(
      "new-folder",
      $path,
      [
        'name' => [
          0 => [
            'value' => $name,
          ],
        ],
      ]);
  }

  /*--------------------------------------------------------------------
   *
   * PATCH requests.
   *
   *--------------------------------------------------------------------*/

  /**
   * Renames a file or folder.
   *
   * The file or folder is named without moving it. An exception is
   * thrown if the new name is already in use.
   *
   * @param string $path
   *   The string path for a remote file or folder.
   * @param string $name
   *   The new name for the item.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path or name are empty.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The path is malformed.
   *   - The item to rename does not exist.
   *   - The user does not have necessary permissions.
   *   - The item is locked by another process.
   *   - The name is malformed.
   *   - The rename would cause a collision.
   *
   * @see ::move()
   */
  public function rename(string $path, string $name) {
    //
    // Validate
    // --------
    // The path and name cannot be empty. Checking that the path and name
    // are well formed, and that the path refers to an existing item must be
    // done on the server.
    if (empty($path) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    if (empty($name) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty new remote file or folder name.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    $this->httpPatch(
      "update-entity",
      $path,
      '',
      [
        'name' => [
          0 => [
            'value' => $name,
          ],
        ],
      ]);
  }

  /**
   * Updates a file or folder field.
   *
   * The value for the selected field in the file or folder is updated
   * to the new value. An exception is thrown if the field is not known
   * or the value is not valid.
   *
   * Only simple fields may be updated using this method.
   *
   * @param string $path
   *   The string path for a remote file or folder.
   * @param string $fieldName
   *   The name of a simple field in the file or folder.
   * @param string $fieldValue
   *   The new value for the field.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if the path or field name are empty.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - The path is malformed.
   *   - The item to rename does not exist.
   *   - The user does not have necessary permissions.
   *   - The item is locked by another process.
   *   - The name is malformed.
   *   - The rename would cause a collision.
   *
   * @see ::rename()
   */
  public function update(string $path, string $fieldName, string $fieldValue) {
    //
    // Validate
    // --------
    // The path and field name cannot be empty. Checking that the path,
    // field name, and value are well formed, and that the path refers to an
    // existing item must be done on the server.
    if (empty($path) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    if (empty($fieldName) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty field name.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    $this->httpPatch(
      "update-entity",
      $path,
      '',
      [
        $fieldName => [
          0 => [
            'value' => $fieldValue,
          ],
        ],
      ]);
  }

  /**
   * Moves a file or folder to a new location.
   *
   * This method is modeled after the way the Linux/macOS/BSD "mv" command
   * operates. It supports moving an item, renaming an item in place, or
   * moving and renaming at the same time.
   *
   * The $fromPath must refer to an existing file or folder to be moved
   * and/or renamed.
   *
   * The $toPath may be one of:
   * - A "/" to refer to the top-level folder list.
   * - A path to an existing file or folder.
   * - A path to a non-existant item within an existing parent folder.
   *
   * If $toPath is "/", $fromPath must refer to a folder since files cannot be
   * moved into "/". The moved folder will have the same name as in $fromPath.
   * If there is already an item with the same name in "/", the move will
   * fail unless $overwrite is TRUE.
   *
   * If $toPath refers to a non-existant item, then the item referred to
   * by $fromPath will be moved into the $toPath's parent folder and
   * renamed to use the last name on $toPath. If $toPath's parent folder
   * is "/", then $fromPath must refer to a folder since files cannot be
   * moved into "/".
   *
   * If $toPath refers to an existing folder, the file or folder referred
   * to by $fromPath will be moved into the $toPath folder and retain its
   * current name. If there is already an item with that name in the
   * $toPath folder, the move will fail unless $overwrite is TRUE.
   *
   * If $toPath refers to an existing file, the move will fail unless
   * $overwrite is TRUE. If overwrite is allowed, the item referred to by
   * $fromPath will be moved into the $toPath item's parent folder and
   * renamed to have th last name in $toPath. If $toPath's parent folder
   * is "/", then $fromPath must refer to a folder since files cannot be
   * moved into "/".
   *
   * In any case where an overwrite is required, if $overwrite is FALSE
   * the operation will fail. Otherwise the item to overwrite will be
   * deleted before the move and/or rename takes place.
   *
   * The user must have permission to modify the item referred to by $fromPath.
   * They user must have permission to modify the folder into which the
   * item will be placed. When moving a folder to "/", the user must have
   * permission to create a new top-level folder. And when overwriting an
   * existing item, the user must have permission to delete that item.
   *
   * @param string $fromPath
   *   The string path for a remote file or folder to move.
   * @param string $toPath
   *   The string path to the remote parent folder to receive the moved item.
   *   Use "/" to move a folder to the root folder list.
   * @param bool $overwrite
   *   (optional, default = FALSE) When TRUE, a move of an item into a folder
   *   that already has an item of the same name will overwrite that item.
   *   When FALSE, an error is generated instead.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - Either path is malformed.
   *   - The item to move/rename does not exist.
   *   - The destination folder does not exist.
   *   - The item is the wrong type for the destination.
   *   - The user does not have necessary permissions.
   *   - One of the item's involved is locked by another process.
   *   - The move/rename would cause a collision, but $overwrite is FALSE.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if either path is empty.
   *
   * @see ::rename()
   */
  public function move(string $fromPath, string $toPath, bool $overwrite = TRUE) {
    //
    // Validate
    // --------
    // Neither path can be empty. Checking that the paths are well formed
    // and refer to appropriate entities must be done on the server.
    if (empty($fromPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    if (empty($toPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote destination folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    if ($overwrite === TRUE) {
      $this->httpPatch("move-overwrite", $fromPath, $toPath);
    }
    else {
      $this->httpPatch("move-no-overwrite", $fromPath, $toPath);
    }
  }

  /**
   * Copies a file or folder to a new location.
   *
   * This method is modeled after the way the Linux/macOS/BSD "cp" command
   * operates. It supports copying one or more items to a new destination.
   *
   * The $fromPath must refer to an existing file or folder to be copied
   * and optionally renamed.
   *
   * The $toPath may be one of:
   * - A "/" to refer to the top-level folder list.
   * - A path to an existing file or folder.
   * - A path to a non-existant item within an existing parent folder.
   *
   * If $toPath is "/", $fromPath must refer to a folder since files cannot be
   * copied into "/". The copied folder will have the same name as in $fromPath.
   * If there is already an item with the same name in "/", the copy will
   * fail unless $overwrite is TRUE.
   *
   * If $toPath refers to a non-existant item, then the item referred to
   * by $fromPath will be copied into the $toPath's parent folder and
   * renamed to use the last name on $toPath. If $toPath's parent folder
   * is "/", then $fromPath must refer to a folder since files cannot be
   * copied into "/".
   *
   * If $toPath refers to an existing folder, the file or folder referred
   * to by $fromPath will be copied into the $toPath folder and retain its
   * current name. If there is already an item with that name in the
   * $toPath folder, the copy will fail unless $overwrite is TRUE.
   *
   * If $toPath refers to an existing file, the copy will fail unless
   * $overwrite is TRUE. If overwrite is allowed, the item referred to by
   * $fromPath will be copied into the $toPath item's parent folder and
   * renamed to have th last name in $toPath. If $toPath's parent folder
   * is "/", then $fromPath must refer to a folder since files cannot be
   * copied into "/".
   *
   * In any case where an overwrite is required, if $overwrite is FALSE
   * the operation will fail. Otherwise the item to overwrite will be
   * deleted before the copy takes place.
   *
   * The user must have permission to view the item referred to by $fromPath.
   * They user must have permission to modify the folder into which the
   * item will be placed, and permission to create the new item there.
   * When copying a folder to "/", the user must have permission to create
   * a new top-level folder. And when overwriting an existing item, the
   * user must have permission to delete that item.
   *
   * @param string $fromPath
   *   The string path for a remote file or folder.
   * @param string $toPath
   *   The string path to the remote parent folder to receive the copied item.
   *   Use "/" to copy a folder to the root folder list.
   * @param bool $overwrite
   *   (optional, default = FALSE) When TRUE, a copy of an item into a folder
   *   that already has an item of the same name will overwrite that item.
   *   When FALSE, an error is generated instead.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - Either path is malformed.
   *   - The item to copy does not exist.
   *   - The destination folder does not exist.
   *   - The item is the wrong type for the destination.
   *   - The user does not have necessary permissions.
   *   - One of the item's involved is locked by another process.
   *   - The copy would cause a collision, but $overwrite is FALSE.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception if either path is empty.
   *
   * @see ::move()
   */
  public function copy(string $fromPath, string $toPath, bool $overwrite = TRUE) {
    //
    // Validate
    // --------
    // Neither path can be empty. Checking that the paths are well formed
    // and refer to appropriate entities must be done on the server.
    if (empty($fromPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    if (empty($toPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote destination folder path.");
    }

    //
    // Execute
    // -------
    // Issue the request.
    if ($overwrite === TRUE) {
      $this->httpPatch("copy-overwrite", $fromPath, $toPath);
    }
    else {
      $this->httpPatch("copy-no-overwrite", $fromPath, $toPath);
    }
  }

  /**
   * Downloads a remote file or folder to a local file or folder.
   *
   * @param string $remotePath
   *   The string path for a remote file or folder.
   * @param string $localPath
   *   The string path for a local file or folder.
   * @param bool $overwrite
   *   (optional, default = FALSE) When TRUE and $localPath refers to an
   *   existing file, the file will be deleted before the download is done.
   *   When FALSE, an existing local file will cause an exception.
   *
   * @return string
   *   Returns the absolute path to the newly downloaded file. If $localPath
   *   is a folder, then the downloaded file path will start with that path,
   *   followed by the file name as returned by the server.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - Either path is malformed.
   *   - The item to download does not exist.
   *   - The item to download is not a file or folder.
   *   - The user does not have necessary permissions.
   *   - One of the item's involved is locked by another process.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception on a client error, including the following
   *   conditions:
   *   - The remote path is empty.
   *   - The local path is empty.
   *   - The local path does not indicate a folder that exists.
   *   - The local path does not indicate a folder that can be written to.
   *   - The local path indicates a file that already exists.
   *
   * @see ::move()
   */
  public function download(
    string $remotePath,
    string $localPath,
    bool $overwrite = FALSE) {
    //
    // Validate
    // --------
    // Neither path can be empty. Checking that the remote path is well formed
    // and refers to an appropriate entity must be done on the server.
    if (empty($remotePath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty remote file or folder path.");
    }

    if (empty($localPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Empty local file path.");
    }

    //
    // Create a temporary local file
    // -----------------------------
    // The downloaded data will be placed into a new local file that must
    // be created now. The open file handle is then passed to CURL.
    //
    // If the local path is for a directory, then the local file name will
    // be a temporary name. At the end of the file download the temporary
    // name will be changed to the name returned by the server.
    $cleanLocalPath = realpath($localPath);
    if (is_dir($cleanLocalPath) === TRUE) {
      // Create a temporary file path in the directory.
      $tmpLocalPath = tempnam($cleanLocalPath, 'foldershare');
      $renameFile = TRUE;
    }
    elseif (is_file($cleanLocalPath) === TRUE) {
      // The file already exists.
      if ($overwrite === FALSE) {
        throw new \InvalidArgumentException(
          "A local file with that name already exists.");
      }

      if (@unlink($cleanLocalPath) === FALSE) {
        throw new \InvalidArgumentException(
          "A local file with that name already exists and cannot be overwritten.");
      }

      $tmpLocalPath = $cleanLocalPath;
      $renameFile = FALSE;
    }
    else {
      // The path appears to be a name for a new file that doesn't exist yet.
      $tmpLocalPath = $cleanLocalPath;
      $renameFile = FALSE;
    }

    // Create and open the file so that CURL can save attached data into it.
    $tmpLocalFile = @fopen($tmpLocalPath, 'wb');
    if ($tmpLocalFile === FALSE) {
      throw new \InvalidArgumentException(
        "Cannot create a local file to receive the remote file contents.");
    }

    //
    // Create headers
    // --------------
    // The headers specify the GET operation and the source path, if any.
    $headers = [];

    // Set the operation.
    $headers[] = 'X-FolderShare-Get-Operation: download';

    // Make sure the path is safe. HTTP headers primarily support ASCII,
    // while paths may include multi-byte characters. Use URL encoding
    // to add them to the header.
    $headers[] = 'X-FolderShare-Source-Path: ' . rawurlencode($remotePath);

    //
    // Create URL
    // ----------
    // The GET URL includes:
    // - The host name.
    // - The canonical web services path.
    // - The entity ID (even if one is not used).
    // - The return syntax.
    $url = $this->hostName . self::URL_GET . '/0?_format=json';

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    //
    // Authentication settings should be included.
    //
    // A CSRF token is not required.
    $this->printVerbose("Issuing GET for download");
    $options                         = $this->getCommonCurlOptions(TRUE);
    $options[CURLOPT_URL]            = $url;
    $options[CURLOPT_HTTPGET]        = TRUE;
    $options[CURLOPT_FILE]           = $tmpLocalFile;
    $options[CURLOPT_HTTPHEADER]     = $headers;
    $options[CURLOPT_HEADERFUNCTION] = [$this, 'downloadHeaderCallback'];

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue GET
    // ---------
    // Issue an HTTP GET to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $this->downloadFilename = '';

    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    // Close the temp file. It may or may not have received anything useful.
    fclose($tmpLocalFile);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      @unlink($tmpLocalPath);
      $message = $this->getNiceHttpErrorMessage($httpCode);
      $this->printVerbose("  Server failed with HTTP code $httpCode");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Check for error codes. The following are standard HTTP success codes:
    // - 200 = OK.
    // - 201 = Created.
    // - 202 = Accepted.
    // - 203 = Non-authoritative information.
    // - 204 = No content.
    // - 205 = Reset content.
    // - 206 = Partial content.
    // - 207 = Multi-status.
    // - 208 = Already reported.
    // - 226 = IM used.
    //
    // A 200 is returned when there is a file.
    //
    // All other standard success codes are unacceptable.
    switch ($httpCode) {
      case 200:
        break;

      default:
        // On an error, the error message has been written to the temp file.
        // Read it in, delete the file, and parse the message (if any).
        $content = file_get_contents($tmpLocalPath);
        @unlink($tmpLocalPath);

        if ($content !== FALSE) {
          $content = json_decode($content, TRUE);
        }

        if (empty($content) === FALSE && isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }

    // Everything worked. Rename the temp file using the file name from
    // the HTTP header.
    //
    // Using the HTTP header's name is necessary if the download is for
    // a folder. The server automatically ZIPs that folder into a file
    // and sends the file. The name of that ZIP file is what we want for
    // the name of the final file, not the name of the directory.
    if ($renameFile === TRUE) {
      $finalPath = $cleanLocalPath . '/' . $this->downloadFilename;
      rename($tmpLocalPath, $finalPath);
    }
    else {
      $finalPath = $cleanLocalPath;
    }

    $this->printVerbose("  Request complete");
    return $cleanLocalPath;
  }

  /**
   * Responds to an HTTP header receipt during a file download.
   *
   * This method is called by CURL while downloading a file. Each call
   * passes a line from the incoming HTTP header. The line is parsed
   * to look for the "Content-Disposition", which includes the name
   * of the file being downloaded. This name is then saved for later use.
   *
   * @param resource $curlSession
   *   The resource for the current CURL session.
   * @param string $line
   *   The HTTP header line to parse.
   *
   * @return int
   *   Returns the number of bytes in the incoming HTTP header line.
   */
  private function downloadHeaderCallback($curlSession, $line) {
    // Get the length (in bytes) of the incoming line. This must be
    // returned when the function is done.
    $lineBytes = strlen($line);

    // Split the line to get the header key and value.
    $parts = mb_split(':', $line, 2);
    if (count($parts) !== 2) {
      return $lineBytes;
    }

    // We only want the content disposition header line.
    if ($parts[0] !== 'Content-Disposition') {
      return $lineBytes;
    }

    // Extract the file name from the line, if there is one.
    $found = [];
    if (mb_ereg('filename="(.*)"', $parts[1], $found) === FALSE) {
      return $lineBytes;
    }

    // Save the file name to use at the end of the download.
    $this->downloadFilename = $found[1];
    return $lineBytes;
  }

  /**
   * Uploads a local file to a remote file.
   *
   * The local path must refer to a file, not a directory. Directory uploading
   * is not yet supported.
   *
   * @param string $localPath
   *   The string path for a local file or folder.
   * @param string $remotePath
   *   The string path for a remote file or folder.
   *
   * @throws \RuntimeException
   *   Throws an exception on a communications or server error, including
   *   the following conditions:
   *   - The host cannot be contacted, such a with a bad host name.
   *   - The host refuses contact, such as with bad authentication credentials.
   *   - The host does not support the operation.
   *   - Either path is malformed.
   *   - The item to upload does not exist.
   *   - The user does not have necessary permissions to create a file.
   *   - One of the item's involved is locked by another process.
   *
   * @throws \InvalidArgumentException
   *   Throws an exception on a client error, including the following
   *   conditions:
   *   - The remote path is empty.
   *   - The local path is empty.
   *   - The local path does not indicate a file that exists.
   *   - The local path does not indicate a regular file.
   *   - The local path does not indicate a file that can be read.
   */
  public function upload(string $localPath, string $remotePath) {
    //
    // Validate
    // --------
    // Neither path can be empty. Checking that the remote path is well formed
    // and refers to an appropriate entity must be done on the server.
    if (empty($remotePath) === TRUE) {
      throw new \InvalidArgumentException(
        "Programmer error: Empty remote file or folder path for upload.");
    }

    if (empty($localPath) === TRUE) {
      throw new \InvalidArgumentException(
        "Programmer error: Empty local file path for upload.");
    }

    if (file_exists($localPath) === FALSE) {
      throw new \InvalidArgumentException(
        "The local file does not exist.\nPlease check your local file path for typos.");
    }

    if (is_file($localPath) === FALSE) {
      if (is_dir($localPath) === TRUE) {
        throw new \InvalidArgumentException(
          "Uploading directories is not yet supported.\nPlease upload files individually.");
      }

      if (is_link($localPath) === TRUE) {
        throw new \InvalidArgumentException(
          "Uploading symbolilc links is not supported.\nOnly files can be uploaded.");
      }

      throw new \InvalidArgumentException(
        "Uploading devices, sockets, and other special file types is not supported.\nOnly files can be uploaded.");
    }

    if (is_readable($localPath) === FALSE) {
      throw new \InvalidArgumentException(
        "The local file cannot be read.\nPlease check that you have permission to read the file.");
    }

    //
    // Confirm cookie authentication
    // -----------------------------
    // As of Drupal 8.5 (and earlier), the REST module's base classes do
    // not support access to the incoming request and attached uploaded files.
    // This prevents us from using a normal REST upload path.
    //
    // Instead, we must POST to a form outside of the REST configuration.
    // This form requires cookie authentication and a custom POST process
    // below.
    if ($this->authenticationType !== self::AUTHENTICATION_COOKIE) {
      throw new \RuntimeException(
        "File upload is not supported.
The current connection with the host is not using cookie-based authentication,
which is required for file uploading. Please contact the host administrator
to enable cookie-based authentication.");
    }

    //
    // Create URL
    // ----------
    // The GET/POST URL includes:
    // - The host name.
    // - The file upload path.
    $url = $this->hostName . self::URL_UPLOAD;

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    $this->printVerbose("Issuing GET for file upload form");
    $options                  = $this->getCommonCurlOptions(TRUE);
    $options[CURLOPT_URL]     = $url;
    $options[CURLOPT_HTTPGET] = TRUE;

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue GET
    // ----------
    // Issue an HTTP GET to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    switch ($httpCode) {
      case 200:
      case 201:
        break;

      default:
        if (isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }

    //
    // Find form token
    // ---------------
    // The GET above gets a web page upload form that contains hidden
    // fields added by Drupal for:
    // - form_build_id.
    // - form_token.
    // - form_id.
    //
    // The form_token is used as a validation mechanism by Drupal when
    // doing a form POST. We need that ID to add to the POST fields below.
    $matches = [];
    preg_match('/form_build_id" *value="([^"]*)"/', $content, $matches);
    $formBuildId = $matches[1];

    preg_match('/form_token" *value="([^"]*)"/', $content, $matches);
    $formToken = $matches[1];

    preg_match('/form_id" *value="([^"]*)"/', $content, $matches);
    $formId = $matches[1];

    //
    // Create POST fields
    // ------------------
    // The POST fields contain:
    // - The form token from above.
    // - The file to upload.
    // - The path of the upload destination.
    $postFields = [
      'form_build_id' => $formBuildId,
      'form_id'       => $formId,
      'form_token'    => $formToken,
      'files[file]'   => new \CURLFile($localPath),
      'path'          => $remotePath,
      'op'            => 'Upload',
    ];

    //
    // Set up request
    // --------------
    // Get common CURL options and update them with specifics of this request.
    $this->printVerbose("Issuing POST for file upload");
    $options                      = $this->getCommonCurlOptions(TRUE);
    $options[CURLOPT_URL]         = $url;
    $options[CURLOPT_POST]        = 1;
    $options[CURLOPT_SAFE_UPLOAD] = TRUE;
    $options[CURLOPT_POSTFIELDS]  = $postFields;

    curl_reset($this->curlSession);
    curl_setopt_array($this->curlSession, $options);

    //
    // Issue POST
    // ----------
    // Issue an HTTP POST to the web server. There are two types of errors:
    // - Communications errors reported by CURL as error numbers.
    // - Web site errors reported as bad HTTP codes.
    //
    // The returned content varies depending upon the operation.
    $content  = curl_exec($this->curlSession);
    $httpCode = curl_getinfo($this->curlSession, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($this->curlSession);

    if ($errno !== 0) {
      // Communications error. Something went wrong with Curl or with
      // its communications with the server.
      $message = $this->getNiceCurlErrorMessage($errno);
      $this->printVerbose("  CURL failed with error code $errno");
      $this->printVerbose("  $message");
      throw new \RuntimeException($message);
    }

    // Decode JSON content.
    if (empty($content) === FALSE) {
      $content = json_decode($content, TRUE);
    }

    // Check for error codes. The following are standard HTTP success codes:
    // - 200 = OK.
    // - 201 = Created.
    // - 202 = Accepted.
    // - 203 = Non-authoritative information.
    // - 204 = No content.
    // - 205 = Reset content.
    // - 206 = Partial content.
    // - 207 = Multi-status.
    // - 208 = Already reported.
    // - 226 = IM used.
    //
    // A POST always creates content, so all successful requests return 201.
    //
    // All other standard success codes are unacceptable.
    switch ($httpCode) {
      case 200:
      case 201:
        $this->printVerbose("  Request complete");
        if (empty($content) === TRUE) {
          return NULL;
        }
        return $content;

      default:
        if (isset($content['message']) === TRUE) {
          $message = $content['message'];
        }
        else {
          $message = $this->getNiceHttpErrorMessage($httpCode);
        }

        $this->printVerbose("  Server failed with HTTP code $httpCode");
        $this->printVerbose("  $message");
        throw new \RuntimeException($message);
    }
  }

}
