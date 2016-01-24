<?php

class GithubAPI {

  private static $TOKEN = null;
  private static $LOGIN_URL = "https://github.com/login/oauth/authorize";


  public static function login_url() {
    return self::$LOGIN_URL."?client_id=" . Config::get("github.client_id") . "&redirect_uri=".Config::get("github.login_redirect"); 
  }

  private static function sendRequest($url, $method = "GET", $params = "", $http_creds = array(), $raw_response = false) {
    $ch = curl_init();
    if(self::$TOKEN != null) {
      $header = array();
      $header[] = 'Authorization: token '. self::$TOKEN;
      curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if($method != "GET") {
      switch($method) {
        case "POST":
          curl_setopt($ch, CURLOPT_POST, 1);
        break;
      }
    }

    if(count($http_creds) > 0) {
      curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
      curl_setopt($ch, CURLOPT_USERPWD, implode(":", $http_creds));
    }

    if($params != "" && $method == "POST") {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

    Makiavelo::info("Inside the sendRequest method url:: " . print_r($url, true));
    //Makiavelo::info("Inside the sendRequest method PArams:: " . print_r($params, true));
    $value = curl_exec($ch);
    //Makiavelo::info("Inside the sendRequest method:: " . print_r($value, true));
    if(!$value) {
      $error = curl_error($ch);
      Makiavelo::info("CURL ERROR :: " . $error);
      return json_decode('{"message": "'.$error.'"}');
    } else {
      if($raw_response) {
        return $value;
      } else {
        return json_decode($value);
      }
    }
  }

  public static function getUserRepos($user) {
    $page = 1;
    $projects = array();
    $dev = load_developer_where("name = '" . $user . "'");
    $full_repo_list = array();
    do {
      $url = "https://api.github.com/users/$user/repos?page=" . $page;
      Makiavelo::info("----------- URL : " . $url);
      $repo_list = self::sendRequest($url);
      //Makiavelo::puts("------------ number of repos found: " . count($repo_list));
      Makiavelo::info("=== Getting list of repos :: " . print_r($repo_list, true));
      foreach($repo_list as $repo) {
        $full_repo_list[] = $repo;
        $proj = load_project_where("owner_id = " . $dev->id . " and name ='".$repo->name."'");
        if($proj == null) {
          $proj = new Project();
          $proj->name = $repo->name;
          $proj->url  = ($repo->html_url) ? $repo->html_url : $repo->url;
          $proj->owner_id = $dev->id;
          $proj->description = $repo->description;
          $proj->stars = $repo->watchers;
          $proj->forks = $repo->forks;
          $proj->language = $repo->language;
          $proj->published = 0;
          $proj->open_issues = $repo->open_issues;
          $proj->close_issues = count(GithubAPI::getProjectIssues($user, $repo->name, "closed"));
          if(save_project($proj)) {
            $projects[] = $proj;
          }
        } else {
          $projects[] = $proj;
        }
      }
      $page++;
    } while (count($repo_list) > 0);
    GithubAPI::deleteOldProjects($dev, $full_repo_list);
    return $projects;
  }

  private static function deleteOldProjects($dev, $github_repos) {
    if(count($github_repos) == 0) return;
    Makiavelo::info("-- Checking for non-active repos");
     $saved_projects = list_project(null, null, 'owner_id = ' . $dev->id);

      foreach($saved_projects as $sproj) {
        $found = false;
        foreach($github_repos as $repo ) {
          if($sproj->name == $repo->name) {
            $found = true;
            break;
          }
        }
        if(!$found) {
          Makiavelo::info(" Deleting project: " . $sproj->name . " - " . $sproj->id );
         delete_project($sproj->id); 
        }
      }

  }

  public static function requestWebAuth($code) {
    $url = "https://github.com/login/oauth/access_token";
    $params = 'client_id='.Config::get("github.client_id").'&client_secret='.Config::get("github.secret").'&code='.$code;

    Makiavelo::info("Requesting WEB auth token to Github :: " . $params);
    $response = self::sendRequest($url, "POST", $params, array(Config::get("github.username"), Config::get("github.pwd")), true);
    $response = explode("&", $response);
    $response = $response[0];
    $response = explode("=", $response);
    $response_token = $response[1];
    Makiavelo::info("Response obtained :: " . print_r($response, true));
    self::$TOKEN = $response_token;
    return self::$TOKEN;
  }

  public static function getCurrentUser() {
    $url = "https://api.github.com/user";
    $usr_data = self::sendRequest($url);
    Makiavelo::info("=== Getting current user:: " . print_r($usr_data, true));
    return array("username" => $usr_data->login, "avatar_url" => $usr_data->avatar_url);
  }

  private static function requestAuth() {
    $url = "https://api.github.com/authorizations";
    $params = '{"client_id": "'.Config::get("github.client_id").'", "client_secret": "'.Config::get("github.secret").'"}';
    Makiavelo::info("Requesting auth token to Github :: " . $params);
    $response = self::sendRequest($url, "POST", $params, array(Config::get("github.username"), Config::get("github.pwd")));
    Makiavelo::info("Response obtained :: " . print_r($response, true));
    self::$TOKEN = $response->token;
    return $response->token;
  }

  public static function queryProjectData($usr, $repo) {

    if(self::$TOKEN == null) {
      self::$TOKEN = self::requestAuth();
    }
    $repo = str_replace(".git", "", $repo);
    $repo_url = "https://api.github.com/repos/".$usr."/".$repo;
    Makiavelo::info("Repo URL: " . $repo_url);
    $data = self::sendRequest($repo_url); 

    $commits_url = $repo_url . "/commits";
    $commits_data = self::sendRequest($commits_url);
    $data->commits = $commits_data;

    $pull_url = $repo_url . "/pulls";
    $pull_data = self::sendRequest($pull_url);

    $pull_url = $repo_url . "/pulls?state=closed";
    $closed_pulls = self::sendRequest($pull_url);
    if(is_array($closed_pulls)) {
      $pull_data = array_merge($closed_pulls, $pull_data);
    }
    $data->pulls = $pull_data;
    $open_issues_list = GithubAPI::getProjectIssues($usr, $repo, "open");
    $data->open_issues_list = $open_issues_list;
    $data->open_issues = count($data->open_issues_list);
    $data->closed_issues = count(GithubAPI::getProjectIssues($usr, $repo, "closed"));

    $data->readme = GithubAPI::getProjectREADME($repo_url);

    return $data;
  }

  public function getProjectREADME($url) {
    $url .= "/readme";
    $resp = self::sendRequest($url);
    Makiavelo::info("Getting readme, using URL:" . $url);
    Makiavelo::info("Content: " . $resp->content);
    return base64_decode($resp->content);
  }

  /**
  * Returns all the issues given an user, a repository and a status
  * 
  * @param (usr) the GitHub username
  * @param (repo) the GitHub repository name
  * @param (status) could be open or closed
  * @return an array with issues.
  */
  public static function getProjectIssues($usr, $repo, $status, $since = null) {

    if(self::$TOKEN == null) {
      self::$TOKEN = self::requestAuth();
    }

    $repo = str_replace(".git", "", $repo);
    $issues_url = "https://api.github.com/repos/".$usr."/".$repo."/issues?state=".$status."&page=1&per_page=100";
    if($since != null) {
      $issues_url = $issues_url . "&since=" . $since . "T00:00:00Z";
    }
    Makiavelo::info("Querying URL: " . $issues_url);

    $data = self::sendRequest($issues_url);
    return $data;
  }
}

?>
