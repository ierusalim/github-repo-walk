<?php

/**
 * This file contains a GitHub Repository Walker Class
 * 
 * PHP Version 5.6
 * 
 * @package    ierusalim\GitRepoWalk
 * @author     Alexander Jer <alex@ierusalim.com>
 * @copyright  2017, Ierusalim
 * @license    http://lunr.nl/LICENSE MIT License
 */

namespace ierusalim\GitRepoWalk;

class GitRepoWalk {
    /**
     * Default git-user name, used when git-user not specified
     * 
     * @var string|null
     */
    public $defaultGitUser;
    
    /**
     * Default git-repository name, used when git-repo parameter not specified
     * 
     * @var string|null
     */
    public $defaultGitRepo;
    
    /**
     * Default git-branch name
     * if set, it will be used instead send api-request
     * when default_branch is unknown
     * 
     * @var string|null
     */
    public $defaultGitBranch;

    /**
     * Can set own branch for every user/repo pair (pair is a key for array)
     * keys must be lowercase
     * 
     * @var array
     */
    public $repoCurrentBranch=[];
    
    /**
     * @var string|null
     */
    public $defaultLocalPath;
    
    /**
     * Can set own local-path for every user/repo pair (pair is a key for array)
     * keys must be lowercase
     * 
     * @var array
     */
    public $localRepoPathArr=[];

    /**
     * Cache for api-answer about repositories 
     * key=user/repo lowercase
     * 
     * @var array
     */
    public $cachedRepositoryInfo=[];
    
    /**
     * Cache for api-answer about repository file-list
     * 
     * @var object|null
     */
    public $cachedObjsInRepoList;
    
    /**
     * Cache for RepositoriesInfo [keys=git-user lowercase]
     * 
     * @var array
     */
    public $userRepositoriesArr=[];

    /**
     * true (default) - use raw.githubusercontent.com
     * false - use api.github for download files (not recomended)
     * api.github have rate-limit 60req/hour not recomended for download files
     * 
     * @var bool
     */
    public $rawDownloadMode = true;
    
    /**
     * This function was called for every path in repository-files-list
     * if this function return true, path not processed (skip file)
     * 
     * @var callable|bool
     */
    public $fnGitPathFilter = false;
    
    /**
     * Function for write content into file
     * 
     * @var callable|bool
     */
    public $fnFilePutContents = false;
    
    /**
     * Function for make directory for download files from repo
     * 
     * @var callable|bool
     */
    public $fnMkDir = false;

    /**
     * This function call before repo walking
     * 
     * @var callable|bool
     */
    public $fnWalkPrepare = false;

    /**
     * This function call after repo walking complete
     * 
     * @var callable|bool
     */
    public $fnWalkFinal = false;
    
    /**
     * The function was called when the local file and
     * the file in the repository are different
     * 
     * @var callable|bool
     */
    public $fnConflict = false;

    /**
     * This hook was called when file have in repo and local not found
     * 
     * @var callable|bool 
     */
    public $hookFileLocalNotFound = false;

    /**
     * This hook was called when file in repo is equal with local file
     * 
     * @var callable|bool 
     */
    public $hookFileIsEqual = false;

    /**
     * This hook is the same as $fnConflict
     * @see GitRepoWalk::fnHookDefault
     * 
     * @var callable|bool 
     */
    public $hookFileIsDiff = false;

    /**
     * This hook was called when subdir have in repo but local not found
     * 
     * @var callable|bool 
     */
    public $hookNoLocalPath = false;

    /**
     * This hook was called when subdir from repo present in local path
     * 
     * @var callable|bool 
     */
    public $hookHaveLocalPath = false;
    
    /**
     * list of hooks for 
     * @see GitRepoWalk::fnHookDefault
     * 
     * @var array
     */
    public $walkHookNames = [
        'hookFileSave',
        'hookFileLocalNotFound',
        'hookFileIsEqual',
        'hookFileIsDiff',
        'hookNoLocalPath',
        'hookHaveLocalPath'
    ];
    
    /**
     * list of properties that interesting to get from each repository-info
     * 
     * @var array
     */
    public $interestingRepoPars = [
        'id',
        'name',
        'description',
        'fork',
        'forks_count',
        'watchers',
        'homepage',
        'created_at',
        'updated_at',
        'pushed_at',
        'size',
        'has_wiki',
        'has_downloads',
        'has_pages',
        'has_issues',
        'open_issues_count',
        'language',
        'default_branch'
    ];
    
