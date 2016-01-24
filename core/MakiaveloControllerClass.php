<?php


class HTTPRequest {
  public $params = array();
  public $request_method;
  public $uri;

  public function __construct($uri, $params, $request_method) {
    Makiavelo::info("Building new HTTPRequest:: " . print_r($params, true));
    foreach($params as $k => $v) {
      $this->params[$k] = $this->escapeString($v);
    }
    Makiavelo::info("Escaped params: " . print_r($this->params, true));
    $this->request_method = $request_method;
    $this->uri = $uri;
  }

  private function escapeString($txt) {
    if(is_array($txt)) {
      foreach($txt as $i => $item) {
        $txt[$i] = $this->escapeString($item);
      }
      return $txt;
    } else {
      return mysql_real_escape_string($txt);
    }
  }

  public function getParam($name) {
    if(isset($this->params[$name])) {
      return $this->params[$name];
    } else {
      return null;
    }
  }

  public function Method() {
    return $this->request_method;
  }

  static public function is_ajax_request() {
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == "xmlhttprequest") ;
  }
}


class MakiaveloController {
  protected $request;
  protected $flash;
  private $action;
  protected $statusCode;




  protected function redirect_to($path) {
    Makiavelo::info("Redirecting to: $path ....");
    header("Location: $path");
    exit;
  }

  public function handleRequest($get, $post, $named) {
    $this->statusCode = Makiavelo::RESPONSE_CODE_OK;

    Makiavelo::info("Handling uploading files...");
    foreach($_FILES as $field_name => $data) {
      foreach($data['tmp_name'] as $fname => $tmp_name) {
        Makiavelo::info("-- File (" . $field_name . ") : " . ROOT_PATH . Makiavelo::UPLOADED_FILES_FOLDER . "/" . $data['name'][$fname]);
        $res = move_uploaded_file($tmp_name, ROOT_PATH . Makiavelo::UPLOADED_FILES_FOLDER . "/" . $data['name'][$fname]);
        $post[$field_name][$fname . "_path"] =  str_replace("/public", "", Makiavelo::UPLOADED_FILES_FOLDER . "/" . $data['name'][$fname]);
      }
    }

    $this->request = new HTTPRequest($_SERVER['REQUEST_URI'], array_merge($get, $post, $named), $_SERVER['REQUEST_METHOD']);
    $this->flash = new Flash();

  }

  public function setAction($a) {
    $this->action = $a;
  }

  public function getViewPath($partial = "") {
    if($partial != "") {
      $fname = $partial;
    } else {
      $fname = $this->action;
    }
    return ROOT_PATH . "/app/views/" . str_replace("Controller", "", get_class($this)) . "/" . $fname. ".html.php";
  }

  public function renderView($partial = "", $opts = array()) {
    if(isset($opts['locals'])) {
      foreach($opts['locals'] as $key => $val) {
        $this->$key = $val;
      }
    }
    require_once($this->getViewPath($partial));
  }
  public function render($params = array(), $action = null) {
    if($action != null) {
      $this->action = $action;
    }
    $layout_name = ($this->layout != null) ? $this->layout : "";
    if($this->layout !== null) {
      $path_layout = ROOT_PATH . "/app/views/layout/" . $this->layout . ".html.php";
    } else {
      $path_layout = $this->getViewPath();
    }

    if($params == null) {
      $params = array();
    }
    foreach($params as $variable => $value) {
      $this->$variable = $value;
    }
    if($this->statusCode == Makiavelo::RESPONSE_CODE_NOT_FOUND) {
      header("HTTP/1.0 404 Not Found");
    }
    require_once($path_layout);
  }
}

?>
