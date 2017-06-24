<?php
namespace ierusalim\gitAPIwork;

class github_repo_walk {
    public $default_git_user;
    public $default_git_repo;
    public $default_git_branch;
    public $local_repo_path;
    
    public $fn_file_put_contents = false;
    public $fn_mkdir = false;
    public $fn_conflict = false;

    public $hookFileLocalNotFound = false;
    public $hookFileIsEqual = false;
    public $hookFileIsDiff = false;
    public $hookNoLocalPath = false;
    public $hookHaveLocalPath = false;
    
    public $WalkHookNames = [
        'hookFileSave',
        'hookFileLocalNotFound',
        'hookFileIsEqual',
        'hookFileIsDiff',
        'hookNoLocalPath',
        'hookHaveLocalPath'
    ];
    
    public $cnt_conflicts = 0;
    public $cnt_not_found = 0;
    public $cnt_found_obj = 0;
    
    public function __construct(
        $local_repo_path,
        $git_user,
        $git_repo,
        $git_branch='master'
    ) {
        $this->set_local_path($local_repo_path);
        $this->default_git_user = $git_user;
        $this->default_git_repo = $git_repo;
        $this->default_git_branch = $git_branch;
        foreach( $this->WalkHookNames as $hookName ) {
            $this->{$hookName} = __CLASS__ . '::fn_hook_default';
        }
    }
    public function write_enable() {
        //set callable functions for mkdir and file_put_contents
        $this->fn_mkdir = __CLASS__ . '::check_dir_mkdir';
        $this->fn_file_put_contents = 'file_put_contents';
   }
    public function set_local_path($local_repo_path) {
        $this->local_repo_path = 
             dirname($local_repo_path . DIRECTORY_SEPARATOR .'a')
            . DIRECTORY_SEPARATOR;        
    }
    public function git_user_repo_pair(
        $git_user = NULL,
        $git_repo = NULL
    ) {
       if(is_null($git_user)) {
            $git_user = $this->default_git_user;
        }
        if(is_null($git_repo)) {
            $git_repo = $this->default_git_repo;
        }
        return $git_user . '/' . $git_repo;
    }
    public function git_repo_list_url(
        $git_user = NULL,
        $git_repo = NULL,
        $git_branch = NULL
    ) {
        if(is_null($git_branch)) {
            $git_branch = $this->default_git_branch;
        }
        return
             'https://api.github.com/repos/'
            . $this->git_user_repo_pair($git_user, $git_repo)
            . '/git/trees/'
            . $git_branch
            . '?recursive=1'
        ;
    }
    public function git_req_repo_list(
        $git_user = NULL,
        $git_repo = NULL,
        $git_branch = NULL     
    ) {
        $raw_json = $this->https_get_contents(
            $this->git_repo_list_url($git_user, $git_repo,$git_branch)
        );
        if(!$raw_json) return false;
        return json_decode($raw_json);
    }
    
    public function git_req_branches_list($git_user = NULL, $git_repo = NULL) {
        $raw_json = $this->https_get_contents(
            'https://api.github.com/repos/'
            . $this->git_user_repo_pair($git_user, $git_repo)
            . '/git/refs/heads/'
        );
        if(!$raw_json) return false;
        return json_decode($raw_json);    
    }

    function git_local_file_compare(
        $fullPathFileName, 
        $ExpectedGitSize,
        $ExpectedGitHash
    ) {
        $fileContent = @file_get_contents($fullPathFileName);
        if($fileContent === false) return false;
        if(strlen($fileContent) != $ExpectedGitSize) return false;
        $localGitHash = sha1(
             'blob' 
            . ' '
            . strlen($fileContent)
            . "\0"
            . $fileContent
        );
        return ($localGitHash == $ExpectedGitHash);
    }