    /**
     * Counter of files that are have locally and in a remote repository
     * but content of files are different
     * 
     * @var integer
     */
    public $cntConflicts = 0;
    
    /**
     * Counter of git-path that have in git-repository but have not local
     * 
     * @var integer
     */
    public $cntNotFound = 0;
    
    /**
     * Counter of git-path that have in local and remote repositories
     * 
     * @var integer
     */
    public $cntFoundObj = 0;
    
    /**
     * Constructior.
     * 
     * @param string|null $local_path
     * @param string|null $git_user_and_repo
     * @param string|null $default_git_branch
     */
    public function __construct(
        $local_path = NULL, //local path for work with repository (set as default)
        $git_user_and_repo = NULL, //user/repo, for example: ierusalim/git-repo-walk
        $default_git_branch=NULL //set as default_branch and for current user/repo
    ) {
        //Initialize hooks to read_only mode
        $this->writeDisable();

        //set default user and repo and extract to vars $git_user and $git_repo
        extract($this->setDefaultUserAndRepo($git_user_and_repo));
        
        //if local_path defined, set it as default local path
        if(!empty($local_path)) {
            //set this local path as default
            $this->setDefaultLocalPath($local_path);
            //if defined git-user and git-repo set this local path for user/repo
            if(!is_null($git_user) && !is_null($git_repo)) {
                $this->setLocalPathForRepo($git_user_and_repo, $local_path);
            }
        }
        
        if(!empty($git_branch)) {
            //set git_branch as default branch
            $this->setDefaultBranch($git_branch);
            //if user/repo defined, set this branch for this user/repo
            if(!is_null($git_user) && !is_null($git_repo)) {
                $this->setCurrentBranchForRepo($git_user_and_repo, $git_branch);
            }
        }
    }
    private function userRepoPairDivide(
        $git_user_and_repo, // 'user/repo' in string
        $require_mask = 0 // 1-user required, 2-repo required, 3 user and repo.
    ) {
        //divide $git_user_and_repo by any divider-char
        //Example: for 'user/repo' return ['git_user'=>'user', 'git_repo'=>'repo']
        $i=strcspn($git_user_and_repo, '/\\ ,:;|*#');
        if($i !== false) {
            $git_user = substr($git_user_and_repo, 0, $i);
            $git_repo = substr($git_user_and_repo, $i+1);
        } else {
            $git_user = $git_user_and_repo;
        }
        if(empty($git_user)) {
            $git_user = NULL;
        }
        if(empty($git_repo)) {
            $git_repo = NULL;
        }
        //check requires and get default values if possible
        $this->userRepoCheckMask($git_user, $git_repo, $require_mask);
        return compact('git_user','git_repo');
    }
    private function userRepoPairBind(
        $git_user = NULL,
        $git_repo = NULL
    ) {
        //Function reverse for user_repo_pair_divide, return user/repo string pair
        $this->userRepoCheckMask($git_user, $git_repo, 3); //user and repo required
        return $git_user . '/' . $git_repo;
    }
    
