<?php

namespace ierusalim\GitRepoWalk;

/**
 * This file contains a GitHub Repository Walker Class
 * 
 * PHP Version 5.6
 * 
 * @package    ierusalim\GitRepoWalk
 * @author     Alexander Jer <alex@ierusalim.com>
 * @copyright  2017, Ierusalim
 * @license    MIT
 */
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
     * @var string|null
     */
    public $cacheGetContentsPath;
    
    /**
     *
     * @var integer|null
     */
    public $cacheDefaultTimeLiveSec;
    
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
     * api.github.com rate limit (60 per hour)
     * @var integer
     */
    public $xRateLimit;
    
    /**
     * api.github.com report how many requests can send before x-RateLimit-Reset
     * @var integer
     */
    public $xRateRemaining;
    
    /**
     * api.github.com report unixtime when rate-limit counter will be resetting
     * @var integer
     */
    public $xRateReset;
    
    /**
     * api.github.com report max.pages in link-header
     * @var string
     */
    public $httpHeaderLink;
    
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
    
    /**
     * Divide user/repo string pair to $git_user, $git_repo vars
     * return array with 2 keys [git_user] and [git_repo]
     * 
     * @param string|null $git_user_and_repo
     * @param integer $require_mask
     * @return array ($git_user,$git_repo)
     */
    protected function userRepoPairDivide(
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
    
    /**
     * Bind and return user/repo pair from specified $git_user and $git_name
     * if any value is not specified, use default values
     * if default values are also not specified, throw exception.
     * 
     * Function reverse for user_repo_pair_divide
     * 
     * @param string|null $git_user
     * @param string|null $git_repo
     * @return string user/repo pair
     */
    protected function userRepoPairBind(
        $git_user = NULL,
        $git_repo = NULL
    ) {
        //
        $this->userRepoCheckMask($git_user, $git_repo, 3); //user and repo required
        return $git_user . '/' . $git_repo;
    }
    
    /**
     * Check git_user and git_repo by require_mask and modify if need
     * if required value is NULL try to get default value
     * if required value is NULL and default value is NULL throw exception
     * $require_mask = 1-user required, 2-repo required, 3-user and repo req.
     * 
     * @param string|null $git_user (may be changes by ref)
     * @param string|null $git_repo (may be changes by ref)
     * @param integer $require_mask
     * @throws \Exception
     */
    private function userRepoCheckMask(&$git_user, &$git_repo, $require_mask=3) {
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
    
    /**
     * Set default values for git_user and git_repo
     * 
     * @param string|null $git_user_and_repo
     * @return array ([git_user],[git_repo])
     */
    public function setDefaultUserAndRepo($git_user_and_repo = NULL)
    {
        //divide $git_user_and_repo to $git_user and $git_repo
        extract($this->userRepoPairDivide($git_user_and_repo), 0);
        $this->defaultGitUser = $git_user;
        $this->defaultGitRepo = $git_repo;
        return compact('git_user', 'git_repo');
    }
    
    /**
     * Set default value for git_branch
     * 
     * @param string|null $git_branch
     */
    public function setDefaultBranch($git_branch = NULL) {
        $this->defaultGitBranch = $git_branch;
    }
    
    /**
     * Set current branch name for specified repository
     * 
     * @param string $git_user_and_repo
     * @param string|null $git_branch
     */
    public function setCurrentBranchForRepo($git_user_and_repo, $git_branch = NULL) {
        extract($this->userRepoPairDivide($git_user_and_repo), 3);
        $pair_low = \strtolower($git_user . '/' .$git_repo);
        $this->repoCurrentBranch[$pair_low] = $git_branch;
    }
    
    /**
     * Set default local path for using when need local path but unspecified
     * 
     * @param string|null $local_path
     */
    public function setDefaultLocalPath($local_path = NULL) {
        $this->defaultLocalPath = empty($local_path) ?
            NULL : $this->pathDs($local_path);
    }
    
    /**
     * Set Local Path for specified repository
     * 
     * @param string $git_user_and_repo
     * @param string $local_path
     */    
    public function setLocalPathForRepo($git_user_and_repo, $local_path) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));
        $pair_low = \strtolower($git_user . '/' .$git_repo);
        $this->localRepoPathArr[$pair_low] = $this->pathDs($local_path);
    }

    /**
     * Set hooks for write-enable mode
     */
    public function writeEnable() {
        $this->writeDisable(); //for clear/initialize all hooks
        //set callable functions for mkdir and file_put_contents
        $this->fnMkDir = array($this, 'checkDirMkDir');
        $this->fnFilePutContents = 'file_put_contents';
    }
    
    /**
     * Set hooks for overwrite-mode
     * This means that local files will be replaced by files from the repository
     */
    public function writeEnableOverwrite() {
        $this->writeDisable(); //for clear/initialize all hooks
        $this->writeEnable();
        //when local file are different remove it and save from repo
        $this->fnConflict = function($par_arr) {
            unlink($par_arr['fullPathFileName']);//remove local version of file
            return true; //true = write file
        };
    }
    
    /**
     * Clear all hooks to init-state, this entails write disable
     */
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
    
    /**
     * Return path with directory separator in end
     * 
     * @param string $localRepoPathArr
     * @return string
     */
    public function pathDs($localRepoPathArr) {
        return \dirname($localRepoPathArr . \DIRECTORY_SEPARATOR . 'a')
            . \DIRECTORY_SEPARATOR;        
    }
    
    /**
     * Return default_branch for specified repository
     * 
     * @param string|null $git_user_and_repo
     * @return string
     * @throws \Exception
     */
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
    
    
    /**
     * @api
     * @param string|null $git_user_and_repo
     * @return object
     * @throws \Exception
     */
    public function getRepositoryInfo($git_user_and_repo = NULL) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));
        $pair = $git_user . '/' . $git_repo;
        $pair_low = strtolower($pair);
        if(!isset($this->cachedRepositoryInfo[$pair_low])) {
            $raw_json = $this->httpsGetContentsOrCache(
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

    /**
     * Function retrieving list of GitHub repositories for specified user
     * and return array in simple internal format
     * Side effect: store result array in $this->userRepositoriesArr[git-user]
     * 
     * @param string|null $git_user_and_repo
     * @return array of repositories
     * @throws \Exception
     */
    public function getUserRepositoriesList(
        $git_user_and_repo = NULL,  // git-user of NULL for use $this->defaultGitUser
        $page_n = false  //page number (api return 30 repositories per page)
    ) {
        extract($this->userRepoPairDivide($git_user_and_repo, 1));
        
        $git_user_low = \strtolower($git_user);
        $header_link_cache_file=$this->httpsGetContentsCacheFile($git_user_low);
        
        if( // looking for cached data
            !isset($this->userRepositoriesArr[$git_user_low])
        ) {
            //if no data in memory cache
            $repo_arr=[];
            $curr_page = ($page_n) ? $page_n : 1;
            $total_pages = 1;
            while($curr_page<=$total_pages) {
                //if pagin_arr unknown, try to get from $header_link_cache_file
                if(empty($pagin_arr) && !empty($header_link_cache_file)) {
                    $link_head = $this->cacheTryGetFile($header_link_cache_file);
                    if(!empty($link_head)) {
                        $pagin_arr = $this->parseHttpHeadLink($link_head);
                        if(is_array($pagin_arr)) extract($pagin_arr);
                        //$total_pages , $base_url, $paginator
                    }
                }
                
                //get first page or other pages
                if($curr_page == 1) {
                    $srcURL = 'https://api.github.com/users/' . $git_user . '/repos';
                } else {
                    if(empty($pagin_arr)) {
                        throw new \Exception("ERROR unknown pagination", 800);
                    }
                    $srcURL = $base_url . $paginator . $curr_page;
                }
                $this->httpHeaderLink=false;
                $raw_json = $this->httpsGetContentsOrCache($srcURL);
                if(!$raw_json) {
                    throw new \Exception("Data not received from $srcURL", 500);
                }
                //if have HeaderLink, put to file
                if($this->httpHeaderLink) {
                    $this->cacheTryPutFile($header_link_cache_file, $this->httpHeaderLink);
                    $pagin_arr = $this->parseHttpHeadLink($this->httpHeaderLink);
                    if(is_array($pagin_arr)) extract($pagin_arr);
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
                foreach($results_obj as $repo_obj) {
                    $iter_obj=[];
                    foreach($this->interestingRepoPars as $repoPar) {
                        $inter_obj[$repoPar] = $repo_obj->{$repoPar};
                    }
                    $repo_arr[\strtolower($repo_obj->name)] = $inter_obj;
                }
                if($page_n) break;
                $curr_page++;
            }
            //cache answer
            $this->userRepositoriesArr[$git_user_low] = $repo_arr;
        }
        return $this->userRepositoriesArr[$git_user_low];
    }
    
    /**
     * Function return branch name if it set by setCurrentBranchForRepo
     * or return default_branch name
     * 
     * @param string|null $git_user_and_repo
     * @return string
     */
    public function getCurrentBranchName($git_user_and_repo = NULL) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));        
        $pair_low = \strtolower($git_user . '/' . $git_repo);
        if(empty($this->repoCurrentBranch[$pair_low])) {
            return $this->getDefaultBranchName($pair_low);
        } else {
            return $this->repoCurrentBranch[$pair_low];
        }
    }
    
    /**
     * Return array [total_pages, base_url, paginator] or false if bad data
     * @param string $pagin
     * @return array|boolean
     */
    public function parseHttpHeadLink($pagin) {
        //How many pages?
        $i=strpos($pagin,'>; rel="last');
        if($i>9) {
            $addit = 0;
        } else {
            $i=strpos($pagin,'>; rel="prev');
            if($i>9) $addit = 1; else return false;
        }
        $tp = explode('=',substr($pagin,$i-7,7));
        if(empty($tp[1]) || !is_numeric($tp[1])) return false;
        $tp = $tp[1]+$addit; //total pages
        //Cut base-link
        $i=strpos($pagin,'<http');
        if($i === false) return false;
        $j=strpos($pagin,'?',$i);
        if($j === false) return false;
        $base=substr($pagin,$i+1,$j-$i-1);
        return ['total_pages'=>$tp,'base_url'=>$base,'paginator'=>'?page='];
    }
    
    /**
     * Function return api-url from wich can get repository files list
     * 
     * @param string|null $git_user
     * @param string|null $git_repo
     * @param string|null $git_branch
     * @return string
     */
    protected function gitRepoFilesUrl(
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
    
    /**
     * Retreive and return repository files list by github api
     * 
     * @uses GitRepoWalk::gitRepoFilesUrl
     * @param string|null $git_user_and_repo
     * @param string|null $git_branch
     * @return object
     * @throws \Exception
     */
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
            $raw_json = $this->httpsGetContentsOrCache($srcURL);
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
    
    /**
     * Return contacts info (emails, names, roles) from specified repository
     * 
     * @uses GitRepoWalk::getBranchesList
     * @param string|null $git_user_and_repo
     * @return array of contacts
     */
    public function getRepositoryContacts($git_user_and_repo = NULL) {
        $branches = $this->getBranchesList($git_user_and_repo);
        $contacts_arr=[];
        foreach($branches as $branch) {
            $obj = json_decode($this->httpsGetContentsOrCache($branch->object->url));
            foreach(['author','committer'] as $key) {
                if(!isset($obj->{$key})) continue;
                $email = $obj->{$key}->email;
                if($email == 'noreply@github.com') continue;
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
    
    /**
     * Retreive and return branches list from specified repository
     * 
     * @param string|null $git_user_and_repo
     * @return object json_decoded
     * @throws \Exception
     */
    public function getBranchesList($git_user_and_repo = NULL) {
        extract($this->userRepoPairDivide($git_user_and_repo, 3));
        $raw_json = $this->httpsGetContentsOrCache(
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
    
    /**
     * Compare local file with file in git-repository
     * 
     * @param string $fullPathFileName
     * @param integer $ExpectedGitSize
     * @param string $ExpectedGitHash
     * @return boolean (true if file is equal)
     */
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

    /**
     * Main work cycle
     * 
     * @param string|null $localPath
     * @param string|null $git_user_and_repo
     * @param string|null $git_branch
     * @return array of statistic results
     * @throws \Exception
     */
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

        //get hooks from $this->vars to local vars (for use by "compact")
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
                \is_callable($this->fnGitPathFilter) &&
                \call_user_func($this->fnGitPathFilter, $git_fo)
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
          'cntConflicts'=>$this->cntConflicts,
          'xRateLimit'=>$this->xRateLimit,
          'xRateRemaining'=>$this->xRateRemaining,
          'xRateResetInSec'=>($this->xRateLimit)?($this->xRateReset-time()):'',
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

    /**
     * Activate cache for  httpsGetContentsOrCache($url) function.
     * 
     * @param string $local_path
     * @param integer|null $time_to_live_sec
     * @throws \Exception
     */
    public function cacheGetContentsActivate($local_path, $time_to_live_sec=3600) {
        if(!is_dir($local_path)) {
            throw new \Exception("Not found path for cache $local_path");
        }
        $local_path = $this->pathDs($local_path);
        $this->cacheGetContentsPath = $local_path;
        $this->cacheDefaultTimeLiveSec = $time_to_live_sec;
    }
    /**
     * Caching data in local folder $this->cacheGetContentsPath
     * do not send new request if have cached data.
     * time to live cached data is $this->cacheGetContentsSec seconds.
     * 
     * @param srting $url
     * @return string
     */
    public function httpsGetContentsOrCache($url, $time_to_live = NULL) {
        if($this->cacheGetContentsPath) {
            $cache_file = $this->httpsGetContentsCacheFile($url);
            if(is_null($time_to_live) && isset($this->cacheDefaultTimeLiveSec)) {
                $time_to_live = $this->cacheDefaultTimeLiveSec;
            }
            $data = $this->cacheTryGetFile($cache_file, $time_to_live);
            if($data !== false) return $data;
        }
        $data = $this->httpsGetContents($url);
        if(!empty($cache_file) && !empty($data)) {
            file_put_contents($cache_file, $data);
        }
        return $data;
    }
    /**
     * Try to get and return data from cache-file
     * return false if not found or time_to_live expired
     * 
     * @param string $cache_file
     * @param integer|null $time_to_live
     * @return string|boolean
     */
    protected function cacheTryGetFile($cache_file, $time_to_live=3600) {
        $filemtime = @filemtime($cache_file);  // returns FALSE if no file
        if (!$filemtime) return false;
        //if cached, check time-limit
        if (time() - $filemtime >= $time_to_live) return false;
        $data = file_get_contents($cache_file);
        return $data;
    }
    /**
     * Try to put data into cache-file
     * 
     * @param string|boolean $cache_file
     * @param string $data
     */
    protected function cacheTryPutFile($cache_file, $data) {
        if(!empty($cache_file) && !empty($data)) {
            file_put_contents($cache_file, $data);
        }
    }
    /**
     * Return cache-file-name for specified $url
     * 
     * @param string $url
     * @return string|boolean
     */
    protected function httpsGetContentsCacheFile($url) {
        if(empty($this->cacheGetContentsPath)) return false;
        $cache_file = $this->cacheGetContentsPath . md5($url) .'.json';
        return $cache_file;
    }
    /**
     * Remove cache-file for specified $url, if exist
     * 
     * @param string $url
     */
    protected function httpsGetContentsCacheRemove($url) {
        if($this->cacheGetContentsPath) {
            $cache_file = $this->httpsGetContentsCacheFile($url);
            @unlink($cache_file);
        }
    }
    
    /**
     * Get content from specified url, using CURL
     * 
     * @param string $url
     * @param string $ua
     * @return string
     */
    public function httpsGetContents($url, $ua = 'curl/7.26.0') {
         $ch = \curl_init();
         \curl_setopt($ch, \CURLOPT_URL, $url);
         \curl_setopt($ch, \CURLOPT_USERAGENT, $ua);
         \curl_setopt($ch, \CURLOPT_SSL_VERIFYPEER, false);
         \curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this,'fnCurlHeadersCheck']);
         \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, 1);
         $data = \curl_exec($ch);
         \curl_close($ch);
         return $data;
    }
    public function fnCurlHeadersCheck($ch, $hstr) {
        $i=strpos($hstr,': ');
        if($i) {
            $h_name = substr($hstr,0,$i);
            $h_value = trim(substr($hstr,$i+2));
            //api.github gives http-headers about rate-limit parameters
            switch($h_name) {
            case 'X-RateLimit-Limit':
                $this->xRateLimit = $h_value;
                break;
            case 'X-RateLimit-Remaining':
                $this->xRateRemaining = $h_value;
                break;
            case 'X-RateLimit-Reset':
                $this->xRateReset = $h_value;
                break;
            case 'Link':
                $this->httpHeaderLink = $h_value;
                break;
            }
        }
        return strlen($hstr);
    }
    

    /**
     * Get content from specified url, using file_get_contents function
     * 
     * @param string $url
     * @param string $http_opt_arr
     * @return string
     */
    public function urlGetContents(
        $url,
        $http_opt_arr = ['user_agent' => 'curl/7.26.0']
    ) {
        return \file_get_contents($url, false, \stream_context_create([
            'http' => $http_opt_arr
        ]));
    }
    
    /**
     * Make url by wich to get content of specified file from git-repository
     * 
     * @param string $git_fileName
     * @param string|null $git_user_and_repo
     * @param string|null $git_branch
     * @return string
     */
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
    
    /**
     * Download and return content of specified file from git-repository
     * 
     * @uses GitRepoWalk::gitRAWfileUrl
     * @param string $git_fileName
     * @param string|null $git_user_and_repo
     * @param string|null $git_branch
     * @return string file content
     */
    public function gitRAWfileDownload(
        $git_fileName,
        $git_user_and_repo = NULL,
        $git_branch = NULL
    ) {
        $srcURL = $this->gitRAWfileUrl($git_fileName, $git_user_and_repo, $git_branch);
        return $this->httpsGetContents($srcURL);
    }
    
    /**
     * Download content of specified file from git-repository using api.github
     * ATTN: this function not recomended for download files,
     * because api.github.com have rate-limit 60req/hour.
     * Better use gitRAWfileDownload function
     * 
     * @param string $srcURL
     * @return string file content
     */
    public function gitAPIfileDownload($srcURL)
    {
       $api_content = $this->httpsGetContents($srcURL);
       if(!$api_content) return false;
       $api_content = \json_decode($api_content);
       if(!$api_content->content) return false;
       return \base64_decode($api_content->content);
    }
    
    /**
     * This function set as for hooks listed in walkHookNames property
     * 
     * @param array $par_arr
     */
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

   /**
    * MkDir with all subfolders
    * 
    * @param string $fullPath
    * @param string $srcDS
    * @return boolean true if successful
    */
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