    public function git_repo_compare_walk(
        $git_user = NULL,
        $git_repo = NULL,
        $branch = NULL,
        $local_path = NULL
    ) {
        //get repository list from GitHub into object
        $git_repo_obj = $this->git_req_repo_list($git_user, $git_repo, $branch);
        if(!$git_repo_obj) return false;

        //check ->tree object
        if(!isset($git_repo_obj->tree)) {
            //throw exception if ->tree not found
            if(isset($git_repo_obj->message)) { //add message if got
                throw new \Exception("ERROR: ".$git_repo_obj->message);
            } else {
                throw new \Exception("Bad response");
            }
        }

        //local path prepare - must have DS in end
        if(is_null($localPath)) {
            $localPath = $this->local_repo_path;
        } elseif(substr($localPath,-1) !== DIRECTORY_SEPARATOR) {
            $localPath = dirname($localPath.DIRECTORY_SEPARATOR .'a')
                . DIRECTORY_SEPARATOR;
        }        

        //set hooks from $this->vars to local vars (for use by "compact")
        $fn_file_put_contents = $this->fn_file_put_contents;
        $fn_mkdir = $this->fn_mkdir;
        $fn_conflict = $this->fn_conflict;
        
        //reset statistic. (This function return statistic as array)
        $this->cnt_conflicts = 0;
        $this->cnt_not_found = 0;
        $this->cnt_found_obj = 0;

        //walk all repo-objects (files and dirs)
        foreach($git_repo_obj->tree as $git_fo) {
            $gitPath = $git_fo->path;
            //convert / to local DS
            $localFile = implode(DIRECTORY_SEPARATOR,explode('/',$gitPath));
            $fullPathFileName = $localPath . $localFile;
            $gitType = $git_fo->type;
            if($gitType == 'blob') { // type "blob" is file
                $gitSize = $git_fo->size;
                $gitHash = $git_fo->sha;
                if(is_file($fullPathFileName)) {
                    if( // ->mode: 100644 - file, 100755 — exe, 120000 — ln.
                        ($git_fo->mode < 110000) && //for skip links
                        //compare file by parameters size and hash
                        $this->git_local_file_compare(
                            $fullPathFileName, $gitSize, $gitHash
                        )
                    ) {
                        $hookName = 'hookFileIsEqual';
                        $this->cnt_found_obj++;
                    } else {
                        $hookName = 'hookFileIsDiff';
                        $this->cnt_conflicts++;
                    }
                } else {
                    $hookName = 'hookFileLocalNotFound';
                    $this->cnt_not_found++;
                }
            } elseif($gitType == 'tree') { //type tree is subdir
                if(is_dir($fullPathFileName)) {
                    $hookName = 'hookHaveLocalPath';
                    $this->cnt_found_obj++;
                } else {
                    $hookName = 'hookNoLocalPath';
                    $this->cnt_not_found++;
                }
            } else {
                throw new Exception("Unknown git-type received: $gitType",999);
            }
            if($this->{$hookName}) {
                call_user_func(
                    $this->{$hookName},
                    compact(
                        'hookName',
                        'fullPathFileName',
                        'localPath',
                        'git_fo',
                        'fn_file_put_contents',
                        'fn_mkdir',
                        'fn_conflict'
                    )
                );
            }
        }
        return [
          'cnt_found_obj'=>$this->cnt_found_obj,
          'cnt_not_found'=>$this->cnt_not_found,
          'cnt_conflicts'=>$this->cnt_conflicts,
        ];
    }

    public function https_get_contents($url,$ua = 'curl/7.26.0') {
         $ch = curl_init();
         curl_setopt($ch, CURLOPT_URL, $url);
         curl_setopt($ch, CURLOPT_USERAGENT, $ua);
         curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
         $data = curl_exec($ch);
         curl_close($ch);
         return $data;
    }

    public function url_get_contents(
        $url,
        $http_opt_arr = ['user_agent' => 'curl/7.26.0']
    ) {
        return \file_get_contents($url, false, \stream_context_create([
            'http' => $http_opt_arr
        ]));
    }
    
    public function git_file_download(
        $srcURL
    ) {
       $api_content = $this->https_get_contents($srcURL);
       if(!$api_content) return false;
       $api_content = json_decode($api_content);
       if(!$api_content->content) return false;
       return base64_decode($api_content->content);
    }
    
    public function fn_hook_default($par_arr) {
        extract($par_arr);
        //$hookName,
        //$fullPathFileName,
        //$localPath,
        //$git_fo
        //$fn_file_put_contents
        //$fn_mkdir
        switch($hookName) {
        case 'hookFileIsDiff':
            if(is_callable($fn_conflict)) {
                call_user_func($fn_conflict,$par_arr);
            }
             //echo $hookName . " $fullPathFileName\n";
            break;
        case 'hookFileLocalNotFound':
            $pathForFile = dirname($fullPathFileName);
            $have_dir = is_dir($pathForFile);
            if (!$have_dir) {
                if(is_callable($fn_mkdir)) {
                    call_user_func($fn_mkdir,$pathForFile);
                    $have_dir = is_dir($pathForFile);
                }
            }
            if($have_dir) {
                if(is_callable($fn_file_put_contents)) {
                    $fileContent = $this->git_file_download($git_fo->url);
                    //save to file
                    call_user_func(
                        $fn_file_put_contents,
                        $fullPathFileName,
                        $fileContent
                    );
                }
            }
            break;
        case 'hookNoLocalPath':
            if(is_callable($fn_mkdir)) {
                //$this->check_dir_mkdir($fullPathFileName);
                call_user_func($fn_mkdir,$fullPathFileName);
            }
            break;
        }
   }

   public function check_dir_mkdir($fullPath, $srcDS = DIRECTORY_SEPARATOR) {
       //Checking path existence and create if not found
       $path_arr=explode($srcDS , $fullPath);
       if(is_dir(implode(DIRECTORY_SEPARATOR,$path_arr))) return true;
       foreach($path_arr as $k=>$sub) {
           if(!$k) {
               $abspath = $sub;
           } else {
               $abspath .= DIRECTORY_SEPARATOR . $sub;
               if(!is_dir($abspath)) {
                   if(!mkdir($abspath)) return false;
               }
           }
       }
       return is_dir($fullPath);
   }
}