    private function userRepoCheckMask(&$git_user, &$git_repo, $require_mask=3) {
        //check git_user and git_repo by require_mask and modify if need
        //if required value is NULL try to get default value
        //if required value is NULL and default value is NULL throw exception
        //$require_mask = 1-user required, 2-repo required, 3-user and repo req.
        if(($require_mask & 1) && is_null($git_user)) { //if user required
            $git_user = $this->defaultGitUser;
            if(is_null($git_user)) {
                throw new \Exception("git-user undefined", 700);
            }
        }
        if(($require_mask & 2) && is_null($git_repo)) { //if repo required
            $git_repo = $this->defaultGitRepo;
            if(is_null($git_repo)) {
                throw new \Exception("git-repository undefined", 701);
            }
        }
    }
    public function setDefaultUserAndRepo($git_user_and_repo = NULL)
    {
        //divide $git_user_and_repo to $git_user and $git_repo
        extract($this->userRepoPairDivide($git_user_and_repo), 0);
        $this->defaultGitUser = empty($git_user) ? NULL : $git_user;
        $this->defaultGitRepo = empty($git_repo) ? NULL : $git_repo;
        return compact('git_user', 'git_repo');
    }
    public function setDefaultBranch($git_branch = NULL) {
        $this->defaultGitBranch = $git_branch;
    }
    public function setCurrentBranchForRepo($git_user_and_repo, $git_branch = NULL) {
        extract($this->userRepoPairDivide($git_user_and_repo), 3);
        $pair_low = \strtolower($git_user . '/' .$git_repo);
        $this->repoCurrentBranch[$pair_low] = $git_branch;
    }
    public function setDefaultLocalPath($local_path = NULL) {
        $this->defaultLocalPath = empty($local_path) ?
            NULL : $this->pathDs($local_path);
    }
    public function setLocalPathForRepo($git_user_and_repo, $local_path) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));
        $pair_low = \strtolower($git_user . '/' .$git_repo);
        $this->localRepoPathArr[$pair_low] = $this->pathDs($local_path);
    }

    public function writeEnable() {
        $this->writeDisable(); //for clear/initialize all hooks
        //set callable functions for mkdir and file_put_contents
        $this->fnMkDir = array($this, 'checkDirMkDir');
        $this->fnFilePutContents = 'file_put_contents';
    }
    public function writeEnableOverwrite() {
        $this->writeDisable(); //for clear/initialize all hooks
        $this->writeEnable();
        //when local file are different remove it and save from repo
        $this->fnConflict = function($par_arr) {
            unlink($par_arr['fullPathFileName']);//remove local version of file
            return true; //true = write file
        };
    }
    public function writeDisable() {
        foreach($this->walkHookNames as $hookName) {
            $this->{$hookName} = array($this, 'fnHookDefault');
        }
        $this->fnMkDir = false;
        $this->fnFilePutContents = false;
        $this->fnConflict = false;
        $this->fnWalkPrepare = false;
        $this->fnWalkFinal = false;
    }
    public function pathDs($localRepoPathArr) {
        // returned path with directory separator in end
        return \dirname($localRepoPathArr . \DIRECTORY_SEPARATOR . 'a')
            . \DIRECTORY_SEPARATOR;        
    }
    public function getDefaultBranchName($git_user_and_repo = NULL) {
        //get $git_user and $git_repo from user/repo pair or from default
        extract($this->userRepoPairDivide($git_user_and_repo, 3));
        
        $user_low = \strtolower($git_user);
        $repo_low = \strtolower($git_repo);
        $pair_low = $user_low . '/' . $repo_low;
        
        //looking for default_branch in userRepositoriesArr
        if(isset($this->userRepositoriesArr[$user_low])) {
            //if repositories list found, check current repository
            if(!isset($this->userRepositoriesArr[$user_low][$repo_low])) {
                throw new \Exception("Not found '$git_repo' in repositories list of git-user '$git_user'");
            }
            return $this->userRepositoriesArr[$user_low][$repo_low]['default_branch'];
        }
        
        //if userRepositoriesArr not found, try to use cachedRepositoryInfo
        if(!isset($this->cachedRepositoryInfo[$pair_low])) {
            //if defaultGitBranch is defined, return it, otherwise makre request
            if(!empty($this->defaultGitBranch)) {
                return $this->defaultGitBranch;
            }
            //try get default_branch from repo_info
            $this->getRepositoryInfo($git_user_and_repo);
        }
        return $this->cachedRepositoryInfo[$pair_low]->default_branch;
    }
    
    public function getRepositoryInfo($git_user_and_repo = NULL) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));
        $pair = $git_user . '/' . $git_repo;
        $pair_low = strtolower($pair);
        if(!isset($this->cachedRepositoryInfo[$pair_low])) {
            $raw_json = $this->httpsGetContents(
                'https://api.github.com/repos/' . $pair
            );
            if(!$raw_json) return false;

            $this->cachedRepositoryInfo[$pair_low] = $ret_obj = json_decode($raw_json);

            if(isset($ret_obj->message)) {
                throw new \Exception(
                "ERROR: can't get repository '$pair' " . $ret_obj->message, 404);
            }
        }
        return $this->cachedRepositoryInfo[$pair_low];
    }

    public function getUserRepositoriesList(
        $git_user_and_repo = NULL  // git-user of NULL for use $this->defaultGitUser
    ) {
        //Function retrieving list of GitHub repositories for specified user
        //and return array in simple internal format
        //Side effect: 
        //  store result array in $this->userRepositoriesArr[git-user]
        extract($this->userRepoPairDivide($git_user_and_repo, 1));
        
        $git_user_low = \strtolower($git_user);
        
        if( // looking for cached data
            !isset($this->userRepositoriesArr[$git_user_low])
        ) { // not found
            // retreive repositories list via api
            $srcURL = 'https://api.github.com/users/' . $git_user . '/repos';
            $raw_json = $this->httpsGetContents($srcURL);
            if(!$raw_json) {
                throw new \Exception("Data not received from $srcURL", 500);
            }
            //decode answer and store in cache
            $results_obj = json_decode($raw_json);
            //errors checking
            if(isset($results_obj->message)) {
                throw new \Exception(
                    "ERROR on git_user_repositories_list($git_user): "
                    . $results_obj->message, 501);
            }
            //get only interesting data
            $repo_arr=[];
            foreach($results_obj as $repo_obj) {
                $iter_obj=[];
                foreach($this->interestingRepoPars as $repoPar) {
                    $inter_obj[$repoPar] = $repo_obj->{$repoPar};
                }
                $repo_arr[\strtolower($repo_obj->name)] = $inter_obj;
            }
            //cache answer
            $this->userRepositoriesArr[$git_user_low] = $repo_arr;
        }
        return $this->userRepositoriesArr[$git_user_low];
    }
    public function getCurrentBranchName($git_user_and_repo) {
        //return branch name if it set by setCurrentBranchForRepo
        // or return default_branch name.
        extract($this->userRepoPairDivide($git_user_and_repo, 3));        
        $pair_low = \strtolower($git_user . '/' . $git_repo);
        if(empty($this->repoCurrentBranch[$pair_low])) {
            return $this->getDefaultBranchName($pair_low);
        } else {
            return $this->repoCurrentBranch[$pair_low];
        }
    }
    private function gitRepoFilesUrl(
        $git_user = NULL,
        $git_repo = NULL,
        $git_branch = NULL
    ) {
        $pair = $this->userRepoPairBind($git_user, $git_repo);
        if(is_null($git_branch)) {
            $git_branch = $this->getCurrentBranchName($pair);
        }
        return
             'https://api.github.com/repos/'
            . $pair
            . '/git/trees/'
            . $git_branch
            . '?recursive=1'
        ;
    }
    public function getRepoFilesList(
        $git_user_and_repo = NULL,
        $git_branch = NULL     
    ) {
        extract($this->userRepoPairDivide($git_user_and_repo));
        $srcURL = $this->gitRepoFilesUrl($git_user, $git_repo, $git_branch);
        if(
            !isset($this->cachedObjsInRepoList->from_url) ||
            $this->cachedObjsInRepoList->from_url != $srcURL
        ) {
            $raw_json = $this->httpsGetContents($srcURL);
            if(!$raw_json) return false;
            $this->cachedObjsInRepoList = json_decode($raw_json);
            $this->cachedObjsInRepoList->from_url = $srcURL;
            if(!isset($this->cachedObjsInRepoList->sha)) {
                 throw new \Exception(
                     "Not found '"
                    .$this->userRepoPairBind($git_user, $git_repo)
                    ."' branch='$git_branch'", 404);
            }
        }
        return $this->cachedObjsInRepoList;
    }
    
    public function getRepositoryContacts($git_user_and_repo = NULL) {
        $branches = $this->getBranchesList($git_user_and_repo);
        $contacts_arr=[];
        foreach($branches as $branch) {
            $obj = json_decode($this->httpsGetContents($branch->object->url));
            foreach(['author','committer'] as $key) {
                if(!isset($obj->{$key})) continue;
                $email = $obj->{$key}->email;
                $contact = [
                    'name'=>$obj->{$key}->name,
                    'role'=>$git_user_and_repo.'#'.$key
                ];
                if(isset($contacts_arr[$email])) {
                    if(in_array($contact, $contacts_arr[$email])) continue;
                    $contacts_arr[$email][] = $contact;
                } else {
                    $contacts_arr[$email] = [$contact];
                }
            }
        }
        return $contacts_arr;
    }
    public function getBranchesList($git_user_and_repo = NULL) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));
        $raw_json = $this->httpsGetContents(
            'https://api.github.com/repos/'
            . $this->userRepoPairBind($git_user, $git_repo)
            . '/git/refs/heads/'
        );
        if(!$raw_json) return false;
        $branches = json_decode($raw_json);
        if(isset($branches->message)) {
            throw new \Exception("ERROR:" . $branches->message, 404);
        }
        return $branches;    
    }
    
    function gitLocalFileCompare(
        $fullPathFileName, 
        $ExpectedGitSize,
        $ExpectedGitHash
    ) {
        $fileContent = @\file_get_contents($fullPathFileName);
        if($fileContent === false) return false;
        if(\strlen($fileContent) != $ExpectedGitSize) return false;
        $localGitHash = sha1(
             'blob' 
            . ' '
            . strlen($fileContent)
            . "\0"
            . $fileContent
        );
        return ($localGitHash == $ExpectedGitHash);
    }

    public function gitRepoWalk(
        $localPath = NULL,
        $git_user_and_repo = NULL,
        $git_branch = NULL
    ) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));

        if(is_null($git_branch)) {
            $git_branch = $this->getCurrentBranchName($git_user_and_repo);
        }
        //get repository list from GitHub into object
        $git_repo_obj = $this->getRepoFilesList(
            $this->userRepoPairBind($git_user, $git_repo),
            $git_branch
        );
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

        $pair_low= \strtolower($git_user . '/' . $git_repo);
                
        //local path prepare - must have DS in end
        if(is_null($localPath)) {
            $localPath = empty($this->localRepoPathArr[$pair_low]) ?
                $this->defaultLocalPath
                :
                $this->localRepoPathArr[$pair_low];
        }
        if(\substr($localPath,-1) !== \DIRECTORY_SEPARATOR) {
            $localPath = $this->pathDs($localPath);
        }        

        //set hooks from $this->vars to local vars (for use by "compact")
        $fnFilePutContents = $this->fnFilePutContents;
        $fnMkDir = $this->fnMkDir;
        $fnConflict = $this->fnConflict;
        
        //reset statistic. (This function return statistic as array)
        $this->cntConflicts = 0;
        $this->cntNotFound = 0;
        $this->cntFoundObj = 0;

        if($this->fnWalkPrepare) {
            if(\call_user_func(
                $this->fnWalkPrepare,
                \compact(
                    'git_repo_obj',
                    'localPath',
                    'git_user',
                    'git_repo',
                    'git_branch'
                )
            )) return $git_repo_obj;
        }
        //walk all repo-objects (files and dirs)
        foreach($git_repo_obj->tree as $git_fo) {
            $gitPath = $git_fo->path;
            if(
                is_callable($this->fnGitPathFilter) &&
                \call_user_func($this->fnGitPathFilter,$git_fo)
              ) continue;
            //convert / to local DS
            $localFile = \implode(\DIRECTORY_SEPARATOR, \explode('/',$gitPath));
            $fullPathFileName = $localPath . $localFile;
            $gitType = $git_fo->type;
            if($gitType == 'blob') { // type "blob" is file
                $gitSize = $git_fo->size;
                $gitHash = $git_fo->sha;
                if(\is_file($fullPathFileName)) {
                    if( // ->mode: 100644 - file, 100755 — exe, 120000 — ln.
                        ($git_fo->mode < 110000) && //for skip links
                        //compare file by parameters size and hash
                        $this->gitLocalFileCompare(
                            $fullPathFileName, $gitSize, $gitHash
                        )
                    ) {
                        $hookName = 'hookFileIsEqual';
                        $this->cntFoundObj++;
                    } else {
                        $hookName = 'hookFileIsDiff';
                        $this->cntConflicts++;
                    }
                } else {
                    $hookName = 'hookFileLocalNotFound';
                    $this->cntNotFound++;
                }
            } elseif($gitType == 'tree') { //type tree is subdir
                if(is_dir($fullPathFileName)) {
                    $hookName = 'hookHaveLocalPath';
                    $this->cntFoundObj++;
                } else {
                    $hookName = 'hookNoLocalPath';
                    $this->cntNotFound++;
                }
            } else {
                throw new \Exception("Unknown git-type received: $gitType",999);
            }
            if($this->{$hookName}) {
                \call_user_func(
                    $this->{$hookName},
                     \compact(
                        'hookName',
                        'fullPathFileName',
                        'localPath',
                        'git_fo',
                        'git_user',
                        'git_repo',
                        'git_branch',
                        'fnFilePutContents',
                        'fnMkDir',
                        'fnConflict'
                    )
                );
            }
        }
        $ret_arr = [
          'cntFoundObj'=>$this->cntFoundObj,
          'cntNotFound'=>$this->cntNotFound,
          'cntConflicts'=>$this->cntConflicts
        ];
        if($this->fnWalkFinal) {
            $ret_arr = \call_user_func(
                $this->fnWalkFinal,
                 \compact(
                    'git_repo_obj',
                    'localPath',
                    'git_user',
                    'git_repo',
                    'git_branch',
                    'ret_arr'
                )
            );
        }
        return $ret_arr;
    }

    public function httpsGetContents($url, $ua = 'curl/7.26.0') {
         $ch = \curl_init();
         \curl_setopt($ch, \CURLOPT_URL, $url);
         \curl_setopt($ch, \CURLOPT_USERAGENT, $ua);
         \curl_setopt($ch, \CURLOPT_SSL_VERIFYPEER, false);
         \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, 1);
         $data = \curl_exec($ch);
         \curl_close($ch);
         return $data;
    }

    public function urlGetContents(
        $url,
        $http_opt_arr = ['user_agent' => 'curl/7.26.0']
    ) {
        return \file_get_contents($url, false, \stream_context_create([
            'http' => $http_opt_arr
        ]));
    }
    public function gitRAWfileUrl(
        $git_fileName, 
        $git_user_and_repo = NULL,
        $git_branch = NULL
    ) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));
        if(is_null($git_branch)) {
            $git_branch = $this->getCurrentBranchName($git_user_and_repo);
        }
        return 'https://raw.githubusercontent.com/'
            . $this->userRepoPairBind($git_user, $git_repo) . '/'
            . $git_branch . '/'
            . $git_fileName;
    }
    public function gitRAWfileDownload(
        $git_fileName,
        $git_user_and_repo = NULL,
        $git_branch = NULL
    ) {
        $srcURL = $this->gitRAWfileUrl($git_fileName, $git_user_and_repo, $git_branch);
        return $this->httpsGetContents($srcURL);
    }
    public function gitAPIfileDownload($srcURL)
    {
        //ATTN: this function not recomended for download files,
        // because api.github.com have rate-limit 60req/hour.
        // Better use gitRAWfileDownload function
       $api_content = $this->httpsGetContents($srcURL);
       if(!$api_content) return false;
       $api_content = \json_decode($api_content);
       if(!$api_content->content) return false;
       return \base64_decode($api_content->content);
    }
    
    public function fnHookDefault($par_arr) {
        \extract($par_arr);
        //$hookName,
        //$fullPathFileName,
        //$localPath,
        //$git_fo
        //$git_user
        //$git_repo
        //$git_branch
        //$fnFilePutContents
        //$fnMkDir
        switch($hookName) {
        case 'hookFileIsDiff':
            if(!is_callable($fnConflict)) break;
            if(!\call_user_func($fnConflict, $par_arr)) break;
        case 'hookFileLocalNotFound':
            $pathForFile = \dirname($fullPathFileName);
            $have_dir = \is_dir($pathForFile);
            if (!$have_dir) {
                if(\is_callable($fnMkDir)) {
                    \call_user_func($fnMkDir,$pathForFile);
                    $have_dir = \is_dir($pathForFile);
                }
            }
            if($have_dir) {
                if(\is_callable($fnFilePutContents)) {
                    if($this->rawDownloadMode) {
                        $fileContent = $this->gitRAWfileDownload(
                            $git_fo->path,
                            $git_user . '/' . $git_repo,
                            $git_branch
                        );
                    } else {
                        $fileContent = $this->gitAPIfileDownload($git_fo->url);
                    }
                    //save to file
                    \call_user_func(
                        $fnFilePutContents,
                        $fullPathFileName,
                        $fileContent
                    );
                }
            }
            break;
        case 'hookNoLocalPath':
            if(is_callable($fnMkDir)) {
                \call_user_func($fnMkDir, $fullPathFileName);
            }
            break;
        }
   }

   public function checkDirMkDir($fullPath, $srcDS = DIRECTORY_SEPARATOR) {
       //Checking path existence and create if not found
       $path_arr = \explode($srcDS, $fullPath);
       if (\is_dir(\implode(\DIRECTORY_SEPARATOR, $path_arr))) {
            return true;
        }
        foreach($path_arr as $k=>$sub) {
           if(!$k) {
               $abspath = $sub;
           } else {
               $abspath .= DIRECTORY_SEPARATOR . $sub;
               if(!\is_dir($abspath)) {
                   if (!\mkdir($abspath)) {
                        return false;
                    }
                }
           }
       }
       return \is_dir($fullPath);
   }
}
