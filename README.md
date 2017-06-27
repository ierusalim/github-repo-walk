# github-repo-walk

Simple class for download files from GitHub repositories.

_It is not wrapper for "git". Using api.github.com to get files list.

### Example of use:
```php
namespace ierusalim\GitRepoWalk;

require 'GitRepoWalk.php'; // or require 'vendor/autoload.php';

$g = new GitRepoWalk();

$g->writeEnable(); // if skip it remote repository will be compare with local

//download all files from repository to local-path
$stat = $g->gitRepoWalk( 
    '<local path for repository>',
    'ierusalim/github-repo-walk' //git-user and repository in one string
);

print_r($stat);
```

Result: download files from this repository to &lt;local path for repository&gt;

### Examples of some other functions:

```php
//Get repositories list for specified user:
$repo_list_arr = $g->getUserRepositoriesList('php-fig');

//Get information about repository 'user/repo'
$repo_info = $g->getRepositoryInfo('ierusalim/github-repo-walk');

//Get contacts from repository 'user/repo' (emails, names, roles)
$contacts = $g->getRepositoryContacts('ierusalim/github-repo-walk');

print_r($contacts);

//Get files list from repository
$files = $g->getRepoFilesList("ierusalim/github-repo-walk");
//show file names
foreach($files->tree as $file_obj) {
    echo $file_obj->path . "\t{$file_obj->size}\n";
}

```
